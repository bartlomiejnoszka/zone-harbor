<?php

declare(strict_types=1);

namespace OvhDns\Health;

interface DnsResolver
{
    public function query(string $name, string $type, string $server, bool $recursive, float $timeout): DnsAnswer;
}
