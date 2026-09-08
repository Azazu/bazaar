<?php

namespace App\Notifications;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** To the buyer: we took the payment but could not fulfil the order; the money is on its way back. */
class OrderUnfulfillableNotice extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Order $order, public string $soldOutItem)
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
        return (new MailMessage)
            ->subject("Order #{$this->order->id} could not be fulfilled")
            ->line("We're sorry — \"{$this->soldOutItem}\" became unavailable while your payment was being processed.")
            ->line("Order #{$this->order->id} has been cancelled and ".money($this->order->total_cents, $this->order->currency).' is being refunded to your original payment method.')
            ->action('Browse the catalog', route('catalog.index'));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'message' => "Order #{$this->order->id} could not be fulfilled — {$this->soldOutItem} is unavailable; refunded",
            'url' => route('orders.show', $this->order),
            'order_id' => $this->order->id,
            'sold_out_item' => $this->soldOutItem,
        ];
    }
}
