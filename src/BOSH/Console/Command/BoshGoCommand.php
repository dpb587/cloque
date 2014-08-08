<?php

namespace BOSH\Console\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Command\Command;
use BOSH\Deployment\ManifestModel;
use Symfony\Component\Yaml\Yaml;

class BoshGoCommand extends Command
{
    protected function configure()
    {
        $this
            ->setName('bosh:go')
            ->setDescription('Apply the latest configuration to a BOSH deployment')
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
        $baseargs = [
            'locality' => $input->getArgument('locality'),
            'deployment' => $input->getArgument('deployment'),
        ];

        if ($input->getOption('basedir')) {
            $baseargs['--basedir'] = $input->getOption('basedir');
        }

        if ($input->getOption('component')) {
            $baseargs['--component'] = $input->getOption('component');
        }

        $this->subrun(
            'bosh:compile',
            $baseargs,
            $output
        );

        $this->subrun(
            'bosh:apply',
            $baseargs,
            $output
        );
    }

    protected function subrun($name, array $arguments, OutputInterface $output)
    {
        $return = $this->getApplication()->find($name)->run(
            new ArrayInput(array_merge($arguments, [ 'command' => $name ])),
            $output
        );

        if ($return) {
            throw new \RuntimeException(sprintf('%s exited with %s', $name, $return));
        }
    }
}
