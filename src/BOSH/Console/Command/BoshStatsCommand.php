<?php

namespace BOSH\Console\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Command\Command;
use BOSH\Deployment\ManifestModel;
use Symfony\Component\Yaml\Yaml;

class BoshStatsCommand extends AbstractDirectorDeploymentCommand
{
    protected function configure()
    {
        parent::configure()
            ->setName('bosh:stats')
            ->setDescription('Get basic VM stats about the deployment')
            ->addOption(
                'format',
                null,
                InputOption::VALUE_REQUIRED,
                'Director name',
                'bosh'
            )
            ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $formatted = ('bosh' != $input->getOption('format'));

        $result = $this->execBosh(
            $input,
            $output,
            [
                'vms',
                '--details',
                '--dns',
                '--vitals',
                $input->getOption('deployment'),
            ],
            !$formatted
        );

        if (!$formatted) {
            return;
        }

        $reformat = $this->translateKeys(
            $this->extractBoshTable($result),
            [
                'Job/index' => 'job',
                'State' => 'state',
                'Resource Pool' => 'resource_pool',
                'IPs' => 'ips',
                'CID' => 'cid',
                'Agent ID' => 'agent_id',
                'Resurrection' => 'resurrection',
                'DNS A records' => 'dns_a_records',
                'Load (avg01, avg05, avg15)' => 'load_avg',
                'CPU User' => 'cpu_user',
                'CPU Sys' => 'cpu_sys',
                'CPU Wait' => 'cpu_wait',
                'Memory Usage' => 'memory_usage',
                'Swap Usage' => 'swap_usage',
                'System Disk Usage' => 'disk_system_usage',
                'Ephemeral Disk Usage' => 'disk_ephemeral_usage',
                'Persistent Disk Usage' => 'disk_persistent_usage',
            ]
        );

        $reformat = array_map(
            function (array $row) {
                $job = explode('/', $row['job'], 2);
                $row['job_name'] = $job[0];
                $row['job_index'] = (int) $job[1];

                $row['ips'] = (array) $row['ips'];
                $row['dns_a_records'] = (array) $row['dns_a_records'];

                $loadavg = explode('%, ', $row['load_avg']);
                $row['load_avg'] = [
                    '1m' => (float) $loadavg[0],
                    '5m' => (float) $loadavg[1],
                    '15m' => (float) $loadavg[2],
                ];

                return $row;
            },
            $reformat
        );

        $reformat = $this->indexArrayWithKey($reformat, 'job');

        return $this->outputFormatted($output, $input->getOption('format'), $reformat);
    }
}
