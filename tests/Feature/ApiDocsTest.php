<?php

it('serves the generated OpenAPI reference', function () {
    $this->get('/docs/api')->assertOk();

    $this->get('/docs/api.json')
        ->assertOk()
        ->assertJsonPath('info.version', '1.0.0')
        ->assertJsonPath('components.securitySchemes.http.scheme', 'bearer')
        ->assertJsonPath('servers.0.url', 'http://localhost:8080/api') // the documented Docker stack, whatever APP_URL is here
        ->assertJsonStructure(['paths' => ['/v1/products', '/v1/checkout', '/v1/orders/{order}/pay']]); // paths are relative to the /api server
});

it('documents bearer auth only where the API actually requires it', function () {
    $spec = $this->get('/docs/api.json')->assertOk()->json();

    // Global default: bearer token…
    expect($spec['security'])->toBe([['http' => []]]);

    // …switched off on the public operations…
    foreach ([['/v1/categories', 'get'], ['/v1/products', 'get'], ['/v1/products/{slug}', 'get'], ['/v1/products/{product}/reviews', 'get'], ['/v1/auth/tokens', 'post']] as [$path, $method]) {
        expect($spec['paths'][$path][$method]['security'] ?? null)->toBe([], "{$method} {$path} must be public");
    }

    // …and left in force on the protected ones (no per-operation override).
    foreach ([['/v1/cart', 'get'], ['/v1/checkout', 'post'], ['/v1/orders/{order}/pay', 'post'], ['/v1/products/{product}/reviews', 'post'], ['/v1/me', 'get']] as [$path, $method]) {
        expect($spec['paths'][$path][$method])->not->toHaveKey('security', "{$method} {$path} must require the bearer token");
    }
});

it('keeps the committed OpenAPI export identical to what the code generates', function () {
    $generated = $this->get('/docs/api.json')->assertOk()->json();
    $committed = json_decode((string) file_get_contents(base_path('docs/openapi.json')), true);

    expect($committed)->toBe($generated); // otherwise: `make docs-api` and commit the result
});
