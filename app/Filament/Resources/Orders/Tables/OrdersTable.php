<?php

namespace App\Filament\Resources\Orders\Tables;

use App\Models\Order;
use App\Services\Order\OrderService;
use App\States\Order\Cancelled;
use App\States\Order\Refunded;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class OrdersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('buyer.name')
                    ->searchable(),
                TextColumn::make('status')
                    ->searchable(),
                TextColumn::make('currency')
                    ->searchable(),
                TextColumn::make('subtotal_cents')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('shipping_cents')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('discount_cents')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('coupon.id')
                    ->searchable(),
                TextColumn::make('total_cents')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('shipping_method')
                    ->searchable(),
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
                Action::make('cancel')
                    ->icon('heroicon-o-x-circle')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->visible(fn (Order $record): bool => $record->status->canTransitionTo(Cancelled::class))
                    ->action(fn (Order $record) => app(OrderService::class)->cancel($record)),
                Action::make('refund')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('Returns the payment, restores stock and voids vendor payouts.')
                    ->visible(fn (Order $record): bool => $record->status->canTransitionTo(Refunded::class))
                    ->action(fn (Order $record) => app(OrderService::class)->refund($record)),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
