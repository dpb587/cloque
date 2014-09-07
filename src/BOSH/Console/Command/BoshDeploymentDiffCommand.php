<?php

namespace BOSH\Console\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Command\Command;
use BOSH\Deployment\ManifestModel;
use Symfony\Component\Yaml\Yaml;

class BoshDeploymentDiffCommand extends AbstractDirectorDeploymentCommand
{
    protected function configure()
    {
        parent::configure()
            ->setName('bosh:deployment:diff')
            ->setDescription('Compare the current and proposed configuration for a BOSH deployment')
            ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $directorDir = $input->getOption('basedir') . '/' . $input->getOption('director');
        $deploymentDir = $directorDir . '/' . $input->getOption('deployment');
        $manifestFile = $deploymentDir . '/' . $input->getOption('manifest') . '.yml';

        $manpath = uniqid($directorDir . '/.compiled/bosh-manifest/');

        passthru(
            sprintf(
                'mkdir -p %s && %s --basedir=%s compile:deployment --manifest %s %s %s > %s',
                escapeshellarg(dirname($manpath)),
                escapeshellarg($_SERVER['argv'][0]),
                escapeshellarg($input->getOption('basedir')),
                escapeshellarg($input->getOption('manifest')),
                escapeshellarg($input->getOption('director')),
                escapeshellarg($input->getOption('deployment')),
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
