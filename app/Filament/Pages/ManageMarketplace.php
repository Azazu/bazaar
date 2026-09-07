<?php

namespace App\Filament\Pages;

use App\Settings\MarketplaceSettings;
use BackedEnum;
use Filament\Forms\Components\TextInput;
use Filament\Pages\SettingsPage;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class ManageMarketplace extends SettingsPage
{
    protected static string $settings = MarketplaceSettings::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static ?string $title = 'Marketplace settings';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('commission_rate')
                ->label('Platform commission')
                ->helperText('Deducted from each sub-order when the order is paid. Applies to new payments only.')
                ->numeric()
                ->minValue(0)
                ->maxValue(100)
                ->step(0.01)
                ->suffix('%')
                ->required(),
        ]);
    }

    /** Stored as an exact decimal ("0.10"); shown as a percentage. bcmath, not floats — this multiplies money. */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['commission_rate'] = bcmul($data['commission_rate'], '100', 2);

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['commission_rate'] = bcdiv((string) $data['commission_rate'], '100', 4);

        return $data;
    }
}
