<?php
// app/Models/User.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Carbon\Carbon;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'timezone',
        'is_premium',
        'subscription_type',
        'premium_expires_at',
        'payment_provider_id',
        'payment_provider',
        'trial_used',
        'trial_ends_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'premium_expires_at' => 'datetime',
        'trial_ends_at' => 'datetime',
        'is_premium' => 'boolean',
        'trial_used' => 'boolean',
    ];

    public function workEntries()
    {
        return $this->hasMany(WorkEntry::class);
    }

    public function isPremiumActive(): bool
    {
        if (!$this->is_premium) {
            return false;
        }

        // Check if subscription expired
        if ($this->premium_expires_at && Carbon::now()->greaterThan($this->premium_expires_at)) {
            return false;
        }

        return true;
    }

    public function isOnTrial(): bool
    {
        return !$this->trial_used &&
            $this->trial_ends_at &&
            Carbon::now()->lessThan($this->trial_ends_at);
    }

    public function canAccessPremiumFeatures(): bool
    {
        return $this->isPremiumActive() || $this->isOnTrial();
    }


    public function daysUntilExpiration(): ?int
    {
        if (!$this->premium_expires_at) {
            return null;
        }

        return Carbon::now()->diffInDays($this->premium_expires_at, false);
    }
}
