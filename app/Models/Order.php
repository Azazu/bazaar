<?php

namespace App\Models;

use App\States\Order\Cancelled;
use App\States\Order\Delivered;
use App\States\Order\OrderState;
use App\States\Order\Paid;
use App\States\Order\Processing;
use App\States\Order\Shipped;
use App\States\SubOrder\Cancelled as SubOrderCancelled;
use App\States\SubOrder\Delivered as SubOrderDelivered;
use App\States\SubOrder\Paid as SubOrderPaid;
use App\States\SubOrder\Pending as SubOrderPending;
use App\States\SubOrder\Refunded as SubOrderRefunded;
use App\States\SubOrder\Shipped as SubOrderShipped;
use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\ModelStates\HasStates;

/**
 * @property OrderState $status spatie/model-states cast — declared so static analysis sees the state object, not a string
 */
class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use HasFactory, HasStates;

    protected $fillable = [
        'buyer_id',
        'status',
        'currency',
        'subtotal_cents',
        'shipping_cents',
        'discount_cents',
        'coupon_id',
        'total_cents',
        'shipping_address',
        'shipping_method',
    ];

    protected function casts(): array
    {
        return [
            'shipping_address' => 'array',
            'status' => OrderState::class,
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'buyer_id');
    }

    /** @return BelongsTo<Coupon, $this> */
    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    /** @return HasMany<OrderItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /** @return HasMany<SubOrder, $this> */
    public function subOrders(): HasMany
    {
        return $this->hasMany(SubOrder::class);
    }

    /** @return HasMany<Payment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * Fulfilment is driven per sub-order by each vendor; the parent's state is derived:
     * everything delivered → delivered, everything at least shipped → shipped, anything
     * started → processing. Cancelled/refunded sub-orders don't count. Moves one legal
     * step at a time so the state machine (and its listeners) see every transition.
     *
     * Payment propagates to sub-orders one by one (MarkSubOrdersPaid), so while any active
     * sub-order is still pending the picture is incomplete: a pending sibling is "not yet
     * paid", not "in fulfilment", and must not push the parent to processing.
     */
    public function syncStateFromSubOrders(): void
    {
        $this->load('subOrders');

        $active = $this->subOrders->reject(fn (SubOrder $s) => $s->status instanceof SubOrderCancelled || $s->status instanceof SubOrderRefunded);
        $ladder = [Paid::class, Processing::class, Shipped::class, Delivered::class];

        if ($active->isEmpty() || ! in_array($this->status::class, [Paid::class, Processing::class, Shipped::class], true)) {
            return;
        }

        if ($active->contains(fn (SubOrder $s) => $s->status instanceof SubOrderPending)) {
            return;
        }

        $target = match (true) {
            $active->every(fn (SubOrder $s) => $s->status instanceof SubOrderDelivered) => Delivered::class,
            $active->every(fn (SubOrder $s) => $s->status instanceof SubOrderShipped || $s->status instanceof SubOrderDelivered) => Shipped::class,
            $active->contains(fn (SubOrder $s) => ! $s->status instanceof SubOrderPaid) => Processing::class,
            default => null,
        };

        if ($target === null) {
            return;
        }

        while (array_search($this->status::class, $ladder, true) < array_search($target, $ladder, true)) {
            $this->status->transitionTo($ladder[array_search($this->status::class, $ladder, true) + 1]);
        }
    }

    /**
     * Cancellation means "stop before fulfilment". The parent's own state machine allows
     * pending/paid → cancelled, but the parent doesn't move while vendors work on their
     * sub-orders — so it must also check that none of them has gone past paid.
     */
    public function isCancellable(): bool
    {
        return $this->status->canTransitionTo(Cancelled::class)
            && $this->subOrders->every(fn (SubOrder $subOrder) => $subOrder->status->canTransitionTo(SubOrderCancelled::class));
    }
}
