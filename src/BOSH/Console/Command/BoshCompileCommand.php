<?php

namespace BOSH\Console\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Command\Command;
use BOSH\Deployment\TemplateEngine;
use Symfony\Component\Yaml\Yaml;

class BoshCompileCommand extends Command
{
    protected function configure()
    {
        $this
            ->setName('bosh:compile')
            ->setDescription('Recompile the configuration for a BOSH deployment')
            ->setDefinition(
                [
                    new InputArgument(
                        'locality',
                        InputArgument::REQUIRED,
                        'Locality name'
                    ),
                    new InputArgument(
                        'deployment',
                        InputArgument::REQUIRED,
                        'Deployment name'
                    ),
                    new InputOption(
                        'component',
                        null,
                        InputOption::VALUE_REQUIRED,
                        'Component name',
                        null
                    ),
                ]
            )
            ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $sourceManifest = sprintf(
            '%s/%s/%s/bosh%s.yml',
            $input->getOption('basedir'),
            $input->getArgument('locality'),
            $input->getArgument('deployment'),
            $input->getOption('component') ? ('-' . $input->getOption('component')) : ''
        );

        $destManifest = sprintf(
            '%s/compiled/%s/%s/bosh%s.yml',
            $input->getOption('basedir'),
            $input->getArgument('locality'),
            $input->getArgument('deployment'),
            $input->getOption('component') ? ('-' . $input->getOption('component')) : ''
        );

        if (!is_dir(dirname($destManifest))) {
            mkdir(dirname($destManifest), 0700, true);
        }

        chdir(dirname($sourceManifest));

        $engine = new TemplateEngine(
            $input->getOption('basedir'),
            $input->getArgument('locality'),
            $input->getArgument('deployment')
        );

        $result = file_get_contents($sourceManifest);
        $result = $engine->render($result);
        $result = Yaml::parse($result);

        $transformers = [];

        if (isset($result['_transformers'])) {
            call_user_func_array('array_push', array_merge([ &$transformers ], $result['_transformers']));
            unset($result['_transformers']);
        }

        for ($i = 0; $i < count($transformers); $i += 1) {
            $transformer = $transformers[$i];

            if (isset($transformer['function'])) {
                switch ($transformer['function']) {
                    case 'deepmerge':
                        if (!empty($transformer['reverse'])) {
                            $result = array_merge_recursive(
                                $result,
                                Yaml::parse($engine->render(file_get_contents($transformer['path'])))
                            );
                        } else {
                            $result = array_merge_recursive(
                                Yaml::parse($engine->render(file_get_contents($transformer['path']))),
                                $result
                            );
                        }

                        break;
                    default:
                        throw new \RuntimeException('Unexpected transform function: ' . $transformer['function']);
                }
            } else {
                $php = include $transformer['path'];

                $result = call_user_func_array(
                    $php,
                    [
                        $result,
                        isset($transformer['options']) ? $transformer['options'] : [],
                        $engine->getParams(),
                    ]
                );
            }

            if (isset($result['_transformers'])) {
                call_user_func_array('array_push', array_merge([ &$transformers ], $result['_transformers']));
                unset($result['_transformers']);
            }
        }

        function recur_ksort(&$array) {
           foreach ($array as &$value) {
              if (is_array($value)) recur_ksort($value);
           }

           return ksort($array);
        }

        recur_ksort($result);

        $lines = explode("\n", Yaml::dump($result, 8));

        foreach ($lines as $i => $line) {
            if (!preg_match('/^(\s+)([^:]+):\s+("|\')\{\*embed\*([^\}]+)\}\3$/', $line, $match)) {
                continue;
            }

            $value = file_get_contents($match[4]);
            $indent = ' ' . $match[1] . str_repeat(' ', strlen($match[2]));
            $lines[$i] = $match[1] . $match[2] . ': |' . "\n" . $indent . str_replace("\n", "\n" . $indent, $value);
        }

        // bosh runs it through erb, so make them literal
        $result = str_replace('<%', '<%= "<%" %>', implode("\n", $lines));

        file_put_contents($destManifest, $result);
    }
}
