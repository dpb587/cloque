<?php

namespace BOSH\Console\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Command\Command;
use BOSH\Deployment\ManifestModel;
use Symfony\Component\Yaml\Yaml;

class BoshDirectorSnapshotsCleanupCommand extends AbstractDirectorDeploymentCommand
{
    protected function configure()
    {
        parent::configure()
            ->setName('boshdirector:snapshots:cleanup')
            ->setDescription('Cleanup snapshots according to retention policies')
            ->addArgument(
                'jobid',
                InputArgument::OPTIONAL,
                'Job/index to connect'
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Do not perform deletions',
                null
            )
            ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $destManifest = sprintf(
            '%s/compiled/%s/%s/bosh%s.yml',
            $input->getOption('basedir'),
            $input->getOption('director'),
            $input->getOption('deployment'),
            $input->getOption('component') ? ('-' . $input->getOption('component')) : ''
        );

        $jobid = explode('/', $input->getArgument('jobid'), 2);
        $index = isset($jobid[1]) ? $jobid[1] : null;
        $job = isset($jobid[0]) ? $jobid[0] : null;

        $directorDir = $input->getOption('basedir') . '/' . $input->getOption('director');

        exec(
            sprintf(
                'bosh %s %s -c %s -d %s snapshots %s %s',
                $output->isDecorated() ? '--color' : '--no-color',
                $input->isInteractive() ? '' : '--non-interactive',
                escapeshellarg($directorDir . '/.bosh_config'),
                escapeshellarg($destManifest),
                (null !== $job) ? escapeshellarg($job) : '',
                (null !== $index) ? escapeshellarg($index) : ''
            ),
            $stdout,
            $return_var
        );

        if ($return_var) {
            throw new \RuntimeException('Exit code was ' . $return_var);
        }

        $snapshots = [];

        foreach ($stdout as $line) {
            if (!preg_match('/^\|(?P<jobindex>[^\|]+)\|(?P<snapshot_cid>[^\|]+)\|(?P<created_at>[^\|]+)\|(?P<clean>[^\|]+)\|$/', trim($line), $match)) {
                continue;
            }

            $match = array_map('trim', $match);

            if ('Clean' == $match['clean']) {
                continue;
            }

            $jobindex = explode('/', trim($match['jobindex']));

            $snapshots[] = [
                'job' => $jobindex[0],
                'index' => (int) $jobindex[1],
                'snapshot_cid' => trim($match['snapshot_cid']),
                'created_at' => new \DateTime(trim($match['created_at'])),
                'clean' => 'true' == trim($match['clean']),
            ];
        }

        $checker = false;

        foreach ([
            $input->getOption('director') . '/' . $input->getOption('deployment') . '/cloque/bosh-snapshot-cleanup.php',
            $input->getOption('director') . '/common/cloque/bosh-snapshot-cleanup.php',
            'common/cloque/bosh-snapshot-cleanup.php',
        ] as $checkerPath) {
            $checkerPath = $input->getOption('basedir') . '/' . $checkerPath;

            if (file_exists($checkerPath)) {
                $checker = include_once $checkerPath;

                break;
            }
        }

        if (!$checker) {
            throw new \RuntimeException('Unable to find snapshot cleanup logic');
        }

        foreach ($snapshots as $snapshot) {
            $output->write('<info>' . $snapshot['snapshot_cid'] . '</info> -> ' . $snapshot['created_at']->format('c') . ' -> ' . ($snapshot['clean'] ? 'clean' : 'dirty') . ' -> ');

            if ($checker($snapshot, $input, $output)) {
                if (!$input->getOption('dry-run')) {
                    unset($stdout);
                    exec(
                        sprintf(
                            'bosh --non-interactive -c %s -d %s delete snapshot %s',
                            escapeshellarg($directorDir . '/.bosh_config'),
                            escapeshellarg($destManifest),
                            $snapshot['snapshot_cid']
                        ),
                        $stdout,
                        $return_var
                    );

                    if ($return_var) {
                        $output->writeln('<error>failed</error>');

                        $output->writeln(implode("\n", $stdout));

                        return 1;
                    }

                    $output->writeln('deleted');
                } else {
                    $output->writeln('[dry-run] deleted');
                }
            } else {
                $output->writeln('retained');
            }
        }
    }
}
