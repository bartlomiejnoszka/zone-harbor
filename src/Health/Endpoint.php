<?php

declare(strict_types=1);

namespace OvhDns\Health;

use OvhDns\Dns\DnsInput;

final readonly class Endpoint
{
    public string $host;
    public function __construct(string $host, public int $port = 443, public bool $startTls = false)
    {
        $this->host = DnsInput::zone($host);
        if (!in_array($port, [443, 465, 587, 993], true) || ($startTls !== ($port === 587))) {
            throw new \InvalidArgumentException('Supported probes: TLS on 443, 465, 993; SMTP STARTTLS on 587.');
        }
    }
    public function key(): string { return $this->host.':'.$this->port; }
}
