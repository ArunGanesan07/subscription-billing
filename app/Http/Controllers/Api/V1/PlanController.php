<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePlanRequest;
use App\Http\Requests\UpdatePlanRequest;
use App\Http\Resources\PlanResource;
use App\Models\Plan;
use App\Services\PlanCatalog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PlanController extends Controller
{
    public function index(Request $request, PlanCatalog $catalog): AnonymousResourceCollection
    {
        return PlanResource::collection($catalog->forMerchant($this->merchant($request)->id));
    }

    public function store(StorePlanRequest $request): PlanResource
    {
        $plan = $this->merchant($request)->plans()->create($request->validated());

        return new PlanResource($plan);
    }

    public function show(Plan $plan): PlanResource
    {
        return new PlanResource($plan);
    }

    /** Invalidation of cached pricing happens in PlanObserver after commit. */
    public function update(UpdatePlanRequest $request, Plan $plan): PlanResource
    {
        $plan->update($request->validated());

        return new PlanResource($plan);
    }
}
