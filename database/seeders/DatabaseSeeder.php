<?php

namespace Database\Seeders;

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
        // No seed data yet — this session builds schema, tenant-context
        // plumbing, and the tenant-isolation suite only. A future session
        // adds real local-development fixtures here.
    }
}
