<?php

declare(strict_types=1);

namespace OvhDns\Provider;

/** Only sanitized, locally generated messages belong here. */
class ProviderException extends \RuntimeException
{
    public function __construct(string $message, public readonly bool $outcomeUnknown = false) { parent::__construct($message); }
}
