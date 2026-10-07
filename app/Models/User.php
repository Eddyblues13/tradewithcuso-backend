<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * A trader — someone who signs up on the website and buys a plan.
 *
 * Staff live in `admins`, on their own guard; nothing here can reach the
 * dashboard.
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'phone',
        'password',
        'is_active',
        'last_login_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'is_active' => 'boolean',
            'password' => 'hashed',
        ];
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class)->latest('id');
    }

    /** Subscriptions running today. Eager-loadable, for list pages. */
    public function runningSubscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class)->current()->oldest('ends_at');
    }

    /**
     * The plan the trader is on right now, if any.
     *
     * Renewals are stacked, so a scheduled one never counts here — it starts
     * when the one running today ends.
     */
    public function currentSubscription(): ?Subscription
    {
        return $this->relationLoaded('runningSubscriptions')
            ? $this->runningSubscriptions->first()
            : $this->runningSubscriptions()->first();
    }

    /** The latest bill still waiting to be paid, if any. */
    public function pendingSubscription(): ?Subscription
    {
        return $this->hasMany(Subscription::class)
            ->where('status', Subscription::STATUS_PENDING)
            ->latest('id')
            ->first();
    }
}
