# Code review: "Add usage recording endpoint"

> Review of the first draft of `UsageController@store` from the brief, written as I'd post it on the PR. The version that shipped is linked at the end.

```php
public function store(Request $request)
{
    $customer = Customer::find($request->customer_id);
    $usage = new UsageEvent();
    $usage->customer_id = $customer->id;
    $usage->units = $request->units;
    $usage->usage_date = $request->usage_date;
    $usage->save();
    return response()->json(['status' => 'ok']);
}
```

---

## Review summary: **Request changes**

Thanks for getting this up quickly. The shape is right (one endpoint, one row per event) and it's easy to read. But this endpoint is how money enters the system: every row here ends up on a customer's invoice. That means we need to be stricter than usual about **who** can write, **what** they can write, and **what happens when a request is retried**. Below are six blocking issues, then a few smaller ones. None of them are hard to fix, and I've suggested a direction for each. Happy to pair on it if that's quicker for you.

---

## Blocking

### 1. Unknown customer → 500, and it doesn't fail safe
**Line 3–5.** `Customer::find()` returns `null` for an unknown ID, and `$customer->id` then throws *"Attempt to read property on null"*. The client gets a 500, which tells them *we* are broken when really *their* input is wrong, and our error tracker fills with noise. It also means the endpoint's correctness depends on an accident rather than a rule.

**Suggestion:** validate `customer_id` up front and return a `422` with a clear message.

### 2. Any caller can write usage for *any* tenant's customer (security)
**Line 3.** There is no authentication and no tenant scoping. Anyone who can reach the endpoint can increment usage, and therefore **someone else's bill**, for any customer ID in the database, across merchants. That is both a billing-integrity problem and a data-isolation problem between tenants.

**Suggestion:**
- Authenticate the merchant (an API key middleware) and resolve the customer **within that merchant only**.
- Return the same response for "doesn't exist" and "belongs to another merchant", so the endpoint can't be used to discover other tenants' IDs.

### 3. Retries double-count, and customers get over-billed
**Lines 4–8.** Clients *will* retry: timeouts, 502s from the load balancer, mobile networks, queue redeliveries on their side. Every retry here inserts another row, so the same API call gets billed twice. For a metering endpoint, this is the most important property to get right.

**Suggestion:**
- Require an `Idempotency-Key` header and add `UNIQUE (merchant_id, idempotency_key)`.
- **Insert first and catch the unique violation.** A "check then insert" (`exists()` followed by `save()`) still races when two retries arrive at the same moment.
- On a duplicate, return the original event with `200` (not an error, since the client did nothing wrong).
- If the same key comes back with a *different* payload, return `409`, because that's a client bug we want them to notice.

### 4. No input validation: negative, fractional or absurd units; arbitrary dates
**Lines 6–7.** `units` could be `-500` (a free credit), `1.7`, `"abc"`, `null` (a DB error, so another 500) or `999999999`. `usage_date` could be `null`, `2019-01-01` (inside a cycle we've already invoiced) or next year.

**Suggestion:** use a FormRequest:
- `units`: integer, `1 … max_units_per_event`.
- `usage_date`: `Y-m-d`, not in the future, and not older than a small backdate window.

That window matters beyond validation. It's what lets us guarantee an invoice never needs to change after it's issued. (In the final version, invoicing waits until the window has closed.)

### 5. No rate limiting on a public, high-volume write endpoint
One misbehaving integration, such as a retry loop with no backoff, can flood the table and slow down every other tenant.

**Suggestion:** `throttle` with a limiter keyed **per API key/merchant**, not per IP. Per-IP limits punish merchants behind NAT and are easy to get around.

### 6. No tests
For code that creates billable records, I'd like to see at least these tests before merging:
- happy path
- retry → no double count
- same key with a different payload → 409
- another tenant's customer → rejected
- validation edge cases
- the rate limit

---

## Non-blocking (worth doing in this PR if you have time)

- **Status code and body.** Return `201 Created` with the stored event (`id`, `recorded_at`) instead of `{status: ok}`. Clients can log the ID, and support can trace it later.
- **Keep the controller thin.** Move the write into a small `UsageRecorder` service. The idempotency logic then has one home and can be reused by a batch endpoint or a queue consumer later.
- **Mass assignment.** Setting fields one by one is fine, but once we move to `create()` we should use `$fillable` and only pass `$request->validated()`, never `$request->all()`.
- **Hot-path cost.** `Customer::find()` is a query on every single event. Once ownership is validated, that lookup is a good candidate for a small cache (customers never change merchant). It's not needed on day one, but keep it in mind for the throughput requirement.
- **Schema.** For this table specifically, I'd keep it narrow, with no FK constraints (they cost a lookup per insert). It needs an index that covers `(customer_id, usage_date, units)` for the daily rollup. I'm happy to walk through why.

---

## How I'd handle this as the lead

- **Leave it with the author, not rewrite it silently.** I'd post this review, then offer a 30-minute pairing session focused on idempotency (#3), because that's the concept that transfers to every future endpoint that touches money.
- **Keep the scope sensible.** Items 1–6 are a must for this PR. The non-blocking list can be a follow-up ticket if the author is short on time.
- **Share the context behind the rules.** A lot of this ("retries happen", "the endpoint is a money path") is team knowledge, not something a mid-level engineer should be expected to already know. I'd add an "endpoints that write billable data" checklist to our PR template, so the next person doesn't have to learn it through a review.
- **Acknowledge what's good.** The code is small, readable, and does the obvious thing. That's a good base to harden.

---

## What shipped

| Concern | Where |
|---|---|
| Auth (API key → merchant), tenant-scoped | [`AuthenticateMerchant`](../app/Http/Middleware/AuthenticateMerchant.php) |
| Validation, backdate window, customer ownership | [`RecordUsageRequest`](../app/Http/Requests/RecordUsageRequest.php) |
| Idempotent insert (unique index; 201 / 200 replay / 409) | [`UsageRecorder`](../app/Services/UsageRecorder.php) |
| Thin controller | [`UsageController`](../app/Http/Controllers/Api/V1/UsageController.php) |
| Per-key rate limit | [`AppServiceProvider`](../app/Providers/AppServiceProvider.php) |
| Tests | [`RecordUsageTest`](../tests/Feature/RecordUsageTest.php) |

```php
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
```
