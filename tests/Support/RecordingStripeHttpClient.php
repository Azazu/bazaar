<?php

namespace Tests\Support;

use Stripe\HttpClient\ClientInterface;

/**
 * Stand-in for Stripe's HTTP layer: records every request and answers with canned JSON,
 * so the gateway is exercised end to end without touching the network.
 */
final class RecordingStripeHttpClient implements ClientInterface
{
    /** @var array<int, array{method: string, url: string, params: array<string, mixed>, headers: array<int, string>}> */
    public array $requests = [];

    /** @param  array<string, mixed>  $response */
    public function __construct(private array $response) {}

    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null): array
    {
        $this->requests[] = ['method' => $method, 'url' => $absUrl, 'params' => $params, 'headers' => $headers];

        return [json_encode($this->response, JSON_THROW_ON_ERROR), 200, []];
    }
}
