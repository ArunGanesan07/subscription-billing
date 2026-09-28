<?php

namespace Database\Factories;

use App\Models\Merchant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Merchant>
 */
class MerchantFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(5)),
            'currency' => 'INR',
            'api_key_hash' => Merchant::hashApiKey('mk_'.Str::random(40)),
            'api_key_prefix' => 'mk_factory',
        ];
    }

    /** Use a known plaintext key (tests need to authenticate with it). */
    public function withApiKey(string $plainKey): static
    {
        return $this->state(fn () => [
            'api_key_hash' => Merchant::hashApiKey($plainKey),
            'api_key_prefix' => substr($plainKey, 0, 10),
        ]);
    }
}
