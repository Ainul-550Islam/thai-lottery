<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Responses\ApiResponse;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

/**
 * Player Profile and Account Settings API Controller.
 */
final class ProfileController
{
    /**
     * Get authenticated player's full profile details.
     */
    public function show(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return ApiResponse::success(
            data: [
                'user' => [
                    'id' => (int) $user->id,
                    'name' => (string) $user->name,
                    'username' => (string) $user->username,
                    'email' => (string) $user->email,
                    'phone' => $user->phone,
                    'avatar_url' => $user->avatar_url,
                    'status' => $user->status->value,
                    'email_verified' => $user->email_verified_at !== null,
                    'phone_verified' => $user->phone_verified_at !== null,
                    'preferences' => $user->preferences ?? [],
                    'last_login_at' => $user->last_login_at?->toIso8601String(),
                    'created_at' => $user->created_at?->toIso8601String(),
                ],
            ],
            message: 'Profile retrieved successfully.',
        );
    }

    /**
     * Update authenticated player's profile info.
     */
    public function update(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'min:2', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'avatar_url' => ['nullable', 'url', 'max:500'],
            'preferences' => ['nullable', 'array'],
        ]);

        if (array_key_exists('name', $validated)) {
            $user->name = trim((string) $validated['name']);
        }

        if (array_key_exists('phone', $validated)) {
            $user->phone = $validated['phone'] !== null ? trim((string) $validated['phone']) : null;
        }

        if (array_key_exists('avatar_url', $validated)) {
            $user->avatar_url = $validated['avatar_url'];
        }

        if (array_key_exists('preferences', $validated)) {
            $user->preferences = $validated['preferences'];
        }

        $user->save();

        return ApiResponse::success(
            data: [
                'user' => [
                    'id' => (int) $user->id,
                    'name' => (string) $user->name,
                    'username' => (string) $user->username,
                    'email' => (string) $user->email,
                    'phone' => $user->phone,
                    'avatar_url' => $user->avatar_url,
                    'status' => $user->status->value,
                    'preferences' => $user->preferences ?? [],
                ],
            ],
            message: 'Profile updated successfully.',
        );
    }

    /**
     * Update authenticated player's password with current password verification.
     */
    public function updatePassword(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        if (! Hash::check($validated['current_password'], (string) $user->password)) {
            return ApiResponse::error(
                code: 'invalid_current_password',
                message: 'The current password provided is incorrect.',
                status: 422,
            );
        }

        $user->password = Hash::make($validated['password']);
        $user->save();

        return ApiResponse::success(
            data: [],
            message: 'Password updated successfully.',
        );
    }
}
