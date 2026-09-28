<?php

namespace App\Models;

use App\Observers\MerchantObserver;
use Database\Factories\MerchantFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[ObservedBy(MerchantObserver::class)]
class Merchant extends Model
{
    /** @use HasFactory<MerchantFactory> */
    use HasFactory;

    protected $fillable = ['name', 'slug', 'currency'];

    protected $hidden = ['api_key_hash'];

    public static function hashApiKey(string $plainKey): string
    {
        return hash('sha256', $plainKey);
    }

    /**
     * Sets a new API key and returns the plaintext. Only its hash is stored.
     */
    public function issueApiKey(?string $plainKey = null): string
    {
        $plainKey ??= 'mk_'.Str::random(40);

        $this->api_key_hash = self::hashApiKey($plainKey);
        $this->api_key_prefix = substr($plainKey, 0, 10);

        return $plainKey;
    }

    public function plans(): HasMany
    {
        return $this->hasMany(Plan::class);
    }

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }
}
