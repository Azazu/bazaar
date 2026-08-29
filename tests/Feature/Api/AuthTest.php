<?php

use App\Models\User;
use Laravel\Sanctum\Sanctum;

it('issues a personal access token for valid credentials', function () {
    $user = User::factory()->create(); // factory password: "password"

    $this->postJson('/api/v1/auth/tokens', [
        'email' => $user->email,
        'password' => 'password',
        'device_name' => 'phone',
    ])
        ->assertCreated()
        ->assertJsonStructure(['token', 'token_type']);

    expect($user->tokens()->count())->toBe(1);
});

it('rejects invalid credentials without revealing whether the account exists', function () {
    $user = User::factory()->create();

    $this->postJson('/api/v1/auth/tokens', [
        'email' => $user->email,
        'password' => 'wrong',
        'device_name' => 'phone',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('email');

    expect($user->tokens()->count())->toBe(0);
});

it('throttles token issuance', function () {
    $payload = ['email' => 'nobody@example.com', 'password' => 'wrong', 'device_name' => 'phone'];

    foreach (range(1, 5) as $i) {
        $this->postJson('/api/v1/auth/tokens', $payload)->assertUnprocessable();
    }

    $this->postJson('/api/v1/auth/tokens', $payload)->assertTooManyRequests();
});

it('returns the authenticated user', function () {
    Sanctum::actingAs($user = User::factory()->create());

    $this->getJson('/api/v1/me')
        ->assertOk()
        ->assertJsonPath('data.email', $user->email)
        ->assertJsonMissingPath('data.password');
});

it('rejects unauthenticated requests with 401 JSON', function () {
    $this->getJson('/api/v1/me')->assertUnauthorized();
});

it('revokes the current token', function () {
    $user = User::factory()->create();
    $token = $user->createToken('phone')->plainTextToken;

    $this->withToken($token)->deleteJson('/api/v1/auth/tokens/current')->assertNoContent();

    expect($user->tokens()->count())->toBe(0);

    // Within one test the guard caches the resolved user; drop it so the next request re-authenticates.
    $this->app['auth']->forgetGuards();
    $this->withToken($token)->getJson('/api/v1/me')->assertUnauthorized();
});
