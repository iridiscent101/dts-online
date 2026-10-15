<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class DocumentRemarkTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_received_office_can_add_an_append_only_remark_without_changing_its_route(): void
    {
        $staff = User::factory()->create(['office' => 'Administrative Unit', 'permissions' => ['platform.index' => true]]);
        $document = Document::factory()->received()->create(['current_office' => 'Administrative Unit']);

        $this->actingAs($staff)
            ->postJson(route('platform.documents.remarks.store', $document), [
                'office' => 'Administrative Unit',
                'remark' => 'Please review the revised figures.',
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Remark added.');

        $activity = $document->activities()->where('description', 'Remark added')->sole();
        $this->assertSame($staff->id, $activity->causer_id);
        $this->assertSame('Administrative Unit', data_get($activity->properties, 'office'));
        $this->assertSame('Please review the revised figures.', data_get($activity->properties, 'remark'));
        $this->assertSame(DocumentMovement::STATUS_RECEIVED, $document->fresh()->status);
        $this->assertDatabaseCount('document_movements', 0);

        $this->get(route('platform.documents.workspace', $document))
            ->assertSee('Please review the revised figures.');
    }

    public function test_other_offices_and_waiting_or_finished_routes_cannot_add_remarks(): void
    {
        $staff = User::factory()->create(['office' => 'Administrative Unit', 'permissions' => ['platform.index' => true]]);
        $document = Document::factory()->received()->create(['current_office' => 'Administrative Unit']);
        $document->officeAssignments()->create(['office' => 'Budget Unit', 'status' => DocumentMovement::STATUS_RECEIVED]);

        $this->actingAs($staff)
            ->postJson(route('platform.documents.remarks.store', $document), [
                'office' => 'Budget Unit', 'remark' => 'Not my office.',
            ])->assertForbidden();

        foreach ([DocumentMovement::STATUS_AWAITING_RECEIPT, DocumentMovement::STATUS_COMPLETED] as $status) {
            $document->officeAssignments()->where('office', 'Administrative Unit')->update(['status' => $status]);
            $this->postJson(route('platform.documents.remarks.store', $document), [
                'office' => 'Administrative Unit', 'remark' => 'Late note.',
            ])->assertForbidden();
        }

        $this->assertSame(0, $document->activities()->where('description', 'Remark added')->count());
    }

    public function test_remark_requires_text_and_an_authenticated_staff_member(): void
    {
        $staff = User::factory()->create(['office' => 'Administrative Unit', 'permissions' => ['platform.index' => true]]);
        $document = Document::factory()->received()->create(['current_office' => 'Administrative Unit']);

        $this->actingAs($staff)
            ->postJson(route('platform.documents.remarks.store', $document), [
                'office' => 'Administrative Unit', 'remark' => '',
            ])->assertUnprocessable()->assertJsonValidationErrors('remark');

        auth()->logout();
        $this->post(route('platform.documents.remarks.store', $document), [
            'office' => 'Administrative Unit', 'remark' => 'Anonymous note.',
        ])->assertRedirectToRoute('platform.login');

        $this->assertSame(0, $document->activities()->where('description', 'Remark added')->count());
    }
}
