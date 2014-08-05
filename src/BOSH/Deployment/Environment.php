<?php

namespace BOSH\Deployment;

use Symfony\Component\Yaml\Yaml;

class Environment implements \ArrayAccess
{
    protected $basedir;
    protected $basename;
    protected $localityName;
    protected $deploymentName;
    protected $cache = [];

    public function __construct($basedir, $basename, $localityName, $deploymentName)
    {
        $this->basedir = $basedir;
        $this->basename = $basename;
        $this->localityName = $localityName;
        $this->deploymentName = $deploymentName;
    }

    public function offsetExists($offset)
    {
        return null !== $this[$offset];
    }

    public function offsetGet($offset)
    {
        if (!isset($this->cache[$offset])) {
            if ('bosh' == $offset) {
                $this->cache[$offset] = Yaml::parse(file_get_contents($this->basedir . '/' . $this->localityName . '/.bosh_config'));
            } elseif ('global.private.aws' == $offset) {
                $this->cache[$offset] = Yaml::parse(file_get_contents($this->basedir . '/global/private/aws.yml'));
            } elseif ('network' == $offset) {
                $this->cache[$offset] = Yaml::parse(file_get_contents($this->basedir . '/' . $this->localityName . '/../network.yml'));
            } elseif ('network.local' == $offset) {
                $this->cache[$offset] = $this['network']['regions'][($this->basename ? ($this->basename . '-') : '') . $this->localityName];
            } elseif (preg_match('#^(?<locality>[^/]+)/infrastructure/(?<deployment>[^/]+)(/(?<component>[^/]+))?$#', $offset, $match)) {
                $this->cache[$offset] = json_decode(file_get_contents($this->basedir . '/compiled/' . (('self' == $match['locality']) ? $this->localityName : $match['locality']) . '/' . $match['deployment'] . '/infrastructure' . (!empty($match['component']) ? ('-' . $match['component']) : '') . '--state.json'), true);
            } else {
                throw new \RuntimeException('Unable to find "' . $offset . '"');
            }
        }

        return $this->cache[$offset];
    }

    public function offsetSet($offset, $value)
    {
        throw new \BadMethodCallException();
    }

    public function offsetUnset($offset)
    {
        throw new \BadMethodCallException();
    }

    public function embedRaw($path)
    {
        return file_get_contents($path);
    }

    public function embed($path)
    {
        // symfony yaml has some issue with losing escape sequences of embedded files
        return '{*embed*' . realpath($path) . '}';
    }
}
