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

        // Edit opens the school's own page, like Users, Offices and Roles; there is no edit dialog on the list.
        $this->actingAs($this->manager)
            ->get(route('platform.systems.schools'))
            ->assertSee(route('platform.systems.schools.edit', $school->id), false)
            ->assertDontSee('editSchoolModal', false)
            ->assertSee('Remove Abaca ES from the school list?');

        $this->get(route('platform.systems.schools.edit', $school))
            ->assertOk()
            ->assertSee('Edit school')
            ->assertSee('value="Abaca ES"', false);

        $this->post(route('platform.systems.schools.edit', $school).'/save', [
            'school' => ['name' => 'Abaca Elementary School'],
        ])->assertRedirectToRoute('platform.systems.schools');
        $this->assertSame('Abaca Elementary School', $school->fresh()->name);
        $this->assertSame(300, $school->fresh()->source_unit_id);

        $this->post(route('platform.systems.schools.edit', $school).'/save', [
            'school' => ['name' => ''],
        ])->assertSessionHasErrors('school.name');

        // Renaming to another school's name is refused, but keeping its own name is fine.
        School::factory()->create(['name' => 'Bagabag ES']);
        $this->post(route('platform.systems.schools.edit', $school).'/save', [
            'school' => ['name' => 'Bagabag ES'],
        ])->assertSessionHasErrors('school.name');
        $this->post(route('platform.systems.schools.edit', $school).'/save', [
            'school' => ['name' => 'Abaca Elementary School'],
        ])->assertSessionHasNoErrors();

        $this->post(route('platform.systems.schools', ['method' => 'remove', 'id' => $school->id]))->assertRedirect();
        $this->assertSoftDeleted($school);
    }

    public function test_offices_can_be_deleted_from_their_row_and_used_ones_hand_their_documents_to_records(): void
    {
        $unused = Office::create(['name' => 'Unused Desk', 'sort_order' => 1]);
        $used = Office::create(['name' => 'Busy Desk', 'sort_order' => 2]);
        $document = Document::factory()->create(['current_office' => 'Busy Desk']);
        Office::create(['name' => 'SDO-Records Unit', 'sort_order' => 3]);

        $this->actingAs($this->manager)
            ->get(route('platform.systems.offices'))
            ->assertSee('Delete Unused Desk?')
            ->assertSee('Delete Busy Desk?')
            ->assertDontSee('Delete SDO-Records Unit?');

        $this->post(route('platform.systems.offices', ['method' => 'remove', 'id' => $used->id]))->assertRedirect();
        // A used office can be deleted too; its open document moves to Records.
        $this->assertModelMissing($used);
        $this->assertSame('SDO-Records Unit', $document->fresh()->current_office);

        $this->post(route('platform.systems.offices', ['method' => 'remove', 'id' => $unused->id]))->assertRedirect();
        $this->assertModelMissing($unused);
    }
}
