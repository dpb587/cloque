<?php

namespace BOSH\Console\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Command\Command;
use BOSH\Deployment\ManifestModel;
use Symfony\Component\Yaml\Yaml;

class BoshListCommand extends Command
{
    protected function configure()
    {
        $this
            ->setName('bosh:list')
            ->setDescription('List all deployments')
            ->setDefinition(
                [
                    new InputArgument(
                        'director',
                        InputArgument::REQUIRED,
                        'Director name'
                    ),
                    new InputOption(
                        'format',
                        null,
                        InputOption::VALUE_REQUIRED,
                        'Director name',
                        'bosh'
                    ),
                ]
            )
            ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $directorDir = $input->getOption('basedir') . '/' . $input->getArgument('director');
        $exec = sprintf(
            'bosh %s %s -c %s deployments',
            $output->isDecorated() ? '--color' : '--no-color',
            $input->isInteractive() ? '' : '--non-interactive',
            escapeshellarg($directorDir . '/.bosh_config')
        );

        if ('bosh' == $input->getOption('format')) {
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

            return pcntl_wexitstatus($pidstatus);
        } else {
            exec($exec, $stdout, $return_var);

            if ($return_var) {
                throw new \RuntimeException('Exit code was ' . $return_var);
            }

            $lastName = null;
            $result = [];

            foreach ($stdout as $line) {
                if (!preg_match('/^\|(?P<name>[^\|]+)\|(?P<release>[^\|]+)\|(?P<stemcell>[^\|]+)\|$/', trim($line), $match)) {
                    continue;
                }

                $match = array_map('trim', $match);
                
                if ('Name' == $match['name']) {
                    continue;
                }

                if (empty($match['name'])) {
                    if (!empty($match['release'])) {
                        $result[$lastName]['release'][] = $match['release'];
                    }

                    if (!empty($match['stemcell'])) {
                        $result[$lastName]['stemcell'][] = $match['stemcell'];
                    }
                } else {
                    $lastName = $match['name'];

                    $result[$match['name']] = [
                        'name' => $match['name'],
                        'release' => [
                            $match['release'],
                        ],
                        'stemcell' => [
                            $match['stemcell'],
                        ],
                    ];
                }
            }

            if ('json' == $input->getOption('format')) {
                $output->writeln(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            } elseif ('yaml' == $input->getOption('format')) {
                $output->writeln(Yaml::dump($result, 4));
            } else {
                throw new \LogicException('Invalid format: ' . $input->getOption('format'));
            }
        }
    }
}
