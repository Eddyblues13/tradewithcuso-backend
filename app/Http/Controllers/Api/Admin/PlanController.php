<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StorePlanRequest;
use App\Http\Resources\AdminPlanResource;
use App\Models\Plan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

class PlanController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return AdminPlanResource::collection(
            Plan::query()
                ->ordered()
                ->withCount('subscriptions')
                ->withCount(['subscriptions as active_count' => fn ($q) => $q->current()])
                ->get()
        );
    }

    public function show(Plan $plan): AdminPlanResource
    {
        return new AdminPlanResource($plan->loadCount('subscriptions'));
    }

    public function store(StorePlanRequest $request): JsonResponse
    {
        $plan = Plan::create($this->attributes($request));

        return (new AdminPlanResource($plan))->response()->setStatusCode(201);
    }

    /**
     * Changing a price affects new subscriptions only — every existing one
     * carries its own frozen copy of what was agreed.
     */
    public function update(StorePlanRequest $request, Plan $plan): AdminPlanResource
    {
        $plan->update($this->attributes($request, $plan));

        return new AdminPlanResource($plan->fresh()->loadCount('subscriptions'));
    }

    public function toggle(Plan $plan): AdminPlanResource
    {
        $plan->update(['is_active' => ! $plan->is_active]);

        return new AdminPlanResource($plan->loadCount('subscriptions'));
    }

    /**
     * A plan anyone has subscribed to is kept, so their history still points
     * somewhere; it can be hidden from the website instead.
     */
    public function destroy(Plan $plan): JsonResponse
    {
        if ($plan->subscriptions()->exists()) {
            throw ValidationException::withMessages([
                'plan' => 'Traders have subscribed to this plan, so it cannot be deleted. Deactivate it to hide it from the website.',
            ]);
        }

        $plan->delete();

        return response()->json(['message' => "{$plan->name} was deleted."]);
    }

    /**
     * @return array<string, mixed>
     */
    private function attributes(StorePlanRequest $request, ?Plan $plan = null): array
    {
        $data = $request->validated();

        $data['slug'] = filled($data['slug'] ?? null)
            ? $data['slug']
            : Plan::uniqueSlug($data['name'], $plan?->id);
        $data['is_popular'] = $request->boolean('is_popular');
        $data['is_active'] = $request->boolean('is_active', $plan?->is_active ?? true);
        $data['sort_order'] = $data['sort_order'] ?? $plan?->sort_order ?? 0;

        return $data;
    }
}
