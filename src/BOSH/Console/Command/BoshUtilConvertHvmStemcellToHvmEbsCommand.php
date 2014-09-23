<?php

namespace BOSH\Console\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Command\Command;
use Aws\Ec2\Ec2Client;
use Symfony\Component\Yaml\Yaml;

class BoshUtilConvertHvmStemcellToHvmEbsCommand extends AbstractDirectorCommand
{
    protected function configure()
    {
        parent::configure()
            ->setName('boshutil:convert-hvm-stemcell-to-hvm-ebs')
            ->setDescription('Create an HVM light-bosh stemcell with EBS ephemeral disk')
            ->addArgument(
                'stemcell-url',
                InputArgument::REQUIRED,
                'Upstream stemcell URL'
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
                'volume-size',
                null,
                InputOption::VALUE_REQUIRED,
                'EBS volume size'
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

        $hvmManifest = Yaml::parse(file_get_contents('stemcell/stemcell.MF'));

        $hvmAmi = $hvmManifest['cloud_properties']['ami'][$sourceRegion];

        $output->isVerbose()
            && $output->writeln('fetched stemcell');


        $awsEc2 = \Aws\Ec2\Ec2Client::factory([
            'region' => $sourceRegion,
        ]);

        $hvmImages = $awsEc2->describeImages([
            'ImageIds' => [
                $hvmAmi,
            ],
        ]);

        $hvmImage = $hvmImages['Images'][0];


        $output->isVeryVerbose()
            && $output->writeln('creating hvm instance');

        $hvmInstances = $awsEc2->runInstances([
            'ImageId' => $hvmImage['ImageId'],
            'MinCount' => 1,
            'MaxCount' => 1,
            'KeyName' => $privateAws['ssh_key_name'],
            'InstanceType' => 't2.micro',
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
            && $output->writeln('creating ebs image');

        $mappings = $hvmImage['BlockDeviceMappings'];

        $mappings[0]['DeviceName'] = $hvmInstance['BlockDeviceMappings'][0]['DeviceName'];
        unset($mappings[0]['Ebs']['SnapshotId']);

        $mappings[1] = [
            'DeviceName' => '/dev/sdb',
            'Ebs' => [
                'DeleteOnTermination' => true,
                'VolumeSize' => $input->getOption('volume-size') ?: 8,
                'VolumeType' => 'standard',
            ],
        ];

        $hvmImage = $awsEc2->createImage([
            'InstanceId' => $hvmInstance['InstanceId'],
            'Name' => 'ebs of ' . $hvmImage['ImageId'] . ' (' . (new \DateTime('now', new \DateTimeZone('UTC')))->format('Ymd\THis\Z') . ')',
            'BlockDeviceMappings' => $mappings,
        ])->toArray();

        $this->waitForImageStatus($output, $awsEc2, $hvmImage, 'available');

        $output->writeln('Created AMI: <info>' . $hvmImage['ImageId'] . '</info>');


        $output->isVeryVerbose()
            && $output->writeln('terminating instances');

        $awsEc2->terminateInstances([
            'InstanceIds' => [
                $hvmInstance['InstanceId'],
                $hvmInstance['InstanceId'],
            ],
        ]);

        $output->isVerbose()
            && $output->writeln('terminated instances');


        $this->execCommand(
            $input,
            $output,
            'boshutil:create-bosh-lite-stemcell-from-ami',
            [
                'stemcell-url' => $input->getArgument('stemcell-url'),
                'source-ami' => $hvmImage['ImageId'],
                '--s3-name' => preg_replace('/^(.*)\.(tgz)$/', '$1-ebs.$2', basename($input->getArgument('stemcell-url'))),
                '--stemcell-name' => $hvmManifest['name'] . '-ebs',
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
