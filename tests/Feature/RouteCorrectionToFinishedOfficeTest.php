<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentMovement;
use App\Models\User;
use App\Support\RouteFixActions;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class RouteCorrectionToFinishedOfficeTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_a_wrong_route_can_be_corrected_to_an_office_that_finished_earlier(): void
    {
        [$records, $document] = $this->sentToLegalByMistake();

        // The correction dialog does not refuse Budget either.
        $this->assertNotContains('SDO-Budget Unit', RouteFixActions::for($document->fresh(), $records)['correction']['assigned']->all());

        $this->actingAs($records)->postJson(route('platform.documents.routes.correct', $document), [
            'source_office' => 'SDO-Records Unit', 'destination_office' => 'SDO-Legal Unit',
            'replacement_office' => 'SDO-Budget Unit', 'correction_reason' => 'Wrong office',
        ])->assertOk();

        // Budget's finished row is reopened, not duplicated, and Legal is off the route.
        $this->assertSame(1, $document->officeAssignments()->where('office', 'SDO-Budget Unit')->count());
        $this->assertSame(DocumentMovement::STATUS_FORWARDED, $document->officeAssignments()->where('office', 'SDO-Budget Unit')->value('status'));
        $this->assertDatabaseMissing('document_office_assignments', ['document_id' => $document->id, 'office' => 'SDO-Legal Unit']);
    }

    public function test_a_route_still_cannot_be_corrected_to_an_office_working_on_it(): void
    {
        [$records, $document] = $this->sentToLegalByMistake();
        $document->officeAssignments()->where('office', 'SDO-Budget Unit')->update(['status' => DocumentMovement::STATUS_RECEIVED]);

        $this->actingAs($records)->postJson(route('platform.documents.routes.correct', $document), [
            'source_office' => 'SDO-Records Unit', 'destination_office' => 'SDO-Legal Unit',
            'replacement_office' => 'SDO-Budget Unit', 'correction_reason' => 'Wrong office',
        ])->assertUnprocessable()->assertJsonValidationErrors('replacement_office');
    }

    /**
     * Records holds the document, Budget finished its part earlier, and Records forwards to Legal by mistake.
     *
     * @return array{0: User, 1: Document}
     */
    private function sentToLegalByMistake(): array
    {
        $records = User::factory()->create(['office' => 'SDO-Records Unit', 'permissions' => ['platform.index' => true]]);
        $document = Document::factory()->create(['current_office' => 'SDO-Records Unit', 'status' => DocumentMovement::STATUS_RECEIVED, 'registered_office' => 'SDO-Records Unit']);
        $receipt = $document->movements()->create(['action' => 'received', 'from_office' => 'SDO-Records Unit', 'to_office' => 'SDO-Records Unit', 'from_status' => 'Forwarded', 'to_status' => 'Received']);
        $document->officeAssignments()->updateOrCreate(['office' => 'SDO-Records Unit'], ['status' => 'Received', 'movement_id' => $receipt->id]);
        $document->officeAssignments()->create(['office' => 'SDO-Budget Unit', 'status' => DocumentMovement::STATUS_COMPLETED]);

        $this->actingAs($records)->postJson(route('platform.documents.movements.store', $document), [
            'workflow' => ['action' => 'forwarded', 'source_office' => 'SDO-Records Unit', 'destinations' => ['SDO-Legal Unit']],
        ])->assertOk();

        return [$records, $document];
    }
}
