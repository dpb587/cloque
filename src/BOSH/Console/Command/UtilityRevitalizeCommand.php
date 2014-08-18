<?php

namespace BOSH\Console\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Yaml\Yaml;

class UtilityRevitalizeCommand extends Command
{
    protected function configure()
    {
        $this
            ->setName('utility:revitalize')
            ->setDescription('Revitalize a deployment')
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
                    new InputOption(
                        'component',
                        null,
                        InputOption::VALUE_REQUIRED,
                        'Component name',
                        null
                    ),
                ]
            )
            ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $baseargs = [
            'locality' => $input->getArgument('director'),
            'deployment' => $input->getArgument('deployment'),
        ];

        if ($input->getOption('component')) {
            $baseargs['--component'] = $input->getOption('component');
        }

        $this->subrun(
            'bosh:compile',
            $baseargs,
            $input,
            $output
        );


        $network = YAML::parse(file_get_contents($input->getOption('basedir') . '/network.yml'));

        $mymanifest = Yaml::parse(file_get_contents($input->getOption('basedir') . '/compiled/' . $input->getArgument('director') . '/' . $input->getArgument('deployment') . '/bosh' . ($input->getOption('component') ? ('--' . $input->getOption('component')) : '') . '.yml'));

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

            $this->sshexec($input, $output, $job, '/var/vcap/bosh/bin/monit stop all && echo -n "    > waiting..." && while /var/vcap/bosh/bin/monit summary | grep running > /dev/null 2>&1 ; do echo -n "." ; sleep 2 ; done');

            $output->writeln('done');


            foreach ($job['cloque.revitalize'] as $revitalizeTask) {
                if ('snapshot_copy' == $revitalizeTask['method']) {
                    $output->writeln('  > <info>snapshot_copy</info>');

                    $output->writeln('    > <comment>finding snapshot</comment>...');

                    $filters = [
                        [
                            'Name' => 'status',
                            'Values' => [
                                'completed',
                            ],
                        ],
                    ];

                    if (isset($revitalizeTask['id'])) {
                        $filters[] = [
                            'Name' => 'snapshot-id',
                            'Values' => [
                                $revitalizeTask['id'],
                            ],
                        ];
                    }

                    if (isset($revitalizeTask['deployment'], $revitalizeTask['job'], $revitalizeTask['index'])) {
                        $filters[] = [
                            'Name' => 'tag:Name',
                            'Values' => [
                                $revitalizeTask['deployment'] . '/' . $revitalizeTask['job'] . '/' . $revitalizeTask['index'] . '/sdf',
                            ],
                        ];
                    }

                    if (isset($revitalizeTask['director'])) {
                        $filters[] = [
                            'Name' => 'tag:director_name',
                            'Values' => [
                                $revitalizeTask['director'],
                            ],
                        ];
                    }

                    if (isset($revitalizeTask['region'])) {
                        $awsEc2region = \Aws\Ec2\Ec2Client::factory([
                            'region' => $revitalizeTask['region'],
                        ]);

                        $snapshots = $awsEc2region->describeSnapshots([
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

                        $output->writeln('      > <info>snapshot-id</info> -> ' . $latestSnapshot['SnapshotId']);
                        $output->writeln('      > <info>start-time</info> -> ' . $latestSnapshot['StartTime']);


                        $output->writeln('    > <comment>copying snapshot</comment>...');

                        $copySnapshot = $awsEc2->copySnapshot([
                            'SourceRegion' => $revitalizeTask['region'],
                            'SourceSnapshotId' => $latestSnapshot['SnapshotId'],
                            'Description' => $revitalizeTask['region'] . '/' . $latestSnapshot['SnapshotId'] . ': ' . $latestSnapshot['Description'],
                            'DestinationRegion' => $network['regions'][$input->getArgument('director')]['region'],
                        ]);

                        $output->writeln('      > <info>snapshot-id</info> -> ' . $copySnapshot['SnapshotId']);


                        if (0 < count($latestSnapshot['Tags'])) {
                            $output->write('    > <comment>tagging snapshot</comment>...');

                            $awsEc2->createTags([
                                'Resources' => [ $copySnapshot['SnapshotId'] ],
                                'Tags' => $latestSnapshot['Tags'],
                            ]);

                            $output->writeln('done');
                        }


                        $currStatus = 'unknown';
                        $output->write('      > <comment>waiting</comment>...');

                        while (true) {
                            $latestSnapshot = $awsEc2->describeSnapshots([
                                'SnapshotIds' => [
                                    $copySnapshot['SnapshotId'],
                                ],
                            ]);

                            $latestSnapshot = $latestSnapshot['Snapshots'][0];

                            if ($currStatus != $latestSnapshot['Progress']) {
                                $currStatus = $latestSnapshot['Progress'];

                                $output->write($currStatus . '...');
                            } else {
                                $output->write('.');
                            }

                            if (('100%' == $currStatus) && ('completed' == $latestSnapshot['State'])) {
                                $output->writeln('done');

                                break;
                            }

                            sleep(32);
                        }
                    } else {
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

                        $output->writeln('      > <info>snapshot-id</info> -> ' . $latestSnapshot['SnapshotId']);
                        $output->writeln('      > <info>start-time</info> -> ' . $latestSnapshot['StartTime']);
                    }


                    $output->writeln('    > <comment>creating volume</comment>...');

                    $volumeStatus = $volume = $awsEc2->createVolume([
                        'SnapshotId' => $latestSnapshot['SnapshotId'],
                        'AvailabilityZone' => $instance['Placement']['AvailabilityZone'],
                    ]);

                    $output->writeln('      > <info>volume-id</info> -> ' . $volume['VolumeId']);


                    $currStatus = 'unknown';
                    $output->write('      > <comment>waiting</comment>...');

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
                            $output->writeln('done');

                            break;
                        }

                        sleep(2);
                    }


                    $output->writeln('    > <comment>attaching volume</comment>...');

                    $awsEc2->attachVolume([
                        'InstanceId' => $instance['InstanceId'],
                        'VolumeId' => $volume['VolumeId'],
                        'Device' => '/dev/xvdj',
                    ]);


                    $currStatus = 'unknown';
                    $output->write('      > <comment>waiting</comment>...');

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
                            $output->writeln('done');

                            break;
                        }

                        sleep(2);
                    }


                    $output->writeln('    > <comment>mounting volume</comment>...');

                    $this->sshexec($input, $output, $job, 'mkdir -p /tmp/xfer-xvdj && mount /dev/xvdj1 /tmp/xfer-xvdj');


                    $output->writeln('    > <comment>transferring data</comment>...');

                    $this->sshexec($input, $output, $job, 'cd /var/vcap/store && for DIR in `ls /tmp/xfer-xvdj` ; do if [[ "lost+found" != "$DIR" ]] ; then [ ! -e $DIR ] || ( echo -n "      > removing $DIR..." ; rm -fr $DIR ; echo "done" ) ; echo -n "      > restoring $DIR..." ; cp -pr /tmp/xfer-xvdj/$DIR $DIR ; echo "done" ; fi ; done');


                    $output->writeln('    > <comment>unmounting volume</comment>...');

                    $this->sshexec($input, $output, $job, 'umount /tmp/xfer-xvdj && rm -fr /tmp/xfer-xvdj');


                    $output->writeln('    > <comment>detaching volume</comment>...');

                    $awsEc2->detachVolume([
                        'InstanceId' => $instance['InstanceId'],
                        'VolumeId' => $volume['VolumeId'],
                        'Device' => '/dev/xvdj',
                    ]);


                    $currStatus = 'unknown';
                    $output->write('      > <comment>waiting</comment>...');

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
                            $output->writeln('done');

                            break;
                        }

                        sleep(2);
                    }


                    $output->writeln('    > <comment>destroying volume</comment>...');

                    $awsEc2->deleteVolume([
                        'VolumeId' => $volume['VolumeId'],
                    ]);
                } elseif ('script' == $revitalizeTask['method']) {
                    $output->writeln('  > <info>script</info>...');

                    $this->sshrun($input, $output, $job, $revitalizeTask['script']);
                } else {
                    throw new \RuntimeException('Unknown reload method: ' . $revitalizeTask['method']);
                }
            }

            $output->writeln('  > <comment>starting services</comment>...');

            $this->sshexec($input, $output, $job, '/var/vcap/bosh/bin/monit start all');
        }
    }

    protected function sshexec(InputInterface $input, OutputInterface $output, array $job, $command)
    {
        $aws = Yaml::parse(file_get_contents($input->getOption('basedir') . '/global/private/aws.yml'));

        passthru($exec = 'ssh -i ' . $input->getOption('basedir') . '/' . $aws['ssh_key_file'] . ' -q vcap@' . $job['networks'][0]['static_ips'][0] . ' ' . escapeshellarg('/bin/bash -c ' . escapeshellarg('echo c1oudc0w | sudo -p "" -S -- /bin/bash -c ' . escapeshellarg('set -e ; ' . $command))), $return_var);

        if ($return_var) {
            throw new \RuntimeException($exec . ' --> exit ' . $return_var);
        }
    }

    protected function sshrun(InputInterface $input, OutputInterface $output, array $job, $script)
    {
        $file = $input->getOption('basedir') . '/compiled/tmp/sshrun-' . getmypid();
        touch($file);
        chmod($file, 0700);

        file_put_contents($file, $script);

        $aws = Yaml::parse(file_get_contents($input->getOption('basedir') . '/global/private/aws.yml'));

        passthru($exec = 'scp -i ' . $input->getOption('basedir') . '/' . $aws['ssh_key_file'] . ' -q ' . escapeshellarg($file) . ' vcap@' . $job['networks'][0]['static_ips'][0] . ':/tmp/' . basename($file), $return_var);

        unlink($file);

        if ($return_var) {
            throw new \RuntimeException($exec . ' --> exit ' . $return_var);
        }

        passthru($exec = 'ssh -i ' . $input->getOption('basedir') . '/' . $aws['ssh_key_file'] . ' -q vcap@' . $job['networks'][0]['static_ips'][0] . ' ' . escapeshellarg('/bin/bash -c ' . escapeshellarg('echo c1oudc0w | sudo -p "" -l -S -- /bin/bash -c ' . escapeshellarg('set -e ; chmod +x /tmp/' . basename($file) . ' ; /tmp/' . basename($file) . ' ; rm /tmp/' . basename($file)))), $return_var);

        if ($return_var) {
            throw new \RuntimeException($exec . ' --> exit ' . $return_var);
        }
    }

    protected function subrun($name, array $arguments, InputInterface $input, OutputInterface $output)
    {
        if ($input->getOption('basedir')) {
            $arguments['--basedir'] = $input->getOption('basedir');
        }

        $arguments['command'] = $name;

        $subinput = new ArrayInput($arguments);
        $subinput->setInteractive($input->isInteractive());

        $return = $this->getApplication()->find($name)->run(
            $subinput,
            $output
        );

        if ($return) {
            throw new \RuntimeException(sprintf('%s exited with %s', $name, $return));
        }
    }
}
