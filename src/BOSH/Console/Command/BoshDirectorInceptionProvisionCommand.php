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

class BoshDirectorInceptionProvisionCommand extends AbstractDirectorCommand
{
    protected $privateAws;
    protected $commandInput;
    protected function configure()
    {
        parent::configure()
            ->setName('boshdirector:inception:provision')
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
        $this->commandInput = $input; //Store for later use in helper methods.  TODO:  There must be a better way to do this

        $network = Yaml::parse(file_get_contents($input->getOption('basedir') . '/network.yml'));
        $networkLocal = $network['regions'][$input->getOption('director')];

        $this->privateAws = Yaml::parse(file_get_contents($input->getOption('basedir') . '/global/private/aws.yml'));

        $awsEc2 = \Aws\Ec2\Ec2Client::factory([
            'region' => $networkLocal['region'],
        ]);

        $output->write('> <comment>finding inception instance</comment>...');

        $instances = $awsEc2->describeInstances([
            'Filters' => [
                [
                    'Name' => 'network-interface.addresses.private-ip-address',
                    'Values' => [
                        $networkLocal['zones'][0]['reserved']['inception'],
                    ],
                ],
            ],
        ]);

        if (!isset($instances['Reservations'][0]['Instances'][0])) {
            throw new \LogicException('Unable to find inception instance');
        }

        $output->writeln('found');

        $instance = $instances['Reservations'][0]['Instances'][0];
        $this->inceptionPrivateIpAddress = $instance['PrivateIpAddress'];

        $output->writeln('  > <info>instance-id</info> -> ' . $instance['InstanceId']);

        $output->writeln('> <comment>deploying</comment>...');

        $stemcell = $input->getArgument('stemcell');
        $ami = preg_match('/^ami-/', $stemcell);

        $should_resurrect_old_BOSH = true;
        $previousDeploymentFile = $input->getOption('basedir') . '/compiled/' . $input->getOption('director') . '/bosh-deployments.yml';
        if ( ! file_exists ( $previousDeploymentFile ) ) {
            $output->writeln("  > <info>unable for find previous deployments in: $previousDeploymentFile.  Will proceed to deploy new microBOSH</info>");
        } else {
            $previousDeploymentInstanceId = $this->get_bosh_deployment_value('vm_cid');
            $currentBOSHInstance = $awsEc2->describeInstances([
                'InstanceIds' => [
                    $previousDeploymentInstanceId,
                ],
            ]);

            if  (   !   empty($currentBOSHInstance['Reservations']) 
                    &&  $currentBOSHInstance['Reservations'][0]['Instances'][0]['State']['Name'] == 'running' ) {
                $output->writeln("  > <info>Found existing microBOSH running as instance: $previousDeploymentInstanceId</info>");
            } else {
                $output->writeln("  > <info>Existing microBOSH instance $previousDeploymentInstanceId missing.  Launching new microBOSH and then attaching old microBOSH's disk to it</info>...");
                $should_resurrect_old_BOSH = true;  

                $resurrectionDeploymentContents = <<<EOT
---
instances:
- :id: 1
  :name: {$this->get_bosh_deployment_value('name')}
  :uuid: {$this->get_bosh_deployment_value('uuid')}
disks: []
registry_instances: []
EOT;
                file_put_contents($previousDeploymentFile.'.resurrect',$resurrectionDeploymentContents);

                $output->writeln('  > <info>uploading "empty" bosh-deployments.yml to prevent attempting to delete old microBOSH</info>...');
                $this->rsync_to_inception_server(
                    $previousDeploymentFile.'.resurrect'
                    ,'~/cloque/self/bosh-deployments.yml'
                );           
            }
        }

        $output->writeln('  > <info>deploying microBOSH</info>...');
        $this->run_on_inception_server([
            'set -e',
            'cd ~/cloque/self',
            !$ami ? ('echo "    > Downloading stemcell (this takes a few minutes)..." ; wget -q ' . escapeshellarg($stemcell)) : "echo '    > Using AMI: $stemcell'",
            'bosh micro deployment bosh/bosh.yml',
            'bosh -n micro deploy --update-if-exists ' . escapeshellarg(($ami ? $stemcell : basename($stemcell))),
        ]);

        if ($should_resurrect_old_BOSH) {
            $output->writeln('> <comment>attaching old microBOSH persistent disk to new microBOSH</comment>...');
            $output->write('  > <info>unmounting new disk</info>...');
            preg_match('/\["(.*)"\]/', $this->capture_from_inception_server(['cd ~/cloque/self','bosh micro agent list_disk'])[0],$newDiskId); $newDiskId = $newDiskId[1];
            $this->run_on_inception_server([
                'cd ~/cloque/self',
                'bosh micro agent stop',
                "bosh micro agent unmount_disk $newDiskId",
            ]);
            $output->writeln("new disk: $newDiskId unmounted.");

            $previousDeploymentDiskId =  $this->get_bosh_deployment_value('disk_cid'); //our local bosh-deployments.yml contains the old microBOSH's disk id
            $boshInstanceId = $this->capture_from_inception_server(['awk \'/\s+:vm_cid:\s+(.*)/ { print $2 }\' ~/cloque/self/bosh-deployments.yml'])[0];
            $output->write("  > <info>Detach new disk $newDiskId and attach old disk $previousDeploymentDiskId to instance: $boshInstanceId</info>");
    
            $awsEc2->detachVolume(array(
                'VolumeId' => $newDiskId
            ));

            //Detaching the old volume takes a while, so retry a few times when attaching the new one 
            //to deal with "Attachment point /dev/sdf is already in use" errors
            $volumeAttached = false; $retries = 0;
            while (!$volumeAttached && $retries < 10) {
                try {
                    $awsEc2->attachVolume(array(
                        'VolumeId' => $previousDeploymentDiskId,
                        'InstanceId' => $boshInstanceId,
                        'Device' => '/dev/sdf'
                    ));
                    $volumeAttached = true;
                } catch (\Aws\Ec2\Exception\Ec2Exception $e) {
                    $retries++;
                    $output->write('.');
                    sleep(2);  
                }
            }

            $output->writeln("done.");

            $output->writeln('  > <info>Changing disk id reference in (microBOSH):/var/vcap/bosh/settings.json</info>');
            $output->writeln('  > <info>When prompted, [sudo] password for vcap is: c1oudc0w</info>');
            $boshIP = $this->capture_from_inception_server(['grep -Po \'"ip":"\K(.*?)(?=")\' ~/cloque/self/bosh-deployments.yml'])[0];
            $this->run_on_server('vcap', $boshIP, ["sudo sed -i 's/$newDiskId/$previousDeploymentDiskId/' /var/vcap/bosh/settings.json"]);
            $output->writeln('  > <info>Changing disk id reference in  ~/cloque/self/bosh-deployments.yml</info>');
            $this->run_on_inception_server(["sed -i 's/$newDiskId/$previousDeploymentDiskId/' ~/cloque/self/bosh-deployments.yml"]);

            $output->writeln('  > <info>Restarting microBOSH</info>');
            $this->run_on_inception_server(['cd ~/cloque/self','bosh micro agent start']);

            $output->writeln("  > <info>Deleting disk: $newDiskId</info>");
            $awsEc2->deleteVolume(array(
                'VolumeId' => $newDiskId
            ));

            $output->writeln('> <comment>Old microBOSH resurrected!</comment>...');
        }

        $output->writeln('> <comment>fetching updated bosh-deployments.yml</comment>...');

        $this->rsync_from_inception_server(
            '~/cloque/self/bosh-deployments.yml',
            $input->getOption('basedir') . '/compiled/' . $input->getOption('director') . '/bosh-deployments.yml'
        );

        $output->write('> <comment>tagging</comment>...');

        $awsEc2->createTags([
            'Resources' => [
                $this->get_bosh_deployment_value('vm_cid'),
            ],
            'Tags' => [
                [
                    'Key' => 'Name',
                    'Value' => 'microbosh',
                ],
                [
                    'Key' => 'deployment',
                    'Value' => 'bosh',
                ],
                [
                    'Key' => 'director',
                    'Value' => $network['root']['name'] . '-' . $input->getOption('director'),
                ],
            ],
        ]);

        $output->writeln('done');
    }

