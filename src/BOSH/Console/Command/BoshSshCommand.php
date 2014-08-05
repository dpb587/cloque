<?php

namespace BOSH\Console\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Command\Command;
use BOSH\Deployment\ManifestModel;
use Symfony\Component\Yaml\Yaml;

class BoshSshCommand extends Command
{
    protected function configure()
    {
        $this
            ->setName('bosh:ssh')
            ->setDescription('Connect to a job in a BOSH deployment')
            ->setDefinition(
                [
                    new InputArgument(
                        'locality',
                        InputArgument::REQUIRED,
                        'Locality name'
                    ),
                    new InputArgument(
                        'deployment',
                        InputArgument::REQUIRED,
                        'Deployment name'
                    ),
                    new InputArgument(
                        'jobid',
                        InputArgument::OPTIONAL,
                        'Job/index to connect'
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
            $input->getArgument('locality'),
            $input->getArgument('deployment'),
            $input->getOption('component') ? ('-' . $input->getOption('component')) : ''
        );

        $manifest = Yaml::parse(file_get_contents($destManifest));

        $jobid = $input->getArgument('jobid');

        if (empty($jobid)) {
            if (1 != count($manifest['jobs'])) {
                throw new \RuntimeException('The jobid argument must be specified');
            }

            $jobid = $manifest['jobs'][0]['name'];
        }

        $descriptorspec = array(
           0 => STDIN,
           1 => STDOUT,
           2 => STDERR,
        );

        $ph = proc_open(
            sprintf(
                'bosh %s -c %s -d %s ssh --default_password asdf %s',
                $output->isDecorated() ? '--color' : '--no-color',
                escapeshellarg($input->getOption('basedir') . '/' . $input->getArgument('locality') . '/.bosh_config'),
                escapeshellarg($destManifest),
                escapeshellarg($jobid)
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
