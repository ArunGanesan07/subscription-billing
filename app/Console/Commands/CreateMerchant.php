<?php

namespace App\Console\Commands;

use App\Models\Merchant;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class CreateMerchant extends Command
{
    protected $signature = 'merchant:create {name} {--currency=INR}';

    protected $description = 'Create a merchant (tenant) and print its API key once';

    public function handle(): int
    {
        $merchant = new Merchant([
            'name' => $this->argument('name'),
            'slug' => Str::slug($this->argument('name')).'-'.Str::lower(Str::random(4)),
            'currency' => strtoupper($this->option('currency')),
        ]);
        $key = $merchant->issueApiKey();
        $merchant->save();

        $this->info("Merchant #{$merchant->id} created.");
        $this->warn("API key (shown once, store it now): {$key}");

        return self::SUCCESS;
    }
}
