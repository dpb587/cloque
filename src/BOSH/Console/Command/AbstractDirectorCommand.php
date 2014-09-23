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

    protected function extractBoshTable($stdout)
    {
        // find headers
        preg_match_all('/^(\+\-+)+\+$/m', $stdout, $matches, PREG_OFFSET_CAPTURE);

        $preg = '';

        foreach (explode('+', trim($matches[0][0][0], '+')) as $col) {
            $preg .= ' (.{' . (strlen($col) - 2) . '}) \|';
        }
        $preg = '/^\|' . $preg . '$/m';

        $headersEnd = $matches[0][1][1];

        // find rows
        preg_match_all($preg, $stdout, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        $reformat = [];
        $headers = null;

        foreach ($matches as $i => $match) {
            $columns = [];

            foreach ($match as $columnIdx => $column) {
                if ($columnIdx > 0) {
                    $columns[] = trim($column[0]);
                }
            }

            if ($match[0][1] < $headersEnd) {
                // headers may span multiple rows
                if ($headers) {
                    foreach ($columns as $columnIdx => $column) {
                        if ('' !== $column) {
                            $headers[$columnIdx] .= ' ' . $column;
                        }
                    }
                } else {
                    $headers = $columns;
                }

                continue;
            }

            $reformat[] = array_combine($headers, $columns);
        }

        // merge multi-rows
        $lastIdx = null;
        foreach ($reformat as $rowIdx => $row) {
            if (!empty($row[$headers[0]])) {
                // first column isn't empty; must not be multi-row
                $lastIdx = $rowIdx;

                continue;
            }

            foreach ($headers as $header) {
                if (empty($row[$header])) {
                    continue;
                }

                if (!is_array($reformat[$lastIdx][$header])) {
                    $reformat[$lastIdx][$header] = [
                        $reformat[$lastIdx][$header],
                    ];
                }

                $reformat[$lastIdx][$header][] = $row[$header];
            }

            unset($reformat[$rowIdx]);
        }
        
        return array_values($reformat);
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
