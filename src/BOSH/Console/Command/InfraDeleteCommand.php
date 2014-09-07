<?php

namespace BOSH\Console\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Command\Command;
use BOSH\Deployment\ManifestModel;
use Symfony\Component\Yaml\Yaml;

class InfraDeleteCommand extends AbstractDirectorDeploymentCommand
{
    protected function configure()
    {
        parent::configure()
            ->setName('infra:delete')
            ->setDescription('Completely destroy the infrastructure')
            ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $network = Yaml::parse(file_get_contents($input->getOption('basedir') . '/network.yml'));

        $stackName = sprintf(
            '%s--%s%s',
            ($input->getOption('basename') ? ($input->getOption('basename') . '-') : '') . $input->getOption('director'),
            $input->getOption('deployment'),
            $input->getOption('component') ? ('--' . $input->getOption('component')) : ''
        );

        // hack
        $stackName = preg_replace('#^prod-abraxas-global(\-\-.*)$#', 'global$1', $stackName);

        $region = $network['regions'][($input->getOption('basename') ? ($input->getOption('basename') . '-') : '') . $input->getOption('director')]['region'];

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
