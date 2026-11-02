<?php

declare(strict_types=1);

use OvhDns\Configuration;

require __DIR__.'/vendor/autoload.php';

umask(0077);
Configuration::initialize(__DIR__);
