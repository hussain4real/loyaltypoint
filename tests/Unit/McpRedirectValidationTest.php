<?php

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Laravel\Mcp\Server\Http\Controllers\OAuthRegisterController;

test('MCP loopback redirects use the parsed host without user info', function (string $uri, bool $allowed) {
    $method = new ReflectionMethod(OAuthRegisterController::class, 'isLocalhostUrl');

    expect($method->invoke(new OAuthRegisterController, $uri))->toBe($allowed);
})->with([
    'IPv4 loopback with port' => ['http://127.0.0.1:8765/callback', true],
    'IPv6 loopback with port' => ['http://[::1]:8765/callback', true],
    'localhost with port' => ['http://localhost:8765/callback', true],
    'attacker host suffix' => ['http://127.0.0.1.attacker.test/callback', false],
    'localhost host suffix' => ['http://localhost.attacker.test/callback', false],
    'IPv4 user info' => ['http://127.0.0.1@attacker.test/callback', false],
    'localhost user info' => ['http://localhost:password@attacker.test/callback', false],
    'IPv6 user info' => ['http://[::1]@attacker.test/callback', false],
    'encoded user info' => ['http://127.0.0.1%40localhost@attacker.test/callback', false],
    'HTTPS is not a loopback exception' => ['https://localhost/callback', false],
]);

test('MCP redirect validation preserves configured custom schemes and rejects user info', function (string $uri, bool $allowed) {
    $previous = Container::getInstance();
    $container = new Container;
    $container->instance('config', new Repository(['mcp' => ['custom_schemes' => ['myapp']]]));
    Container::setInstance($container);

    try {
        $method = new ReflectionMethod(OAuthRegisterController::class, 'isValidRedirectUri');

        expect($method->invoke(new OAuthRegisterController, $uri))->toBe($allowed);
    } finally {
        Container::setInstance($previous);
    }
})->with([
    'HTTPS callback' => ['https://client.test/callback', true],
    'IPv4 callback' => ['http://127.0.0.1:8765/callback', true],
    'IPv6 callback' => ['http://[::1]:8765/callback', true],
    'configured custom scheme' => ['myapp://callback', true],
    'unconfigured custom scheme' => ['otherapp://callback', false],
    'HTTP user info' => ['http://127.0.0.1@attacker.test/callback', false],
    'HTTPS user info' => ['https://user:password@client.test/callback', false],
    'custom scheme user info' => ['myapp://user@callback', false],
    'missing scheme' => ['//localhost/callback', false],
]);
