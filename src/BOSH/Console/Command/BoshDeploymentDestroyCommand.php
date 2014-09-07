<?php

namespace BOSH\Console\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Command\Command;
use BOSH\Deployment\ManifestModel;
use Symfony\Component\Yaml\Yaml;

class BoshDeploymentDestroyCommand extends AbstractDirectorDeploymentCommand
{
    protected function configure()
    {
        parent::configure()
            ->setName('bosh:deployment:destroy')
            ->setDescription('Completely destroy a BOSH deployment')
            ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->execBoshDeployment(
            $input,
            $output,
            [
                'delete',
                'deployment',
                $input->getOption('deployment') . ($input->getOption('component') ? ('-' . $input->getOption('component')) : ''),
            ]
        );
    }
}
