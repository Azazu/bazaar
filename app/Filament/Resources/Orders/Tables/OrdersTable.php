<?php

namespace App\Filament\Resources\Orders\Tables;

use App\Filament\Resources\Orders\Actions\OrderActions;
use App\Models\Order;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class OrdersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('#')
                    ->sortable(),
                TextColumn::make('buyer.name')
                    ->searchable(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (Order $record): string => $record->status->label())
                    ->color(fn (Order $record): string => match ($record->status->getValue()) {
                        'paid', 'processing', 'shipped' => 'info',
                        'delivered' => 'success',
                        'cancelled', 'refunded' => 'danger',
                        default => 'warning',
                    }),
                TextColumn::make('total_cents')
                    ->label('Total')
                    ->formatStateUsing(fn (int $state, Order $record): string => money($state, $record->currency))
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('subtotal_cents')
                    ->label('Subtotal')
                    ->formatStateUsing(fn (int $state, Order $record): string => money($state, $record->currency))
                    ->alignEnd()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('shipping_cents')
                    ->label('Shipping')
                    ->formatStateUsing(fn (int $state, Order $record): string => money($state, $record->currency))
                    ->alignEnd()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('discount_cents')
                    ->label('Discount')
                    ->formatStateUsing(fn (int $state, Order $record): string => money($state, $record->currency))
                    ->alignEnd()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('coupon.code')
                    ->label('Coupon')
                    ->placeholder('—'),
                TextColumn::make('shipping_method')
                    ->badge()
                    ->color('gray'),
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
                ViewAction::make(),
                OrderActions::cancel(),
                OrderActions::refund(),
            ])
            // Orders are financial records: no bulk deletion; cancel/refund are the only "removals".
            ->toolbarActions([])
            ->defaultSort('id', 'desc');
    }
}
