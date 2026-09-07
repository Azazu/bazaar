<?php

namespace App\Notifications;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** To the buyer: the order was refunded after fulfilment had started. */
class OrderRefundedNotice extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Order $order)
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
            ->subject("Order #{$this->order->id} refunded")
            ->line("Your order #{$this->order->id} has been refunded.")
            ->line(money($this->order->total_cents, $this->order->currency).' is on its way back to your original payment method.')
            ->action('View order', route('orders.show', $this->order));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'message' => "Order #{$this->order->id} was refunded",
            'url' => route('orders.show', $this->order),
            'order_id' => $this->order->id,
        ];
    }
}
