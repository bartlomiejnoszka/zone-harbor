<?php

declare(strict_types=1);

namespace OvhDns\Health;

enum Status: string
{
    case PASS = 'PASS'; case WARN = 'WARN'; case FAIL = 'FAIL'; case UNKNOWN = 'UNKNOWN'; case SKIP = 'SKIP';
}
