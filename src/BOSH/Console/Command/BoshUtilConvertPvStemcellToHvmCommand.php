<?php

namespace BOSH\Console\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Command\Command;
use Aws\Ec2\Ec2Client;
use Symfony\Component\Yaml\Yaml;

class BoshUtilConvertPvStemcellToHvmCommand extends AbstractDirectorCommand
{
    protected function configure()
    {
        parent::configure()
            ->setName('boshutil:convert-pv-stemcell-to-hvm')
            ->setDescription('Create an HVM light-bosh stemcell')
            ->addArgument(
                'stemcell-url',
                InputArgument::REQUIRED,
                'Upstream stemcell URL'
            )
            ->addArgument(
                'hvm-ami',
                InputArgument::REQUIRED,
                'Source AMI'
            )
            ->addArgument(
                'subnet-id',
                InputArgument::REQUIRED,
                'Subnet ID'
            )
            ->addArgument(
                'security-group-id',
                InputArgument::REQUIRED,
                'Security Group ID'
            )
            ->addOption(
                's3-prefix',
                null,
                InputOption::VALUE_REQUIRED,
                'S3 Path Prefix for new stemcell'
            )
            ->addOption(
                's3-name',
                null,
                InputOption::VALUE_REQUIRED,
                'Override S3 file name'
            )
            ->addOption(
                'stemcell-name',
                null,
                InputOption::VALUE_REQUIRED,
                'Override stemcell name'
            )
            ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $network = Yaml::parse(file_get_contents($input->getOption('basedir') . '/network.yml'));
        $privateAws = Yaml::parse(file_get_contents($input->getOption('basedir') . '/global/private/aws.yml'));

        $sourceRegion = $network['regions'][$input->getOption('director')]['region'];

        chdir(sys_get_temp_dir());
        $uid = uniqid('stemcell-');
        exec('mkdir -p ' . escapeshellarg($uid) . '/stemcell');
        chdir($uid);

        $output->isDebug()
            && $output->writeln('cwd: ' . getcwd());


        $output->isVeryVerbose()
            && $output->writeln('fetching stemcell');

        unset($stdout);
        exec('wget -qO- ' . escapeshellarg($input->getArgument('stemcell-url')) . ' | tar -xzf- -C stemcell', $stdout, $exit);

        if ($exit) {
            $output->writeln('<error>' . implode("\n", $stdout) . '</error>');
            
            return $exit;
        }

        $pvManifest = Yaml::parse(file_get_contents('stemcell/stemcell.MF'));

        $pvAmi = $pvManifest['cloud_properties']['ami'][$sourceRegion];

        $output->isVerbose()
            && $output->writeln('fetched stemcell');


        $awsEc2 = \Aws\Ec2\Ec2Client::factory([
            'region' => $sourceRegion,
        ]);

        $pvImages = $awsEc2->describeImages([
            'ImageIds' => [
                $pvAmi,
            ],
        ]);

        $pvImage = $pvImages['Images'][0];


        $output->isVeryVerbose()
            && $output->writeln('creating pv instance');

        $pvInstances = $awsEc2->runInstances([
            'ImageId' => $pvImage['ImageId'],
            'MinCount' => 1,
            'MaxCount' => 1,
            'KeyName' => $privateAws['ssh_key_name'],
            'InstanceType' => 'm3.medium',
            'NetworkInterfaces' => [
                [
                    'DeviceIndex' => 0,
                    'SubnetId' => $input->getArgument('subnet-id'),
                    'Groups' => [
                        $input->getArgument('security-group-id'),
                    ],
                ],
            ],
        ]);

        $pvInstance = $pvInstances['Instances'][0];

        $output->isVerbose()
            && $output->writeln('created pv instance: ' . $pvInstance['InstanceId']);

        $pvInstance = $this->waitForInstanceStatus($output, $awsEc2, $pvInstance, 'running');


        $awsEc2->stopInstances([
            'InstanceIds' => [
                $pvInstance['InstanceId'],
            ],
        ]);

        $this->waitForInstanceStatus($output, $awsEc2, $pvInstance, 'stopped');


        $output->isVeryVerbose()
            && $output->writeln('creating hvm instance');

        $hvmInstances = $awsEc2->runInstances([
            'ImageId' => $input->getArgument('hvm-ami'),
            'MinCount' => 1,
            'MaxCount' => 1,
            'KeyName' => $privateAws['ssh_key_name'],
            'InstanceType' => 'm3.medium',
            'Placement' => [
                'AvailabilityZone' => $pvInstance['Placement']['AvailabilityZone'],
            ],
            'NetworkInterfaces' => [
                [
                    'DeviceIndex' => 0,
                    'SubnetId' => $input->getArgument('subnet-id'),
                    'Groups' => [
                        $input->getArgument('security-group-id'),
                    ],
                ],
            ],
        ]);

        $hvmInstance = $hvmInstances['Instances'][0];

        $output->isVerbose()
            && $output->writeln('created hvm instance: ' . $hvmInstance['InstanceId']);

        $hvmInstance = $this->waitForInstanceStatus($output, $awsEc2, $hvmInstance, 'running');


        $awsEc2->stopInstances([
            'InstanceIds' => [
                $hvmInstance['InstanceId'],
            ],
        ]);

        $this->waitForInstanceStatus($output, $awsEc2, $hvmInstance, 'stopped');


        $output->isVeryVerbose()
            && $output->writeln('detaching ' . $pvInstance['BlockDeviceMappings'][0]['Ebs']['VolumeId'] . ' from pv');

        $pvVolume = $awsEc2->detachVolume([
            'VolumeId' => $pvInstance['BlockDeviceMappings'][0]['Ebs']['VolumeId'],
        ])->toArray();

        $this->waitForVolumeStatus($output, $awsEc2, $pvVolume, 'available');


        $output->isVeryVerbose()
            && $output->writeln('detaching ' . $hvmInstance['BlockDeviceMappings'][0]['Ebs']['VolumeId'] . ' from hvm');

        $hvmVolume = $awsEc2->detachVolume([
            'VolumeId' => $hvmInstance['BlockDeviceMappings'][0]['Ebs']['VolumeId'],
        ])->toArray();

        $this->waitForVolumeStatus($output, $awsEc2, $hvmVolume, 'available');


        $output->isVeryVerbose()
            && $output->writeln('attaching ' . $pvVolume['VolumeId'] . ' to hvm');

        $awsEc2->attachVolume([
            'VolumeId' => $pvVolume['VolumeId'],
            'InstanceId' => $hvmInstance['InstanceId'],
            'Device' => $hvmInstance['BlockDeviceMappings'][0]['DeviceName'],
        ]);

        $this->waitForVolumeStatus($output, $awsEc2, $pvVolume, 'in-use');


        $output->isVeryVerbose()
            && $output->writeln('creating hvm image');

        $mappings = $pvImage['BlockDeviceMappings'];

        $mappings[0]['DeviceName'] = $hvmInstance['BlockDeviceMappings'][0]['DeviceName'];
        unset($mappings[0]['Ebs']['SnapshotId']);

fwrite(STDOUT, print_r($mappings, true));

        $hvmImage = $awsEc2->createImage([
            'InstanceId' => $hvmInstance['InstanceId'],
            'Name' => 'hvm of ' . $pvImage['ImageId'] . ' (' . (new \DateTime('now', new \DateTimeZone('UTC')))->format('Ymd\THis\Z') . ')',
            'BlockDeviceMappings' => $mappings,
        ])->toArray();

        $this->waitForImageStatus($output, $awsEc2, $hvmImage, 'available');

        $output->writeln('Created AMI: <info>' . $hvmImage['ImageId'] . '</info>');


        $awsEc2->detachVolume([
            'VolumeId' => $pvVolume['VolumeId'],
        ])->toArray();

        $this->waitForVolumeStatus($output, $awsEc2, $pvVolume, 'available');


        $output->isVeryVerbose()
            && $output->writeln('terminating instances');

        $awsEc2->terminateInstances([
            'InstanceIds' => [
                $pvInstance['InstanceId'],
                $hvmInstance['InstanceId'],
            ],
        ]);

        $output->isVerbose()
            && $output->writeln('terminated instances');


        $output->isVeryVerbose()
            && $output->writeln('deleting volumes');

        $awsEc2->deleteVolume([
            'VolumeId' => $pvVolume['VolumeId'],
        ]);

        $awsEc2->deleteVolume([
            'VolumeId' => $hvmVolume['VolumeId'],
        ]);

        $output->isVerbose()
            && $output->writeln('deleted volumes');


        $this->execCommand(
            $input,
            $output,
            'boshutil:create-bosh-lite-stemcell-from-ami',
            [
                'stemcell-url' => $input->getArgument('stemcell-url'),
                'source-ami' => $hvmImage['ImageId'],
                '--s3-name' => preg_replace('/^(.*)\.(tgz)$/', '$1-hvm.$2', basename($input->getArgument('stemcell-url'))),
                '--stemcell-name' => $pvManifest['name'] . '-hvm',
            ]
        );
    }

