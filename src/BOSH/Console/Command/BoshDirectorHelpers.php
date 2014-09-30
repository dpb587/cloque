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

class BoshDirectorHelpers
{
    protected $privateAws;
    protected $input;
    protected $output;

    public $ec2Client;

    function __construct(InputInterface $input, OutputInterface $output) {
       $this->input = $input;
       $this->output = $output;

       $this->privateAws = Yaml::parse(file_get_contents($input->getOption('basedir') . '/global/private/aws.yml'));
       $this->ec2Client = \Aws\Ec2\Ec2Client::factory([
            'region' => $this->getNetwork()['region'],
        ]);
    }

    public function tagDirectorResources() {
        $this->output->write('> <comment>tagging</comment>...');

        $network = Yaml::parse(file_get_contents($this->input->getOption('basedir') . '/network.yml'));
        $this->ec2Client->createTags([
            'Resources' => [
                $this->getBoshDeploymentValue('vm_cid'),
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
                    'Value' => $network['root']['name'] . '-' . $this->input->getOption('director'),
                ],
            ],
        ]);
        $this->output->writeln('done');
    }

    public function getBoshDeploymentValue($key) {
        $value=array('','');
        # symfony yaml doesn't like ruby names, so regex out the data we need
        preg_match('/\s+:'.$key.':\s+(.*)/', 
            file_get_contents($this->input->getOption('basedir') . '/compiled/' . $this->input->getOption('director') . '/bosh-deployments.yml'),
            $value); 
        return $value[1];
    }

    public function captureFromServer($username, $ip, $cmds) {
         $stdout = "";
         $return_var = 0;
         exec(
            sprintf(
                'ssh -o "StrictHostKeyChecking no" -t -i %s %s@%s %s',
                escapeshellarg($this->input->getOption('basedir') . '/' . $this->privateAws['ssh_key_file']),
                $username,
                $ip,
                escapeshellarg( 
                    implode(
                        ' ; ',
                        $cmds
                    )
                )
            ),
            $stdout,
            $return_var
        );

        if ($return_var) {
            throw new \RuntimeException('Exit code ' . $return_var);
        }
        return $stdout;
    }

    public function runOnServer($username, $ip, $cmds) {
         $return_var = 0;
         passthru(
            sprintf(
                'ssh -o "StrictHostKeyChecking no" -t -i %s %s@%s %s',
                escapeshellarg($this->input->getOption('basedir') . '/' . $this->privateAws['ssh_key_file']),
                $username,
                $ip,
                escapeshellarg( 
                    implode(
                        ' ; ',
                        $cmds
                    )
                )
            ),
            $return_var
        );

        if ($return_var) {
            throw new \RuntimeException('Exit code ' . $return_var);
        }
    }


    public function rsyncToServer($local, $remote) {
        passthru(
            sprintf(
                'rsync -auze %s --progress %s %s',
                escapeshellarg('ssh -i ' . escapeshellarg($this->input->getOption('basedir') . '/' . $this->privateAws['ssh_key_file'])),
                escapeshellarg($local),
                escapeshellarg($remote)
            )
        );
    }

    public function rsyncFromServer($remote, $local) {
        passthru(
            sprintf(
                'rsync -auze %s --progress %s %s',
                escapeshellarg('ssh -i ' . escapeshellarg($this->input->getOption('basedir') . '/' . $this->privateAws['ssh_key_file'])),
                escapeshellarg($remote),
                escapeshellarg($local)
            )
        );
    }

    public function fetchBoshDeployments($inceptionIp) {
        $this->output->writeln('> <comment>fetching updated bosh-deployments.yml</comment>...');

        $this->rsyncFromServer(
            "ubuntu@$inceptionIp:~/cloque/self/bosh-deployments.yml",
            $this->input->getOption('basedir') . '/compiled/' . $this->input->getOption('director') . '/bosh-deployments.yml'
        );
    }

    public function getNetwork() {
        $network = Yaml::parse(file_get_contents($this->input->getOption('basedir') . '/network.yml'));
        return $network['regions'][$this->input->getOption('director')];
    }

    public function getInceptionInstanceDetails() {

        $this->output->write('> <comment>finding inception instance</comment>...');

        $instances = $this->ec2Client->describeInstances([
            'Filters' => [
                [
                    'Name' => 'network-interface.addresses.private-ip-address',
                    'Values' => [
                        $this->getNetwork()['zones'][0]['reserved']['inception'],
                    ],
                ],
            ],
        ]);

        if (!isset($instances['Reservations'][0]['Instances'][0])) {
            throw new \LogicException('Unable to find inception instance');
        }

        $this->output->writeln('found');

        $instance = $instances['Reservations'][0]['Instances'][0];
        $this->output->writeln('  > <info>instance-id</info> -> ' . $instance['InstanceId']);

        return $instance;
    }
}
