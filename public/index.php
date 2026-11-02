<?php

declare(strict_types=1);

use OvhDns\Kernel;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;

ini_set('display_errors', '0');
try {
    require dirname(__DIR__).'/bootstrap.php';
    $kernel = new Kernel($_SERVER['APP_ENV'] ?? $_ENV['APP_ENV'] ?? 'prod', false);
    $request = Request::createFromGlobals();
    $response = $kernel->handle($request);
    $response->send();
    $kernel->terminate($request, $response);
} catch (Throwable) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo '{"error":"Application unavailable; check configuration."}';
}
