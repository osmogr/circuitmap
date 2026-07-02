<?php

declare(strict_types=1);

namespace CircuitMap\Middleware;

use CircuitMap\Services\RateLimit\RateLimiterService;
use CircuitMap\Support\ClientIp;
use CircuitMap\Support\Response as ResponseHelper;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;

final class RateLimitMiddleware implements MiddlewareInterface
{
    /**
     * @param 'ip'|'user' $keyBy 'ip' for unauthenticated endpoints like
     *   login (keyed by client IP), 'user' for authenticated endpoints
     *   like upload/edit (keyed by session user id).
     */
    public function __construct(
        private readonly RateLimiterService $limiter,
        private readonly string $bucketPrefix,
        private readonly int $windowSeconds,
        private readonly int $maxHits,
        private readonly string $keyBy = 'ip'
    ) {
    }

    public function process(Request $request, RequestHandler $handler): Response
    {
        $identity = $this->keyBy === 'user'
            ? 'user:' . ($_SESSION['user_id'] ?? 'anonymous')
            : 'ip:' . ClientIp::from($request);

        $bucketKey = $this->bucketPrefix . ':' . $identity;

        if (!$this->limiter->attempt($bucketKey, $this->windowSeconds, $this->maxHits)) {
            return ResponseHelper::json(['error' => 'Too many requests, try again later'], 429);
        }

        return $handler->handle($request);
    }
}
