<?php

namespace BOSH\Console\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Command\Command;
use BOSH\Deployment\ManifestModel;
use Symfony\Component\Yaml\Yaml;

class BoshSnapshotCreateCommand extends Command
{
    protected function configure()
    {
        $this
            ->setName('bosh:snapshot:create')
            ->setDescription('Create a new snapshot')
            ->setDefinition(
                [
                    new InputArgument(
                        'director',
                        InputArgument::REQUIRED,
                        'Director name'
                    ),
                    new InputArgument(
                        'deployment',
                        InputArgument::REQUIRED,
                        'Deployment name'
                    ),
                    new InputArgument(
                        'job',
                        InputArgument::OPTIONAL,
                        'Job'
                    ),
                    new InputArgument(
                        'index',
                        InputArgument::OPTIONAL,
                        'Index'
                    ),
                    new InputOption(
                        'component',
                        null,
                        InputOption::VALUE_REQUIRED,
                        'Component name',
                        null
                    ),
                ]
            )
            ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $destManifest = sprintf(
            '%s/compiled/%s/%s/bosh%s.yml',
            $input->getOption('basedir'),
            $input->getArgument('director'),
            $input->getArgument('deployment'),
            $input->getOption('component') ? ('-' . $input->getOption('component')) : ''
        );

        $job = explode('/', $input->getArgument('job'), 2);
        $index = $input->getArgument('index') ?: (isset($job[1]) ? $job[1] : null);
        $job = isset($job[0]) ? $job[0] : null;

        $directorDir = $input->getOption('basedir') . '/' . $input->getArgument('director');

        $descriptorspec = array(
           0 => STDIN,
           1 => STDOUT,
           2 => STDERR,
        );

        $ph = proc_open(
            sprintf(
                'bosh %s %s -c %s -d %s take snapshot %s %s',
                $output->isDecorated() ? '--color' : '--no-color',
                $input->isInteractive() ? '' : '--non-interactive',
                escapeshellarg($directorDir . '/.bosh_config'),
                escapeshellarg($destManifest),
                (null !== $job) ? escapeshellarg($job) : '',
                (null !== $index) ? escapeshellarg($index) : ''
            ),
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
