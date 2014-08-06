<?php

namespace BOSH\Deployment;

class TemplateEngine
{
    protected $basedir;
    protected $directorName;
    protected $deploymentName;
    protected $params;
    protected $twig;

    public function __construct($basedir, $directorName, $deploymentName, array $params = [])
    {
        $this->basedir = $basedir;
        $this->directorName = $directorName;
        $this->deploymentName = $deploymentName;

        $env = new Environment($this->basedir, $this->directorName, $this->deploymentName);
        $this->params = [
            'network_name' => $env['network']['root']['name'],
            'director_name' => $this->directorName,
            'deployment' => $this->deploymentName,
            'env' => $env,
        ];

        $this->twig = new \Twig_Environment(
            new \Twig_Loader_String(),
            [
                'autoescape' => false,
                'strict_variables' => true,
            ]
        );

        $this->twig->addFilter(new \Twig_SimpleFilter('cidr_network', function ($value) {
            list($network, $mask) = explode('/', $value, 2);

            return $network;
        }));

        $this->twig->addFilter(new \Twig_SimpleFilter('cidr_netmask', function ($value) {
            list($network, $mask) = explode('/', $value, 2);

            return $mask;
        }));

        $this->twig->addFilter(new \Twig_SimpleFilter('cidr_netmask_ext', function ($value) {
            list($network, $mask) = explode('/', $value, 2);

            $netmask = [
                '0' => '0.0.0.0',
                '1' => '128.0.0.0',
                '2' => '192.0.0.0',
                '3' => '224.0.0.0',
                '4' => '240.0.0.0',
                '5' => '248.0.0.0',
                '6' => '252.0.0.0',
                '7' => '254.0.0.0',
                '8' => '255.0.0.0',

                '9' => '255.128.0.0',
                '10' => '255.192.0.0',
                '11' => '255.224.0.0',
                '12' => '255.240.0.0',
                '13' => '255.248.0.0',
                '14' => '255.252.0.0',
                '15' => '255.254.0.0',
                '16' => '255.255.0.0',

                '17' => '255.255.128.0',
                '18' => '255.255.192.0',
                '19' => '255.255.224.0',
                '20' => '255.255.240.0',
                '21' => '255.255.248.0',
                '22' => '255.255.252.0',
                '23' => '255.255.254.0',
                '24' => '255.255.255.0',

                '25' => '255.255.255.128',
                '26' => '255.255.255.192',
                '27' => '255.255.255.224',
                '28' => '255.255.255.240',
                '29' => '255.255.255.248',
                '30' => '255.255.255.252',
                '31' => '255.255.255.254',
                '32' => '255.255.255.255',
            ];

            return $netmask[$mask];
        }));
    }

    public function render($template, array $params = [])
    {
        return $this->twig->render($template, array_merge($this->params, $params));
    }

    public function getParams()
    {
        return $this->params;
    }
}
