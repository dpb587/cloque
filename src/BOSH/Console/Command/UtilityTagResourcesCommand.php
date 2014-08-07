<?php

namespace BOSH\Console\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Command\Command;
use Aws\Ec2\Ec2Client;
use Symfony\Component\Yaml\Yaml;

class UtilityTagResourcesCommand extends Command
{
    protected function configure()
    {
        $this
            ->setName('utility:tag-resources')
            ->setDescription('Tag volumes attached to instances')
            ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $network = Yaml::parse(file_get_contents($input->getOption('basedir') . '/network.yml'));

        foreach ($network['regions'] as $regionName => $regionData) {
            $region = $regionData['region'];

            if ('global' == $regionName) {
                continue;
            } elseif (!file_exists($input->getOption('basedir') . '/compiled/' . $regionName . '/core/infrastructure--state.json')) {
                continue;
            }

            $infraCore = json_decode(file_get_contents($input->getOption('basedir') . '/compiled/' . $regionName . '/core/infrastructure--state.json'), true);

            $output->writeln('> <comment>reviewing ' . $region . '</comment>...');

            $awsEc2 = Ec2Client::factory([
                'region' => $region,
            ]);

            $instances = $awsEc2->describeInstances([
                'Filters' => [
                    [
                        'Name' => 'instance-state-name',
                        'Values' => [ 'running', 'stopped' ],
                    ],
                    [
                        'Name' => 'vpc-id',
                        'Values' => [ $infraCore['VpcId'] ],
                    ],
                ],
            ]);

            foreach ($instances['Reservations'] as $reservation) {
                foreach ($reservation['Instances'] as $instance) {
                    $niceInstanceTags = $this->remapTags($instance['Tags']);

                    if (!isset($niceInstanceTags['director'])) {
                        continue;
                    }

                    $output->write('  > <info>' . $this->getNiceInstanceName($instance['Tags']) . '</info>');
                    $output->write(' -> ' . $instance['InstanceId']);
                    $output->writeln('');

                    foreach ($instance['BlockDeviceMappings'] as $mapping) {
                        $output->write('    > <info>' . $mapping['DeviceName'] . '</info>');
                        $output->write(' -> ' . $mapping['Ebs']['VolumeId']);
                        $output->writeln('');

                        $volumes = $awsEc2->describeVolumes([
                            'VolumeIds' => [
                                $mapping['Ebs']['VolumeId'],
                            ],
                        ]);

                        $volume = $volumes['Volumes'][0];
                        $niceVolumeTags = $this->remapTags(isset($volume['Tags']) ? $volume['Tags'] : []);

                        $addTags = [];

                        $expectedTags = [
                            'director' => $niceInstanceTags['director'],
                            'deployment' => $niceInstanceTags['deployment'],
                            'Name' => $niceInstanceTags['Name'] . '/' . preg_replace('#^/dev/(.*)$#', '$1', $mapping['DeviceName']),
                        ];

                        foreach ($expectedTags as $tagKey => $tagValue) {
                            if (empty($niceVolumeTags[$tagKey])) {
                                $output->writeln('      > <comment>adding ' . $tagKey . '</comment> -> ' . $tagValue);

                                $addTags[] = [
                                    'Key' => $tagKey,
                                    'Value' => $tagValue,
                                ];
                            } elseif ($niceVolumeTags[$tagKey] != $tagValue) {
                                $output->writeln('      > <comment>updating ' . $tagKey . '</comment> -> ' . $niceVolumeTags[$tagKey] . ' -> ' . $tagValue);

                                $addTags[] = [
                                    'Key' => $tagKey,
                                    'Value' => $tagValue,
                                ];
                            }
                        }

                        if ($addTags) {
                            $awsEc2->createTags([
                               'Resources' => [
                                   $volume['VolumeId'],
                               ],
                               'Tags' => $addTags,
                            ]);
                        }
                    }
                }
            }
        }
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
}
