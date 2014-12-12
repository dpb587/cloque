<?php

namespace BOSH\Console\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Command\Command;

class BoshDirectorInceptionProvisionCommand extends AbstractDirectorCommand
{
    protected function configure()
    {
        parent::configure()
            ->setName('boshdirector:inception:provision')
            ->setDescription('Provision the BOSH')
            ->addArgument(
                'stemcell',
                InputArgument::REQUIRED,
                'BOSH AMI or Stemcell URL'
            )
            ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $output->writeln('><comment>Deploying microBOSH</comment>...');
        $h = new \BOSH\Console\Command\BoshDirectorHelpers($input, $output);

        $inceptionIp = $h->getInceptionInstanceDetails()['PrivateIpAddress'];

        $output->writeln('> <comment>deploying</comment>...');

        $stemcell = $input->getArgument('stemcell');
        $ami = preg_match('/^ami-/', $stemcell);

        $output->writeln('  > <info>deploying microBOSH</info>...');
        $h->runOnServer('ubuntu', $inceptionIp, [
            'set -e',
            'cd ~/cloque/self',
            !$ami ? ('echo "    > Downloading stemcell (this takes a few minutes)..." ; wget -q -O ' . escapeshellarg(basename($stemcell)) . ' ' . escapeshellarg($stemcell)) : "echo '    > Using AMI: $stemcell'",
            'bosh micro deployment bosh/bosh.yml',
            'bosh -n micro deploy --update-if-exists ' . escapeshellarg(($ami ? $stemcell : basename($stemcell))),
        ]);

        $h->fetchBoshDeployments($inceptionIp);

        $h->tagDirectorResources();

        $output->writeln('> <info>microBOSH deployed successfully</info>');

    }

}
