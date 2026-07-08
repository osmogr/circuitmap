<?php

declare(strict_types=1);

namespace CircuitMap\Services\Cacti;

/**
 * The Cacti database could not be reached or queried. Callers treat this
 * as "no fresh data this cycle", never as "everything is down".
 */
final class CactiUnavailableException extends \RuntimeException
{
}
