<?php

declare(strict_types=1);

namespace OvhDns\Health;

interface TlsProbe
{
    /** The address is already validated and must be pinned with the endpoint hostname retained as SNI. */
    public function inspect(Endpoint $endpoint, string $address, Budget $budget): TlsObservation;
}
