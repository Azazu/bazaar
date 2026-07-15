<?php

namespace App\Notifications;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OrderConfirmed extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Order $order) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Order #{$this->order->id} confirmed")
            ->greeting('Thanks for your order!')
            ->line("Your order #{$this->order->id} has been paid.")
            ->line('Total: '.money($this->order->total_cents, $this->order->currency))
            ->action('View order', route('orders.show', $this->order))
            ->line('We will let you know when it ships.');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'order_id' => $this->order->id,
            'total_cents' => $this->order->total_cents,
            'currency' => $this->order->currency,
        ];
    }
}
