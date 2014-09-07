<?php

namespace BOSH\Console\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Command\Command;
use BOSH\Deployment\ManifestModel;
use Symfony\Component\Yaml\Yaml;

class BoshSnapshotCleanupSelfCommand extends AbstractDirectorCommand
{
    protected function configure()
    {
        parent::configure()
            ->setName('bosh:snapshot:cleanup-self')
            ->setDescription('Cleanup director self snapshots')
            ->addArgument(
                'interval',
                InputArgument::REQUIRED,
                'Interval to retain snapshots'
            )
            ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $network = Yaml::parse(file_get_contents($input->getOption('basedir') . '/network.yml'));

        $awsEc2 = \Aws\Ec2\Ec2Client::factory([
            'region' => $network['regions'][$input->getOption('director')]['region'],
        ]);

        $snapshots = $awsEc2->describeSnapshots([
            'Filters' => [
                [
                    'Name' => 'tag:director_name',
                    'Values' => [
                        $network['root']['name'] . '-' . $input->getOption('director'),
                    ],
                ],
                [
                    'Name' => 'tag:Name',
                    'Values' => [
                        'self/director/0/sdf',
                        'self/director/0/sdb',
                        'self/director/0/xvda',
                    ],
                ]
            ],
        ]);

        $now = new \DateTime();
        $retain = clone $now; $retain->sub(new \DateInterval('P' . strtoupper($input->getArgument('interval'))));

        foreach ($snapshots['Snapshots'] as $snapshot) {
            $snapshot['StartTime'] = new \DateTime($snapshot['StartTime']);

            $output->write('<info>' . $snapshot['SnapshotId'] . '</info> -> ' . $snapshot['StartTime']->format('c') . ' -> ');

            if ($snapshot['StartTime'] > $retain) {
                $output->writeln('retained');

                continue;
            }

            $awsEc2->deleteSnapshot([
                'SnapshotId' => $snapshot['SnapshotId'],
            ]);

            $output->writeln('deleted');
        }
    }
}
