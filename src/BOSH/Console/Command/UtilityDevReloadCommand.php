<?php

namespace BOSH\Console\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Yaml\Yaml;

class UtilityDevReloadCommand extends Command
{
    protected function configure()
    {
        $this
            ->setName('utility:dev:reload')
            ->setDescription('Deploy the httpassetcache deployment')
            ->setDefinition(
                [
                    new InputArgument(
                        'director',
                        InputArgument::REQUIRED,
                        'Director name'
                    ),
                    new InputArgument(
                        'deployment',
                        InputArgument::REQUIRED,
                        'Deployment name'
                    ),
                    new InputArgument(
                        'component',
                        InputArgument::OPTIONAL,
                        'Component name'
                    ),
                ]
            )
            ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $network = YAML::parse(file_get_contents($input->getOption('basedir') . '/network.yml'));

        $mymanifest = Yaml::parse(file_get_contents($input->getOption('basedir') . '/compiled/' . $input->getArgument('director') . '/' . $input->getArgument('deployment') . '/bosh' . ($input->getArgument('component') ? ('--' . $input->getArgument('component')) : '') . '.yml'));

        $awsEc2 = \Aws\Ec2\Ec2Client::factory([
            'region' => $network['regions'][$input->getArgument('director')]['region'],
        ]);

        foreach ($mymanifest['jobs'] as $job) {
            $output->writeln('> <info>' . $job['name'] . '</info>');

            $output->writeln('  > <comment>finding ' . $job['networks'][0]['static_ips'][0] . '</comment>...');

            $instances = $awsEc2->describeInstances([
                'Filters' => [
                    [
                        'Name' => 'network-interface.addresses.private-ip-address',
                        'Values' => [
                            $job['networks'][0]['static_ips'][0],
                        ],
                    ],
                    [
                        'Name' => 'instance-state-name',
                        'Values' => [
                            'running',
                        ],
                    ],
                ],
            ]);

            $instance = $instances['Reservations'][0]['Instances'][0];

            $output->writeln('    > <info>instance-id</info> -> ' . $instance['InstanceId']);
            $output->writeln('    > <info>availability-zone</info> -> ' . $instance['Placement']['AvailabilityZone']);


            $output->writeln('  > <comment>stopping services</comment>...');

            $this->sshexec($input, $output, $job, '/var/vcap/bosh/bin/monit stop all && echo -n "    > waiting..." && while `/var/vcap/bosh/bin/monit summary | grep running` ; do echo -n "." ; sleep 2 ; done');

            $output->writeln('done');


            foreach ($job['_dev_reload'] as $reloadTask) {
                if ('snapshot_copy' == $reloadTask['method']) {
                    $output->writeln('  > <comment>finding snapshot</comment>...');

                    $filters = [
                        [
                            'Name' => 'status',
                            'Values' => [
                                'completed',
                            ],
                        ],
                        [
                            'Name' => 'tag:Name',
                            'Values' => [
                                $reloadTask['name'] . '/main/0/sdf',
                            ],
                        ],
                    ];

                    if (isset($reloadTask['director'])) {
                        $filters[] = [
                            'Name' => 'tag:director_name',
                            'Values' => [
                                $reloadTask['director'],
                            ],
                        ];
                    }

                    $snapshots = $awsEc2->describeSnapshots([
                        'Filters' => $filters,
                    ]);

                    $latestSnapshot = null;

                    foreach ($snapshots['Snapshots'] as $snapshot) {
                        if (!$latestSnapshot) {
                            $latestSnapshot = $snapshot;
                        } elseif ($latestSnapshot['StartTime'] < $snapshot['StartTime']) {
                            $latestSnapshot = $snapshot;
                        }
                    }

                    if (!$latestSnapshot) {
                        throw new \RuntimeException('Unable to find a recent snapshot.');
                    }

                    $output->writeln('    > <info>snapshot-id</info> -> ' . $latestSnapshot['SnapshotId']);
                    $output->writeln('    > <info>start-time</info> -> ' . $latestSnapshot['StartTime']);


                    $output->writeln('  > <comment>creating volume</comment>...');

                    $volumeStatus = $volume = $awsEc2->createVolume([
                        'SnapshotId' => $latestSnapshot['SnapshotId'],
                        'AvailabilityZone' => $instance['Placement']['AvailabilityZone'],
                    ]);

                    $output->writeln('    > <info>volume-id</info> -> ' . $volume['VolumeId']);


                    $currStatus = 'unknown';
                    $output->write('    > <comment>waiting</comment>...');

                    while (true) {
                        $volumeStatus = $awsEc2->describeVolumes([
                            'VolumeIds' => [
                                $volume['VolumeId'],
                            ],
                        ]);

                        $volumeStatus = $volumeStatus['Volumes'][0];

                        if ($currStatus != $volumeStatus['State']) {
                            $currStatus = $volumeStatus['State'];

                            $output->write($currStatus . '...');
                        } else {
                            $output->write('.');
                        }

                        if ('available' == $currStatus) {
                            $output->writeln('');

                            break;
                        }

                        sleep(2);
                    }


                    $output->writeln('  > <comment>attaching volume</comment>...');

                    $awsEc2->attachVolume([
                        'InstanceId' => $instance['InstanceId'],
                        'VolumeId' => $volume['VolumeId'],
                        'Device' => '/dev/xvdj',
                    ]);


                    $currStatus = 'unknown';
                    $output->write('    > <comment>waiting</comment>...');

                    while (true) {
                        $volumeStatus = $awsEc2->describeVolumes([
                            'VolumeIds' => [
                                $volume['VolumeId'],
                            ],
                        ]);

                        $volumeStatus = $volumeStatus['Volumes'][0];

                        if ($currStatus != $volumeStatus['State']) {
                            $currStatus = $volumeStatus['State'];

                            $output->write($currStatus . '...');
                        } else {
                            $output->write('.');
                        }

                        if ('in-use' == $currStatus) {
                            $output->writeln('');

                            break;
                        }

                        sleep(2);
                    }


                    $output->writeln('  > <comment>mounting volume</comment>...');

                    $this->sshexec($input, $output, $job, 'mkdir -p /tmp/xfer-xvdj && mount /dev/xvdj1 /tmp/xfer-xvdj');


                    $output->writeln('  > <comment>transferring data</comment>...');

                    $this->sshexec($input, $output, $job, 'cd /var/vcap/store && for DIR in `ls /tmp/xfer-xvdj` ; do [ ! -e $DIR ] || ( echo -n "  > removing $DIR..." ; rm -fr $DIR ; echo "done" ) ; echo -n "  > restoring $DIR..." ; cp -pr /tmp/xfer-xvdj/$DIR $DIR ; echo "done" ; done');


                    $output->writeln('  > <comment>unmounting volume</comment>...');

                    $this->sshexec($input, $output, $job, 'umount /tmp/xfer-xvdj && rm -fr /tmp/xfer-xvdj');


                    $output->writeln('  > <comment>detaching volume</comment>...');

                    $awsEc2->detachVolume([
                        'InstanceId' => $instance['InstanceId'],
                        'VolumeId' => $volume['VolumeId'],
                        'Device' => '/dev/xvdj',
                    ]);


                    $currStatus = 'unknown';
                    $output->write('    > <comment>waiting</comment>...');

                    while (true) {
                        $volumeStatus = $awsEc2->describeVolumes([
                            'VolumeIds' => [
                                $volume['VolumeId'],
                            ],
                        ]);

                        $volumeStatus = $volumeStatus['Volumes'][0];

                        if ($currStatus != $volumeStatus['State']) {
                            $currStatus = $volumeStatus['State'];

                            $output->write($currStatus . '...');
                        } else {
                            $output->write('.');
                        }

                        if ('available' == $currStatus) {
                            $output->writeln('');

                            break;
                        }

                        sleep(2);
                    }


                    $output->writeln('  > <comment>destroying volume</comment>...');

                    $awsEc2->deleteVolume([
                        'VolumeId' => $volume['VolumeId'],
                    ]);
                } else {
                    throw new \RuntimeException('Unknown reload method: ' . $reloadTask['method']);
                }
            }

            $output->writeln('  > <comment>starting services</comment>...');

            $this->sshexec($input, $output, $job, '/var/vcap/bosh/bin/monit start all');
        }
    }

    protected function sshexec(InputInterface $input, OutputInterface $output, array $job, $command)
    {
        $aws = Yaml::parse(file_get_contents($input->getOption('basedir') . '/global/private/aws.yml'));

        passthru('ssh -i ' . $input->getOption('basedir') . '/' . $aws['ssh_key_file'] . ' -t -q vcap@' . $job['networks'][0]['static_ips'][0] . ' -- /bin/bash -c ' . escapeshellarg('echo -n c1oudc0w | sudo -S ' . escapeshellarg('/bin/bash -c ' . escapeshellarg('set -e ; ' . $command))), $return_var);

        if ($return_var) {
            throw new \RuntimeException('Exit code was ' . $return_var);
        }
    }
}
