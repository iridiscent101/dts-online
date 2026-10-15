<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentMovement;
use App\Models\Office;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OfficeManagementTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_office_management_requires_the_office_permission(): void
    {
        $manager = User::factory()->create(['permissions' => [
            'platform.index' => true,
            'platform.systems.offices' => true,
        ]]);
        $staff = User::factory()->create(['permissions' => ['platform.index' => true]]);

        $this->actingAs($manager)
            ->get(route('platform.systems.offices'))
            ->assertOk()
            ->assertSee('Office management')
            ->assertSee('Add');

        $this->actingAs($staff)
            ->get(route('platform.systems.offices'))
            ->assertForbidden();
    }

    public function test_administrator_can_create_an_office_for_assignments(): void
    {
        $admin = User::factory()->create(['permissions' => [
            'platform.index' => true,
            'platform.systems.offices' => true,
        ]]);

        $this->actingAs($admin)
            ->get(route('platform.systems.offices.create'))
            ->assertOk()
            ->assertSee('Create office')
            ->assertSee('Included in SDO list')
            ->assertSee('Included in client list')
            ->assertSee('Active');

        $this->actingAs($admin)
            ->post(route('platform.systems.offices.create').'/save', [
                'office' => [
                    'name' => 'New Records Window',
                    'sort_order' => 77,
                    'sdo_sort_order' => 12,
                    'client_sort_order' => 15,
                    'is_sdo' => '1',
                    'is_client' => '1',
                    'is_active' => '1',
                ],
            ])
            ->assertRedirectToRoute('platform.systems.offices');

        $this->assertDatabaseHas('offices', [
            'name' => 'New Records Window',
            'sort_order' => 77,
            'sdo_sort_order' => 12,
            'client_sort_order' => 15,
            'is_sdo' => true,
            'is_client' => true,
            'is_active' => true,
        ]);
    }

    public function test_administrator_can_update_and_deactivate_an_office(): void
    {
        $admin = User::factory()->create(['permissions' => [
            'platform.index' => true,
            'platform.systems.offices' => true,
        ]]);
        $office = Office::create([
            'name' => 'Temporary Desk',
            'sort_order' => 25,
            'is_sdo' => true,
            'is_client' => true,
            'is_active' => true,
        ]);

        $this->actingAs($admin)
            ->post(route('platform.systems.offices.edit', $office).'/save', [
                'office' => [
                    'name' => 'Temporary Desk Archive',
                    'sort_order' => 88,
                    'sdo_sort_order' => null,
                    'client_sort_order' => null,
                    'is_sdo' => '0',
                    'is_client' => '1',
                    'is_active' => '0',
                ],
            ])
            ->assertRedirectToRoute('platform.systems.offices');

        $office->refresh();

        $this->assertSame('Temporary Desk Archive', $office->name);
        $this->assertSame(88, $office->sort_order);
        $this->assertFalse($office->is_sdo);
        $this->assertTrue($office->is_client);
        $this->assertFalse($office->is_active);
    }

    public function test_office_with_movement_history_cannot_be_renamed(): void
    {
        $admin = User::factory()->create(['permissions' => [
            'platform.index' => true,
            'platform.systems.offices' => true,
        ]]);
        $staff = User::factory()->create([
            'office' => 'Temporary Desk',
            'permissions' => ['platform.index' => true],
        ]);
        $office = Office::create(['name' => 'Temporary Desk', 'sort_order' => 1]);
        $office->users()->attach($staff);

        $primaryDocument = Document::factory()->create(['current_office' => 'Temporary Desk']);
        $movement = $primaryDocument->movements()->create([
            'action' => 'sent',
            'to_office' => 'Temporary Desk',
            'to_status' => $primaryDocument->status,
            'acted_by' => $admin->id,
        ]);

        $secondaryDocument = Document::factory()->create(['current_office' => 'Records Unit']);
        $secondaryDocument->officeAssignments()->create([
            'office' => 'Temporary Desk',
            'status' => $secondaryDocument->status,
        ]);

        $newName = str_repeat('L', 140);

        $this->actingAs($admin)
            ->post(route('platform.systems.offices.edit', $office).'/save', [
                'office' => [
                    'name' => $newName,
                    'sort_order' => 1,
                    'is_sdo' => '0',
                    'is_client' => '0',
                    'is_active' => '1',
                ],
            ])
            ->assertSessionHasErrors(['office.name' => 'An office with document history cannot be renamed.']);

        $this->assertSame('Temporary Desk', $office->fresh()->name);
        $this->assertSame('Temporary Desk', $staff->fresh()->office);
        $this->assertSame('Temporary Desk', $primaryDocument->fresh()->current_office);
        $this->assertDatabaseHas('document_office_assignments', ['document_id' => $primaryDocument->id, 'office' => 'Temporary Desk']);
        $this->assertDatabaseHas('document_office_assignments', ['document_id' => $secondaryDocument->id, 'office' => 'Temporary Desk']);
        $this->assertSame('Temporary Desk', $movement->fresh()->to_office);

        $this->actingAs($staff)
            ->get(route('platform.documents.view', $secondaryDocument))
            ->assertOk();
    }

    public function test_renaming_an_office_preserves_a_clients_pending_paper_route(): void
    {
        $manager = User::factory()->create(['permissions' => [
            'platform.index' => true,
            'platform.systems.offices' => true,
        ]]);
        $recordsStaff = User::factory()->create([
            'office' => 'SDO-Records Unit',
            'permissions' => ['platform.index' => true],
        ]);
        $office = Office::create(['name' => 'Original Desk', 'sort_order' => 1, 'is_active' => true]);
        $document = Document::factory()->create([
            'current_office' => 'SDO-Records Unit',
            'status' => DocumentMovement::STATUS_AWAITING_PHYSICAL_DOCUMENT,
        ]);
        DB::table('document_route_steps')->insert([
            'document_id' => $document->id,
            'sequence' => 1,
            'office' => $office->name,
            'remarks' => 'Review the paper copy.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($manager)
            ->post(route('platform.systems.offices.edit', $office).'/save', [
                'office' => [
                    'name' => 'Renamed Desk',
                    'sort_order' => 1,
                    'is_sdo' => '0',
                    'is_client' => '0',
                    'is_active' => '1',
                ],
            ])
            ->assertRedirectToRoute('platform.systems.offices');

        $this->actingAs($recordsStaff)
            ->post(route('platform.documents.movements.store', $document), [
                'workflow' => [
                    'action' => 'received',
                    'source_office' => 'SDO-Records Unit',
                ],
            ])
            ->assertRedirectToRoute('platform.documents.view', $document);

        $this->assertDatabaseHas('document_office_assignments', [
            'document_id' => $document->id,
            'office' => 'Renamed Desk',
        ]);
        $this->assertDatabaseMissing('document_office_assignments', [
            'document_id' => $document->id,
            'office' => 'Original Desk',
        ]);
        $this->assertDatabaseCount('document_route_steps', 0);
    }

    public function test_records_intake_office_cannot_be_renamed(): void
    {
        $manager = User::factory()->create(['permissions' => [
            'platform.index' => true,
            'platform.systems.offices' => true,
        ]]);
        $office = Office::create([
            'name' => 'SDO-Records Unit',
            'sort_order' => 1,
            'is_active' => true,
        ]);

        $this->actingAs($manager)
            ->from(route('platform.systems.offices.edit', $office))
            ->post(route('platform.systems.offices.edit', $office).'/save', [
                'office' => [
                    'name' => 'Renamed Records Desk',
                    'sort_order' => 1,
                    'is_sdo' => '1',
                    'is_client' => '1',
                    'is_active' => '1',
                ],
            ])
            ->assertRedirect(route('platform.systems.offices.edit', $office))
            ->assertSessionHasErrors(['office.name' => 'The Records intake office cannot be renamed.']);

        $this->assertSame('SDO-Records Unit', $office->fresh()->name);
    }

    public function test_records_intake_office_cannot_be_deactivated(): void
    {
        $manager = User::factory()->create(['permissions' => [
            'platform.index' => true,
            'platform.systems.offices' => true,
        ]]);
        $office = Office::create(['name' => 'SDO-Records Unit', 'sort_order' => 1, 'is_active' => true]);

        $this->actingAs($manager)
            ->from(route('platform.systems.offices.edit', $office))
            ->post(route('platform.systems.offices.edit', $office).'/save', [
                'office' => [
                    'name' => 'SDO-Records Unit',
                    'sort_order' => 1,
                    'is_active' => '0',
                ],
            ])
            ->assertRedirect(route('platform.systems.offices.edit', $office))
            ->assertSessionHasErrors(['office.is_active' => 'The Records intake office must remain active.']);

        $this->assertTrue($office->fresh()->is_active);
    }

    public function test_office_names_must_be_unique(): void
    {
        $admin = User::factory()->create(['permissions' => [
            'platform.index' => true,
            'platform.systems.offices' => true,
        ]]);

        Office::create(['name' => 'Records Unit', 'sort_order' => 1]);

        $this->actingAs($admin)
            ->from(route('platform.systems.offices.create'))
            ->post(route('platform.systems.offices.create').'/save', [
                'office' => [
                    'name' => 'Records Unit',
                    'sort_order' => 2,
                    'is_sdo' => '1',
                    'is_client' => '0',
                    'is_active' => '1',
                ],
            ])
            ->assertRedirect(route('platform.systems.offices.create'))
            ->assertSessionHasErrors('office.name');
    }

    public function test_office_list_can_be_searched_sorted_and_paginated(): void
    {
        $admin = User::factory()->create(['permissions' => [
            'platform.index' => true,
            'platform.systems.offices' => true,
        ]]);

        Office::create(['name' => 'Office Alpha', 'sort_order' => 2, 'is_sdo' => true, 'is_active' => true]);
        Office::create(['name' => 'Office Zulu', 'sort_order' => 1, 'is_client' => true, 'is_active' => true]);
        Office::create(['name' => 'Unrelated Desk', 'sort_order' => 3, 'is_active' => true]);

        $this->actingAs($admin)
            ->get(route('platform.systems.offices', [
                'office_search' => 'Office',
                'sort' => '-name',
                'per_page' => 25,
            ]))
            ->assertOk()
            ->assertSee('<turbo-frame id="admin-offices-table"', false)
            ->assertSee('Search offices')
            ->assertSee('data-table-select-all', false)
            ->assertSee('data-table-row-select', false)
            ->assertSee('data-column="number"', false)
            ->assertSee('Rows per page')
            ->assertSee('SDO')
            ->assertSee('Client')
            ->assertSee('Active')
            ->assertSee('Edit')
            ->assertDontSee('Sort')
            ->assertSeeInOrder(['Office Zulu', 'Office Alpha'])
            ->assertDontSee('Unrelated Desk');
    }

    public function test_office_list_bulk_deletes_only_unused_offices(): void
    {
        $manager = User::factory()->create(['permissions' => [
            'platform.index' => true,
            'platform.systems.offices' => true,
        ]]);
        $selected = collect([
            Office::create(['name' => 'Temporary Desk A', 'sort_order' => 1]),
            Office::create(['name' => 'Temporary Desk B', 'sort_order' => 2, 'is_sdo' => true]),
        ]);
        $unselected = Office::create(['name' => 'Keep Desk', 'sort_order' => 3]);

        $this->actingAs($manager)
            ->get(route('platform.systems.offices'))
            ->assertSee('data-admin-bulk-delete-kind="office"', false);

        $this->post(route('platform.systems.offices').'/bulkRemove', [
            'selected' => $selected->pluck('id')->all(),
        ])->assertRedirect();

        foreach ($selected as $office) {
            $this->assertDatabaseMissing('offices', ['id' => $office->id]);
        }
        $this->assertDatabaseHas('offices', ['id' => $unselected->id]);
    }

    public function test_office_bulk_delete_rejects_official_and_referenced_offices_without_partial_changes(): void
    {
        $manager = User::factory()->create(['permissions' => [
            'platform.index' => true,
            'platform.systems.offices' => true,
        ]]);
        $unused = Office::create(['name' => 'Unused Desk', 'sort_order' => 1]);
        $official = Office::create(['name' => 'SDO-Records Unit', 'sort_order' => 2, 'is_sdo' => true]);
        $inUse = Office::create(['name' => 'Assigned Desk', 'sort_order' => 3]);
        Document::factory()->create(['current_office' => $inUse->name]);
        $staffOffice = Office::create(['name' => 'Staff Desk', 'sort_order' => 4]);
        User::factory()->create(['office' => $staffOffice->name]);
        $historyOffice = Office::create(['name' => 'History Desk', 'sort_order' => 5]);
        $document = Document::factory()->create(['current_office' => 'Records Unit']);
        $document->movements()->create([
            'action' => 'sent',
            'to_office' => $historyOffice->name,
            'to_status' => $document->status,
        ]);
        $plannedOffice = Office::create(['name' => 'Planned Desk', 'sort_order' => 6]);
        DB::table('document_route_steps')->insert([
            'document_id' => $document->id,
            'sequence' => 1,
            'office' => $plannedOffice->name,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $url = route('platform.systems.offices').'/bulkRemove';

        foreach ([$official, $inUse, $staffOffice, $historyOffice, $plannedOffice] as $protected) {
            $this->actingAs($manager)
                ->from(route('platform.systems.offices'))
                ->post($url, ['selected' => [$unused->id, $protected->id]])
                ->assertRedirect(route('platform.systems.offices'))
                ->assertSessionHasErrors('selected');

            $this->assertDatabaseHas('offices', ['id' => $unused->id]);
            $this->assertDatabaseHas('offices', ['id' => $protected->id]);
        }
    }

    public function test_office_bulk_delete_requires_permission_and_valid_selection(): void
    {
        $manager = User::factory()->create(['permissions' => [
            'platform.index' => true,
            'platform.systems.offices' => true,
        ]]);
        $viewer = User::factory()->create(['permissions' => ['platform.index' => true]]);
        $office = Office::create(['name' => 'Unused Desk', 'sort_order' => 1]);
        $url = route('platform.systems.offices').'/bulkRemove';

        $this->actingAs($viewer)
            ->post($url, ['selected' => [$office->id]])
            ->assertForbidden();

        $this->actingAs($manager)
            ->from(route('platform.systems.offices'))
            ->post($url, ['selected' => [$office->id, $office->id]])
            ->assertRedirect(route('platform.systems.offices'))
            ->assertSessionHasErrors();

        $this->assertDatabaseHas('offices', ['id' => $office->id]);
    }
}
