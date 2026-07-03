<?php

declare(strict_types=1);

namespace CircuitMap\Support;

use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Resolves the client IP used for rate-limiting keys and audit logging.
 *
 * X-Forwarded-For is a client-supplied header. A reverse proxy that appends
 * to it (nginx's `$proxy_add_x_forwarded_for`) leaves any value the client
 * itself sent in the LEFTMOST positions and appends the address it actually
 * observed on the RIGHT. So the leftmost entry is attacker-controlled and
 * must never be trusted: taking it would let a client forge its IP to evade
 * the IP-keyed login rate limiter or poison audit-log entries, even with a
 * proxy in front.
 *
 * Instead we trust only the rightmost TRUSTED_PROXY_COUNT entries (the ones
 * appended by proxies we operate) and treat the leftmost of that trusted
 * span as the real client. With the default single proxy, that is simply the
 * rightmost XFF entry. Set TRUSTED_PROXY_COUNT to the number of trusted
 * proxies between the client and this app; set it to 0 to ignore XFF
 * entirely and always use REMOTE_ADDR (correct when the app is exposed
 * directly with no proxy).
 */
final class ClientIp
{
    public static function from(Request $request): string
    {
        $server = $request->getServerParams();
        $remoteAddr = is_string($server['REMOTE_ADDR'] ?? null) ? $server['REMOTE_ADDR'] : 'unknown';

        $trustedProxyCount = Env::getInt('TRUSTED_PROXY_COUNT', 1);
        if ($trustedProxyCount < 1) {
            return $remoteAddr;
        }

        $forwarded = $request->getHeaderLine('X-Forwarded-For');
        if ($forwarded === '') {
            return $remoteAddr;
        }

        $entries = array_values(array_filter(
            array_map('trim', explode(',', $forwarded)),
            static fn (string $entry): bool => $entry !== ''
        ));
        if ($entries === []) {
            return $remoteAddr;
        }

        // Trust only the rightmost $trustedProxyCount entries; the leftmost of
        // that span is the closest-to-client hop we can still vouch for.
        // Clamp to 0 so a client sending fewer entries than expected (or
        // trying to short the list) can't push the index negative.
        $index = max(0, count($entries) - $trustedProxyCount);

        return $entries[$index];
    }
}
