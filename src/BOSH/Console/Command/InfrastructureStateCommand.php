<?php

namespace BOSH\Console\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Command\Command;
use BOSH\Deployment\TemplateEngine;
use Symfony\Component\Yaml\Yaml;

class InfrastructureStateCommand extends AbstractDirectorDeploymentCommand
{
    protected function configure()
    {
        $this
            ->setName('infrastructure:dump-state')
            ->setAliases([
                'infra:state',
            ])
            ->setDescription('Dump the state')
            ->addArgument(
                'jq',
                InputArgument::OPTIONAL,
                'jq command'
            )
            ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $destManifest = sprintf(
            '%s/compiled/%s/%s/infrastructure%s--state.json',
            $input->getOption('basedir'),
            $input->getOption('director'),
            $input->getOption('deployment'),
            $input->getOption('component') ? ('-' . $input->getOption('component')) : ''
        );

        $descriptorspec = array(
           0 => fopen($destManifest, 'r'),
           1 => STDOUT,
           2 => STDERR,
        );

        $ph = proc_open(
            'jq -r ' . escapeshellarg($input->getArgument('jq') ?: '.'),
            $descriptorspec,
            $pipes,
            getcwd(),
            null
        );

        $status = proc_get_status($ph);

        do {
            pcntl_waitpid($status['pid'], $pidstatus);
        } while (!pcntl_wifexited($pidstatus));

        return pcntl_wexitstatus($pidstatus);
    }
}
