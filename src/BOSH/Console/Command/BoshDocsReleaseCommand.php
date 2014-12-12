<?php

namespace BOSH\Console\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Command\Command;
use Aws\Ec2\Ec2Client;
use Symfony\Component\Yaml\Yaml;

class BoshDocsReleaseCommand extends AbstractCommand
{
    protected function configure()
    {
        parent::configure()
            ->setName('boshdocs:release')
            ->setDescription('Generate a job/package summary of a release')
            ->addOption(
                'release-readme',
                null,
                InputOption::VALUE_REQUIRED,
                'A path to some summary notes to include'
            )
            ->addOption(
                'release-home',
                null,
                InputOption::VALUE_REQUIRED,
                'A URL path to some release notes to include'
            )
            ->addOption(
                'version-artifact',
                null,
                InputOption::VALUE_REQUIRED,
                'A path to the release artifact'
            )
            ->addOption(
                'version-readme',
                null,
                InputOption::VALUE_REQUIRED,
                'A path to some release notes to include'
            )
            ->addArgument(
                'release-spec',
                InputArgument::REQUIRED,
                'Release specification file'
            )
            ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $releaseSpec = Yaml::parse($this->indentyaml(file_get_contents($input->getArgument('release-spec'))));

        $releaseSpec['_artifact'] = $input->getOption('version-artifact');
        $releaseSpec['_notes'] = $input->getOption('version-readme');

        if ($releaseSpec['_notes']) {
            $releaseSpec['_notes'] = file_get_contents($releaseSpec['_notes']);
        }

        $releaseSpec['release_readme'] = $input->getOption('release-readme');
        $releaseSpec['release_home'] = $input->getOption('release-home');

        if ($releaseSpec['release_readme']) {
            $releaseSpec['release_readme'] = file_get_contents($releaseSpec['release_readme']);
        }

        foreach ($releaseSpec['packages'] as $packageIdx => &$package) {
            $tgz = '/packages/' . $package['name'] . '/' . $package['version'] . '.tgz';
            $tgz = (file_exists('.dev_builds' . $tgz) ? '.dev_builds' : '.final_builds') . $tgz;

            $package['size'] = filesize($tgz);

            if (!empty($package['dependencies'])) {
                sort($package['dependencies']);
            }

            $package['spec'] = YAML::parse($this->indentyaml(file_get_contents('packages/' . $package['name'] . '/spec')));

            $filemap = $this->parsetarlist(shell_exec('tar -ztvf ' . escapeshellarg($tgz)));

            foreach ($package['spec']['files'] as $path) {
                $size = 0;

                foreach ($filemap as $fileone) {
                    if (preg_match('#^\./' . $path . '(/|$)#', $fileone['path'])) {
                        $size += $fileone['size'];
                    }
                }

                $package['filesize'][$path] = $size;
            }
        }

        $allProperties = [];

        foreach ($releaseSpec['jobs'] as $jobIdx => &$job) {
            $tgz = '/jobs/' . $job['name'] . '/' . $job['version'] . '.tgz';
            $tgz = (file_exists('.dev_builds' . $tgz) ? '.dev_builds' : '.final_builds') . $tgz;

            $job['size'] = filesize($tgz);
            $job['spec'] = YAML::parse($this->indentyaml(shell_exec('tar -xzOf ' . escapeshellarg($tgz) . ' ./job.MF')));

            if (!empty($job['spec']['packages'])) {
                sort($job['spec']['packages']);
            }

            ksort($job['spec']['properties']);

            foreach ($job['spec']['properties'] as $propertyKey => $property) {
                $allProperties[$propertyKey][] = $job['name'];
            }
        }

        ksort($allProperties);

        foreach ($allProperties as &$jobs) {
            sort($jobs);
        }

        $releaseSpec['all_properties'] = $allProperties;

        $twig = new \Twig_Environment(
            new \Twig_Loader_String(),
            [
                'autoescape' => false,
                'strict_variables' => true,
            ]
        );

        $twig->addFilter(new \Twig_SimpleFilter('yaml_encode', function ($value, $depth = 8) {
            return YAML::dump($value, $depth);
        }));

        $twig->addFilter(new \Twig_SimpleFilter('filesize_format', function ($value, $round = 1) {
            if ($value >= 1073741824) {
                return number_format($value / 1073741824, $round) . ' GB';
            } elseif ($value >= 1048576) {
                return number_format($value / 1048576, $round) . ' MB';
            } elseif ($value >= 1024) {
                return number_format($value / 1024, $round) . ' KB';
            } else {
                return $value . ' B';
            }
        }));

        $twig->addFilter(new \Twig_SimpleFilter('markdown', function ($value) {
            require_once __DIR__ . '/../../Util/markdown.php';

            return Markdown($value);
        }, [ 'is_safe' => 'html' ]));



        $output->writeln($twig->render(
            file_get_contents(__DIR__ . '/../../Resources/templates/boshdocs-release.html.twig'),
            $releaseSpec
        ));
    }

    // symfony/yaml doesn't like the following
    //
    //     root:
    //     - child1: foo
    //       child2: bar
    //
    // and needs it to look like
    //
    //     root:
    //       - child1: foo
    //         child2: bar
    private function indentyaml($contents)
    {
        $lines = explode("\n", $contents);
        $indent = 0;

        foreach ($lines as &$line) {
            if ($indent) {
                if (str_repeat('  ', $indent) != substr($line, 0, $indent * 2)) {
                    if (preg_match('/^(\s+)/', $line, $match)) {
                        $indent = strlen($match[1]) / 2;
                    } else {
                        $indent = 0;
                    }
                }

                $line = str_repeat('  ', $indent) . $line;
            }

            if (preg_match('/^(\s*\- )([^\s"]+):/', $line)) {
                if ((str_repeat('  ', $indent) . ' -') != substr($line, 0, 2 + $indent * 2)) {
                    $line = '  ' . $line;
                }

                $indent += 1;
            }
        }

        $contents = implode("\n", $lines);

        return $contents;
    }

    private function parsetarlist($contents)
    {
        $lines = explode("\n", trim($contents));
        $result = [];

        foreach ($lines as $line) {
            preg_match(
                '/^(?P<flags>.{11})\s+(?P<stat>\d+)\s+(?P<user>[^ ]+)\s+(?P<group>[^ ]+)\s+(?P<size>\d+)\s+(?P<date>.{12})\s+(?<path>.+)$/',
                $line,
                $match
            );

            $result[] = $match;
        }

        return $result;
    }
}
