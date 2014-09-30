<?php

namespace BOSH\Console\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Command\Command;
use BOSH\Deployment\ManifestModel;
use Symfony\Component\Yaml\Yaml;

use BOSH\Console\Command\BoshDirectorHelpers;

class BoshDirectorInceptionResurrectCommand extends AbstractDirectorCommand
{
    protected $privateAws;
    protected $commandInput;
    protected function configure()
    {
        parent::configure()
            ->setName('boshdirector:inception:resurrect')
            ->setDescription('Start an inception server')
            ->addArgument(
                'stemcell',
                InputArgument::REQUIRED,
                'BOSH AMI or Stemcell URL'
            )
            ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $output->writeln('><comment>Resurrecting microBOSH</comment>...');
        $h = new \BOSH\Console\Command\BoshDirectorHelpers($input, $output);

        $previousDeploymentFile = $input->getOption('basedir') . '/compiled/' . $input->getOption('director') . '/bosh-deployments.yml';
        if ( ! file_exists ( $previousDeploymentFile ) ) {
            $output->writeln("  > <info>unable for find previous deployments in: $previousDeploymentFile. Aborting since there is nothing to resurrect.</info>");
            return;
        }

        $previousDeploymentInstanceId = $h->getBOSHDeploymentValue('vm_cid');

        $previousDeployment = $h->ec2Client->describeInstances([
            'InstanceIds' => [
                $previousDeploymentInstanceId,
            ],
        ]);

        if  (   !   empty($previousDeployment['Reservations']) 
                &&  $previousDeployment['Reservations'][0]['Instances'][0]['State']['Name'] == 'running' ) {
            $output->writeln("  > <info>Found existing microBOSH running as instance: $previousDeploymentInstanceId.  Aborting since no resurrection required.</info>");
            return;
        }

        $previousDeploymentDiskId = $h->getBOSHDeploymentValue('disk_cid'); 
        $output->writeln("> <comment>Existing microBOSH instance $previousDeploymentInstanceId missing.  Launching new microBOSH and then attaching old microBOSH's disk ($previousDeploymentDiskId) to it</comment>...");
        $previousDeploymentFileBackup = $previousDeploymentFile.date("YmdHis");
        $output->writeln("> <comment>Backing up previous deployment settings to $previousDeploymentFileBackup</comment>...");
        copy($previousDeploymentFile, $previousDeploymentFileBackup);
        
        $resurrectionDeploymentContents = <<<EOT
---
instances:
- :id: 1
  :name: {$h->getBOSHDeploymentValue('name')}
  :uuid: {$h->getBOSHDeploymentValue('uuid')}
disks: []
registry_instances: []
EOT;
        file_put_contents($previousDeploymentFile.'.resurrect',$resurrectionDeploymentContents);

        $output->writeln('  > <info>uploading "empty" bosh-deployments.yml to prevent attempting to delete old microBOSH</info>...');
        $inceptionIp = $h->getInceptionInstanceDetails()['PrivateIpAddress'];
        $h->rsyncToServer(
            $previousDeploymentFile.'.resurrect'
            ,"ubuntu@$inceptionIp:~/cloque/self/bosh-deployments.yml"
        );           

        $output->writeln('  > <info>deploying microBOSH</info>...');
        $this->execCommand($input, $output, 'boshdirector:inception:provision', [ 'stemcell' => $input->getArgument('stemcell') ]);

        $output->writeln('> <comment>attaching old microBOSH persistent disk to new microBOSH</comment>...');
        $output->write('  > <info>unmounting new disk</info>...');
        preg_match('/\["(.*)"\]/', $h->captureFromServer('ubuntu', $inceptionIp, ['cd ~/cloque/self','bosh micro agent list_disk'])[0],$newDiskId); 
        $newDiskId = $newDiskId[1];
        $h->runOnServer('ubuntu', $inceptionIp, [
            'cd ~/cloque/self',
            'bosh micro agent stop',
            "bosh micro agent unmount_disk $newDiskId",
        ]);
        $output->writeln("new disk: $newDiskId unmounted.");

        $boshInstanceId = $h->captureFromServer('ubuntu', $inceptionIp, ['awk \'/\s+:vm_cid:\s+(.*)/ { print $2 }\' ~/cloque/self/bosh-deployments.yml'])[0];
        $output->writeln("  > <info>Detach new disk $newDiskId and attach old disk $previousDeploymentDiskId to instance: $boshInstanceId</info>");

        $output->write("Detaching...");
        $h->ec2Client->detachVolume(array(
            'VolumeId' => $newDiskId
        ));
        $h->ec2Client->waitUntilVolumeAvailable(array(
            'VolumeId' => $newDiskId
        ));

        //Detaching the old volume takes a while, so retry a few times when attaching the new one 
        //to deal with "Attachment point /dev/sdf is already in use" errors
        $output->write(" Attaching ...");
        $volumeAttached = false; $retries = 0;
        while (!$volumeAttached && $retries < 20) {
            try {
                $output->write('.');
                $h->ec2Client->attachVolume(array(
                    'VolumeId' => $previousDeploymentDiskId,
                    'InstanceId' => $boshInstanceId,
                    'Device' => '/dev/sdf'
                ));
                $h->ec2Client->waitUntilVolumeAvailable(array(
                    'VolumeId' => $previousDeploymentDiskId
                ));
                $volumeAttached = true;
            } catch (\Aws\Ec2\Exception\Ec2Exception $e) {
                $retries++;
                sleep(3); 
            }
        }

        $output->write(" Deleting ...");
        $h->ec2Client->deleteVolume(array(
            'VolumeId' => $newDiskId
        ));

        $output->writeln("done.");

        $output->writeln('  > <info>Changing disk id reference in (microBOSH):/var/vcap/bosh/settings.json</info>');
        $boshIP = $h->captureFromServer('ubuntu', $inceptionIp, ['grep -Po \'"ip":"\K(.*?)(?=")\' ~/cloque/self/bosh-deployments.yml'])[0];
        $h->runOnServer('vcap', $boshIP, ["echo c1oudc0w | sudo sed -i 's/$newDiskId/$previousDeploymentDiskId/' /var/vcap/bosh/settings.json"]);
        $output->writeln('  > <info>Changing disk id reference in  ~/cloque/self/bosh-deployments.yml</info>');
        $h->runOnServer('ubuntu', $inceptionIp, ["sed -i 's/$newDiskId/$previousDeploymentDiskId/' ~/cloque/self/bosh-deployments.yml"]);

        $output->writeln('  > <info>Restarting microBOSH</info>');
        $h->runOnServer('ubuntu', $inceptionIp, ['cd ~/cloque/self','bosh micro agent start']);

        $h->fetchBoshDeployments($inceptionIp);

        $h->tagDirectorResources();

        $output->writeln('><info>Old microBOSH resurrected successfully</info>');
    }

}
