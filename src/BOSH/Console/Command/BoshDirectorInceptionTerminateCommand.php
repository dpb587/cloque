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

class BoshDirectorInceptionTerminateCommand extends AbstractDirectorCommand
{
    protected function configure()
    {
        parent::configure()
            ->setName('boshdirector:inception:terminate')
            ->setDescription('Terminate an inception server')
            ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $network = Yaml::parse(file_get_contents($input->getOption('basedir') . '/network.yml'));
        $networkLocal = $network['regions'][$input->getOption('director')];

        $privateAws = Yaml::parse(file_get_contents($input->getOption('basedir') . '/global/private/aws.yml'));

        $awsEc2 = \Aws\Ec2\Ec2Client::factory([
            'region' => $networkLocal['region'],
        ]);

        $output->write('> <comment>finding instance</comment>...');

        $instances = $awsEc2->describeInstances([
            'Filters' => [
                [
                    'Name' => 'network-interface.addresses.private-ip-address',
                    'Values' => [
                        $networkLocal['zones'][0]['reserved']['inception'],
                    ],
                ],
            ],
        ]);

        if (!isset($instances['Reservations'][0]['Instances'][0])) {
            throw new \LogicException('Unable to find inception instance');
        }

        $output->writeln('found');

        $instance = $instances['Reservations'][0]['Instances'][0];

        $output->writeln('  > <info>instance-id</info> -> ' . $instance['InstanceId']);


        $output->writeln('> <comment>terminating</comment>...');

        $result = $awsEc2->terminateInstances(array(
            'InstanceIds' => array($instance['InstanceId']),
        ));

        if ($result['TerminatingInstances'][0]['InstanceId']!==$instance['InstanceId']) {
            throw new \RuntimeException('Termination failed');
        }

        $output->writeln('done');
    }
}
