<?php

namespace BOSH\Console\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Command\Command;
use BOSH\Deployment\ManifestModel;
use Symfony\Component\Yaml\Yaml;

class BoshReleaseListCommand extends AbstractDirectorCommand
{
    protected function configure()
    {
        parent::configure()
            ->setName('bosh:release:list')
            ->setDescription('List all releases')
            ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->execBosh($input, $output, 'releases');
    }
}
