<?php

namespace App\Http\Controllers\Api\Account;

use App\Http\Controllers\Controller;
use App\Http\Resources\TraderResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * Trader accounts.
 *
 * Tokens are issued on the `customer` guard, so a trader's token is never
 * accepted by the dashboard.
 */
class AuthController extends Controller
{
    private const PHONE = ['required', 'string', 'max:30', 'regex:/^\+?[0-9][0-9\s\-()]{6,}$/'];

    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:180', 'unique:users,email'],
            'phone' => self::PHONE,
            'password' => ['required', 'string', 'confirmed', Password::min(8)],
            // Recorded as a condition of signing up, not stored: the account
            // existing is the record that the box was ticked.
            'accept_terms' => ['accepted'],
        ], [
            'accept_terms.accepted' => 'Please accept the terms and conditions to continue.',
            'phone.regex' => 'Enter a valid phone number.',
        ]);

        unset($data['accept_terms']);

        $user = User::create([...$data, 'last_login_at' => now()]);

        return response()->json([
            'token' => $user->createToken('website')->plainTextToken,
            'user' => new TraderResource($user),
        ], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', $data['email'])->first();

        // One message for both cases, so this cannot be used to discover which
        // addresses have accounts.
        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => 'Those details do not match an account.',
            ]);
        }

        if (! $user->is_active) {
            throw ValidationException::withMessages([
                'email' => 'This account has been suspended. Contact support for help.',
            ]);
        }

        $user->forceFill(['last_login_at' => now()])->save();

        return response()->json([
            'token' => $user->createToken('website')->plainTextToken,
            'user' => new TraderResource($user),
        ]);
    }

    public function me(Request $request): TraderResource
    {
        return new TraderResource($request->user());
    }

    public function logout(Request $request): JsonResponse
    {
        // Only the token in hand, so signing out on a phone leaves a laptop
        // signed in.
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Signed out.']);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => [
                'required', 'email', 'max:180',
                Rule::unique('users', 'email')->ignore($user->getKey()),
            ],
            'phone' => self::PHONE,
        ], [
            'phone.regex' => 'Enter a valid phone number.',
        ]);

        $user->update($data);

        return response()->json([
            'message' => 'Profile updated.',
            'data' => new TraderResource($user->fresh()),
        ]);
    }

    public function updatePassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'confirmed', Password::min(8)],
        ]);

        $user = $request->user();

        if (! Hash::check($data['current_password'], $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => 'That is not your current password.',
            ]);
        }

        $user->update(['password' => $data['password']]);

        // Every other session is dropped, in case the password was changed
        // because someone else knew it.
        $current = $user->currentAccessToken();
        $user->tokens()->whereKeyNot($current->getKey())->delete();

        return response()->json(['message' => 'Password updated.']);
    }
}
