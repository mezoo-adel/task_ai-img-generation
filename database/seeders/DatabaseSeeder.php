<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // The demo account is for local development only — never seed it in
        // other environments, and never overwrite an account that already
        // uses this address.
        if (! app()->environment('local', 'testing')) {
            return;
        }

        if (! User::where('email', 'admin@example.com')->exists()) {
            User::factory()->create([
                'name' => 'Test User',
                'email' => 'admin@example.com',
                'password' => 'admin123',
            ]);
        }
    }
}
