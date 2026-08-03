<?php

namespace Database\Seeders;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $tenant = Tenant::create([
            'name' => 'Demo Tenant',
            'slug' => 'demo',
        ]);

        User::factory()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Demo Owner',
            'email' => 'owner@demo.test',
            'role' => 'owner',
        ]);
    }
}
