<?php

namespace BOSH\Console\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Command\Command;
use Aws\Ec2\Ec2Client;
use Symfony\Component\Yaml\Yaml;

class UtilityComputePricingCommand extends AbstractCommand
{
    protected $spotPriceCache = [];

    protected function configure()
    {
        parent::configure()
            ->setName('utility:compute-pricing')
            ->setDescription('Compute pricing estimate from active resources')
            ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $output->write('> <comment>loading on-demand pricing</comment>...');

        $ondemandPricing = [];

        foreach ([
            'http://a0.awsstatic.com/pricing/1/ec2/previous-generation/linux-od.min.js',
            'http://a0.awsstatic.com/pricing/1/ec2/linux-od.min.js',
        ] as $pricingUrl) {
            // unofficial api
            $rawPricing = file_get_contents($pricingUrl);
            // unwrap from JSONP response
            $rawPricing = preg_replace('#^.*callback\((\{.*\})\);.*$#sm', '$1', $rawPricing);
            $rawPricing = preg_replace('#(,|\{)([^\{":]+):#', '$1"$2":', $rawPricing);
            $rawPricing = json_decode($rawPricing, true);

            $output->write('.');

            foreach ($rawPricing['config']['regions'] as $regionPricing) {
                $regionPricing['region'] = preg_replace('/^us-east$/', 'us-east-1', $regionPricing['region']);
                $regionPricing['region'] = preg_replace('/^us-west$/', 'us-west-1', $regionPricing['region']);
                $regionPricing['region'] = preg_replace('/^eu-ireland$/', 'eu-west-1', $regionPricing['region']);

                foreach ($regionPricing['instanceTypes'] as $instanceTypePricing) {
                    foreach ($instanceTypePricing['sizes'] as $size) {
                        $ondemandPricing[$regionPricing['region']][$size['size']] = (float) $size['valueColumns'][0]['prices']['USD'];
                    }
                }

                $output->write('.');
            }
        }

        $output->writeln('done');


        $hourlyStats = [];

        $network = Yaml::parse(file_get_contents($input->getOption('basedir') . '/network.yml'));

        foreach ($network['regions'] as $regionName => $regionData) {
            $region = $regionData['region'];

            if ('global' == $regionName) {
                continue;
            } elseif (!file_exists($input->getOption('basedir') . '/compiled/' . $regionName . '/core/infrastructure--state.json')) {
                continue;
            }

            $infraCore = json_decode(file_get_contents($input->getOption('basedir') . '/compiled/' . $regionName . '/core/infrastructure--state.json'), true);

            $output->writeln('> <comment>analyzing ' . $region . '</comment>...');

            $awsEc2 = Ec2Client::factory([
                'region' => $region,
            ]);

            $instances = $awsEc2->describeInstances([
                'Filters' => [
                    [
                        'Name' => 'instance-state-name',
                        'Values' => [ 'running' ],
                    ],
                    [
                        'Name' => 'vpc-id',
                        'Values' => [ $infraCore['VpcId'] ],
                    ],
                ],
            ]);

            foreach ($instances['Reservations'] as $reservation) {
                foreach ($reservation['Instances'] as $instance) {
                    $hourlyStat = [
                        'instance' => $instance,
                        'strategy' => null,
                        'tags' => $this->remapTags($instance['Tags']),
                        'price_hourly' => null,
                        'price_daily' => null,
                        'price_monthly' => null,
                    ];

                    $output->write('  > <info>' . $this->getNiceInstanceName($instance['Tags']) . '</info>');
                    $output->write(' -> ' . $instance['InstanceType']);

                    if (!empty($instance['SpotInstanceRequestId'])) {
                        $hourlyStat['strategy'] = 'spot';
                        $hourlyStat['price_hourly'] = $this->lookupSpotPrice($awsEc2, $instance['Placement']['AvailabilityZone'], $instance['InstanceType']);
                    } else {
                        $hourlyStat['strategy'] = 'on-demand';
                        $hourlyStat['price_hourly'] = $ondemandPricing[$region][$instance['InstanceType']];
                    }

                    $hourlyStat['price_daily'] = bcmul($hourlyStat['price_hourly'], 24, 4);
                    $hourlyStat['price_monthly'] = bcmul($hourlyStat['price_daily'], 31, 4);

                    $output->write(' -> ' . $hourlyStat['strategy']);
                    $output->write(' -> ' . number_format($hourlyStat['price_hourly'], 4));
                    $output->write(' -> ' . number_format($hourlyStat['price_daily'], 4));
                    $output->write(' -> ' . number_format($hourlyStat['price_monthly'], 4));

                    $output->writeln('');

                    $hourlyStats[] = $hourlyStat;
                }
            }
        }

        $output->writeln('> <comment>summarizing</comment>...');

        $output->writeln('  > <comment>deployment</comment>...');

        $deployments = [];

        foreach ($hourlyStats as $hourlyStat) {
            $deployments[isset($hourlyStat['tags']['deployment']) ? $hourlyStat['tags']['deployment'] : 'unknown'] = true;
        }

        ksort($deployments);

        foreach ($deployments as $deploymentName => $null) {
            $groupHourlyStats = array_filter($hourlyStats, function (array $r) use ($deploymentName) {
                return (isset($r['tags']['deployment']) ? $r['tags']['deployment'] : 'unknown') == $deploymentName;
            });

            $output->write('    > <info>' . $deploymentName . '</info>');
            $output->write(' (' . count($groupHourlyStats) . ')');

            $output->write(' -> ' . array_reduce($groupHourlyStats, function ($carry, $item) {
                return bcadd($carry, $item['price_hourly'], 4);
            }));
            $output->write(' -> ' . array_reduce($groupHourlyStats, function ($carry, $item) {
                return bcadd($carry, $item['price_daily'], 4);
            }));
            $output->write(' -> ' . array_reduce($groupHourlyStats, function ($carry, $item) {
                return bcadd($carry, $item['price_monthly'], 4);
            }));
            $output->writeln('');
        }

        $output->writeln('  > <comment>strategy</comment>...');

        foreach ([ 'on-demand', 'spot' ] as $strategyName) {
            $groupHourlyStats = array_filter($hourlyStats, function (array $r) use ($strategyName) {
                return ($strategyName == $r['strategy']);
            });

            $output->write('    > <info>' . $strategyName . '</info>');
            $output->write(' (' . count($groupHourlyStats) . ')');

            $output->write(' -> ' . array_reduce($groupHourlyStats, function ($carry, $item) {
                return bcadd($carry, $item['price_hourly'], 4);
            }));
            $output->write(' -> ' . array_reduce($groupHourlyStats, function ($carry, $item) {
                return bcadd($carry, $item['price_daily'], 4);
            }));
            $output->write(' -> ' . array_reduce($groupHourlyStats, function ($carry, $item) {
                return bcadd($carry, $item['price_monthly'], 4);
            }));
            $output->writeln('');
        }

        $output->write('  > <info>overall</info>');
        $output->write(' (' . count($hourlyStats) . ')');

        $output->write(' -> ' . array_reduce($hourlyStats, function ($carry, $item) {
            return bcadd($carry, $item['price_hourly'], 4);
        }));
        $output->write(' -> ' . array_reduce($hourlyStats, function ($carry, $item) {
            return bcadd($carry, $item['price_daily'], 4);
        }));
        $output->write(' -> ' . array_reduce($hourlyStats, function ($carry, $item) {
            return bcadd($carry, $item['price_monthly'], 4);
        }));
        $output->writeln('');
    }

