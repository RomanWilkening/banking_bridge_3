<?php
declare(strict_types=1);

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

final class SessionCsrfMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (in_array(strtoupper($request->getMethod()), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $handler->handle($request);
        }

        $body = $request->getParsedBody();
        $token = $request->getHeaderLine('X-CSRF-Token');
        if ($token === '' && is_array($body)) {
            $token = $body['csrf_token'] ?? null;
        }
        $expected = $_SESSION['csrf_token'] ?? null;
        if (!is_string($token) || !is_string($expected) || $expected === '' || !hash_equals($expected, $token)) {
            $response = new Response(403);
            $response->getBody()->write('{"success":false,"message":"Ungültiges CSRF-Token. Bitte Seite neu laden."}');
            return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
        }

        return $handler->handle($request);
    }
}
