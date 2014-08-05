<?php

namespace BOSH\Console\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Command\Command;
use BOSH\Deployment\ManifestModel;
use Symfony\Component\Yaml\Yaml;

class InfrastructureApplyCommand extends Command
{
    protected function configure()
    {
        $this
            ->setName('infrastructure:apply')
            ->setAliases([
                'infra:apply',
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
                    ),
                    new InputOption(
                        'aws-cloudformation',
                        null,
                        InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED,
                        'Additional CloudFormation arguments',
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

        $awsCloudFormation = \Aws\CloudFormation\CloudFormationClient::factory([
            'region' => $region,
        ]);


        $apiArgs = [];

        foreach ($input->getOption('aws-cloudformation') as $arg) {
            list($argName, $argValue) = explode('=', $arg, 2);

            $apiArgs[$argName] = preg_match('/^(\{|\[).+(\}|\])$/', $argValue) ? json_decode($argValue, true) : $argValue;
        }


        $output->write('> <comment>validating</comment>...');

        $awsCloudFormation->validateTemplate([
            'TemplateBody' => file_get_contents($destManifest),
        ]);

        $output->writeln('done');


        $output->write('> <comment>checking</comment>...');

        try {
            $stacks = $awsCloudFormation->describeStacks([
                'StackName' => $stackName,
            ]);

            $output->writeln('exists');
            $apiCall = 'updateStack';
        } catch (\Exception $e) {
            $output->writeln('missing');
            $apiCall = 'createStack';
            $apiArgs['Tags'] = [
                [
                    'Key' => 'deployment',
                    'Value' => ($input->getOption('basename') ? ($input->getOption('basename') . '-') : '') . $input->getArgument('locality') . '.' . $network['root']['host'],
                ],
                [
                    'Key' => 'director',
                    'Value' => 'aws.amazon.com',
                ],
            ];
        }


        $output->writeln('> <comment>deploying</comment>...');

        $awsCloudFormation->$apiCall(array_merge($apiArgs, [
            'StackName' => $stackName,
            'TemplateBody' => file_get_contents($destManifest),
        ]));


        $output->write('  > <comment>waiting</comment>...');

        $currStatus = 'waiting';

        while (true) {
            $stackStatus = $awsCloudFormation->describeStacks([
                'StackName' => $stackName,
            ]);

            $stackStatus = $stackStatus['Stacks'][0]['StackStatus'];

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
