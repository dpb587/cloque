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

class OpenvpnRebuildPackagesCommand extends AbstractCommand
{
    protected function configure()
    {
        parent::configure()
            ->setName('openvpn:rebuild-packages')
            ->setDescription('Rebuild and upload OpenVPN gateway packages')
            ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $network = Yaml::parse(file_get_contents($input->getOption('basedir') . '/network.yml'));

        foreach ($network['regions'] as $regionName => $regionConfig) {
            if ('global' == $regionName) {
                continue;
            }

            $output->writeln('> <info>' . $regionName . '</info>');

            $mdir = $input->getOption('basedir') . '/compiled/tmp/openvpn-package-build';

            if (file_exists($mdir)) {
                $output->write('  > <comment>cleaning</comment>...');

                passthru('rm -fr ' . escapeshellarg($mdir) . ' ' . escapeshellarg($mdir . '.tgz'));

                $output->writeln('done');
            }

            mkdir($mdir . '/keys', 0700, true);


            $output->write('  > <info>build.sh</info>...');

            file_put_contents($mdir . '/build.sh', '#!/bin/bash

set -e
set -u

if [ -e /usr/local/openvpn ] ; then
  # restart, but do not rebuild
  sudo service openvpn restart

  exit 0
fi

sudo yum install -y gcc openssl-devel lzo-devel

mkdir /tmp/buildroot
cd /tmp/buildroot

wget "http://swupdate.openvpn.org/community/releases/openvpn-2.3.4.tar.gz"
tar -xzf openvpn-2.3.4.tar.gz
cd openvpn-2.3.4/

./configure \
  --prefix /usr/local/openvpn \
  --disable-plugin-auth-pam

make
sudo make install

sudo ln -s /usr/local/openvpn/sbin/openvpn /usr/sbin/openvpn

sudo cp distro/rpm/openvpn.init.d.rhel /etc/init.d/openvpn
sudo service openvpn start
sudo chkconfig --level 345 openvpn on
');

            chmod($mdir . '/build.sh', 0755);

            $output->writeln('created');


            $output->write('  > <info>ccd</info>...');

            passthru('cp -r ' . escapeshellarg($input->getOption('basedir') . '/global/openvpn/ccd/' . $regionName) . ' ' . escapeshellarg($mdir . '/ccd'));

            $output->writeln('created');


            $pkiDir = $input->getOption('basedir') . '/global/openvpn/easyrsa/pki';

            foreach ([
                'keys/ca.crt' => 'ca.crt',
                'keys/crl.pem' => 'crl.pem',
                'keys/dh.pem' => 'dh.pem',
                'keys/server.crt' => 'issued/gateway.' . $regionName . '.' . $network['root']['name'] . '.' . $network['root']['host'] . '.crt',
                'keys/server.key' => 'private/gateway.' . $regionName . '.' . $network['root']['name'] . '.' . $network['root']['host'] . '.key',
                'keys/client.crt' => 'issued/gateway-client.' . $regionName . '.' . $network['root']['name'] . '.' . $network['root']['host'] . '.crt',
                'keys/client.key' => 'private/gateway-client.' . $regionName . '.' . $network['root']['name'] . '.' . $network['root']['host'] . '.key',
            ] as $target => $source) {
                $output->write('  > <info>' . $target . '</info>...');

                passthru('cp ' . escapeshellarg($pkiDir . '/' . $source) . ' ' . escapeshellarg($mdir . '/' . $target));

                $output->writeln('created');
            }


            $engine = new TemplateEngine(
                $input->getOption('basedir'),
                $regionName,
                null
            );


            $output->write('  > <info>server.conf</info>...');

            file_put_contents($mdir . '/server.conf', $engine->render('mode server
client-config-dir /etc/openvpn/ccd
ca /etc/openvpn/keys/ca.crt
cert /etc/openvpn/keys/server.crt
key /etc/openvpn/keys/server.key
dh /etc/openvpn/keys/dh.pem
crl-verify /etc/openvpn/keys/crl.pem
proto tcp
port 1194
comp-lzo
group nobody
user nobody
status /var/log/openvpn--server.log
dev tun
local {{ env["network.local"]["zones"][0]["reserved"]["gateway"] }}
server {{ env["network.local"]["vpn"]|cidr_network }} {{ env["network.local"]["vpn"]|cidr_netmask_ext }}
push "route {{ env["network.local"]["cidr"]|cidr_network }} {{ env["network.local"]["cidr"]|cidr_netmask_ext }} vpn_gateway 8"
push "persist-tun"
push "ping 15"
push "ping-restart 60"
keepalive 15 60
tls-server
client-to-client
topology subnet
persist-key
persist-tun
'));

            $output->writeln('created');


            foreach ($network['regions'] as $subregionName => $subregionConfig) {
                if (in_array($subregionName, [ 'global', $regionName ])) {
                    continue;
                }

                $output->write('  > <info>client-' . $subregionName . '.conf</info>...');

                file_put_contents($mdir . '/client-' . $subregionName . '.conf', 'client
dev tun
proto tcp
ca /etc/openvpn/keys/ca.crt
cert /etc/openvpn/keys/client.crt
key /etc/openvpn/keys/client.key
remote gateway.' . $subregionName . '.' . $network['root']['name'] . '.' . $network['root']['host'] . ' 1194
comp-lzo
resolv-retry infinite
nobind
persist-key
persist-tun
mute-replay-warnings
remote-cert-tls server
verb 3
mute 20
tls-client
');

                $output->writeln('created');

            }


            $output->write('  > <comment>packaging</comment>...');

            passthru('cd ' . escapeshellarg($mdir) . ' && tar -czf ../openvpn-package-build.tgz *');

            $output->writeln('done');


            $output->write('  > <comment>uploading</comment>...');

            $awsS3 = \Aws\S3\S3Client::factory();
            $awsS3->putObject([
                'Bucket' => $network['root']['bucket'],
                'SourceFile' => $mdir . '/../openvpn-package-build.tgz',
                'ACL' => 'private',
                'Key' => 'openvpn/gateway-package/' . $regionName . '.tgz',
            ]);

            $output->writeln('done');


            $output->write('  > <comment>cleaning</comment>...');

            passthru('rm -fr ' . escapeshellarg($mdir) . ' ' . escapeshellarg($mdir . '.tgz'));

            $output->writeln('done');
        }
    }
}
