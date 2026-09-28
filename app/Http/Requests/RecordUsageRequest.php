<?php

namespace App\Http\Requests;

use App\Services\TenantLookup;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class RecordUsageRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        // The idempotency key travels in a header (as in Stripe's API); fold it
        // in so it's validated with everything else.
        $this->merge(['idempotency_key' => $this->header('Idempotency-Key')]);
    }

    public function rules(): array
    {
        $today = today();
        $earliest = $today->copy()->subDays(config('billing.usage.max_backdate_days'));

        return [
            'idempotency_key' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_\-:.]+$/'],
            'customer_id' => ['required', 'integer', 'min:1'],
            'units' => ['required', 'integer', 'min:1', 'max:'.config('billing.usage.max_units_per_event')],
            'usage_date' => [
                'required',
                'date_format:Y-m-d',
                'after_or_equal:'.$earliest->toDateString(),
                'before_or_equal:'.$today->toDateString(),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'idempotency_key.required' => 'An Idempotency-Key header is required so retries are never double-counted.',
            'usage_date.after_or_equal' => 'usage_date may be at most '.config('billing.usage.max_backdate_days').' days in the past.',
            'usage_date.before_or_equal' => 'usage_date cannot be in the future.',
        ];
    }

    public function after(TenantLookup $lookup): array
    {
        return [
            function (Validator $validator) use ($lookup) {
                if ($validator->errors()->has('customer_id')) {
                    return;
                }

                // Tenant isolation: a merchant can only record usage for its own
                // customers. Same message whether the ID is foreign or missing.
                if ($lookup->customerMerchantId((int) $this->input('customer_id')) !== $this->user()->id) {
                    $validator->errors()->add('customer_id', 'Unknown customer.');
                }
            },
        ];
    }
}
