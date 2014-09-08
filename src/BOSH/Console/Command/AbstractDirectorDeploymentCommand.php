<?php

namespace BOSH\Console\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Command\Command;
use BOSH\Deployment\ManifestModel;
use Symfony\Component\Yaml\Yaml;

abstract class AbstractDirectorDeploymentCommand extends AbstractDirectorCommand
{
    protected function configure()
    {
        return parent::configure()
            ->addOption(
                'deployment',
                null,
                InputOption::VALUE_REQUIRED,
                'Deployment name'
            )
            ->addOption(
                'component',
                null,
                InputOption::VALUE_REQUIRED,
                'Component name',
                null
            )
            ;
    }


    protected function execBoshDeployment(InputInterface $input, OutputInterface $output, $args, $passthru = true)
    {
        return parent::execBosh(
            $input,
            $output,
            array_merge(
                [
                    '-d',
                    sprintf(
                        '%s/compiled/%s/%s/bosh%s.yml',
                        $input->getOption('basedir'),
                        $input->getOption('director'),
                        $input->getOption('deployment'),
                        $input->getOption('component') ? ('-' . $input->getOption('component')) : ''
                    )
                ],
                (array) $args
            ),
            $passthru
        );
    }

    protected function getSharedOptions()
    {
        return array_merge(
            parent::getSharedOptions(),
            [
                'deployment',
                'component',
            ]
        );
    }
}
