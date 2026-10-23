<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class AssignmentMovementBackfillTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_old_route_rows_are_linked_and_count_on_the_dashboard_again(): void
    {
        $budget = User::factory()->create(['office' => 'SDO-Budget Unit', 'permissions' => ['platform.index' => true]]);
        $document = Document::factory()->create(['current_office' => 'SDO-Legal Unit', 'status' => DocumentMovement::STATUS_FORWARDED]);
        $document->officeAssignments()->delete();
        $forward = $document->movements()->create([
            'action' => DocumentMovement::ACTION_FORWARDED, 'from_office' => 'SDO-Budget Unit', 'to_office' => 'SDO-Legal Unit',
            'from_status' => DocumentMovement::STATUS_RECEIVED, 'to_status' => DocumentMovement::STATUS_FORWARDED,
        ]);
        // As the 2026_10_03 migration made it: no link to the step.
        $legacy = $document->officeAssignments()->create(['office' => 'SDO-Legal Unit', 'status' => DocumentMovement::STATUS_FORWARDED, 'movement_id' => null]);
        // A row with no step for its office stays empty, and a row already linked is not touched.
        $orphan = Document::factory()->create(['current_office' => 'SDO-Cash Unit']);
        $orphan->officeAssignments()->update(['movement_id' => null]);
        // Already received at Legal, so it never counts as waiting (a random status would make the count flaky).
        $linkedDocument = Document::factory()->received()->create(['current_office' => 'SDO-Legal Unit']);
        $linkedStep = $linkedDocument->movements()->create(['action' => 'forwarded', 'from_office' => 'SDO-Budget Unit', 'to_office' => 'SDO-Legal Unit', 'from_status' => 'Received', 'to_status' => 'Forwarded']);
        $linkedDocument->officeAssignments()->update(['movement_id' => $linkedStep->id, 'status' => DocumentMovement::STATUS_RECEIVED]);

        // The Outgoing card reads "...has not received it yet" for exactly one document, "them" otherwise.
        $this->actingAs($budget)->get(route('platform.main'))
            ->assertDontSee('sent; the next office has not received it yet');

        (require database_path('migrations/2026_10_23_102129_backfill_movement_id_on_document_office_assignments.php'))->up();

        $this->assertSame($forward->id, $legacy->fresh()->movement_id);
        $this->assertNull($orphan->officeAssignments()->value('movement_id'));
        $this->assertSame($linkedStep->id, $linkedDocument->officeAssignments()->value('movement_id'));
        $this->actingAs($budget)->get(route('platform.main'))
            ->assertSee('sent; the next office has not received it yet');
    }

    public function test_the_step_that_set_the_current_status_is_preferred(): void
    {
        $document = Document::factory()->create(['current_office' => 'SDO-Legal Unit', 'status' => DocumentMovement::STATUS_RECEIVED]);
        $document->officeAssignments()->delete();
        $document->movements()->create(['action' => 'forwarded', 'from_office' => 'SDO-Budget Unit', 'to_office' => 'SDO-Legal Unit', 'from_status' => 'Received', 'to_status' => 'Forwarded']);
        $receipt = $document->movements()->create(['action' => 'received', 'from_office' => 'SDO-Legal Unit', 'to_office' => 'SDO-Legal Unit', 'from_status' => 'Forwarded', 'to_status' => 'Received']);
        $document->movements()->create(['action' => 'forwarded', 'from_office' => 'SDO-Cash Unit', 'to_office' => 'SDO-Legal Unit', 'from_status' => 'Received', 'to_status' => 'Forwarded']);
        $row = $document->officeAssignments()->create(['office' => 'SDO-Legal Unit', 'status' => DocumentMovement::STATUS_RECEIVED, 'movement_id' => null]);

        (require database_path('migrations/2026_10_23_102129_backfill_movement_id_on_document_office_assignments.php'))->up();

        $this->assertSame($receipt->id, $row->fresh()->movement_id);
    }
}
