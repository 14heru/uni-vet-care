<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    /**
     * Seed the canonical application roles.
     */
    public function run(): void
    {
        foreach (['owner', 'admin', 'doctor', 'customer'] as $name) {
            Role::firstOrCreate(['name' => $name]);
        }
    }
}