    protected function waitForInstanceStatus(OutputInterface $output, \Aws\Ec2\Ec2Client $awsEc2, array $instance, $status)
    {
        $output->isVeryVerbose()
            && $output->writeln('waiting for ' . $instance['InstanceId'] . ' to be ' . $status);

        $currStatus = 'unknown';

        while (true) {
            $instanceStatus = $awsEc2->describeInstances([
                'InstanceIds' => [
                    $instance['InstanceId'],
                ],
            ]);

            $instanceStatus = $instanceStatus['Reservations'][0]['Instances'][0];

            if ($currStatus != $instanceStatus['State']['Name']) {
                $currStatus = $instanceStatus['State']['Name'];

                $output->isVerbose()
                    && $output->writeln($instance['InstanceId'] . ' is ' . $currStatus);

                if ($status == $currStatus) {
                    return $instanceStatus;
                }
            }

            sleep(2);
        }
    }

    protected function waitForVolumeStatus(OutputInterface $output, \Aws\Ec2\Ec2Client $awsEc2, array $volume, $status)
    {
        $output->isVeryVerbose()
            && $output->writeln('waiting for ' . $volume['VolumeId'] . ' to be ' . $status);

        $currStatus = 'unknown';

        while (true) {
            $volumeStatus = $awsEc2->describeVolumes([
                'VolumeIds' => [
                    $volume['VolumeId'],
                ],
            ]);

            $volumeStatus = $volumeStatus['Volumes'][0];

            if ($currStatus != $volumeStatus['State']) {
                $currStatus = $volumeStatus['State'];

                $output->isVerbose()
                    && $output->writeln($volume['VolumeId'] . ' is ' . $currStatus);

                if ($status == $currStatus) {
                    return $volumeStatus;
                }
            }

            sleep(2);
        }
    }

    protected function waitForImageStatus(OutputInterface $output, \Aws\Ec2\Ec2Client $awsEc2, array $image, $status)
    {
        $output->isVeryVerbose()
            && $output->writeln('waiting for ' . $image['ImageId'] . ' to be ' . $status);

        $currStatus = 'unknown';

        while (true) {
            $imageStatus = $awsEc2->describeImages([
                'ImageIds' => [
                    $image['ImageId'],
                ],
            ]);

            $imageStatus = $imageStatus['Images'][0];

            if ($currStatus != $imageStatus['State']) {
                $currStatus = $imageStatus['State'];

                $output->isVerbose()
                    && $output->writeln($image['ImageId'] . ' is ' . $currStatus);

                if ($status == $currStatus) {
                    return $imageStatus;
                }
            }

            sleep(2);
        }
    }
}
