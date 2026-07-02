<?php

declare(strict_types=1);

namespace CircuitMap\Support;

use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Response as SlimResponse;

final class Response
{
    /**
     * @param array<mixed> $data
     */
    public static function json(array $data, int $status = 200): ResponseInterface
    {
        $response = new SlimResponse($status);
        $response->getBody()->write((string) json_encode($data, JSON_UNESCAPED_SLASHES));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
