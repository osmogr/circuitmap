<?php

declare(strict_types=1);

namespace CircuitMap\Support;

use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Trusts X-Forwarded-For, since the app is designed to sit behind an
 * external reverse proxy on infrastructure the operator controls (see
 * README assumptions). If deployed directly exposed to untrusted clients
 * without a proxy, this header would be spoofable.
 */
final class ClientIp
{
    public static function from(Request $request): string
    {
        $forwarded = $request->getHeaderLine('X-Forwarded-For');
        if ($forwarded !== '') {
            return trim(explode(',', $forwarded)[0]);
        }

        $server = $request->getServerParams();
        return is_string($server['REMOTE_ADDR'] ?? null) ? $server['REMOTE_ADDR'] : 'unknown';
    }
}
