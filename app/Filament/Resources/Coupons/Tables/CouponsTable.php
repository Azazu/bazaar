<?php

namespace App\Filament\Resources\Coupons\Tables;

use App\Enums\CouponType;
use App\Models\Coupon;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class CouponsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')
                    ->searchable(),
                TextColumn::make('type')
                    ->badge()
                    ->searchable(),
                TextColumn::make('value')
                    ->label('Discount')
                    ->formatStateUsing(fn (int $state, Coupon $record): string => $record->type === CouponType::Percent ? "{$state}%" : money($state))
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('min_subtotal_cents')
                    ->label('Min. subtotal')
                    ->formatStateUsing(fn (int $state): string => money($state))
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('starts_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('expires_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('max_uses')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('used_count')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
