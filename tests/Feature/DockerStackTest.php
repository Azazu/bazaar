<?php

/*
 * ME-004: the documented `make init` / `make up` must leave a queue worker and a scheduler
 * running — otherwise mail, notifications, image derivatives and provider refunds silently
 * never happen. This pins the compose file to that promise; the live check is `docker compose ps`.
 */

it('declares a queue worker and a scheduler in the compose stack, health-checked and restarted', function () {
    $compose = file_get_contents(base_path('docker-compose.yml'));

    expect($compose)
        ->toMatch('/^\s{2}queue:\n/m')
        ->toMatch('/^\s{2}scheduler:\n/m')
        ->toContain('artisan queue:listen redis')
        ->toContain('artisan schedule:work')
        ->toContain("grep -q 'queue:listen' /proc/1/cmdline")
        ->toContain("grep -q 'schedule:work' /proc/1/cmdline");

    // Both restart on their own and neither is optional (no profile gating them off).
    expect(substr_count($compose, 'restart: unless-stopped'))->toBeGreaterThanOrEqual(7)
        ->and($compose)->not->toContain('profiles:');
});
