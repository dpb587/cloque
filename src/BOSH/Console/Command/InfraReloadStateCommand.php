<?php

namespace BOSH\Console\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Command\Command;
use BOSH\Deployment\ManifestModel;
use Symfony\Component\Yaml\Yaml;

class InfraReloadStateCommand extends AbstractDirectorDeploymentCommand
{
    protected function configure()
    {
        parent::configure()
            ->setName('infra:reload-state')
            ->setDescription('Dump the current infrastructure state')
            ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $network = Yaml::parse(file_get_contents($input->getOption('basedir') . '/network.yml'));

        $stackName = sprintf(
            '%s--%s%s',
            $network['root']['name'] . '-' . $input->getOption('director'),
            $input->getOption('deployment'),
            $input->getOption('component') ? ('--' . $input->getOption('component')) : ''
        );

        // hack
        $stackName = preg_replace('#^prod-abraxas-global(\-\-.*)$#', 'global$1', $stackName);
        $region = $network['regions'][$input->getOption('director')]['region'];

        $awsCloudFormation = \Aws\CloudFormation\CloudFormationClient::factory([
            'region' => $region,
        ]);

        $awsEc2 = \Aws\Ec2\Ec2Client::factory([
            'region' => $region,
        ]);


        $result = [];

        $stacks = $awsCloudFormation->describeStacks([
            'StackName' => $stackName,
        ]);

        if (!isset($stacks['Stacks'][0])) {
            throw new \RuntimeException('Unable to find stack');
        }

        $stack = $stacks['Stacks'][0];

        $result['StackId'] = $stack['StackId'];
        $result['StackName'] = $stack['StackName'];

        $lookupSecurityGroups = [];

        $stackResources = $awsCloudFormation->describeStackResources([
            'StackName' => $stackName,
        ]);

        foreach ($stackResources['StackResources'] as $stackResource) {
            $result[$stackResource['LogicalResourceId'] . 'Id'] = $stackResource['PhysicalResourceId'];

            if ('AWS::EC2::SecurityGroup' == $stackResource['ResourceType']) {
                $lookupSecurityGroups[$stackResource['PhysicalResourceId']] = $stackResource['LogicalResourceId'];
            }
        }

        if (0 < count($lookupSecurityGroups)) {
            $securityGroups = $awsEc2->describeSecurityGroups([
                'GroupIds' => array_keys($lookupSecurityGroups),
            ]);

            foreach ($securityGroups['SecurityGroups'] as $securityGroup) {
                $result[$lookupSecurityGroups[$securityGroup['GroupId']] . 'Name'] = $securityGroup['GroupName'];
            }
        }

        if (isset($stack['Outputs'])) {
            foreach ($stack['Outputs'] as $output) {
                $result[$output['OutputKey']] = $output['OutputValue'];
            }
        }

        ksort($result);


        $destManifest = sprintf(
            '%s/compiled/%s/%s/infrastructure%s--state.json',
            $input->getOption('basedir'),
            $input->getOption('director'),
            $input->getOption('deployment'),
            $input->getOption('component') ? ('-' . $input->getOption('component')) : ''
        );

        if (!is_dir(dirname($destManifest))) {
            mkdir(dirname($destManifest), 0700, true);
        }

        file_put_contents($destManifest, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
