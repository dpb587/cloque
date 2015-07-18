<?php

namespace BOSH\Console\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Command\Command;
use Aws\Ec2\Ec2Client;
use Symfony\Component\Yaml\Yaml;

class BoshUtilPackageDockerBuildCommand extends AbstractCommand
{
    protected function configure()
    {
        parent::configure()
            ->setName('boshutil:package-docker-build')
            ->setDescription('Debug the build process of a package in a Docker container')
            ->addArgument(
                'docker-from',
                InputArgument::REQUIRED,
                'Docker base image'
            )
            ->addArgument(
                'package',
                InputArgument::REQUIRED,
                'Package name'
            )
            ->addOption(
                'export-package',
                null,
                InputOption::VALUE_REQUIRED,
                'Export package path'
            )
            ->addOption(
                'import-package',
                null,
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Import package path'
            )
            ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $spec = Yaml::parse(file_get_contents('packages/' . $input->getArgument('package') . '/spec'));

        $cwd = getcwd();
        $mdir = uniqid($input->getOption('basedir') . '/compiled/tmp/bosh-debug-package-');
        mkdir($mdir . '/compile', 0700, true);
        mkdir($mdir . '/packages', 0700, true);

        $dockerfile = [
            'FROM ' . $input->getArgument('docker-from'),
            'RUN apt-get update && apt-get -y install build-essential cmake m4 unzip wget',
            'RUN /bin/echo "./packaging" >> ~/.bash_history',
            'ENTRYPOINT /bin/bash',
            'RUN mkdir -p /var/vcap/packages/' . $input->getArgument('package'),
            'ENV BOSH_COMPILE_TARGET /var/vcap/data/compile/' . $input->getArgument('package'),
            'ENV BOSH_INSTALL_TARGET /var/vcap/packages/' . $input->getArgument('package'),
            'WORKDIR /var/vcap/data/compile/' . $input->getArgument('package'),
            'ADD compile /var/vcap/data/compile/' . $input->getArgument('package'),
        ];

        $output->write('> <info>compile/packaging</info>...');

        copy('packages/' . $input->getArgument('package') . '/packaging', $mdir . '/compile/packaging');
        chmod($mdir . '/compile/packaging', 0755);

        $output->writeln('done');


        foreach ($spec['files'] as $globfile) {
            $globfiles = glob('src/' . $globfile);
            
            if (!$globfiles) {
                $globfiles = glob('blobs/' . $globfile);
            }

            foreach ($globfiles as $file) {
                $tfile = preg_replace('#^(src/|blobs/)#', '', $file);

                $output->write('> <info>compile/' . $tfile . '</info>...');

                if (!file_exists($mdir . '/compile/' . dirname($tfile))) {
                    mkdir($mdir . '/compile/' . dirname($tfile), 0700, true);
                }

                passthru('cp -rp ' . escapeshellarg($cwd . '/' . $file) . ' ' . escapeshellarg($mdir . '/compile/' . $tfile));

                $output->writeln('done');
            }
        }


        foreach ($input->getOption('import-package') as $package) {
            $output->write('> <info>import:' . $package . '</info>...');

            copy($package, $mdir . '/packages/' . basename($package));
            $dockerfile[] = 'ADD packages/' . basename($package) . ' /var/vcap/packages';

            $output->writeln('done');
        }


        $output->write('> <info>Dockerfile</info>...');

        file_put_contents($mdir . '/Dockerfile', implode("\n", $dockerfile));

        $output->writeln('done');


        $output->writeln('> <comment>building</comment>...');

        passthru('cd ' . escapeshellarg($mdir) . ' && find . -exec touch -ht 200001010000.00 {} ";" && docker build -t ' . basename($mdir) . ' .');


        $output->write('> <comment>removing cache</comment>...');

        passthru('rm -fr ' . escapeshellarg($mdir));

        $output->writeln('done');


        $output->writeln('> <comment>running</comment>...');

        $descriptorspec = array(
           0 => STDIN,
           1 => STDOUT,
           2 => STDERR,
        );

        $ph = proc_open(
            'docker run --cidfile ' . escapeshellarg($mdir . '.cid') . ' -t -i ' . basename($mdir),
            $descriptorspec,
            $pipes,
            getcwd(),
            null
        );

        $status = proc_get_status($ph);

        do {
            pcntl_waitpid($status['pid'], $pidstatus);
        } while (!pcntl_wifexited($pidstatus));


        if ($input->getOption('export-package')) {
            $output->write('> <comment>exporting package</comment>...');

            passthru('docker cp ' . file_get_contents($mdir . '.cid') . ':/var/vcap/packages/' . $input->getArgument('package') . ' ' . escapeshellarg($mdir . '-export'));
            passthru('tar -czf ' . escapeshellarg($input->getOption('export-package')) . ' -C ' . escapeshellarg($mdir . '-export') . ' ' . $input->getArgument('package'));
            passthru('rm -fr ' . escapeshellarg($mdir . '-export'));

            $output->writeln('done');
        }


        $output->write('> <comment>removing container</comment>...');

        passthru('docker rm ' . file_get_contents($mdir . '.cid') . ' > /dev/null');
        unlink($mdir . '.cid');

        $output->writeln('done');


        #$output->writeln('> <comment>removing image</comment>...');

        #passthru('docker rmi ' . basename($mdir) . ' > /dev/null');

        #$output->writeln('done');


        return pcntl_wexitstatus($pidstatus);
    }
}
