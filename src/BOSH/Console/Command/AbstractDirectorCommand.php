<?php

namespace BOSH\Console\Command;

use RuntimeException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Command\Command;
use BOSH\Deployment\ManifestModel;
use Symfony\Component\Yaml\Yaml;

abstract class AbstractDirectorCommand extends AbstractCommand
{
    protected function configure()
    {
        return parent::configure()
            ->addOption(
                'director',
                null,
                InputOption::VALUE_REQUIRED,
                'Director name'
            )
            ;
    }

    protected function execBosh(InputInterface $input, OutputInterface $output, $args, $passthru = true)
    {
        $exec = sprintf(
            'bosh %s %s -c %s',
            $output->isDecorated() ? '--color' : '--no-color',
            $input->isInteractive() ? '' : '--non-interactive',
            escapeshellarg($input->getOption('basedir') . '/' . $input->getOption('director') . '/.bosh_config')
        );

        foreach ((array) $args as $arg) {
            if (null === $arg) {
                continue;
            }

            $exec .= ' ' . escapeshellarg($arg);
        }

        if ($passthru) {
            $descriptorspec = array(
               0 => STDIN,
               1 => STDOUT,
               2 => STDERR,
            );

            $ph = proc_open(
                $exec,
                $descriptorspec,
                $pipes,
                getcwd(),
                null
            );

            $status = proc_get_status($ph);

            do {
                pcntl_waitpid($status['pid'], $pidstatus);
            } while (!pcntl_wifexited($pidstatus));

            $exit = pcntl_wexitstatus($pidstatus);

            if ($exit) {
                throw new RuntimeException('The following command exited with ' . $exit . "\n" . $exec);
            }

            return true;
        } else {
            $stdout = [];
            exec($exec, $stdout, $exit);

            if ($exit) {
                throw new RuntimeException('The following command exited with ' . $exit . "\n" . $exec);
            }

            return implode("\n", $stdout);
        }
    }

    protected function getSharedOptions()
    {
        return array_merge(
            parent::getSharedOptions(),
            [
                'director',
            ]
        );
    }
}
