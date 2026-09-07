<?php

namespace App\Notifications;

use App\Models\SubOrder;
use App\States\SubOrder\SubOrderState;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * To the buyer: a vendor moved their part of the order forward (processing / shipped / delivered).
 *
 * The status is passed in explicitly: the queued job re-fetches the model when it runs, and
 * by then the sub-order may already be two steps further — "processing" would read "shipped".
 */
class SubOrderStatusUpdated extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public SubOrder $subOrder, public SubOrderState $status)
    {
        $this->afterCommit();
    }

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $label = strtolower($this->status->label());
        $store = $this->subOrder->store->name ?? 'the seller';

        return (new MailMessage)
            ->subject("Order #{$this->subOrder->order_id}: items from {$store} are {$label}")
            ->line("Your items from {$store} in order #{$this->subOrder->order_id} are now {$label}.")
            ->action('View order', route('orders.show', $this->subOrder->order_id));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'order_id' => $this->subOrder->order_id,
            'sub_order_id' => $this->subOrder->id,
            'status' => $this->status->getValue(),
        ];
    }
}
