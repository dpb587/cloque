<?php

namespace BOSH\Console;

class Manifest
{
    public static function getVersion()
    {
        if (('@@' . 'bosh-git-tag' . '@@') != '@@bosh-git-tag@@') {
            return ltrim('@@bosh-git-tag@@', 'v');
        }

        return 'dev';
    }
}