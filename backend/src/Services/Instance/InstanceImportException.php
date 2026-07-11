<?php

declare(strict_types=1);

namespace CircuitMap\Services\Instance;

/**
 * Import validation/restore failure whose message is safe to show to the
 * admin performing the import.
 */
final class InstanceImportException extends \RuntimeException
{
}
