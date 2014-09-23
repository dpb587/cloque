<?php

namespace BOSH\Console\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Command\Command;
use BOSH\Deployment\ManifestModel;
use Symfony\Component\Yaml\Yaml;

class BoshDirectorStemcellsListCommand extends AbstractDirectorCommand
{
    protected function configure()
    {
        parent::configure()
            ->setName('boshdirector:stemcells')
            ->setDescription('List all stemcells in the director')
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
            'stemcells',
            !$formatted
        );

        if (!$formatted) {
            return;
        }

        $reformat = $this->translateKeys(
            $this->extractBoshTable($result),
            [
                'Name' => 'name',
                'Version' => 'version',
                'CID' => 'cid',
            ]
        );

        $reformat = array_map(
            function (array $row) {
                $trim = rtrim($row['version'], '*');

                if ($trim !== $row['version']) {
                    $row['version'] = $trim;
                    $row['in_use'] = true;
                } else {
                    $row['in_use'] = false;
                }

                return $row;
            },
            $reformat
        );

        $reformat = $this->indexArrayWithKey(
            $reformat,
            function (array $value) {
                return $value['name'] . '/' . $value['version'];
            }
        );

        return $this->outputFormatted($output, $input->getOption('format'), $reformat);
    }
}
