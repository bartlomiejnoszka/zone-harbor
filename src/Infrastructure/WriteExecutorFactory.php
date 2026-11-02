<?php

declare(strict_types=1);

namespace OvhDns\Infrastructure;

use OvhDns\Configuration;
use OvhDns\Dns\DnsService;
use OvhDns\Operation\WriteExecutor;

final class WriteExecutorFactory
{
    public static function create(DnsService $dns): WriteExecutor { return new WriteExecutor($dns, Configuration::operationDirectory(...)); }
}
