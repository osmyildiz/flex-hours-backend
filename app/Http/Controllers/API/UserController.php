<?php
// app/Http/Controllers/API/UserController.php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Carbon\Carbon;

class UserController extends Controller
{
    /**
     * Get user's premium status
     */
    public function premiumStatus(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'success' => true,
            'data' => [
                'is_premium' => $user->isPremiumActive(),
                'subscription_type' => $user->subscription_type,
                'premium_expires_at' => $user->premium_expires_at,
                'days_remaining' => $user->daysUntilExpiration(),
                'on_trial' => $user->isOnTrial(),
                'trial_ends_at' => $user->trial_ends_at,
                'can_access_premium' => $user->canAccessPremiumFeatures(),
            ]
        ]);
    }

    /**
     * Start free trial (7 days)
     */
    public function startTrial(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->trial_used) {
            return response()->json([
                'success' => false,
                'message' => 'Trial already used'
            ], 400);
        }

        $user->update([
            'trial_used' => true,
            'trial_ends_at' => now()->addDays(7),
        ]);

        return response()->json([
            'success' => true,
            'message' => '7-day trial activated',
            'data' => [
                'trial_ends_at' => $user->trial_ends_at,
            ]
        ]);
    }

    /**
     * Activate premium subscription
     */
    public function activatePremium(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'subscription_type' => 'required|in:monthly,yearly',
            'payment_provider' => 'required|string|in:stripe,apple,google',
            'payment_provider_id' => 'required|string',
            'receipt_data' => 'nullable|string', // For Apple/Google verification
        ]);

        $user = $request->user();

        // Calculate expiration based on subscription type
        $expiresAt = match($validated['subscription_type']) {
            'monthly' => now()->addMonth(),
            'yearly' => now()->addYear(),
        };

        $user->update([
            'is_premium' => true,
            'subscription_type' => $validated['subscription_type'],
            'premium_expires_at' => $expiresAt,
            'payment_provider' => $validated['payment_provider'],
            'payment_provider_id' => $validated['payment_provider_id'],
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Premium activated successfully',
            'data' => [
                'is_premium' => true,
                'subscription_type' => $user->subscription_type,
                'premium_expires_at' => $user->premium_expires_at,
            ]
        ]);
    }

    /**
     * Cancel premium subscription
     */
    public function cancelPremium(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user->is_premium) {
            return response()->json([
                'success' => false,
                'message' => 'No active premium subscription'
            ], 400);
        }

        // Don't immediately revoke - let it expire naturally
        $user->update([
            'is_premium' => false,
            // Keep premium_expires_at so they can still use until end of period
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Premium cancelled. Access until ' . $user->premium_expires_at->format('Y-m-d'),
            'data' => [
                'premium_expires_at' => $user->premium_expires_at,
            ]
        ]);
    }

    /**
     * Restore premium from receipt (iOS/Android)
     */
    public function restorePurchase(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'receipt_data' => 'required|string',
            'platform' => 'required|in:ios,android',
        ]);

        // TODO: Implement receipt verification with Apple/Google
        // For now, just return success

        return response()->json([
            'success' => true,
            'message' => 'Purchase restoration not yet implemented',
        ]);
    }
}
