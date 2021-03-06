<?php

namespace BOSH\Console\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Command\Command;
use BOSH\Deployment\ManifestModel;
use Symfony\Component\Yaml\Yaml;

class BoshDirectorDeploymentsListCommand extends AbstractDirectorCommand
{
    protected function configure()
    {
        parent::configure()
            ->setName('boshdirector:deployments')
            ->setDescription('List all deployments in the director')
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

        $result = $this->execBosh(
            $input,
            $output,
            'deployments',
            !$formatted
        );

        if (!$formatted) {
            return;
        }

        $reformat = $this->translateKeys(
            $this->extractBoshTable($result),
            [
                'Name' => 'name',
                'Release(s)' => 'releases',
                'Stemcell(s)' => 'stemcells',
            ]
        );

        $reformat = array_map(
            function (array $row) {
                $row['stemcells'] = (array) $row['stemcells'];

                return $row;
            },
            $reformat
        );

        $reformat = $this->indexArrayWithKey($reformat, 'name');

        return $this->outputFormatted($output, $input->getOption('format'), $reformat);
    }
}
