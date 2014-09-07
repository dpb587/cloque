<?php

namespace BOSH\Console\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Command\Command;
use Aws\Ec2\Ec2Client;
use Symfony\Component\Yaml\Yaml;

class UtilityInitializeNetworkCommand extends AbstractCommand
{
    protected function configure()
    {
        $this
            ->setName('utility:initialize-network')
            ->setDescription('Initialize a new network')
            ;
    }

    protected function requireDirectory(InputInterface $input, OutputInterface $output, $path)
    {
        $output->write('> <info>local:fs/' . $path . '</info> -> ');

        if (is_dir($input->getOption('basedir') . '/' . $path)) {
            $output->writeln('exists');
        } else {
            mkdir($input->getOption('basedir') . '/' . $path, 0700);

            $output->writeln('created');
        }
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if (!file_exists($input->getOption('basedir') . '/network.yml')) {
            throw new \LogicException('Missing network.yml');
        }

        $network = Yaml::parse(file_get_contents($input->getOption('basedir') . '/network.yml'));


        foreach ($network['regions'] as $regionName => $regionConfig) {
            $this->requireDirectory($input, $output, $regionName);
            $this->requireDirectory($input, $output, $regionName . '/core');
        }

        $this->requireDirectory($input, $output, 'global/private');


        $output->write('> <info>local:fs/global/private/aws.yml</info> -> ');

        if (!file_exists($input->getOption('basedir') . '/global/private/aws.yml')) {
            touch($input->getOption('basedir') . '/global/private/aws.yml');

            $output->writeln('created');

            $privateAws = [];
        } else {
            $output->writeln('exists');

            $privateAws = Yaml::parse(file_get_contents($input->getOption('basedir') . '/global/private/aws.yml'));
        }


        $sshkeyname = $network['root']['name'] . '-cloque-' . date('Ymd') . 'a';
        $sshkeypath = 'global/private/cloque-' . date('Ymd') . 'a.pem';
        $output->write('> <info>local:fs/' . $sshkeypath . '</info> -> ');

        if (file_exists($input->getOption('basedir') . '/' . $sshkeypath)) {
            $output->writeln('exists');
        } else {
            passthru('ssh-keygen -t rsa -b 2048 -f ' . escapeshellarg($input->getOption('basedir') . '/' . $sshkeypath) . ' -P "" > /dev/null 2>&1', $return_var);

            if ($return_var) {
                throw new \RuntimeException('Failed to create sshkey');
            }

            $output->writeln('created');
        }

        $privateAwsChanged = false;

        if (!isset($privateAws['ssh_key_name'])) {
            $privateAws['ssh_key_name'] = $sshkeyname;
            $privateAwsChanged = true;
        }

        if (!isset($privateAws['ssh_key_file'])) {
            $privateAws['ssh_key_file'] = $sshkeypath;
            $privateAwsChanged = true;
        }

        if ($privateAwsChanged) {
            $output->write('> <info>local:fs/global/private/aws.yml</info> -> ');

            file_put_contents($input->getOption('basedir') . '/global/private/aws.yml', Yaml::dump($privateAws));

            $output->writeln('updated');
        }


        /**
         * aws
         */

        foreach ($network['regions'] as $regionName => $region) {
            if ('global' == $regionName) {
                continue;
            }

            $output->write('> <info>aws:ec2:key-pairs/' . $region['region'] . '</info> -> ');

            $awsEc2 = Ec2Client::factory([
                'region' => $region['region'],
            ]);

            try {
                $uploadedPairs = $awsEc2->describeKeyPairs([
                    'KeyNames' => [
                        $sshkeyname,
                    ],
                ]);

                $output->writeln('exists');
            } catch (\Exception $e) {
                $awsEc2->importKeyPair([
                    'KeyName' => $sshkeyname,
                    'PublicKeyMaterial' => file_get_contents($input->getOption('basedir') . '/' . $sshkeypath . '.pub'),
                ]);

                $output->writeln('created');
            }
        }


        $output->write('> <info>aws:iam:user/' . $network['root']['name'] . '-bosh</info> -> ');

        $awsIam = \Aws\Iam\IamClient::factory();
        $users = $awsIam->listUsers([
            'MaxItems' => 1000,
        ]);

        $found = false;

        foreach ($users['Users'] as $user) {
            if ($network['root']['name'] . '-bosh' == $user['UserName']) {
                $found = true;

                break;
            }
        }

        if ($found) {
            $output->writeln('exists');
        } else {
            $awsIam->createUser([
                'UserName' => $network['root']['name'] . '-bosh',
            ]);

            $output->writeln('created');
        }


        $output->write('> <info>aws:iam:user/' . $network['root']['name'] . '-bosh/access-key</info> -> ');

        $accessKeys = $awsIam->listAccessKeys([
            'UserName' => $network['root']['name'] . '-bosh',
        ]);

        if (0 < count($accessKeys['AccessKeyMetadata'])) {
            $output->writeln('exists');
        } else {
            $accessKey = $awsIam->createAccessKey([
                'UserName' => $network['root']['name'] . '-bosh',
            ]);

            $privateAws['access_key_id'] = $accessKey['AccessKey']['AccessKeyId'];
            $privateAws['secret_access_key'] = $accessKey['AccessKey']['SecretAccessKey'];

            $output->writeln('created');


            $output->write('> <info>local:fs/global/private/aws.yml</info> -> ');

            file_put_contents($input->getOption('basedir') . '/global/private/aws.yml', Yaml::dump($privateAws));

            $output->writeln('updated');
        }


        $output->write('> <info>aws:iam:user/' . $network['root']['name'] . '-bosh/user-policy</info> -> ');

        $userPolicies = $awsIam->listUserPolicies([
            'UserName' => $network['root']['name'] . '-bosh',
        ]);

        if (in_array('cloque-ec2-full-access', $userPolicies['PolicyNames'])) {
            $output->writeln('exists');
        } else {
            $awsIam->putUserPolicy([
                'UserName' => $network['root']['name'] . '-bosh',
                'PolicyName' => 'cloque-ec2-full-access',
                'PolicyDocument' => json_encode([
                    'Version' => '2012-10-17',
                    'Statement' => [
                        [
                            'Action' => 'ec2:*',
                            'Effect' => 'Allow',
                            'Resource' => '*',
                        ],
                        [
                            'Effect' => 'Allow',
                            'Action' => 'elasticloadbalancing:*',
                            'Resource' => '*',
                        ],
                        [
                            'Effect' => 'Allow',
                            'Action' => 'cloudwatch:*',
                            'Resource' => '*',
                        ],
                        [
                            'Effect' => 'Allow',
                            'Action' => 'autoscaling:*',
                            'Resource' => '*',
                        ]
                    ]
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            ]);

            $output->writeln('created');
        }


        /**
         * openvpn
         */

        $this->requireDirectory($input, $output, 'global/openvpn');
        $this->requireDirectory($input, $output, 'global/openvpn/easyrsa');

        $output->write('> <info>easyrsa:src</info> -> ');

        if (file_exists($input->getOption('basedir') . '/global/openvpn/easyrsa/x509-types')) {
            $output->writeln('exists');
        } else {
            passthru('wget -qO- "https://github.com/OpenVPN/easy-rsa/releases/download/v3.0.0-rc1/EasyRSA-3.0.0-rc1.tgz" | tar -xzC ' . escapeshellarg($input->getOption('basedir') . '/global/openvpn/easyrsa') . ' --strip-components 1');

            $output->writeln('created');
        }


        $output->write('> <info>easyrsa:pki.vars</info> -> ');

        if (file_exists($input->getOption('basedir') . '/global/openvpn/easyrsa/pki.vars')) {
            $output->writeln('exists');
        } else {
            $vars = file_get_contents($input->getOption('basedir') . '/global/openvpn/easyrsa/vars.example');
            $vars = preg_replace('/#?set_var\s+EASYRSA_REQ_COUNTRY\s+.*/', 'set_var EASYRSA_REQ_COUNTRY ' . escapeshellarg($network['about']['location']['country']), $vars);
            $vars = preg_replace('/#?set_var\s+EASYRSA_REQ_PROVINCE\s+.*/', 'set_var EASYRSA_REQ_PROVINCE ' . escapeshellarg($network['about']['location']['territory']), $vars);
            $vars = preg_replace('/#?set_var\s+EASYRSA_REQ_CITY\s+.*/', 'set_var EASYRSA_REQ_CITY ' . escapeshellarg($network['about']['location']['city']), $vars);
            $vars = preg_replace('/#?set_var\s+EASYRSA_REQ_ORG\s+.*/', 'set_var EASYRSA_REQ_ORG ' . escapeshellarg($network['about']['name']), $vars);
            $vars = preg_replace('/#?set_var\s+EASYRSA_REQ_EMAIL\s+.*/', 'set_var EASYRSA_REQ_EMAIL ' . escapeshellarg($network['about']['email']), $vars);
            $vars = preg_replace('/#?set_var\s+EASYRSA_REQ_OU\s+.*/', 'set_var EASYRSA_REQ_OU ' . escapeshellarg($network['root']['name'] . '/openvpn'), $vars);

            file_put_contents($input->getOption('basedir') . '/global/openvpn/easyrsa/pki.vars', $vars);

            $output->writeln('created');
        }


        $output->write('> <info>easyrsa:pki</info> -> ');

        if (file_exists($input->getOption('basedir') . '/global/openvpn/easyrsa/pki')) {
            $output->writeln('exists');
        } else {
            passthru('cd ' . escapeshellarg($input->getOption('basedir') . '/global/openvpn/easyrsa') . ' && ./easyrsa --vars=pki.vars init-pki > /dev/null 2>&1');

            $output->writeln('created');
        }


        $output->write('> <info>easyrsa:private/ca.key</info> -> ');

        if (file_exists($input->getOption('basedir') . '/global/openvpn/easyrsa/pki/private/ca.key')) {
            $output->writeln('exists');
        } else {
            $output->writeln('creating...');

            $output->writeln('  > <comment>Common Name</comment>: openvpn.' . $network['root']['name']);

            passthru('cd ' . escapeshellarg($input->getOption('basedir') . '/global/openvpn/easyrsa') . ' && ./easyrsa --vars=pki.vars build-ca');
        }


        $output->write('> <info>easyrsa:crl.pem</info> -> ');

        if (file_exists($input->getOption('basedir') . '/global/openvpn/easyrsa/pki/crl.pem')) {
            $output->writeln('exists');
        } else {
            passthru('cd ' . escapeshellarg($input->getOption('basedir') . '/global/openvpn/easyrsa') . ' && ./easyrsa --vars=pki.vars gen-crl > /dev/null 2>&1');

            $output->writeln('created');
        }


        $output->write('> <info>easyrsa:dh.pem</info> -> ');

        if (file_exists($input->getOption('basedir') . '/global/openvpn/easyrsa/pki/dh.pem')) {
            $output->writeln('exists');
        } else {
            passthru('cd ' . escapeshellarg($input->getOption('basedir') . '/global/openvpn/easyrsa') . ' && ./easyrsa --vars=pki.vars gen-dh > /dev/null 2>&1');

            $output->writeln('created');
        }


        /**
         * gateway openvpn certificates
         */

        $this->requireDirectory($input, $output, 'global/openvpn/ccd');

        foreach ($network['regions'] as $regionName => $regionConfig) {
            if ('global' == $regionName) {
                continue;
            }

            $this->requireDirectory($input, $output, 'global/openvpn/ccd/' . $regionName);

            foreach ([
                'gateway' => 'server',
                'gateway-client' => 'client',
            ] as $cnshort => $cnrole) {
                $cn = $cnshort . '.' . $regionName . '.' . $network['root']['name'] . '.' . $network['root']['host'];

                $output->write('> <info>openvpn:' . $regionName . '/' . $cnshort . '/key</info> -> ');

                if (file_exists($input->getOption('basedir') . '/global/openvpn/easyrsa/pki/reqs/' . $cn . '.req')) {
                    $output->writeln('exists');
                } else {
                    passthru(
                        sprintf(
                            'openssl req -subj %s -days 3650 -nodes -new -out %s -newkey rsa:2048 -keyout %s',
                            escapeshellarg('/C=' . $network['about']['location']['country'] . '/ST=' . $network['about']['location']['territory'] . '/L=' . $network['about']['location']['city'] . '/O=' . $network['about']['name'] . '/OU=' . $network['root']['name'] . '/CN=' . $cn . '/emailAddress=' . $network['about']['email']),
                            escapeshellarg($input->getOption('basedir') . '/global/openvpn/easyrsa/pki/reqs/' . $cn . '.req'),
                            escapeshellarg($input->getOption('basedir') . '/global/openvpn/easyrsa/pki/private/' . $cn . '.key')
                        )
                    );

                    $output->writeln('created');
                }


                $output->write('> <info>openvpn:' . $regionName . '/' . $cnshort . '/certificate</info> -> ');

                if (file_exists($input->getOption('basedir') . '/global/openvpn/easyrsa/pki/issued/' . $cn . '.crt')) {
                    $output->writeln('exists');
                } else {
                    $output->writeln('creating...');

                    passthru('cd ' . escapeshellarg($input->getOption('basedir') . '/global/openvpn/easyrsa') . ' && ./easyrsa --vars=pki.vars sign ' . $cnrole . ' ' . $cn);
                }
            }
        }


        /**
         * bucket
         */


        $awsS3 = \Aws\S3\S3Client::factory();

        $output->write('> <info>aws:s3:bucket/' . $network['root']['bucket'] . '</info>...');

        $buckets = $awsS3->listBuckets();
        $found = false;

        foreach ($buckets['Buckets'] as $bucket) {
            if ($network['root']['bucket'] == $bucket['Name']) {
                $found = true;

                break;
            }
        }

        if ($found) {
            $output->writeln('exists');
        } else {
            $awsS3->createBucket([
                'Bucket' => $network['root']['bucket'],
                'LocationConstraint' => $network['regions']['global']['region'],
            ]);

            $output->writeln('created');
        }
    }
}
