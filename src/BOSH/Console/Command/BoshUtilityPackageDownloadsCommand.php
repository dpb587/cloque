<?php

namespace BOSH\Console\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Command\Command;
use Aws\Ec2\Ec2Client;
use Symfony\Component\Yaml\Yaml;

class BoshUtilityPackageDownloadsCommand extends Command
{
    protected function configure()
    {
        $this
            ->setName('bosh:utility:package-downloads')
            ->setDescription('Dump commands to download packaging spec files')
            ->setDefinition(
                [
                    new InputArgument(
                        'file',
                        InputArgument::REQUIRED | InputArgument::IS_ARRAY,
                        'Specification file'
                    ),
                ]
            )
            ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $dirs = [];

        foreach ($input->getArgument('file') as $file) {
            $spec = file_get_contents('packages/' . $file . '/spec');

            #preg_match_all("/\s*#\s*(.*)\n\s*\-\s+(\"|')?(.*)\1/m", $spec, $matches, PREG_SET_ORDER);
            preg_match_all("/\s*#\s*(.*)\n\s*\-\s+(\")?([^'\"]+)\\2/m", $spec, $matches, PREG_SET_ORDER);

            foreach ($matches as $match) {
                $dir = dirname($match[3]);

                if (!isset($dirs[$dir])) {
                    $output->writeln('mkdir -p ' . escapeshellarg('blobs/' . $dir));
                    $dirs[$dir] = true;
                }

                $output->writeln('[ -f ' . escapeshellarg('blobs/' . $match[3]) . ' ] || wget -O ' . escapeshellarg('blobs/' . $match[3]) . ' ' . escapeshellarg($match[1]));
            }
        }
    }
}
