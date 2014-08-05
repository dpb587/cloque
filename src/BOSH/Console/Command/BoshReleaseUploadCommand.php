<?php

namespace BOSH\Console\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Command\Command;
use BOSH\Deployment\ManifestModel;
use Symfony\Component\Yaml\Yaml;

class BoshReleaseUploadCommand extends Command
{
    protected function configure()
    {
        $this
            ->setName('bosh:release:upload')
            ->setDescription('Upload a release to the BOSH director')
            ->setDefinition(
                [
                    new InputArgument(
                        'director',
                        InputArgument::REQUIRED,
                        'Director name'
                    ),
                    new InputArgument(
                        'release',
                        InputArgument::OPTIONAL,
                        'Deployment name'
                    ),
                ]
            )
            ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $directorDir = $input->getOption('basedir') . '/' . $input->getArgument('director');

        $descriptorspec = array(
           0 => STDIN,
           1 => STDOUT,
           2 => STDERR,
        );

        $ph = proc_open(
            sprintf(
                'bosh %s %s -c %s upload release %s',
                $output->isDecorated() ? '--color' : '--no-color',
                $input->isInteractive() ? '' : '--non-interactive',
                escapeshellarg($directorDir . '/.bosh_config'),
                $input->getArgument('release') ? escapeshellarg($input->getArgument('release')) : ''
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
