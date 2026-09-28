<?php

namespace App\Services;

use App\Exceptions\BillingException;
use App\Models\Merchant;
use App\Models\UsageEvent;
use App\Support\CacheKeys;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Idempotent usage ingestion.
 *
 * The happy path is a single INSERT. Idempotency comes from the unique index
 * on (merchant_id, idempotency_key), not from a "check then insert", which
 * would race under concurrent retries. On a duplicate we read the original
 * event back:
 *   - same payload → treat as a replay (200, nothing new counted)
 *   - different payload → 409, because the client is reusing a key for a
 *     different event, which is a client bug we should surface, not silently drop.
 */
class UsageRecorder
{
    public function __construct(private readonly Cache $cache) {}

    public function record(
        Merchant $merchant,
        int $customerId,
        int $units,
        string $usageDate,
        string $idempotencyKey,
    ): RecordedUsage {
        try {
            $event = UsageEvent::query()->create([
                'merchant_id' => $merchant->id,
                'customer_id' => $customerId,
                'idempotency_key' => $idempotencyKey,
                'units' => $units,
                'usage_date' => $usageDate,
            ]);
        } catch (UniqueConstraintViolationException) {
            return $this->replay($merchant, $customerId, $units, $usageDate, $idempotencyKey);
        }

        // Cheap liveness signal for on-call: "when did this merchant last record usage?"
        $this->cache->put(CacheKeys::lastUsageRecordedAt($merchant->id), now()->toIso8601String(), now()->addDays(7));

        return new RecordedUsage($event, created: true);
    }

    private function replay(
        Merchant $merchant,
        int $customerId,
        int $units,
        string $usageDate,
        string $idempotencyKey,
    ): RecordedUsage {
        $existing = UsageEvent::query()
            ->where('merchant_id', $merchant->id)
            ->where('idempotency_key', $idempotencyKey)
            ->firstOrFail();

        if (! $existing->matches($customerId, $units, $usageDate)) {
            throw BillingException::conflict(
                'idempotency_key_reused',
                'This Idempotency-Key was already used for a different usage event.',
            );
        }

        return new RecordedUsage($existing, created: false);
    }
}
