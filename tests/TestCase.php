<?php

namespace Tests;

use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    // Seed roles (reference data) into the fresh test database before each
    // feature test, so features that assign roles (e.g. registration) work.
    protected bool $seed = true;

    protected string $seeder = RoleSeeder::class;
}
