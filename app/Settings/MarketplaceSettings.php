<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

/** Admin-editable marketplace parameters (Filament → Marketplace settings). */
class MarketplaceSettings extends Settings
{
    /** Platform commission per sub-order as an exact decimal string, e.g. "0.10" = 10 %. */
    public string $commission_rate;

    public static function group(): string
    {
        return 'marketplace';
    }
}
