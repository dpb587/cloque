<?php

namespace BOSH\Console\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Command\Command;
use BOSH\Deployment\ManifestModel;
use Symfony\Component\Yaml\Yaml;

class InceptionStartCommand extends Command
{
    protected function configure()
    {
        $this
            ->setName('inception:start')
            ->setDescription('Start an inception server')
            ->setDefinition(
                [
                    new InputArgument(
                        'locality',
                        InputArgument::REQUIRED,
                        'Locality name'
                    ),
                    new InputOption(
                        'subnet',
                        null,
                        InputOption::VALUE_REQUIRED,
                        'Subnet'
                    ),
                    new InputOption(
                        'instance-type',
                        null,
                        InputOption::VALUE_REQUIRED,
                        'Instance type',
                        't2.micro'
                    ),
                    new InputOption(
                        'security-group',
                        null,
                        InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                        'Security groups'
                    ),
                ]
            )
            ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $network = Yaml::parse(file_get_contents($input->getOption('basedir') . '/network.yml'));
        $networkLocal = $network['regions'][$input->getArgument('locality')];

        $privateAws = Yaml::parse(file_get_contents($input->getOption('basedir') . '/global/private/aws.yml'));

        $awsEc2 = \Aws\Ec2\Ec2Client::factory([
            'region' => $networkLocal['region'],
        ]);

        $output->write('> <comment>finding instance</comment>...');

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
            $output->writeln('missing');

            // need to create one
            $instances = $awsEc2->runInstances([
                'ImageId' => $networkLocal['images']['ubuntu-trusty']['hvm'],
                'MinCount' => 1,
                'MaxCount' => 1,
                'KeyName' => $privateAws['ssh_key_name'],
                'InstanceType' => $input->getOption('instance-type'),
                'Placement' => [
                    'AvailabilityZone' => $networkLocal['zones'][0]['availability_zone'],
                ],
                'NetworkInterfaces' => [
                    [
                        'DeviceIndex' => 0,
                        'SubnetId' => $input->getOption('subnet'),
                        'PrivateIpAddresses' => [
                            [
                                'PrivateIpAddress' => $networkLocal['zones'][0]['reserved']['inception'],
                                'Primary' => true,
                            ],
                        ],
                        'Groups' => $input->getOption('security-group'),
                        'AssociatePublicIpAddress' => true,
                    ],
                ],
            ]);

            $instance = $instances['Instances'][0];
        } else {
            $output->writeln('found');

            $instance = $instances['Reservations'][0]['Instances'][0];
        }

        $output->writeln('  > <info>instance-id</info> -> ' . $instance['InstanceId']);

        $addTags = [];

        $expectedTags = [
            'director' => $network['root']['name'] . '-' . $input->getArgument('locality'),
            'deployment' => 'cloque/inception',
            'Name' => 'main',
        ];

        foreach ($expectedTags as $tagKey => $tagValue) {
            if (empty($niceVolumeTags[$tagKey])) {
                $output->writeln('  > <comment>tagging ' . $tagKey . '</comment> -> ' . $tagValue);

                $addTags[] = [
                    'Key' => $tagKey,
                    'Value' => $tagValue,
                ];
            } elseif ($niceVolumeTags[$tagKey] != $tagValue) {
                $output->writeln('  > <comment>tagging ' . $tagKey . '</comment> -> ' . $niceVolumeTags[$tagKey] . ' -> ' . $tagValue);

                $addTags[] = [
                    'Key' => $tagKey,
                    'Value' => $tagValue,
                ];
            }
        }

        if ($addTags) {
            $awsEc2->createTags([
                'Resources' => [
                    $instance['InstanceId'],
                ],
                'Tags' => $addTags,
            ]);
        }

        if ('stopped' == $instance['State']['Name']) {
            $output->write('> <comment>starting instance</comment>...');

            $awsEc2->startInstances([
                'InstanceIds' => $instance['InstanceId'],
            ]);

            $output->writeln('done');
        }

        if ('running' != $instance['State']['Name']) {
            $output->write('> <comment>waiting for instance</comment>...');

            $currStatus = 'waiting';

            while (true) {
                $instanceStatus = $awsEc2->describeInstances([
                    'InstanceIds' => [
                        $instance['InstanceId'],
                    ],
                ]);

                $instanceStatus = $instanceStatus['Reservations'][0]['Instances'][0];

                if ($currStatus != $instanceStatus['State']['Name']) {
                    $currStatus = $instanceStatus['State']['Name'];

                    $output->write($currStatus . '...');
                } else {
                    $output->write('.');
                }

                if ('running' == $currStatus) {
                    $output->writeln('done');

                    break;
                }

                sleep(2);
            }
        }


        $output->write('> <comment>waiting for ssh</comment>...');

        while (true) {
            $sh = @fsockopen($instance['PublicIpAddress'], 22, $errno, $errstr, 8);

            $output->write('.');

            if ($sh) {
                fclose($sh);

                $output->writeln('done');

                break;
            }

            sleep(4);
        }


        $output->writeln('> <comment>installing</comment>...');

        passthru(
            sprintf(
                'ssh -i %s ubuntu@%s %s',
                escapeshellarg($input->getOption('basedir') . '/' . $privateAws['ssh_key_file']),
                $instance['PublicIpAddress'],
                escapeshellarg(
                    implode(
                        ' ; ',
                        [
                            'set -x',
                            'sudo apt-get update -y',
                            'sudo apt-get install -y ruby ruby-dev make build-essential g++ libxml2-dev libxslt-dev libsqlite3-dev postgresql-server-dev-9.3 libmysqlclient-dev',
                            'sudo gem install --no-rdoc --no-ri bosh_cli bosh_cli_plugin_micro',
                            'mkdir -p ~/cloque/self ~/cloque/global/private',
                        ]
                    )
                )
            )
        );


        $output->writeln('> <comment>uploading compiled/self</comment>...');

        passthru(
            sprintf(
                'rsync -auze %s --progress %s ubuntu@%s:%s',
                escapeshellarg('ssh -i ' . escapeshellarg($input->getOption('basedir') . '/' . $privateAws['ssh_key_file'])),
                escapeshellarg($input->getOption('basedir') . '/compiled/' . $input->getArgument('locality') . '/.'),
                $instance['PublicIpAddress'],
                escapeshellarg('~/cloque/self/.')
            )
        );


        $output->writeln('> <comment>uploading global/private</comment>...');

        passthru(
            sprintf(
                'rsync -auze %s --progress %s ubuntu@%s:%s',
                escapeshellarg('ssh -i ' . escapeshellarg($input->getOption('basedir') . '/' . $privateAws['ssh_key_file'])),
                escapeshellarg($input->getOption('basedir') . '/global/private/.'),
                $instance['PublicIpAddress'],
                escapeshellarg('~/cloque/global/private/.')
            )
        );
    }
}
