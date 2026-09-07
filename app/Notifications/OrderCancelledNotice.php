<?php

namespace App\Notifications;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** To the buyer: the order was cancelled; mentions the refund when money had been taken. */
class OrderCancelledNotice extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Order $order, public bool $refunded)
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
        $mail = (new MailMessage)
            ->subject("Order #{$this->order->id} cancelled")
            ->line("Your order #{$this->order->id} has been cancelled.");

        if ($this->refunded) {
            $mail->line('The payment of '.money($this->order->total_cents, $this->order->currency).' is being refunded to your original payment method.');
        }

        return $mail->action('View order', route('orders.show', $this->order));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'message' => "Order #{$this->order->id} was cancelled".($this->refunded ? ' and refunded' : ''),
            'url' => route('orders.show', $this->order),
            'order_id' => $this->order->id,
            'refunded' => $this->refunded,
        ];
    }
}
