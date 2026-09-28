<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\RecordUsageRequest;
use App\Http\Resources\UsageEventResource;
use App\Services\UsageRecorder;
use Illuminate\Http\JsonResponse;

class UsageController extends Controller
{
    /**
     * POST /api/v1/usage
     *
     * 201 Created: new event recorded.
     * 200 OK + `Idempotent-Replayed: true`: same key and payload seen before; nothing counted twice.
     * 409 Conflict: key reused with a different payload.
     */
    public function store(RecordUsageRequest $request, UsageRecorder $recorder): JsonResponse
    {
        $result = $recorder->record(
            merchant: $this->merchant($request),
            customerId: (int) $request->validated('customer_id'),
            units: (int) $request->validated('units'),
            usageDate: $request->validated('usage_date'),
            idempotencyKey: $request->validated('idempotency_key'),
        );

        return (new UsageEventResource($result->event))
            ->response()
            ->setStatusCode($result->created ? 201 : 200)
            ->header('Idempotent-Replayed', $result->created ? 'false' : 'true');
    }
}
