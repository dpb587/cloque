<?php

namespace BOSH\Console\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Command\Command;
use BOSH\Deployment\ManifestModel;
use Symfony\Component\Yaml\Yaml;

class BoshDirectorDeploymentsListCommand extends AbstractDirectorCommand
{
    protected function configure()
    {
        parent::configure()
            ->setName('boshdirector:deployments')
            ->setDescription('List all deployments in the director')
            ->addOption(
                'format',
                null,
                InputOption::VALUE_REQUIRED,
                'Director name',
                'bosh'
            )
            ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $formatted = ('bosh' != $input->getOption('format'));

        $result = $this->execBosh(
            $input,
            $output,
            'deployments',
            !$formatted
        );

        if (!$formatted) {
            return;
        }

        $reformat = [];

        foreach (explode("\n", $result) as $line) {
            if (!preg_match('/^\|(?P<name>[^\|]+)\|(?P<release>[^\|]+)\|(?P<stemcell>[^\|]+)\|$/', trim($line), $match)) {
                continue;
            }

            $match = array_map('trim', $match);
            
            if ('Name' == $match['name']) {
                continue;
            }

            if (empty($match['name'])) {
                if (!empty($match['release'])) {
                    $reformat[$lastName]['release'][] = $match['release'];
                }

                if (!empty($match['stemcell'])) {
                    $reformat[$lastName]['stemcell'][] = $match['stemcell'];
                }
            } else {
                $lastName = $match['name'];

                $reformat[$match['name']] = [
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
            $output->writeln(json_encode($reformat, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } elseif ('yaml' == $input->getOption('format')) {
            $output->writeln(Yaml::dump($reformat, 4));
        } else {
            throw new \LogicException('Invalid format: ' . $input->getOption('format'));
        }
    }
}
