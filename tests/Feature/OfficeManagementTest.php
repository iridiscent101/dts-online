<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentMovement;
use App\Models\KioskSigningRequest;
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
        $office = Office::query()->where('name', 'Temporary Desk')->sole();

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
        $this->assertSame(['Temporary Desk'], $staff->fresh()->assignedOfficeNames()->all());
        $this->assertSame('Temporary Desk', $primaryDocument->fresh()->current_office);
        $this->assertDatabaseHas('document_office_assignments', ['document_id' => $primaryDocument->id, 'office' => 'Temporary Desk']);
        $this->assertDatabaseHas('document_office_assignments', ['document_id' => $secondaryDocument->id, 'office' => 'Temporary Desk']);
        $this->assertSame('Temporary Desk', $movement->fresh()->to_office);

        $this->actingAs($staff)
            ->get(route('platform.documents.workspace', $secondaryDocument))
            ->assertOk();
    }

    public function test_office_registered_on_a_document_without_movements_cannot_be_renamed(): void
    {
        $manager = User::factory()->create(['permissions' => [
            'platform.index' => true,
            'platform.systems.offices' => true,
        ]]);
        $office = Office::create(['name' => 'Original Desk', 'sort_order' => 1, 'is_active' => true]);
        $document = Document::factory()->create([
            'current_office' => 'Records Unit',
            'registered_office' => $office->name,
        ]);

        $this->actingAs($manager)
            ->post(route('platform.systems.offices.edit', $office).'/save', [
                'office' => [
                    'name' => 'Renamed Desk',
                    'sort_order' => 1,
                    'is_active' => '1',
                ],
            ])
            ->assertSessionHasErrors('office.name');

        $this->assertSame('Original Desk', $office->fresh()->name);
        $this->assertSame('Original Desk', $document->fresh()->registered_office);
    }

    public function test_office_can_be_deleted_without_removing_its_document_references(): void
    {
        $manager = User::factory()->create(['permissions' => [
            'platform.index' => true,
            'platform.systems.offices' => true,
        ]]);
        $office = Office::create(['name' => 'Original Desk', 'sort_order' => 1, 'is_active' => true]);
        $document = Document::factory()->create([
            'current_office' => 'Records Unit',
            'registered_office' => $office->name,
        ]);

        $this->actingAs($manager)
            ->post(route('platform.systems.offices').'/remove', [
                'id' => $office->id,
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('offices', ['id' => $office->id]);
        $this->assertSame('Original Desk', $document->fresh()->registered_office);
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

    public function test_office_bulk_delete_protects_records_but_allows_referenced_offices(): void
    {
        $manager = User::factory()->create(['permissions' => [
            'platform.index' => true,
            'platform.systems.offices' => true,
        ]]);
        $unused = Office::create(['name' => 'Unused Desk', 'sort_order' => 1]);
        $official = Office::create(['name' => 'SDO-Records Unit', 'sort_order' => 2, 'is_sdo' => true]);
        $inUse = Office::create(['name' => 'Assigned Desk', 'sort_order' => 3]);
        $assignedDocument = Document::factory()->create(['current_office' => $inUse->name]);
        $staffOffice = Office::create(['name' => 'Staff Desk', 'sort_order' => 4]);
        $staff = User::factory()->create(['office' => $staffOffice->name]);
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

        $this->actingAs($manager)
            ->from(route('platform.systems.offices'))
            ->post($url, ['selected' => [$unused->id, $official->id]])
            ->assertRedirect(route('platform.systems.offices'))
            ->assertSessionHasErrors('selected');

        $this->assertDatabaseHas('offices', ['id' => $unused->id]);
        $this->assertDatabaseHas('offices', ['id' => $official->id]);

        $selected = [$unused->id, $inUse->id, $staffOffice->id, $historyOffice->id, $plannedOffice->id];
        $this->post($url, ['selected' => $selected])->assertSessionHasNoErrors();

        foreach ($selected as $officeId) {
            $this->assertDatabaseMissing('offices', ['id' => $officeId]);
        }
        $this->assertSame([], $staff->fresh()->assignedOfficeNames()->all());
        $this->assertSame(Office::RECORDS_UNIT_NAME, $assignedDocument->fresh()->current_office);
        $this->assertDatabaseMissing('document_office_assignments', ['document_id' => $assignedDocument->id, 'office' => 'Assigned Desk']);
        $this->assertDatabaseHas('document_office_assignments', ['document_id' => $assignedDocument->id, 'office' => Office::RECORDS_UNIT_NAME, 'status' => DocumentMovement::STATUS_FORWARDED]);
        $this->assertDatabaseHas('document_movements', ['document_id' => $assignedDocument->id, 'from_office' => 'Assigned Desk', 'to_office' => Office::RECORDS_UNIT_NAME, 'remarks' => 'The office Assigned Desk was deleted. Its pending work was transferred to Records Unit.']);
        $this->assertDatabaseHas('document_movements', ['document_id' => $document->id, 'to_office' => 'History Desk']);
        $this->assertDatabaseMissing('document_route_steps', ['document_id' => $document->id, 'office' => 'Planned Desk']);
        $this->assertDatabaseHas('document_route_steps', ['document_id' => $document->id, 'office' => Office::RECORDS_UNIT_NAME]);

        $records = User::factory()->create(['office' => Office::RECORDS_UNIT_NAME, 'permissions' => ['platform.index' => true]]);
        $this->actingAs($records)->get(route('platform.documents.workspace', $assignedDocument))
            ->assertSee('The office Assigned Desk was deleted. Its pending work was transferred to Records Unit.');
        $this->postJson(route('platform.documents.movements.store', $assignedDocument), [
            'workflow' => ['action' => 'received', 'source_office' => Office::RECORDS_UNIT_NAME],
        ])->assertOk();
        $this->assertSame(DocumentMovement::STATUS_RECEIVED, $assignedDocument->fresh()->status);
    }

    public function test_office_deletion_preserves_existing_records_receipt_other_routes_and_finished_documents(): void
    {
        $manager = User::factory()->create(['permissions' => ['platform.index' => true, 'platform.systems.offices' => true]]);
        $office = Office::create(['name' => 'Closing Desk', 'is_active' => true]);
        $document = Document::factory()->received()->create(['current_office' => $office->name]);
        $receipt = $document->movements()->create([
            'action' => 'received', 'from_office' => Office::RECORDS_UNIT_NAME, 'to_office' => Office::RECORDS_UNIT_NAME,
            'from_status' => 'Forwarded', 'to_status' => 'Received',
        ]);
        $document->officeAssignments()->create(['office' => Office::RECORDS_UNIT_NAME, 'status' => 'Received', 'movement_id' => $receipt->id]);
        $document->officeAssignments()->create(['office' => 'SDO-Legal Unit', 'status' => 'Received']);
        $finished = Document::factory()->create(['current_office' => $office->name, 'status' => 'Completed']);

        $this->actingAs($manager)->post(route('platform.systems.offices').'/remove', ['id' => $office->id])
            ->assertSessionHasNoErrors();

        $this->assertSame(Office::RECORDS_UNIT_NAME, $document->fresh()->current_office);
        $this->assertDatabaseHas('document_office_assignments', ['document_id' => $document->id, 'office' => Office::RECORDS_UNIT_NAME, 'status' => 'Received', 'movement_id' => $receipt->id]);
        $this->assertSame(1, $document->officeAssignments()->where('office', Office::RECORDS_UNIT_NAME)->count());
        $this->assertDatabaseHas('document_office_assignments', ['document_id' => $document->id, 'office' => 'SDO-Legal Unit', 'status' => 'Received']);
        $this->assertSame('Completed', $finished->fresh()->status);
        $this->assertSame('Closing Desk', $finished->current_office);
        $this->assertSame(0, $finished->movements()->count());
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

    public function test_transfer_invalidates_an_existing_records_kiosk_request(): void
    {
        $manager = User::factory()->create(['permissions' => ['platform.index' => true, 'platform.systems.offices' => true]]);
        $office = Office::create(['name' => 'Closing Desk', 'is_active' => true]);
        $document = Document::factory()->received()->create(['current_office' => $office->name]);
        $document->officeAssignments()->create(['office' => Office::RECORDS_UNIT_NAME, 'status' => 'Received']);
        $signing = KioskSigningRequest::create([
            'document_id' => $document->id, 'requested_by' => $manager->id, 'office' => Office::RECORDS_UNIT_NAME,
            'status' => KioskSigningRequest::STATUS_PENDING, 'expires_at' => now()->addMinutes(10),
        ]);

        $this->actingAs($manager)->post(route('platform.systems.offices').'/remove', ['id' => $office->id])->assertSessionHasNoErrors();

        $this->assertSame(KioskSigningRequest::STATUS_CANCELLED, $signing->fresh()->status);
    }

    public function test_pending_submission_keeps_its_intake_state_and_instructions_when_offices_merge_into_records(): void
    {
        $manager = User::factory()->create(['permissions' => ['platform.index' => true, 'platform.systems.offices' => true]]);
        $records = User::factory()->create(['office' => Office::RECORDS_UNIT_NAME, 'permissions' => ['platform.index' => true]]);
        $office = Office::create(['name' => 'Closing Desk', 'is_active' => true]);
        $document = Document::factory()->create(['current_office' => Office::RECORDS_UNIT_NAME, 'status' => DocumentMovement::STATUS_AWAITING_PHYSICAL_DOCUMENT, 'received_at' => null]);
        foreach ([Office::RECORDS_UNIT_NAME, $office->name] as $index => $name) {
            DB::table('document_route_steps')->insert([
                'document_id' => $document->id, 'sequence' => $index + 1, 'office' => $name,
                'remarks' => $index === 1 ? 'Verify the attached budget.' : null, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $this->actingAs($manager)->post(route('platform.systems.offices').'/remove', ['id' => $office->id])->assertSessionHasNoErrors();

        $this->assertSame(DocumentMovement::STATUS_AWAITING_PHYSICAL_DOCUMENT, $document->fresh()->status);
        $this->assertNull($document->received_at);
        $this->assertSame(1, DB::table('document_route_steps')->where('document_id', $document->id)->count());
        $this->actingAs($records)->get(route('platform.documents.workspace', $document))->assertSee('Verify the attached budget.');
        $this->postJson(route('platform.documents.movements.store', $document), [
            'workflow' => ['action' => 'received', 'source_office' => Office::RECORDS_UNIT_NAME],
        ])->assertOk();
        $this->assertSame(DocumentMovement::STATUS_RECEIVED, $document->fresh()->status);
        $this->assertNotNull($document->fresh()->received_at);
    }

    public function test_bulk_transfer_merges_routes_and_reopens_a_previously_released_records_route(): void
    {
        $manager = User::factory()->create(['permissions' => ['platform.index' => true, 'platform.systems.offices' => true]]);
        $first = Office::create(['name' => 'Closing Desk A', 'is_active' => true]);
        $second = Office::create(['name' => 'Closing Desk B', 'is_active' => true]);
        $document = Document::factory()->received()->create(['current_office' => $first->name]);
        $document->officeAssignments()->create(['office' => $second->name, 'status' => 'Received']);
        $document->officeAssignments()->create(['office' => Office::RECORDS_UNIT_NAME, 'status' => 'Released']);

        $this->actingAs($manager)->post(route('platform.systems.offices').'/bulkRemove', ['selected' => [$first->id, $second->id]])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, $document->officeAssignments()->count());
        $this->assertDatabaseHas('document_office_assignments', ['document_id' => $document->id, 'office' => Office::RECORDS_UNIT_NAME, 'status' => 'Forwarded']);
        $this->assertSame(2, $document->movements()->where('action', 'forwarded')->count());
        $this->assertSame('Forwarded', $document->fresh()->status);
        $this->assertSame(Office::RECORDS_UNIT_NAME, $document->fresh()->current_office);
    }
}
