<?php

namespace BOSH\Console\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Command\Command;
use BOSH\Deployment\ManifestModel;
use Symfony\Component\Yaml\Yaml;

class BoshDirectorInitDeployCommand extends AbstractDirectorDeploymentCommand
{
    protected function configure()
    {
        parent::configure()
            ->setName('boshdirector:init:deploy')
            ->setDescription('Terminate an inception server')
            ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->execCommand(
            $input,
            $output,
            'bosh:recompile',
            [
                '--deployment' => 'bosh',
            ]
        );

        $destManifest = sprintf(
            '%s/compiled/%s/%s/bosh%s.yml',
            $input->getOption('basedir'),
            $input->getOption('director'),
            $input->getOption('deployment'),
            $input->getOption('component') ? ('-' . $input->getOption('component')) : ''
        );

        passthru(
            sprintf(
                'cd %s ; bosh-init deploy %s',
                dirname($destManifest),
                basename($destManifest)
            ),
            $return_var
        );
        
        return $return_var;
    }
}
