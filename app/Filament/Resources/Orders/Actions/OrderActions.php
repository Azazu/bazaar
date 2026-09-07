<?php

namespace App\Filament\Resources\Orders\Actions;

use App\Models\Order;
use App\Services\Order\OrderService;
use App\States\Order\Refunded;
use Filament\Actions\Action;

/**
 * The only ways an admin may change an order. Both go through OrderService so the
 * state machine, refund, restock and payout side effects always run together;
 * there is deliberately no edit/delete path for orders in the panel.
 */
class OrderActions
{
    public static function cancel(): Action
    {
        return Action::make('cancel')
            ->icon('heroicon-o-x-circle')
            ->color('warning')
            ->requiresConfirmation()
            ->modalDescription('Stops the order before fulfilment. A paid order is refunded and its stock restored.')
            ->visible(fn (Order $record): bool => $record->isCancellable())
            ->action(fn (Order $record) => app(OrderService::class)->cancel($record));
    }

    public static function refund(): Action
    {
        return Action::make('refund')
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('danger')
            ->requiresConfirmation()
            ->modalDescription('Returns the payment, restores stock and voids vendor payouts.')
            ->visible(fn (Order $record): bool => $record->status->canTransitionTo(Refunded::class))
            ->action(fn (Order $record) => app(OrderService::class)->refund($record));
    }
}
