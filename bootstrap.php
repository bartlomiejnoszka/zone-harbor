<?php

declare(strict_types=1);

use OvhDns\Configuration;

require __DIR__.'/vendor/autoload.php';

Configuration::loadEnvironment(__DIR__);
Configuration::loadSecretFiles();
