<?php

namespace BOSH\Console\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Command\Command;
use BOSH\Deployment\ManifestModel;
use Symfony\Component\Yaml\Yaml;

class BoshSnapshotsListCommand extends AbstractDirectorDeploymentCommand
{
    protected function configure()
    {
        parent::configure()
            ->setName('bosh:snapshots:list')
            ->setDescription('List all snapshots of the deployment')
            ->addOption(
                'format',
                null,
                InputOption::VALUE_REQUIRED,
                'Output format (bosh, json, yaml)',
                'bosh'
            )
            ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $formatted = ('bosh' != $input->getOption('format'));

        $result = $this->execBoshDeployment(
            $input,
            $output,
            'snapshots',
            !$formatted
        );

        if (!$formatted) {
            return;
        }

        $reformat = $this->translateKeys(
            $this->extractBoshTable($result),
            [
                'Job/index' => 'job',
                'Snapshot CID' => 'cid',
                'Created at' => 'created_at',
                'Clean' => 'clean',
            ]
        );

        $reformat = array_map(
            function (array $row) {
                $job = explode('/', $row['job'], 2);
                $row['job_name'] = $job[0];
                $row['job_index'] = (int) $job[1];

                $row['clean'] = filter_var($row['clean'], FILTER_VALIDATE_BOOLEAN);

                return $row;
            },
            $reformat
        );

        $reformat = $this->indexArrayWithKey($reformat, 'cid');

        return $this->outputFormatted($output, $input->getOption('format'), $reformat);
    }
}
