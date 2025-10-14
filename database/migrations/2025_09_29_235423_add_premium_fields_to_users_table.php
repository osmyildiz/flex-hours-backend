<?php
// database/migrations/2025_09_29_add_premium_fields_to_users_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Premium status
            $table->boolean('is_premium')->default(false);

            // Subscription type
            $table->enum('subscription_type', ['free', 'monthly', 'yearly'])
                ->default('free');

            // Premium expiration
            $table->timestamp('premium_expires_at')->nullable();

            // Payment provider (Stripe/RevenueCat/etc)
            $table->string('payment_provider_id')->nullable();
            $table->string('payment_provider')->nullable(); // 'stripe', 'apple', 'google'

            // Trial tracking
            $table->boolean('trial_used')->default(false);
            $table->timestamp('trial_ends_at')->nullable();

            // Indexes for performance
            $table->index('is_premium');
            $table->index('premium_expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'is_premium',
                'subscription_type',
                'premium_expires_at',
                'payment_provider_id',
                'payment_provider',
                'trial_used',
                'trial_ends_at',
            ]);
        });
    }
};
