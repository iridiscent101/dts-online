<?php

namespace Tests\Feature;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class DatabaseSeederTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_default_seeding_loads_catalogs_without_demo_accounts_or_documents(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertDatabaseCount('offices', 60);
        $this->assertDatabaseCount('schools', 380);
        $this->assertDatabaseCount('document_types', 135);
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('documents', 0);
    }
}
