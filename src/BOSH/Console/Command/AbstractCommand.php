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

abstract class AbstractCommand extends Command
{
    protected function configure()
    {
        return $this
            ->addOption(
                'basedir',
                null,
                InputOption::VALUE_REQUIRED,
                'Base configuration directory'
            )
            ;
    }

    protected function getSharedOptions()
    {
        return [
            'basedir',
        ];
    }

    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        foreach ($this->getSharedOptions() as $option) {
            if (null !== $input->getOption($option)) {
                continue;
            }

            $input->setOption($option, getenv('CLOQUE_' . preg_replace('/[^A-Z]/', '_', strtoupper($option))));
        }
    }

    protected function execCommand(InputInterface $input, OutputInterface $output, $name, array $arguments = [])
    {
        $arguments['command'] = $name;

        $execCommand = $this->getApplication()->find($name);

        foreach (array_intersect($this->getSharedOptions(), $execCommand->getSharedOptions()) as $option) {
            if (!empty($arguments['--' . $option])) {
                continue;
            }

            $arguments['--' . $option] = $input->getOption($option);
        }

        $execInput = new ArrayInput($arguments);
        $execInput->setInteractive($input->isInteractive());

        $exitcode = $execCommand->run($execInput, $output);

        if ($exitcode) {
            throw new \RuntimeException(sprintf('%s exited with %s', $name, $exitcode));
        }
    }
}
