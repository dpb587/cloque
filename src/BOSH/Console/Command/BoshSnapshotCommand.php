<?php

namespace BOSH\Console\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Command\Command;
use BOSH\Deployment\ManifestModel;
use Symfony\Component\Yaml\Yaml;

class BoshSnapshotCommand extends AbstractDirectorDeploymentCommand
{
    protected function configure()
    {
        parent::configure()
            ->setName('bosh:snapshot')
            ->setDescription('Create a new snapshot for the deployment')
            ->addArgument(
                'jobid',
                InputArgument::OPTIONAL,
                'Job/index to snapshot'
            )
            ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $destManifest = sprintf(
            '%s/compiled/%s/%s/bosh%s.yml',
            $input->getOption('basedir'),
            $input->getOption('director'),
            $input->getOption('deployment'),
            $input->getOption('component') ? ('-' . $input->getOption('component')) : ''
        );

        $args = [
            'take', 'snapshot',
        ];

        $jobid = explode('/', $input->getArgument('jobid'), 2);

        if (!empty($jobid[0])) {
            $args[] = $jobid[0];
        }

        if (isset($jobid[1])) {
            $args[] = $jobid[1];
        }

        $this->execBosh($input, $output, $args);
    }
}
