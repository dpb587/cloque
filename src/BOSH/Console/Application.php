<?php

namespace BOSH\Console;

use Symfony\Component\Finder\Finder;
use Symfony\Component\Console\Application as BaseApplication;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Formatter\OutputFormatter;
use RuntimeException;

class Application extends BaseApplication
{
    protected $client;
    protected $input;

    public function __construct()
    {
        parent::__construct('bosh', Manifest::getVersion());
    }

    protected function getDefaultCommands()
    {
        $commands = parent::getDefaultCommands();

        $finder = new Finder();
        $finder->files()->name('*Command.php')->in(__DIR__ . '/Command');

        $prefix = __NAMESPACE__ . '\\Command';

        foreach ($finder as $file) {
            $class = $prefix . '\\' . $file->getBasename('.php');

            $r = new \ReflectionClass($class);

            if ($r->isSubclassOf('Symfony\\Component\\Console\\Command\\Command') && !$r->isAbstract() && !$r->getConstructor()->getNumberOfRequiredParameters()) {
                $commands[] = $r->newInstance();
            }
        }

        return $commands;
    }
}