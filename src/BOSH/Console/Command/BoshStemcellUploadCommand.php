<?php

namespace BOSH\Console\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Command\Command;
use BOSH\Deployment\ManifestModel;
use Symfony\Component\Yaml\Yaml;

class BoshStemcellUploadCommand extends AbstractDirectorCommand
{
    protected function configure()
    {
        parent::configure()
            ->setName('bosh:stemcell:upload')
            ->setDescription('Upload a stemcell to the BOSH director')
            ->addArgument(
                'stemcell',
                InputArgument::REQUIRED,
                'Stemcell path'
            )
            ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->execBosh(
            $input,
            $output,
            [
                'upload', 'stemcell',
                $input->getArgument('stemcell'),
            ]
        );
    }
}
