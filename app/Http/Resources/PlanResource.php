<?php

namespace App\Http\Resources;

use App\Models\Plan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** A plan as the public website shows it. */
class PlanResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->name,
            'tagline' => $this->tagline,
            'price' => $this->price,
            'duration_days' => $this->duration_days,
            'duration_label' => Plan::durationLabel($this->duration_days),
            'features' => $this->features ?? [],
            'is_popular' => $this->is_popular,
        ];
    }
}