    protected function getNiceInstanceName(array $tags)
    {
        $tags = $this->remapTags($tags);

        $name = '';

        if (!empty($tags['director'])) {
            $name .= ($name ? '/' : '') . $tags['director'];
        }

        if (!empty($tags['deployment'])) {
            $name .= ($name ? '/' : '') . $tags['deployment'];
        }

        if (!empty($tags['Name'])) {
            $name .= ($name ? '/' : '') . $tags['Name'];
        }

        return $name;
    }

    protected function remapTags(array $tags)
    {
        $remap = [];

        foreach ($tags as $tag) {
            $remap[$tag['Key']] = $tag['Value'];
        }

        return $remap;
    }

    protected function lookupSpotPrice(Ec2Client $aws, $availabilityZone, $instanceType)
    {
        $cacheKey = $availabilityZone . '/' . $instanceType;

        if (!isset($this->spotPriceCache[$cacheKey])) {
            $history = $aws->describeSpotPriceHistory($x = [
                'StartTime' => strtotime('-15 minutes'),
                'EndTime' => strtotime('now'),
                'InstanceTypes' => [
                    $instanceType,
                ],
                'AvailabilityZone' => $availabilityZone,
            ]);

            $priceVpc = null;
            $priceDefault = null;

            // non-ec2-classic regions aren't marked as vpc
            foreach ($history['SpotPriceHistory'] as $spotHistory) {
                if ('Linux/UNIX (Amazon VPC)' == $spotHistory['ProductDescription']) {
                    $priceVpc = $spotHistory['SpotPrice'];
                } elseif ('Linux/UNIX' == $spotHistory['ProductDescription']) {
                    $priceDefault = $spotHistory['SpotPrice'];
                }
            }

            $this->spotPriceCache[$cacheKey] = $priceVpc ?: $priceDefault;
        }

        return $this->spotPriceCache[$cacheKey];
    }
}
