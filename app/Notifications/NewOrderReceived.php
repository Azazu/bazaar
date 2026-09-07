<?php

namespace App\Notifications;

use App\Models\SubOrder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** To the store owner: a paid order contains items from their store. One per sub-order. */
class NewOrderReceived extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public SubOrder $subOrder)
    {
        $this->afterCommit(); // the sub-order is written inside the payment transaction
    }

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject("New order #{$this->subOrder->order_id} for {$this->subOrder->store?->name}")
            ->greeting('You have a new order!')
            ->line("Order #{$this->subOrder->order_id} includes the following items from your store:");

        foreach ($this->subOrder->items as $item) {
            $mail->line("• {$item->product_title} — {$item->variant_name} × {$item->qty}");
        }

        return $mail
            ->line('Your share: '.money($this->subOrder->subtotal_cents))
            ->action('Open incoming orders', route('vendor.orders'))
            ->line('Mark it as processing once you start preparing the shipment.');
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'message' => "New order #{$this->subOrder->order_id}: ".money($this->subOrder->subtotal_cents).' for your store',
            'url' => route('vendor.orders'),
            'order_id' => $this->subOrder->order_id,
            'sub_order_id' => $this->subOrder->id,
            'subtotal_cents' => $this->subOrder->subtotal_cents,
        ];
    }
}
