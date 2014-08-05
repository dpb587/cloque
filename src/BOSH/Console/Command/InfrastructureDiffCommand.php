<?php

namespace BOSH\Console\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Command\Command;
use BOSH\Deployment\TemplateEngine;
use Symfony\Component\Yaml\Yaml;

class InfrastructureDiffCommand extends Command
{
    protected function configure()
    {
        $this
            ->setName('infrastructure:diff')
            ->setAliases([
                'infra:diff',
            ])
            ->setDescription('Compare the running configuration with the local configuration')
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
        $destManifest = sprintf(
            '%s/compiled/%s/%s/infrastructure%s.json',
            $input->getOption('basedir'),
            $input->getArgument('locality'),
            $input->getArgument('deployment'),
            $input->getOption('component') ? ('-' . $input->getOption('component')) : ''
        );

        $network = Yaml::parse(file_get_contents($input->getOption('basedir') . '/network.yml'));

        $stackName = sprintf(
            '%s--%s%s',
            ($input->getOption('basename') ? ($input->getOption('basename') . '-') : '') . $input->getArgument('locality'),
            $input->getArgument('deployment'),
            $input->getOption('component') ? ('--' . $input->getOption('component')) : ''
        );

        // hack
        $stackName = preg_replace('#^prod-abraxas-global(\-\-.*)$#', 'global$1', $stackName);
        $region = $network['regions'][($input->getOption('basename') ? ($input->getOption('basename') . '-') : '') . $input->getArgument('locality')]['region'];

        passthru('aws --region ' . $region . ' --query TemplateBody cloudformation get-template --stack-name ' . $stackName . ' | jq --raw-output --sort-keys "." | diff - ' . escapeshellarg($destManifest));
    }
}
