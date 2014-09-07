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

class InceptionProvisionBoshCommand extends AbstractDirectorCommand
{
    protected function configure()
    {
        parent::configure()
            ->setName('inception:provision-bosh')
            ->setDescription('Start an inception server')
            ->addArgument(
                'ami',
                InputArgument::REQUIRED,
                'BOSH AMI'
            )
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


        $output->writeln('> <comment>deploying</comment>...');

        passthru(
            sprintf(
                'ssh -i %s ubuntu@%s %s',
                escapeshellarg($input->getOption('basedir') . '/' . $privateAws['ssh_key_file']),
                $instance['PublicIpAddress'],
                escapeshellarg(
                    implode(
                        ' ; ',
                        [
                            'set -e',
                            'cd ~/cloque/self',
                            '[ ! -f bosh-deployments.yml ] || EXARGS="--update"',
                            'bosh micro deployment bosh/bosh.yml',
                            'bosh -n micro deploy ${EXARGS:-} ' . $input->getArgument('ami'),
                        ]
                    )
                )
            ),
            $return_var
        );

        if ($return_var) {
            throw new \RuntimeException('Exit code ' . $return_var);
        }


        $output->writeln('> <comment>fetching bosh-deployments.yml</comment>...');

        passthru(
            sprintf(
                'rsync -auze %s --progress ubuntu@%s:%s %s',
                escapeshellarg('ssh -i ' . escapeshellarg($input->getOption('basedir') . '/' . $privateAws['ssh_key_file'])),
                $instance['PublicIpAddress'],
                escapeshellarg('~/cloque/self/bosh-deployments.yml'),
                escapeshellarg($input->getOption('basedir') . '/compiled/' . $input->getOption('director') . '/bosh-deployments.yml')
            )
        );


        $output->write('> <comment>tagging</comment>...');

        # symfony yaml doesn't like ruby names
        preg_match('/\s+:vm_cid:\s+(.*)/', file_get_contents($input->getOption('basedir') . '/compiled/' . $input->getOption('director') . '/bosh-deployments.yml'), $microbosh);

        $awsEc2->createTags([
            'Resources' => [
                $microbosh[1],
            ],
            'Tags' => [
                [
                    'Key' => 'Name',
                    'Value' => 'microbosh',
                ],
                [
                    'Key' => 'deployment',
                    'Value' => 'bosh',
                ],
                [
                    'Key' => 'director',
                    'Value' => $network['root']['name'] . '-' . $input->getOption('director'),
                ],
            ],
        ]);

        $output->writeln('done');
    }

    protected function subrun($name, array $arguments, OutputInterface $output)
    {
        $return = $this->getApplication()->find($name)->run(
            new ArrayInput(array_merge($arguments, [ 'command' => $name ])),
            $output
        );

        if ($return) {
            throw new \RuntimeException(sprintf('%s exited with %s', $name, $return));
        }
    }
}