    protected function get_bosh_deployment_value($key) {
        $value=array('','');
        # symfony yaml doesn't like ruby names, so regex out the data we need
        preg_match('/\s+:'.$key.':\s+(.*)/', 
            file_get_contents($this->commandInput->getOption('basedir') . '/compiled/' . $this->commandInput->getOption('director') . '/bosh-deployments.yml'),
            $value); 
        return $value[1];
    }

    protected function capture_from_inception_server($cmds) {
        return $this->capture_on_server('ubuntu', $this->inceptionPrivateIpAddress, $cmds);
    }

    protected function capture_on_server($username, $ip, $cmds) {
         exec(
            sprintf(
                'ssh -o "StrictHostKeyChecking no" -t -i %s %s@%s %s',
                escapeshellarg($this->commandInput->getOption('basedir') . '/' . $this->privateAws['ssh_key_file']),
                $username,
                $ip,
                escapeshellarg( 
                    implode(
                        ' ; ',
                        $cmds
                    )
                )
            ),
            $output,
            $return_var
        );

        if ($return_var) {
            throw new \RuntimeException('Exit code ' . $return_var);
        }
        return $output;
    }

    protected function run_on_inception_server($cmds) {
         $this->run_on_server('ubuntu', $this->inceptionPrivateIpAddress, $cmds);
    }

    protected function run_on_server($username, $ip, $cmds) {
         passthru(
            sprintf(
                'ssh -o "StrictHostKeyChecking no" -t -i %s %s@%s %s',
                escapeshellarg($this->commandInput->getOption('basedir') . '/' . $this->privateAws['ssh_key_file']),
                $username,
                $ip,
                escapeshellarg( 
                    implode(
                        ' ; ',
                        $cmds
                    )
                )
            ),
            $return_var
        );

        if ($return_var) {
            throw new \RuntimeException('Exit code ' . $return_var);
        }
    }


    protected function rsync_to_inception_server($local, $remote) {
        passthru(
            sprintf(
                'rsync -auze %s --progress %s ubuntu@%s:%s',
                escapeshellarg('ssh -i ' . escapeshellarg($this->commandInput->getOption('basedir') . '/' . $this->privateAws['ssh_key_file'])),
                escapeshellarg($local),
                $this->inceptionPrivateIpAddress,
                escapeshellarg($remote)
            )
        );
    }

    protected function rsync_from_inception_server($remote, $local) {
        passthru(
            sprintf(
                'rsync -auze %s --progress ubuntu@%s:%s %s',
                escapeshellarg('ssh -i ' . escapeshellarg($this->commandInput->getOption('basedir') . '/' . $this->privateAws['ssh_key_file'])),
                $this->inceptionPrivateIpAddress,
                escapeshellarg($remote),
                escapeshellarg($local)
            )
        );
    }

    protected function subrun($name, array $arguments, OutputInterface $output)
    {
        $return = $this->getApplication()->find($name)->run(
            new ArrayInput(array_merge($arguments, [ 'command' => $name ])),
            $output
        );

        if ($return) {
            throw new \RuntimeException(sprintf('%s exited with %s', $name, $return));
        }
    }
}
