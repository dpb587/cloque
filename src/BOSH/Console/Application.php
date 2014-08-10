<?php

namespace BOSH\Console;

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

        $commands[] = new Command\BoshApplyCommand();
        $commands[] = new Command\BoshGoCommand();
        $commands[] = new Command\BoshCompileCommand();
        $commands[] = new Command\BoshDestroyCommand();
        $commands[] = new Command\BoshDiffCommand();
        $commands[] = new Command\BoshSshCommand();
        $commands[] = new Command\BoshListCommand();
        $commands[] = new Command\BoshReleaseListCommand();
        $commands[] = new Command\BoshReleaseUploadCommand();
        $commands[] = new Command\BoshStemcellUploadCommand();
        $commands[] = new Command\BoshSnapshotCreateCommand();
        $commands[] = new Command\InfrastructureCompileCommand();
        $commands[] = new Command\InfrastructureReloadStateCommand();
        $commands[] = new Command\InfrastructureDiffCommand();
        $commands[] = new Command\InfrastructureApplyCommand();
        $commands[] = new Command\InfrastructureDestroyCommand();
        $commands[] = new Command\InfrastructureGoCommand();
        $commands[] = new Command\UtilityComputePricingCommand();
        $commands[] = new Command\UtilityRevitalizeCommand();
        $commands[] = new Command\UtilityTagResourcesCommand();
        $commands[] = new Command\UtilityInitializeNetworkCommand();
        $commands[] = new Command\OpenvpnRebuildPackagesCommand();
        $commands[] = new Command\OpenvpnReloadServersCommand();
        $commands[] = new Command\OpenvpnSignCertificateCommand();
        $commands[] = new Command\OpenvpnGenerateProfileCommand();
        $commands[] = new Command\InceptionStartCommand();
        $commands[] = new Command\InceptionProvisionBoshCommand();
        $commands[] = new Command\BoshUtilityPackageDownloadsCommand();
        $commands[] = new Command\BoshUtilityPackageDockerBuildCommand();
        $commands[] = new Command\InfrastructureStateCommand();

        return $commands;
    }

    protected function configureIO(InputInterface $input, OutputInterface $output)
    {
        parent::configureIO($input, $output);

        // save this for when we need to build an API client
        $this->input = $input;
        $this->output = $output;
    }

    protected function getDefaultInputDefinition()
    {
        $definition = parent::getDefaultInputDefinition();

        $definition->addOption(
            new InputOption(
                'basedir',
                null,
                InputOption::VALUE_REQUIRED,
                'Base configuration directory'
            )
        );

        return $definition;
    }
}