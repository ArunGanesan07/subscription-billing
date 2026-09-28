<?php

namespace App\Observers;

use App\Models\Plan;
use App\Services\PlanCatalog;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

/**
 * Invalidates cached pricing after the change is committed. Invalidating inside
 * the transaction would let a concurrent request re-cache the old, still
 * committed, row before the update becomes visible.
 */
class PlanObserver implements ShouldHandleEventsAfterCommit
{
    public function __construct(private readonly PlanCatalog $catalog) {}

    public function saved(Plan $plan): void
    {
        $this->catalog->forget($plan);
    }

    public function deleted(Plan $plan): void
    {
        $this->catalog->forget($plan);
    }
}
