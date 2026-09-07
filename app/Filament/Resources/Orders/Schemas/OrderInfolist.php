<?php

namespace App\Filament\Resources\Orders\Schemas;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\SubOrder;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/** Read-only view of an order: it is a financial record and is never edited by hand. */
class OrderInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Order')
                    ->columns(4)
                    ->schema([
                        TextEntry::make('id')->label('#'),
                        TextEntry::make('buyer.name'),
                        TextEntry::make('status')
                            ->badge()
                            ->formatStateUsing(fn (Order $record): string => $record->status->label())
                            ->color(fn (Order $record): string => match ($record->status->getValue()) {
                                'paid', 'processing', 'shipped' => 'info',
                                'delivered' => 'success',
                                'cancelled', 'refunded' => 'danger',
                                default => 'warning',
                            }),
                        TextEntry::make('created_at')->dateTime(),
                    ]),
                Section::make('Amounts')
                    ->columns(5)
                    ->schema([
                        TextEntry::make('subtotal_cents')
                            ->label('Subtotal')
                            ->formatStateUsing(fn (int $state, Order $record): string => money($state, $record->currency)),
                        TextEntry::make('shipping_cents')
                            ->label('Shipping')
                            ->formatStateUsing(fn (int $state, Order $record): string => money($state, $record->currency)),
                        TextEntry::make('discount_cents')
                            ->label('Discount')
                            ->formatStateUsing(fn (int $state, Order $record): string => money($state, $record->currency)),
                        TextEntry::make('total_cents')
                            ->label('Total')
                            ->weight('bold')
                            ->formatStateUsing(fn (int $state, Order $record): string => money($state, $record->currency)),
                        TextEntry::make('coupon.code')
                            ->label('Coupon')
                            ->placeholder('—'),
                    ]),
                Section::make('Shipping')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('shipping_method')->badge()->color('gray')->placeholder('—'),
                        KeyValueEntry::make('shipping_address')
                            ->keyLabel('Field')
                            ->valueLabel('Value'),
                    ]),
                Section::make('Items')
                    ->schema([
                        RepeatableEntry::make('items')
                            ->hiddenLabel()
                            ->table([
                                TableColumn::make('Product'),
                                TableColumn::make('Variant'),
                                TableColumn::make('Unit price'),
                                TableColumn::make('Qty'),
                            ])
                            ->schema([
                                TextEntry::make('product_title'),
                                TextEntry::make('variant_name')->placeholder('—'),
                                TextEntry::make('unit_price_cents')
                                    ->formatStateUsing(fn (int $state, OrderItem $record): string => money($state, $record->order->currency ?? 'USD')),
                                TextEntry::make('qty'),
                            ]),
                    ]),
                Section::make('Sub-orders')
                    ->description('One per store; each vendor fulfils their own part.')
                    ->schema([
                        RepeatableEntry::make('subOrders')
                            ->hiddenLabel()
                            ->table([
                                TableColumn::make('Store'),
                                TableColumn::make('Status'),
                                TableColumn::make('Subtotal'),
                                TableColumn::make('Payout'),
                            ])
                            ->schema([
                                TextEntry::make('store.name'),
                                TextEntry::make('status')
                                    ->badge()
                                    ->formatStateUsing(fn (SubOrder $record): string => $record->status->label()),
                                TextEntry::make('subtotal_cents')
                                    ->formatStateUsing(fn (int $state, SubOrder $record): string => money($state, $record->order->currency ?? 'USD')),
                                TextEntry::make('payout.status')->badge()->color('gray')->placeholder('—'),
                            ]),
                    ]),
                Section::make('Payments')
                    ->schema([
                        RepeatableEntry::make('payments')
                            ->hiddenLabel()
                            ->placeholder('No payment attempts yet.')
                            ->table([
                                TableColumn::make('Gateway'),
                                TableColumn::make('Transaction'),
                                TableColumn::make('Status'),
                                TableColumn::make('Amount'),
                            ])
                            ->schema([
                                TextEntry::make('gateway'),
                                TextEntry::make('transaction_id')->copyable(),
                                TextEntry::make('status')->badge()->color('gray'),
                                TextEntry::make('amount_cents')
                                    ->formatStateUsing(fn (int $state, Payment $record): string => money($state, $record->currency)),
                            ]),
                    ]),
            ]);
    }
}
