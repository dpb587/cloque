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

class OpenvpnGenerateProfileCommand extends AbstractCommand
{
    protected function configure()
    {
        $this
            ->setName('openvpn:generate-profile')
            ->setDescription('Generate an OVPN profile for a gateway')
            ->addArgument(
                'director',
                InputArgument::REQUIRED,
                'Director name'
            )
            ->addArgument(
                'cn',
                InputArgument::REQUIRED,
                'Common name'
            )
            ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $network = Yaml::parse(file_get_contents($input->getOption('basedir') . '/network.yml'));

        $output->writeln('client');
        $output->writeln('dev tun');
        $output->writeln('proto tcp');
        $output->writeln('remote gateway.' . $input->getArgument('director') . '.' . $network['root']['name'] . '.' . $network['root']['host'] . ' 1194');
        $output->writeln('comp-lzo');
        $output->writeln('resolv-retry infinite');
        $output->writeln('nobind');
        $output->writeln('persist-key');
        $output->writeln('persist-tun');
        $output->writeln('mute-replay-warnings');
        $output->writeln('remote-cert-tls server');
        $output->writeln('verb 3');
        $output->writeln('mute 20');
        $output->writeln('tls-client');

        $output->writeln('<ca>');
        $output->writeln(file_get_contents($input->getOption('basedir') . '/global/openvpn/easyrsa/pki/ca.crt'));
        $output->writeln('</ca>');

        $output->writeln('<cert>');
        $output->writeln(file_get_contents($input->getOption('basedir') . '/global/openvpn/easyrsa/pki/issued/' . $input->getArgument('cn') . '.crt'));
        $output->writeln('</cert>');
    }
}
