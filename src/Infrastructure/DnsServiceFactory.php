<?php

declare(strict_types=1);

namespace OvhDns\Infrastructure;

use OvhDns\Configuration;
use OvhDns\Dns\DnsService;
use OvhDns\Dns\OvhDnsClient;

final class DnsServiceFactory
{
    public static function create(): DnsService
    {
        return new DnsService(static fn (): OvhDnsClient => new OvhDnsClient(Configuration::createApi()));
    }
}
