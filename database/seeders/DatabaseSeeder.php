<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command->error('Refusing to seed demo data (with well-known API keys) in production.');

            return;
        }

        $this->call(DemoSeeder::class);
    }
}
