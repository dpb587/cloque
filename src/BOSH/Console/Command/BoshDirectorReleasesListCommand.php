<?php

namespace BOSH\Console\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Command\Command;
use BOSH\Deployment\ManifestModel;
use Symfony\Component\Yaml\Yaml;

class BoshDirectorReleasesListCommand extends AbstractDirectorCommand
{
    protected function configure()
    {
        parent::configure()
            ->setName('boshdirector:releases')
            ->setDescription('List all releases in the director')
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
            'releases',
            !$formatted
        );

        if (!$formatted) {
            return;
        }

        $reformat = $this->translateKeys(
            $this->extractBoshTable($result),
            [
                'Name' => 'name',
                'Versions' => 'versions',
                'Commit Hash' => 'commit_hash',
            ]
        );

        $reformat = array_map(
            function (array $row) {
                $row['versions'] = (array) $row['versions'];
                $row['commit_hash'] = (array) $row['commit_hash'];

                $merged = [];

                foreach ($row['versions'] as $versionIdx => $version) {
                    $commit = $row['commit_hash'][$versionIdx];
                    $versionTrim = trim($version, '*');
                    $commitTrim = trim($commit, '+');

                    $merged[$versionTrim] = [
                        'version' => $versionTrim,
                        'in_use' => $version !== $versionTrim,
                        'commit_hash' => $commitTrim,
                        'commit_dirty' => $commit !== $commitTrim,
                    ];
                }

                $row['versions'] = $merged;
                unset($row['commit_hash']);

                return $row;
            },
            $reformat
        );

        $reformat = $this->indexArrayWithKey($reformat, 'name');

        return $this->outputFormatted($output, $input->getOption('format'), $reformat);
    }
}
