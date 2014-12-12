<?php

namespace BOSH\Console\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Command\Command;

class HttpassetcacheDeployCommand extends AbstractDirectorCommand
{
    protected function configure()
    {
        parent::configure()
            ->setName('httpassetcache:deploy')
            ->setDescription('Deploy the httpassetcache deployment')
            ->addArgument(
                'region',
                InputArgument::OPTIONAL | InputArgument::IS_ARRAY,
                'Only deploy to specific region'
            )
            ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $hostedZoneId = 'ZS4Q1EHA5GUKF';
        $regions = [
            'aws-use1',
            'aws-usw2',
        ];

        if ($input->getArgument('region')) {
            $regions = array_intersect($regions, $input->getArgument('region'));
        }

        $regionIps = [];
        $regionHealthChecks = [];
        $regionHealthCheckIds = [];
        $regionResourceRecordSets = [];

        $awsRoute53 = \Aws\Route53\Route53Client::factory();
        $awsCloudWatch = \Aws\CloudWatch\CloudWatchClient::factory([
            'region' => 'us-east-1',
        ]);


        $output->writeln('> <comment>loading addresses</comment>...');

        foreach ($regions as $region) {
            $env = json_decode(file_get_contents($input->getOption('basedir') . '/compiled/' . $region . '/core/infrastructure-deployment--state.json'), true);

            if (isset($env['Z0HttpassetcacheEipId'])) {
                $regionIps[$region] = $env['Z0HttpassetcacheEipId'];

                $output->writeln('  > <info>' . $region . '</info> -> ' . $env['Z0HttpassetcacheEipId']);
            }
        }


        $output->writeln('> <comment>loading health checks</comment>...');

        $rawHealthChecks = $awsRoute53->listHealthChecks();

        foreach ($rawHealthChecks['HealthChecks'] as $healthCheck) {
            if (false === $region = array_search($healthCheck['HealthCheckConfig']['IPAddress'], $regionIps)) {
                continue;
            }

            $regionHealthChecks[$region] = $healthCheck;
            $regionHealthCheckIds[$region] = $healthCheck['Id'];

            $output->writeln('  > <info>' . $region . '</info> -> ' . $healthCheck['Id']);
        }


        $output->writeln('> <comment>loading resource record sets</comment>...');

        $rawResourceRecordSets = $awsRoute53->listResourceRecordSets([
            'HostedZoneId' => $hostedZoneId,
        ]);

        foreach ($rawResourceRecordSets['ResourceRecordSets'] as $resourceRecordSet) {
            if (empty($resourceRecordSet['HealthCheckId'])) {
                continue;
            } elseif (false === $region = array_search($resourceRecordSet['HealthCheckId'], $regionHealthCheckIds)) {
                continue;
            }

            $regionResourceRecordSets[$region] = $resourceRecordSet;

            $rrs = implode(
                ', ',
                array_map(
                    function (array $r) {
                        return $r['Value'];
                    },
                    $resourceRecordSet['ResourceRecords']
                )
            );

            $output->writeln('  > <info>' . $region . '</info> -> ' . $resourceRecordSet['Name'] . ' (' . $rrs . '; weight ' . $resourceRecordSet['Weight'] . '; ttl ' . $resourceRecordSet['TTL'] . ')');
        }


        foreach ($regions as $region) {
            $output->writeln('> <comment>managing ' . $region . '</comment>...');

            if (0 != $regionResourceRecordSets[$region]['Weight']) {
                $output->write('  > <comment>unpublishing service</comment>...');

                $ref = $awsRoute53->changeResourceRecordSets([
                    'HostedZoneId' => $hostedZoneId,
                    'ChangeBatch' => [
                        'Changes' => [
                            [
                                'Action' => 'UPSERT',
                                'ResourceRecordSet' => [
                                    'Name' => $regionResourceRecordSets[$region]['Name'],
                                    'Type' => $regionResourceRecordSets[$region]['Type'],
                                    'SetIdentifier' => $regionResourceRecordSets[$region]['SetIdentifier'],
                                    'Weight' => 0,
                                    'TTL' => $regionResourceRecordSets[$region]['TTL'],
                                    'ResourceRecords' => $regionResourceRecordSets[$region]['ResourceRecords'],
                                    'HealthCheckId' => $regionResourceRecordSets[$region]['HealthCheckId'],
                                ],
                            ],
                        ],
                    ],
                ]);

                $regionResourceRecordSets[$region]['Weight'] = 0;

                $output->write('.');

                while ('INSYNC' != $ref['ChangeInfo']['Status']) {
                    sleep(5);

                    $ref = $awsRoute53->getChange([
                        'Id' => $ref['ChangeInfo']['Id'],
                    ]);

                    $output->write('.');
                }

                $output->writeln('done');


                $output->write('  > <comment>waiting for propagation</comment>...');

                for ($i = 0, $ic = (2 * $regionResourceRecordSets[$region]['TTL']) / 10; $i < $ic; $i +=1) {
                    sleep(10);

                    $output->write('.');
                }

                $output->writeln('done');
            }

            $output->write('  > <comment>running deploy</comment>...');

            $this->execCommand(
                $input,
                $output,
                'bosh:put',
                [
                    '--director' => $region,
                    '--deployment' => 'httpassetcache',
                ]
            );

            $output->write('  > <comment>delaying for health checks</comment>...');

            for ($i = 0; $i < 24; $i += 1) {
                sleep(5);

                $output->write('.');
            }

            $output->writeln('done');


            $lastPercent = null;
            $output->write('  > <comment>waiting on health checks</comment>...');

            do {
                sleep(10);

                $res = $awsCloudWatch->getMetricStatistics($x = [
                    'Namespace' => 'AWS/Route53',
                    'MetricName' => 'HealthCheckPercentageHealthy',
                    'Dimensions' => [
                        [
                            'Name' => 'HealthCheckId',
                            'Value' => $regionHealthCheckIds[$region],
                        ],
                    ],
                    'StartTime' => strtotime('-5 minutes'),
                    'EndTime' => strtotime('now'),
                    'Period' => 60,
                    'Statistics' => [
                        'Minimum',
                    ],
                ]);

                $datapoints = $res->toArray()['Datapoints'];
                usort(
                    $datapoints,
                    function ($a, $b) {
                        return $a['Timestamp'] > $b['Timestamp'] ? -1 : 1;
                    }
                );

                $newPercent = floor($datapoints[0]['Minimum']);

                if ($lastPercent != $newPercent) {
                    $lastPercent = $newPercent;

                    $output->write($newPercent . '%...');
                } else {
                    $output->write('.');
                }
            } while (100 > $datapoints[0]['Minimum']);

            $output->writeln('done');


            if (10 != $regionResourceRecordSets[$region]['Weight']) {
                $output->write('  > <comment>publishing service</comment>...');

                $ref = $awsRoute53->changeResourceRecordSets([
                    'HostedZoneId' => $hostedZoneId,
                    'ChangeBatch' => [
                        'Changes' => [
                            [
                                'Action' => 'UPSERT',
                                'ResourceRecordSet' => [
                                    'Name' => $regionResourceRecordSets[$region]['Name'],
                                    'Type' => $regionResourceRecordSets[$region]['Type'],
                                    'SetIdentifier' => $regionResourceRecordSets[$region]['SetIdentifier'],
                                    'Weight' => 10,
                                    'TTL' => $regionResourceRecordSets[$region]['TTL'],
                                    'ResourceRecords' => $regionResourceRecordSets[$region]['ResourceRecords'],
                                    'HealthCheckId' => $regionResourceRecordSets[$region]['HealthCheckId'],
                                ],
                            ],
                        ],
                    ],
                ]);

                $output->write('.');

                while ('INSYNC' != $ref['ChangeInfo']['Status']) {
                    sleep(5);

                    $ref = $awsRoute53->getChange([
                        'Id' => $ref['ChangeInfo']['Id'],
                    ]);
                }

                $output->writeln('done');


                $output->write('  > <comment>waiting for propagation</comment>...');

                for ($i = 0, $ic = (2 * $regionResourceRecordSets[$region]['TTL']) / 10; $i < $ic; $i +=1) {
                    sleep(10);

                    $output->write('.');
                }

                $output->writeln('done');
            }

            $output->write('  > <comment>waiting for sanity</comment>...');

            for ($i = 0; $i < 12; $i += 1) {
                sleep(5);

                $output->write('.');
            }

            $output->writeln('done');
        }
    }
}
