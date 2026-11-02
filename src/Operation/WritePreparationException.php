<?php

declare(strict_types=1);

namespace OvhDns\Operation;

/** A failure known to precede any mutating OVH request. Safe for the caller. */
final class WritePreparationException extends \RuntimeException {}
