<?php

namespace Tests\Feature;

use App\Models\Office;
use Database\Seeders\OfficeSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class OfficeSeederTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_it_seeds_the_sdo_and_client_office_lists_without_duplicates(): void
    {
        $this->seed(OfficeSeeder::class);
        $this->seed(OfficeSeeder::class);

        $this->assertDatabaseCount('offices', 60);
        $this->assertDatabaseHas('offices', [
            'name' => 'SDO-Schools Division Superintendent (SDS) Office',
            'sort_order' => 1,
            'sdo_sort_order' => 1,
            'client_sort_order' => 1,
            'is_sdo' => true,
            'is_client' => true,
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('offices', [
            'name' => 'SDO-SGOD Personnel (SMME/SMAN/EFU/YFP/DRRM/HNU)',
            'sdo_sort_order' => null,
            'client_sort_order' => 4,
            'is_sdo' => false,
            'is_client' => true,
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('offices', [
            'name' => 'SDO-CID Personnel (EPS/PSDS)',
            'sdo_sort_order' => null,
            'client_sort_order' => 6,
            'is_sdo' => false,
            'is_client' => true,
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('offices', [
            'name' => 'SDO-EPS (School Sports/ Quality Management Office (QMO))',
            'sdo_sort_order' => 14,
            'client_sort_order' => null,
            'is_sdo' => true,
            'is_client' => false,
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('offices', [
            'name' => 'SDO-School Sports/ Quality Management Office (QMO)',
            'sdo_sort_order' => null,
            'client_sort_order' => 17,
            'is_sdo' => false,
            'is_client' => true,
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('offices', [
            'name' => 'SDO-PSDS (Villaverde District)',
            'sdo_sort_order' => 55,
            'client_sort_order' => null,
            'is_sdo' => true,
            'is_client' => false,
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('offices', [
            'name' => 'Personal Delivery',
            'sdo_sort_order' => 56,
            'client_sort_order' => 20,
            'is_sdo' => true,
            'is_client' => true,
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('offices', [
            'name' => 'Others',
            'sdo_sort_order' => 57,
            'client_sort_order' => 21,
            'is_sdo' => true,
            'is_client' => true,
            'is_active' => true,
        ]);

        $this->assertSame(60, Office::query()->distinct()->count('name'));
        $this->assertSame(57, Office::query()->where('is_sdo', true)->count());
        $this->assertSame(21, Office::query()->where('is_client', true)->count());
    }

    public function test_it_preserves_offices_not_owned_by_the_official_list(): void
    {
        Office::query()->create([
            'name' => 'Local Testing Office',
            'sort_order' => 999,
            'is_active' => false,
        ]);

        $this->seed(OfficeSeeder::class);

        $this->assertDatabaseCount('offices', 61);
        $this->assertDatabaseHas('offices', [
            'name' => 'Local Testing Office',
            'sort_order' => 999,
            'is_sdo' => false,
            'is_client' => false,
            'is_active' => false,
        ]);
    }
}
