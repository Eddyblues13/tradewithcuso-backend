<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\Paystack;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    public function show(Paystack $paystack): JsonResponse
    {
        return $this->respond($paystack);
    }

    public function update(Request $request, Paystack $paystack): JsonResponse
    {
        $data = $request->validate([
            'contact_email' => ['nullable', 'email', 'max:180'],
            'contact_phone' => ['nullable', 'string', 'max:30'],
            'whatsapp_number' => ['nullable', 'string', 'max:30', 'regex:/^\+?[0-9\s\-()]+$/'],
            'telegram_url' => ['nullable', 'url', 'max:255'],
            'instagram_url' => ['nullable', 'url', 'max:255'],
            'twitter_url' => ['nullable', 'url', 'max:255'],
            'bank_name' => ['nullable', 'string', 'max:120'],
            'bank_account_number' => ['nullable', 'string', 'max:20', 'regex:/^[0-9]+$/'],
            'bank_account_name' => ['nullable', 'string', 'max:160'],
        ], [
            'bank_account_number.regex' => 'An account number is digits only.',
            'whatsapp_number.regex' => 'Enter the number with its country code, e.g. +2348012345678.',
        ]);

        Setting::store($data);

        return $this->respond($paystack, 'Settings saved.');
    }

    private function respond(Paystack $paystack, ?string $message = null): JsonResponse
    {
        return response()->json(array_filter([
            'message' => $message,
            'data' => Setting::values(),
            // Read-only here: Paystack keys live in .env, never in the database.
            'paystack_configured' => $paystack->configured(),
        ], fn ($v) => $v !== null));
    }
}
