<?php

namespace BOSH\Console\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Command\Command;
use BOSH\Deployment\ManifestModel;
use Symfony\Component\Yaml\Yaml;

class BoshSshCommand extends AbstractDirectorDeploymentCommand
{
    protected function configure()
    {
        parent::configure()
            ->setName('bosh:ssh')
            ->setDescription('Connect to a job in the deployment')
            ->addArgument(
                'jobid',
                InputArgument::OPTIONAL,
                'Job/index to connect'
            )
            ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $jobid = $input->getArgument('jobid');

        if (empty($jobid)) {
            $destManifest = sprintf(
                '%s/compiled/%s/%s/bosh%s.yml',
                $input->getOption('basedir'),
                $input->getOption('director'),
                $input->getOption('deployment'),
                $input->getOption('component') ? ('-' . $input->getOption('component')) : ''
            );

            $manifest = Yaml::parse(file_get_contents($destManifest));

            if (1 != count($manifest['jobs'])) {
                throw new \RuntimeException('The jobid argument must be specified');
            }

            $jobid = $manifest['jobs'][0]['name'];
        }

        $this->execBoshDeployment(
            $input,
            $output,
            [
                'ssh',
                '--default_password',
                'c1oudc0w',
                $jobid,
            ]
        );
    }
}
