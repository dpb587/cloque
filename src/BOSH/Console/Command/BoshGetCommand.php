<?php

namespace BOSH\Console\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Command\Command;
use BOSH\Deployment\TemplateEngine;
use Symfony\Component\Yaml\Yaml;

class BoshGetCommand extends AbstractDirectorDeploymentCommand
{
    protected function configure()
    {
        parent::configure()
            ->setName('bosh:get')
            ->setDescription('Get some information about the deployment')
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
            '%s/compiled/%s/%s/bosh%s.yml',
            $input->getOption('basedir'),
            $input->getOption('director'),
            $input->getOption('deployment'),
            $input->getOption('component') ? ('-' . $input->getOption('component')) : ''
        );

        $fh = fopen('php://temp', 'w+');
        fwrite($fh, json_encode(YAML::parse(file_get_contents($destManifest))));
        fseek($fh, 0);

        $descriptorspec = array(
           0 => $fh,
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

        fclose($fh);

        return pcntl_wexitstatus($pidstatus);
    }
}
