<?php

namespace BOSH\Console\Command;

use RuntimeException;
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
    protected $logger;

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
        $this->logger = $this->getApplication()->getLogger();

        $missing = [];

        foreach ($this->getSharedOptions() as $option) {
            if (null !== $input->getOption($option)) {
                continue;
            }

            $envkey = 'CLOQUE_' . preg_replace('/[^A-Z]/', '_', strtoupper($option));
            $envval = getenv($envkey);

            if (false === $envval) {
                $missing[$envkey] = $option;

                continue;
            }

            $input->setOption($option, $envval);
        }

        if ((0 < count($missing)) && is_file(getcwd() . '/.env')) {
            exec('env -i bash -c "source .env ; env"', $dotenv, $exit);

            if ($exit) {
                throw new RuntimeException('Failed to parse .env file');
            }

            foreach ($dotenv as $line) {
                list($localkey, $localval) = explode('=', $line, 2);

                if (isset($missing[$localkey])) {
                    $input->setOption($missing[$localkey], $localval);
                }
            }
        }

        if (null === $input->getOption('basedir')) {
            $input->setOption('basedir', getcwd());
        } else {
            if (preg_match('#' . preg_quote($input->getOption('basedir')) . '/(?<director>[^/]+)(/(?<deployment>[^/]+))?#', getcwd(), $match)) {
                if ($input->hasOption('director') && ((null === $input->getOption('director')) || ($input->getOption('director') == $match['director']))) {
                    $input->setOption('director', $match['director']);
                
                    if ($input->hasOption('deployment') && isset($match['deployment']) && ((null === $input->getOption('deployment')) || ($input->getOption('deployment') == $match['deployment']))) {
                        $input->setOption('deployment', $match['deployment']);
                    }
                }
            }
        }
    }

    protected function execCommand(InputInterface $input, OutputInterface $output, $name, array $arguments = [])
    {
        $arguments['command'] = $name;

        $execCommand = $this->getApplication()->find($name);

        foreach (array_intersect($this->getSharedOptions(), $execCommand->getSharedOptions()) as $option) {
            if (!empty($arguments['--' . $option])) {
                continue;
            } elseif (null === $input->getOption($option)) {
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
