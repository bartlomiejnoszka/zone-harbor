<?php

declare(strict_types=1);

namespace OvhDns\Provider;

final readonly class Capabilities
{
    /** @param list<string> $recordTypes */
    public function __construct(public array $recordTypes, public bool $publication, public bool $nativeExport) {}
}
