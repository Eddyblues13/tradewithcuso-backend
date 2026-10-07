<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\Paystack;
use Illuminate\Http\JsonResponse;

/**
 * Contact details for the footer, and which ways to pay are switched on, so
 * the checkout never offers one the server would refuse.
 */
class SettingsController extends Controller
{
    public function __invoke(Paystack $paystack): JsonResponse
    {
        return response()->json([
            'data' => [
                ...array_intersect_key(Setting::values(), array_flip(Setting::PUBLIC_KEYS)),
                'payment_methods' => [
                    'paystack' => $paystack->configured(),
                    'bank_transfer' => Setting::bankDetails() !== null,
                ],
            ],
        ]);
    }
}
