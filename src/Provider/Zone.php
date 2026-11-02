<?php

declare(strict_types=1);

namespace OvhDns\Provider;

final readonly class Zone
{
    public function __construct(public string $name, public string $reference) {}
}
