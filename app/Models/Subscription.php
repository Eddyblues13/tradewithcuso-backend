<?php

namespace App\Models;

use Database\Factories\SubscriptionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * One purchase of one plan by one trader.
 *
 * Only three statuses are stored — pending, active, cancelled. Whether an
 * active subscription is still to start, running, or over is read from its
 * dates, so nothing has to run on a schedule to expire it.
 */
class Subscription extends Model
{
    /** @use HasFactory<SubscriptionFactory> */
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_CANCELLED = 'cancelled';

    public const METHOD_PAYSTACK = 'paystack';

    public const METHOD_TRANSFER = 'bank_transfer';

    /** Granted from the dashboard — a comp, or a payment taken outside the site. */
    public const METHOD_MANUAL = 'manual';

    public const METHODS = [self::METHOD_PAYSTACK, self::METHOD_TRANSFER, self::METHOD_MANUAL];

    /** What the dashboard and the trader actually see. */
    public const STATES = ['pending', 'scheduled', 'active', 'expired', 'cancelled'];

    protected $fillable = [
        'user_id',
        'plan_id',
        'reference',
        'plan_name',
        'amount',
        'duration_days',
        'status',
        'payment_method',
        'payment_reference',
        'payment_channel',
        'amount_paid',
        'paid_at',
        'transfer_reported_at',
        'starts_at',
        'ends_at',
        'cancelled_at',
        'activated_by',
        'admin_note',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'amount_paid' => 'integer',
            'duration_days' => 'integer',
            'paid_at' => 'datetime',
            'transfer_reported_at' => 'datetime',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function activator(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'activated_by');
    }

    public static function generateReference(): string
    {
        // No 0/O or 1/I, so a reference read out over the phone or typed
        // into a bank narration survives the trip.
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

        do {
            $code = '';
            for ($i = 0; $i < 8; $i++) {
                $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            $reference = "TWC-{$code}";
        } while (static::where('reference', $reference)->exists());

        return $reference;
    }

    /** A fresh Paystack reference per attempt — Paystack refuses a repeat. */
    public function newPaymentReference(): string
    {
        return $this->reference.'-'.Str::lower(Str::random(6));
    }

    public function state(): string
    {
        if ($this->status === self::STATUS_CANCELLED) {
            return 'cancelled';
        }

        if ($this->status === self::STATUS_PENDING) {
            return 'pending';
        }

        if ($this->starts_at?->isFuture()) {
            return 'scheduled';
        }

        return $this->ends_at?->isFuture() ? 'active' : 'expired';
    }

    public function daysLeft(): ?int
    {
        if ($this->state() !== 'active') {
            return null;
        }

        return (int) ceil(now()->diffInSeconds($this->ends_at) / 86400);
    }

    /** Running today. */
    public function scopeCurrent(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE)
            ->where('starts_at', '<=', now())
            ->where('ends_at', '>', now());
    }

    public function scopeInState(Builder $query, string $state): Builder
    {
        return match ($state) {
            'pending' => $query->where('status', self::STATUS_PENDING),
            // Bank transfers the trader says they have sent — the queue an
            // admin works through.
            'awaiting' => $query->where('status', self::STATUS_PENDING)->whereNotNull('transfer_reported_at'),
            'scheduled' => $query->where('status', self::STATUS_ACTIVE)->where('starts_at', '>', now()),
            'active' => $query->current(),
            'expired' => $query->where('status', self::STATUS_ACTIVE)->where('ends_at', '<=', now()),
            'cancelled' => $query->where('status', self::STATUS_CANCELLED),
            default => $query,
        };
    }
}
