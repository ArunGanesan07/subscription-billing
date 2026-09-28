<?php

namespace App\Services;

use App\Models\UsageEvent;

final readonly class RecordedUsage
{
    public function __construct(
        public UsageEvent $event,
        public bool $created,
    ) {}
}
