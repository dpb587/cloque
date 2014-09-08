<?php

namespace BOSH\Console\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Command\Command;
use Aws\Ec2\Ec2Client;
use Symfony\Component\Yaml\Yaml;
use BOSH\Deployment\TemplateEngine;

class OpenvpnReloadServersCommand extends AbstractCommand
{
    protected function configure()
    {
        parent::configure()
            ->setName('openvpn:reload-servers')
            ->setDescription('Update servers with latest configuration')
            ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $network = Yaml::parse(file_get_contents($input->getOption('basedir') . '/network.yml'));
        $privateAws = Yaml::parse(file_get_contents($input->getOption('basedir') . '/global/private/aws.yml'));

        foreach ($network['regions'] as $regionName => $regionConfig) {
            if ('global' == $regionName) {
                continue;
            }

            $output->writeln('> <info>' . $regionName . '</info>');

            passthru(
                sprintf(
                    'ssh -i %s -t "ec2-user@%s" %s',
                    escapeshellarg($input->getOption('basedir') . '/' . $privateAws['ssh_key_file']),
                    'gateway.' . $regionName . '.' . $network['root']['name'] . '.' . $network['root']['host'],
                    escapeshellarg(implode("; ", [
                        'set -e',
                        'aws s3api get-object --bucket ' . $network['root']['bucket'] . ' --key openvpn/gateway-package/' . $regionName . '.tgz /tmp/etc-openvpn.tgz',
                        'sudo rm -fr /etc/openvpn',
                        'sudo mkdir /etc/openvpn',
                        'sudo tar -xzf /tmp/etc-openvpn.tgz -C /etc/openvpn',
                        'rm /tmp/etc-openvpn.tgz',
                        'sudo chown -R root:root /etc/openvpn/keys',
                        'sudo chmod 755 /etc/openvpn/keys',
                        'sudo chmod -R 500 /etc/openvpn/keys/*',
                        'sudo chmod -R 555 /etc/openvpn/keys/crl.pem',
                        'sudo /etc/openvpn/build.sh',
                    ]))
                )
            );

            $output->writeln('done');
        }
    }
}
