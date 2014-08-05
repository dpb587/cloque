<?php

namespace BOSH\Console\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Command\Command;
use BOSH\Deployment\ManifestModel;
use Symfony\Component\Yaml\Yaml;

class BoshDiffCommand extends Command
{
    protected function configure()
    {
        $this
            ->setName('bosh:diff')
            ->setDescription('Compare the current and proposed configuration for a BOSH deployment')
            ->setDefinition(
                [
                    new InputArgument(
                        'director',
                        InputArgument::REQUIRED,
                        'Director name'
                    ),
                    new InputArgument(
                        'deployment',
                        InputArgument::REQUIRED,
                        'Deployment name'
                    ),
                    new InputOption(
                        'manifest',
                        null,
                        InputOption::VALUE_REQUIRED,
                        'Manifest name',
                        'manifest'
                    ),
                ]
            )
            ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $directorDir = $input->getOption('basedir') . '/' . $input->getArgument('director');
        $deploymentDir = $directorDir . '/' . $input->getArgument('deployment');
        $manifestFile = $deploymentDir . '/' . $input->getOption('manifest') . '.yml';

        $manpath = uniqid($directorDir . '/.compiled/bosh-manifest/');

        passthru(
            sprintf(
                'mkdir -p %s && %s --basedir=%s compile:deployment --manifest %s %s %s > %s',
                escapeshellarg(dirname($manpath)),
                escapeshellarg($_SERVER['argv'][0]),
                escapeshellarg($input->getOption('basedir')),
                escapeshellarg($input->getOption('manifest')),
                escapeshellarg($input->getArgument('director')),
                escapeshellarg($input->getArgument('deployment')),
                escapeshellarg($manpath)
            ),
            $return_var
        );

        if ($return_var) {
            return $return_var;
        }

        passthru(
            sprintf(
                'bosh %s %s -c %s -d %s diff %s',
                $output->isDecorated() ? '--color' : '--no-color',
                $input->isInteractive() ? '' : '--non-interactive',
                escapeshellarg($directorDir . '/.bosh_config'),
                escapeshellarg($manpath),
                escapeshellarg($manpath)
            ),
            $return_var
        );

        if ($return_var) {
            return $return_var;
        }
    }
}
