<?php

namespace BOSH\Console\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Yaml\Yaml;
use Elastica\Client;
use Elastica\Request;

class UtilityLogsearchShipperMetricsCheckCommand extends AbstractCommand
{
    protected function configure()
    {
        parent::configure()
            ->setName('utility:logsearch-shipper:metrics-check')
            ->setDescription('Super-generic alerting about metrics')
            ->addArgument(
                'elasticsearch',
                InputArgument::OPTIONAL,
                'Elasticsearch host to communicate with',
                'localhost:9200'
            )
            ->addOption(
                'now',
                null,
                InputOption::VALUE_REQUIRED,
                'Evaluate from a given date/time instead of now'
            )
            ->addOption(
                'now-prior',
                null,
                InputOption::VALUE_REQUIRED,
                'Include prior days in the index checks'
            )
            ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $elasticsearchEndpoint = explode(':', $input->getArgument('elasticsearch'));
        $elasticsearch = new Client([
            'host' => $elasticsearchEndpoint[0],
            'port' => isset($elasticsearchEndpoint[1]) ? $elasticsearchEndpoint[1] : 9200,
        ]);

        $now = new \DateTime($input->getOption('now') ?: null);
        $now->setTimeZone(new \DateTimeZone('UTC'));

        $indexPath = 'logstash-' . $now->format('Y.m.d');
        $nowPrior = clone $now;

        for ($i = 0; $i < $input->getOption('now-prior') ?: 0; $i ++) {
            $nowPrior->sub(new \DateInterval('P1D'));
            $indexPath .= ',logstash-' . $nowPrior->format('Y.m.d');
        }

        $res = $elasticsearch->request(
            '/' . $indexPath . '/metric/_search',
            Request::POST,
            [
               'aggregations' => [
                  'director' => [
                     'terms' => [
                        'field' => '@source.bosh_director',
                        'order' => [
                           '_term' => 'asc',
                        ]
                     ],
                     'aggregations' => [
                        'deployment' => [
                           'terms' => [
                              'field' => '@source.bosh_deployment',
                              'order' => [
                                 '_term' => 'asc',
                              ]
                           ],
                           'aggregations' => [
                              'job' => [
                                 'terms' => [
                                    'field' => '@source.bosh_job',
                                    'order' => [
                                       '_term' => 'asc',
                                    ]
                                 ]
                              ]
                           ]
                        ]
                     ]
                  ]
               ],
               'size' => 0,
            ]
        )->getData();

        $all = [
            'critical' => 0,
            'warning' => 0,
        ];
        $directors = [];

        foreach ($res['aggregations']['director']['buckets'] as $director) {
            $directors[$director['key']] = [
                'name' => $director['key'],
                'deployments' => [],
                'critical' => 0,
                'warning' => 0,
            ];

            foreach ($director['deployment']['buckets'] as $deployment) {
                $directors[$director['key']]['deployments'][$deployment['key']] = [
                    'name' => $deployment['key'],
                    'jobs' => [],
                    'critical' => 0,
                    'warning' => 0,
                ];

                foreach ($deployment['job']['buckets'] as $job) {
                    $directors[$director['key']]['deployments'][$deployment['key']]['jobs'][$job['key']] = [
                        'name' => $job['key'],
                        'critical' => [],
                        'warning' => [],
                    ];
                }
            }
        }


        foreach ($directors as $directorName => &$director) {
            $output->writeln('> <info>' . $directorName . '</info>');

            foreach ($director['deployments'] as $deploymentName => &$deployment) {
                $output->writeln('  > <info>' . $deploymentName . '</info>');

                foreach ($deployment['jobs'] as $jobName => &$job) {
                    $output->writeln('    > <info>' . $jobName . '</info>');

                    $multiCallback = [];
                    $multiQuery = [];
                    $baseQuery = [
                        'query' => [
                            'bool' => [
                                'must' => [
                                    [
                                        'range' => [
                                            '@timestamp' => [
                                                'lte' => $now->format('c'),
                                            ],
                                        ],
                                    ],
                                    [
                                        'term' => [
                                            '@source.bosh_director' => $directorName,
                                        ],
                                    ],
                                    [
                                        'term' => [
                                            '@source.bosh_deployment' => $deploymentName,
                                        ],
                                    ],
                                    [
                                        'term' => [
                                            '@source.bosh_job' => $jobName,
                                        ],
                                    ],
                                ],
                            ],
                        ],
                        'size' => 1,
                        'sort' => [
                            [
                                '@timestamp' => 'desc',
                            ],
                        ]
                    ];

                    $pushQuery = function (array $must, $callback) use (&$multiQuery, &$multiCallback, $indexPath, $baseQuery) {
                        $multiCallback[] = $callback;

                        $multiQuery[] = json_encode([
                            'index' => $indexPath,
                            'type' => 'metric',
                        ]);

                        $query = $baseQuery;
                        $query['query']['bool']['must'][] = $must;

                        $multiQuery[] = json_encode($query);
                    };

                    foreach( [ 'system' => 'xvda1', 'ephemeral' => 'xvdb2', 'persistent' => 'xvdf1' ] as $diskName => $disk) {
                        $pushQuery(
                            [ 'term' => [ 'name' => 'host.df_' . $disk . '.df_complex_free' ] ],
                            function (array $data) use (&$job, $diskName) {
                                $job['disk.' . $diskName . '.free'] = isset($data['hits']['hits'][0]['_source']['value']) ? $data['hits']['hits'][0]['_source']['value'] : null;
                            }
                        );

                        $pushQuery(
                            [ 'term' => [ 'name' => 'host.df_' . $disk . '.df_complex_used' ] ],
                            function (array $data) use (&$job, $diskName) {
                                $job['disk.' . $diskName . '.used'] = isset($data['hits']['hits'][0]['_source']['value']) ? $data['hits']['hits'][0]['_source']['value'] : null;
                            }
                        );
                    }

                    $res = $elasticsearch->request('/_msearch', Request::POST, implode("\n", $multiQuery) . "\n")->getData();

                    foreach ($res['responses'] as $i => $multires) {
                        $multiCallback[$i]($multires);
                    }


                    // analyze
                    foreach ([ 'system', 'ephemeral', 'persistent' ] as $disk) {
                        if (isset($job['disk.' . $disk . '.free'], $job['disk.' . $disk . '.used'])) {
                            $total = $job['disk.' . $disk . '.free'] + $job['disk.' . $disk . '.used'];
                            $percent = $job['disk.' . $disk . '.used'] / $total;

                            if (0.80 < $percent) {
                                $level = (0.90 < $percent) ? 'critical' : 'warning';

                                $all[$level] += 1;
                                $deployment[$level] += 1;
                                $director[$level] += 1;

                                $output->writeln(
                                    sprintf(
                                        '      > %s: %s',
                                        'critical' == $level ? '<error>critical</error>' : '<comment>warning</comment>',
                                        $disk . ' disk is ' . ceil(100 * $percent) . '% full with ' . round($job['disk.' . $disk . '.free'] / (1024 * 1024 * 1024), 1) . ' GB left'
                                    )
                                );
                            }
                        }
                    }
                }
            }
        }

        return (int) ($all['critical'] > 0);
    }
}
