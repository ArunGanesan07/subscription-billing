<?php

namespace App\Observers;

use App\Models\Customer;
use App\Services\TenantLookup;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

class CustomerObserver implements ShouldHandleEventsAfterCommit
{
    public function __construct(private readonly TenantLookup $lookup) {}

    public function deleted(Customer $customer): void
    {
        $this->lookup->forgetCustomer($customer->id);
    }
}
