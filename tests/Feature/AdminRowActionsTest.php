<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\Office;
use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class AdminRowActionsTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manager = User::factory()->create([
            'permissions' => ['platform.index' => true, 'platform.systems.offices' => true, 'platform.systems.schools' => true],
        ]);
    }

    public function test_schools_can_be_renamed_and_removed_from_their_row(): void
    {
        $school = School::factory()->create(['source_unit_id' => 300, 'name' => 'Abaca ES']);

        $this->actingAs($this->manager)
            ->get(route('platform.systems.schools'))
            ->assertSee('editSchoolModal', false)
            ->assertSee('Remove Abaca ES from the school list?');

        $this->post(route('platform.systems.schools', ['method' => 'saveSchool', 'school' => $school->id]), [
            'school' => ['name' => 'Abaca Elementary School'],
        ])->assertRedirect();
        $this->assertSame('Abaca Elementary School', $school->fresh()->name);
        $this->assertSame(300, $school->fresh()->source_unit_id);

        $this->post(route('platform.systems.schools', ['method' => 'saveSchool', 'school' => $school->id]), [
            'school' => ['name' => ''],
        ])->assertSessionHasErrors('school.name');

        $this->post(route('platform.systems.schools', ['method' => 'remove', 'id' => $school->id]))->assertRedirect();
        $this->assertSoftDeleted($school);
    }

    public function test_unused_offices_can_be_deleted_from_their_row_but_used_ones_cannot(): void
    {
        $unused = Office::create(['name' => 'Unused Desk', 'sort_order' => 1]);
        $used = Office::create(['name' => 'Busy Desk', 'sort_order' => 2]);
        Document::factory()->create(['current_office' => 'Busy Desk']);
        Office::create(['name' => 'SDO-Records Unit', 'sort_order' => 3]);

        $this->actingAs($this->manager)
            ->get(route('platform.systems.offices'))
            ->assertSee('Delete Unused Desk?')
            ->assertSee('Delete Busy Desk?')
            ->assertDontSee('Delete SDO-Records Unit?');

        $this->post(route('platform.systems.offices', ['method' => 'remove', 'id' => $used->id]))->assertRedirect();
        $this->assertModelExists($used);

        $this->post(route('platform.systems.offices', ['method' => 'remove', 'id' => $unused->id]))->assertRedirect();
        $this->assertModelMissing($unused);
    }
}
