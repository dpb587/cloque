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

class OpenvpnSignCertificateCommand extends Command
{
    protected function configure()
    {
        $this
            ->setName('openvpn:sign-certificate')
            ->setDescription('Sign a certificate for client usage')
            ->setDefinition(
                [
                    new InputArgument(
                        'csr',
                        InputArgument::REQUIRED,
                        'CSR path'
                    ),
                ]
            )
            ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $network = Yaml::parse(file_get_contents($input->getOption('basedir') . '/network.yml'));

        $csr = file_get_contents($input->getArgument('csr'));
        $csrDetails = openssl_csr_get_subject($csr);

        if (file_exists($input->getOption('basedir') . '/global/openvpn/easyrsa/pki/reqs/' . $csrDetails['CN'] . '.req')) {
            throw new \LogicException('The certificate request for the CN already exists');
        }

        file_put_contents($input->getOption('basedir') . '/global/openvpn/easyrsa/pki/reqs/' . $csrDetails['CN'] . '.req', $csr);

        passthru('cd ' . escapeshellarg($input->getOption('basedir') . '/global/openvpn/easyrsa') . ' && ./easyrsa --vars=pki.vars sign client ' . escapeshellarg($csrDetails['CN']));//, $return_var);

        if ($return_var) {
            throw new \RuntimeException('Exit code was ' . $return_var);
        }

        $output->writeln('<info>' . file_get_contents($input->getOption('basedir') . '/global/openvpn/easyrsa/pki/issued/' . $csrDetails['CN'] . '.crt') . '</info>');
    }
}
