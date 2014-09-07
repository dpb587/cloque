<?php

namespace BOSH\Console\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Command\Command;
use BOSH\Deployment\TemplateEngine;
use Symfony\Component\Yaml\Yaml;

class InfraRecompileCommand extends AbstractDirectorDeploymentCommand
{
    protected function configure()
    {
        parent::configure()
            ->setName('infra:recompile')
            ->setDescription('Locally recompile the configuration for the infrastructure')
            ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $sourceManifest = sprintf(
            '%s/%s/%s/infrastructure%s.json',
            $input->getOption('basedir'),
            $input->getOption('director'),
            $input->getOption('deployment'),
            $input->getOption('component') ? ('-' . $input->getOption('component')) : ''
        );

        $destManifest = sprintf(
            '%s/compiled/%s/%s/infrastructure%s.json',
            $input->getOption('basedir'),
            $input->getOption('director'),
            $input->getOption('deployment'),
            $input->getOption('component') ? ('-' . $input->getOption('component')) : ''
        );

        if (!is_dir(dirname($destManifest))) {
            mkdir(dirname($destManifest), 0700, true);
        }

        chdir(dirname($sourceManifest));

        $engine = new TemplateEngine(
            $input->getOption('basedir'),
            $input->getOption('director'),
            $input->getOption('deployment')
        );

        $result = file_get_contents($sourceManifest);
        $result = $engine->render($result);
        $result = json_decode($result, true);


        file_put_contents($destManifest, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        passthru(
            sprintf(
                'mv %s %s.z && cat %s.z | jq --raw-output --sort-keys "." > %s && rm %s.z',
                escapeshellarg($destManifest),
                escapeshellarg($destManifest),
                escapeshellarg($destManifest),
                escapeshellarg($destManifest),
                escapeshellarg($destManifest)
            )
        );
    }
}
