<?php

it('serves the generated OpenAPI reference', function () {
    $this->get('/docs/api')->assertOk();

    $this->get('/docs/api.json')
        ->assertOk()
        ->assertJsonPath('info.version', '1.0.0')
        ->assertJsonPath('components.securitySchemes.http.scheme', 'bearer')
        ->assertJsonPath('servers.0.url', fn (string $url) => str_ends_with($url, '/api'))
        ->assertJsonStructure(['paths' => ['/v1/products', '/v1/checkout', '/v1/orders/{order}/pay']]); // paths are relative to the /api server
});
