<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        // Seeded from config so an existing BAZAAR_COMMISSION_RATE keeps working; edited in the admin from here on.
        $this->migrator->add('marketplace.commission_rate', (string) config('bazaar.commission_rate'));
    }
};
