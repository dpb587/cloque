<?php

namespace BOSH\Console\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Command\Command;
use BOSH\Deployment\ManifestModel;
use Symfony\Component\Yaml\Yaml;

class BoshDirectorReleasesPutCommand extends AbstractDirectorCommand
{
    protected function configure()
    {
        parent::configure()
            ->setName('boshdirector:releases:put')
            ->setDescription('Upload a release to the director')
            ->addArgument(
                'release',
                InputArgument::OPTIONAL,
                'Deployment name'
            )
            ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->execBosh(
            $input,
            $output,
            [
                'upload', 'release',
                $input->getArgument('release') ? $input->getArgument('release') : null
            ]
        );
    }
}
