<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\SubscriptionChangeRequest;
use App\Http\Resources\SubscriptionResource;
use App\Models\Customer;
use App\Models\Subscription;
use App\Services\SubscriptionService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

class SubscriptionController extends Controller
{
    public function __construct(private readonly SubscriptionService $subscriptions) {}

    /** POST /api/v1/customers/{customer}/subscriptions */
    public function store(SubscriptionChangeRequest $request, Customer $customer): SubscriptionResource
    {
        $subscription = $this->subscriptions->subscribe($customer, $request->plan(), $this->effectiveDate($request));

        return new SubscriptionResource($subscription->load('segments.plan'));
    }

    public function show(Subscription $subscription): SubscriptionResource
    {
        return new SubscriptionResource($subscription->load('segments.plan'));
    }

    /** POST /api/v1/subscriptions/{subscription}/change-plan (upgrade or downgrade) */
    public function changePlan(SubscriptionChangeRequest $request, Subscription $subscription): SubscriptionResource
    {
        $subscription = $this->subscriptions->changePlan($subscription, $request->plan(), $this->effectiveDate($request));

        return new SubscriptionResource($subscription->load('segments.plan'));
    }

    /** POST /api/v1/subscriptions/{subscription}/cancel */
    public function cancel(Request $request, Subscription $subscription): SubscriptionResource
    {
        $request->validate(['effective_date' => ['sometimes', 'date_format:Y-m-d']]);

        $subscription = $this->subscriptions->cancel($subscription, $this->effectiveDate($request));

        return new SubscriptionResource($subscription->load('segments.plan'));
    }

    private function effectiveDate(Request $request): ?CarbonImmutable
    {
        return $request->filled('effective_date') ? CarbonImmutable::parse($request->input('effective_date')) : null;
    }
}
