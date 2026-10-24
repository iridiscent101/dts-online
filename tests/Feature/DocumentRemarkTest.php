<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Orchid\Platform\Models\Role;
use Tests\TestCase;

class DocumentRemarkTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_received_office_can_add_an_append_only_remark_without_changing_its_route(): void
    {
        $staff = User::factory()->create(['name' => 'Rosa Reyes', 'office' => 'Administrative Unit', 'permissions' => ['platform.index' => true]]);
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

        // Each remark names who wrote it first: "Name: remark".
        $this->get(route('platform.documents.workspace', $document))
            ->assertSee('<p><strong>'.$staff->name.':</strong> Please review the revised figures.</p>', false);
        $this->get(route('tracking.index', ['tracking' => $document->tracking_number]))
            ->assertSee($staff->name.':</strong> Please review the revised figures.', false);
    }

    public function test_administrator_can_add_a_remark_for_any_received_office(): void
    {
        // Administrators route for any office on the document without being assigned to it, so they remark the same way.
        $adminRole = Role::create(['name' => 'Admin', 'slug' => User::ADMIN_ROLE_SLUG]);
        $admin = User::factory()->create(['office' => null]);
        $admin->replaceRoles([$adminRole->id]);
        $document = Document::factory()->received()->create(['current_office' => 'Administrative Unit']);

        $this->actingAs($admin)
            ->postJson(route('platform.documents.remarks.store', $document), [
                'office' => 'Administrative Unit',
                'remark' => 'Checked by the administrator.',
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Remark added.');

        $this->assertSame('Checked by the administrator.', data_get($document->activities()->where('description', 'Remark added')->sole()->properties, 'remark'));
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
