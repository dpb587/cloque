<?php

namespace BOSH\Console\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Yaml\Yaml;

class AWSHelpers
{
    public $ec2Client;

    public function __construct($ec2Client, $output)
    {
       $this->ec2Client = $ec2Client;
       $this->output = $output;
    }

    public function attachVolume($instanceId, $volumeId, $device, $maxRetries = 20)
    {
        $this->output->write(" Attaching volume $volumeId ..");
        $volumeAttached = false; $retries = 0;
        while (!$volumeAttached && $retries < $maxRetries) {
            try {
                $this->output->write('.');
                $this->ec2Client->attachVolume(array(
                    'VolumeId' => $volumeId,
                    'InstanceId' => $instanceId,
                    'Device' => $device
                ));
                $this->ec2Client->waitUntilVolumeAvailable(array(
                    'VolumeId' => $volumeId
                ));
                $volumeAttached = true;
            } catch (\Aws\Ec2\Exception\Ec2Exception $e) {
                $retries++;
                sleep(3);
            }
        }
    }

    public function detachVolume($volumeId)
    {
        $this->output->write(" Detaching volume $volumeId ...");
        $this->ec2Client->detachVolume(array(
            'VolumeId' => $volumeId
        ));
        $this->ec2Client->waitUntilVolumeAvailable(array(
            'VolumeId' => $volumeId
        ));
    }

    public function deleteVolume($volumeId)
    {
        $this->output->write(" Deleting volume $volumeId ...");
        $this->ec2Client->deleteVolume(array(
            'VolumeId' => $volumeId
        ));
    }

}
