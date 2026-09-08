<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ledger of provider events we have acted on. Two jobs:
 *
 *  - idempotency: `event_id` is unique, so a redelivered event is recognised and skipped
 *    (see PaymentService::confirm());
 *  - audit trail: which payment (and so which order) the event concerned, what kind of event
 *    it was and what we did about it (`outcome`), and when.
 *
 * Retention: rows are pruned after bazaar.payment_event_retention_days (`model:prune`, daily).
 * That is safe for the audit because the durable financial record is the payment itself —
 * status, refund reference, timestamps — and for idempotency because Stripe stops redelivering
 * an event within days, not months.
 */
class PaymentEvent extends Model
{
    use Prunable;

    public const string OUTCOME_APPLIED = 'applied';                              // order marked paid

    public const string OUTCOME_REFUNDED_UNFULFILLABLE = 'refunded_unfulfillable';  // charged, but a line could not be delivered

    public const string OUTCOME_REFUNDED_SURPLUS = 'refunded_surplus';              // charged for an order another attempt had settled

    public const string OUTCOME_ATTEMPT_FAILED = 'attempt_failed';                  // the provider reported the attempt failed

    public const string OUTCOME_IGNORED = 'ignored';                                // the event changed nothing: the payment was already accounted for

    protected $fillable = ['event_id', 'payment_id', 'gateway', 'transaction_id', 'type', 'outcome', 'processed_at'];

    protected function casts(): array
    {
        return ['processed_at' => 'datetime'];
    }

    /** @return BelongsTo<Payment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /** @return Builder<static> */
    public function prunable(): Builder
    {
        return static::query()->where('processed_at', '<', now()->subDays((int) config('bazaar.payment_event_retention_days')));
    }
}
