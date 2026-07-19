<?php

namespace App\Filament\Resources\Orders\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class OrderForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('buyer_id')
                    ->relationship('buyer', 'name')
                    ->required(),
                TextInput::make('status')
                    ->required()
                    ->default('pending'),
                TextInput::make('currency')
                    ->required()
                    ->default('USD'),
                TextInput::make('subtotal_cents')
                    ->required()
                    ->numeric(),
                TextInput::make('shipping_cents')
                    ->required()
                    ->numeric()
                    ->default(0),
                TextInput::make('discount_cents')
                    ->required()
                    ->numeric()
                    ->default(0),
                Select::make('coupon_id')
                    ->relationship('coupon', 'id'),
                TextInput::make('total_cents')
                    ->required()
                    ->numeric(),
                TextInput::make('shipping_address')
                    ->required(),
                TextInput::make('shipping_method'),
            ]);
    }
}
