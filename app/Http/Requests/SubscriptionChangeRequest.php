<?php

namespace App\Http\Requests;

use App\Exceptions\BillingException;
use App\Models\Plan;
use App\Services\PlanCatalog;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Shared by subscribe and change-plan: a plan of the calling merchant, and an
 * optional effective date (defaults to today).
 */
class SubscriptionChangeRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'plan_id' => ['required', 'integer'],
            'effective_date' => ['sometimes', 'date_format:Y-m-d'],
        ];
    }

    public function plan(): Plan
    {
        $plan = app(PlanCatalog::class)->findForMerchant($this->user()->id, (int) $this->validated('plan_id'));

        if ($plan === null) {
            throw new BillingException('plan_not_found', 'Plan not found.');
        }

        return $plan;
    }
}
