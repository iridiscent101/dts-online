<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\School;
use App\Models\User;
use Database\Seeders\SchoolSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SchoolCatalogTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_school_import_keeps_one_row_per_name_and_can_be_repeated(): void
    {
        $this->seed(SchoolSeeder::class);
        $this->seed(SchoolSeeder::class);

        $this->assertDatabaseCount('schools', 380);
        $this->assertDatabaseHas('schools', ['source_unit_id' => 200, 'name' => 'Abaca ES']);
        $this->assertDatabaseHas('schools', ['source_unit_id' => 584, 'name' => 'Yaway ES']);
        $this->assertSame(1, School::query()->where('name', 'Bugkalot High School')->count());
        $this->assertSame(0, School::query()->whereIn('source_unit_id', [298, 424, 519, 522, 531])->count());
    }

    public function test_duplicate_cleanup_permanently_removes_only_the_extra_source_rows(): void
    {
        $this->seed(SchoolSeeder::class);

        foreach ([
            298 => 'Bugkalot High School',
            424 => 'Luclocos Integrated School',
            519 => 'San Juan ES',
            522 => 'San Luis ES',
            531 => 'Solano East CS',
        ] as $sourceId => $name) {
            School::factory()->create(['source_unit_id' => $sourceId, 'name' => $name])->delete();
        }

        $unrelated = School::factory()->create(['source_unit_id' => 999, 'name' => 'Other School']);
        $unrelated->delete();

        (require database_path('migrations/2026_10_14_054019_permanently_remove_duplicate_school_catalog_rows.php'))->up();

        $this->assertSame(0, DB::table('schools')->whereIn('source_unit_id', [298, 424, 519, 522, 531])->count());
        $this->assertSame(1, DB::table('schools')->where('source_unit_id', 999)->count());
        $this->assertSame(1, DB::table('schools')->where('source_unit_id', 297)->count());
    }

    public function test_schools_are_suggested_as_origins_without_becoming_routing_offices(): void
    {
        School::factory()->create(['name' => 'Abaca ES']);

        $this->assertContains('Abaca ES', Document::originOptions());
        $this->assertNotContains('Abaca ES', Document::officeOptions());

        $this->get(route('submission.create'))
            ->assertOk()
            ->assertSee('data-options="origin-units"', false)
            ->assertSee('value="Abaca ES"', false);

        $user = User::factory()->create(['permissions' => ['platform.index' => true]]);
        $this->actingAs($user)
            ->get(route('platform.documents.create'))
            ->assertOk()
            ->assertSee('data-options="origin-units"', false)
            ->assertSee('value="Abaca ES"', false);
    }

    public function test_school_management_requires_permission_and_lists_searchable_schools(): void
    {
        $manager = User::factory()->create(['permissions' => [
            'platform.index' => true,
            'platform.systems.offices' => true,
            'platform.systems.schools' => true,
        ]]);
        $staff = User::factory()->create(['permissions' => ['platform.index' => true]]);
        School::factory()->create(['source_unit_id' => 200, 'name' => 'Abaca ES']);
        School::factory()->create(['source_unit_id' => 201, 'name' => 'Bugkalot High School']);

        $this->actingAs($manager)
            ->get(route('platform.systems.schools', [
                'school_search' => 'Abaca',
                'per_page' => 25,
            ]))
            ->assertOk()
            ->assertSee('School Management')
            ->assertSee('<turbo-frame id="admin-schools-table"', false)
            ->assertSee('class="dts-registry-table-toolbar"', false)
            ->assertSee('data-admin-bulk-delete-kind="school"', false)
            ->assertSee('data-table-select-all', false)
            ->assertSee('data-table-row-select', false)
            ->assertSeeInOrder(['Offices', 'Schools'])
            ->assertSee('Search schools')
            ->assertSee('School name')
            ->assertDontSee('Source unit ID')
            ->assertSee('Abaca ES')
            ->assertDontSee('Bugkalot High School');

        $this->actingAs($staff)
            ->get(route('platform.systems.schools'))
            ->assertForbidden();
    }

    public function test_school_bulk_delete_removes_only_selected_schools(): void
    {
        $manager = User::factory()->create(['permissions' => [
            'platform.index' => true,
            'platform.systems.schools' => true,
        ]]);
        $selected = School::factory()->count(2)->create();
        $unselected = School::factory()->create();
        $document = Document::factory()->create(['origin' => $selected->first()->name]);

        $this->actingAs($manager)
            ->post(route('platform.systems.schools').'/bulkRemove', [
                'selected' => $selected->modelKeys(),
            ])
            ->assertRedirect();

        foreach ($selected as $school) {
            $this->assertSoftDeleted($school);
            $this->assertNotContains($school->name, Document::originOptions());
        }

        $this->assertDatabaseHas('schools', ['id' => $unselected->id]);
        $this->assertSame($selected->first()->name, $document->fresh()->origin);
    }

    public function test_reseeding_does_not_restore_a_deleted_school(): void
    {
        $this->seed(SchoolSeeder::class);
        $school = School::query()->where('source_unit_id', 200)->firstOrFail();
        $school->delete();

        $this->seed(SchoolSeeder::class);

        $this->assertSoftDeleted($school);
        $this->assertSame(379, School::query()->count());
        $this->assertNotContains('Abaca ES', Document::originOptions());
    }

    public function test_school_bulk_delete_requires_permission_and_valid_selection(): void
    {
        $manager = User::factory()->create(['permissions' => [
            'platform.index' => true,
            'platform.systems.schools' => true,
        ]]);
        $staff = User::factory()->create(['permissions' => ['platform.index' => true]]);
        $school = School::factory()->create();
        $url = route('platform.systems.schools').'/bulkRemove';

        $this->actingAs($staff)
            ->post($url, ['selected' => [$school->id]])
            ->assertForbidden();

        $this->actingAs($manager)
            ->from(route('platform.systems.schools'))
            ->post($url, ['selected' => [$school->id, $school->id]])
            ->assertRedirect(route('platform.systems.schools'))
            ->assertSessionHasErrors();

        $this->assertDatabaseHas('schools', ['id' => $school->id]);
    }
}
