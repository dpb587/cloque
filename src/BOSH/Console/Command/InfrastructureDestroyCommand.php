<?php

namespace BOSH\Console\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Command\Command;
use BOSH\Deployment\ManifestModel;
use Symfony\Component\Yaml\Yaml;

class InfrastructureDestroyCommand extends Command
{
    protected function configure()
    {
        $this
            ->setName('infrastructure:destroy')
            ->setAliases([
                'infra:destroy',
            ])
            ->setDescription('Deploy the httpassetcache deployment')
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
                    )
                ]
            )
            ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
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

        $awsCloudFormation = \Aws\CloudFormation\CloudFormationClient::factory([
            'region' => $region,
        ]);


        $output->writeln('> <comment>destroying</comment>...');

        $awsCloudFormation->deleteStack([
            'StackName' => $stackName,
        ]);


        $output->write('  > <comment>waiting</comment>...');

        $currStatus = 'waiting';

        while (true) {
            try {
                $stackStatus = $awsCloudFormation->describeStacks([
                    'StackName' => $stackName,
                ]);

                $stackStatus = $stackStatus['Stacks'][0]['StackStatus'];
            } catch (\Exception $e) {
                $stackStatus = 'DELETE_COMPLETE';
            }

            if ($currStatus != $stackStatus) {
                $currStatus = $stackStatus;

                $output->write($stackStatus . '...');
            } else {
                $output->write('.');
            }

            if (in_array($currStatus, [ 'ROLLBACK_COMPLETE', 'UPDATE_ROLLBACK_COMPLETE' ])) {
                $output->writeln('');

                return 1;
            } elseif (preg_match('/_COMPLETE$/', $currStatus)) {
                $output->writeln('');

                break;
            } elseif (preg_match('/_FAILED$/', $currStatus)) {
                $output->writeln('');

                return 1;
            }

            sleep(5);
        }
    }
}
