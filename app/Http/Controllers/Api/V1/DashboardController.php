<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\MerchantDashboard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    /** GET /api/v1/merchants/{merchant}/dashboard */
    public function __invoke(Request $request, int $merchant, MerchantDashboard $dashboard): JsonResponse
    {
        $current = $this->merchant($request);

        abort_unless($merchant === $current->id, 403, 'You can only view your own dashboard.');

        return response()->json(['data' => $dashboard->build($current, today())]);
    }
}
