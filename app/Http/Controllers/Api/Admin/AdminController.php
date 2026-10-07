<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreAdminRequest;
use App\Http\Resources\AdminResource;
use App\Models\Admin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

/**
 * Team management. Owner-only at the route level; the guards here stop an
 * owner from locking the whole business out of its own dashboard.
 */
class AdminController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return AdminResource::collection(Admin::orderBy('name')->get());
    }

    public function store(StoreAdminRequest $request): JsonResponse
    {
        $admin = Admin::create([
            ...$request->validated(),
            'is_active' => $request->boolean('is_active', true),
        ]);

        return (new AdminResource($admin))->response()->setStatusCode(201);
    }

    public function update(StoreAdminRequest $request, Admin $admin): AdminResource
    {
        $data = $request->validated();

        if (blank($data['password'] ?? null)) {
            unset($data['password']);
        }

        $this->guardLastOwner($admin, $data, $request->user());

        $admin->update($data);

        // A password reset or deactivation should not leave live tokens behind.
        if (isset($data['password']) || ($data['is_active'] ?? true) === false) {
            $admin->tokens()->delete();
        }

        return new AdminResource($admin->fresh());
    }

    public function destroy(Request $request, Admin $admin): JsonResponse
    {
        if ($request->user()->is($admin)) {
            throw ValidationException::withMessages([
                'admin' => 'You cannot delete your own account.',
            ]);
        }

        if ($this->isLastActiveOwner($admin)) {
            throw ValidationException::withMessages([
                'admin' => 'This is the last active owner — promote someone else first.',
            ]);
        }

        $admin->tokens()->delete();
        $admin->delete();

        return response()->json(['message' => "{$admin->name} was removed from the team."]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function guardLastOwner(Admin $admin, array $data, Admin $actor): void
    {
        $losingOwnership = ($data['role'] ?? $admin->role) !== Admin::ROLE_OWNER;
        $beingDeactivated = ($data['is_active'] ?? $admin->is_active) === false;

        if (! $losingOwnership && ! $beingDeactivated) {
            return;
        }

        if ($actor->is($admin) && $beingDeactivated) {
            throw ValidationException::withMessages([
                'is_active' => 'You cannot deactivate your own account.',
            ]);
        }

        if ($this->isLastActiveOwner($admin)) {
            throw ValidationException::withMessages([
                'role' => 'This is the last active owner — promote someone else first.',
            ]);
        }
    }

    private function isLastActiveOwner(Admin $admin): bool
    {
        if (! $admin->isOwner() || ! $admin->is_active) {
            return false;
        }

        return Admin::where('role', Admin::ROLE_OWNER)
            ->where('is_active', true)
            ->whereKeyNot($admin->getKey())
            ->doesntExist();
    }
}
