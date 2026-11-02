<?php

declare(strict_types=1);

namespace OvhDns\Health;

final readonly class TlsObservation
{
    public function __construct(public string $certificate, public bool $verified, public string $address) {}
}
