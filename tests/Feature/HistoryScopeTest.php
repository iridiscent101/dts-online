<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

/**
 * Staff whose history is limited to their own offices must not see another office's actions on a shared document,
 * in any view: the unfiltered panel history, its later pages, the full document page or the route cards.
 */
class HistoryScopeTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_route_edit_history_is_visible_to_affected_office_not_unaffected_current_office(): void
    {
        $legal = User::factory()->create(['office' => 'Legal Unit', 'permissions' => ['platform.index' => true]]);
        $budget = User::factory()->create(['office' => 'Budget Unit', 'permissions' => ['platform.index' => true]]);
        $document = Document::factory()->incoming()->create(['current_office' => 'Legal Unit']);
        $document->officeAssignments()->create(['office' => 'Budget Unit', 'status' => DocumentMovement::STATUS_AWAITING_RECEIPT]);
        activity('documents')->performedOn($document)
            ->withProperties(['affected_offices' => ['Budget Unit'], 'correction_reason' => 'Budget routing correction'])
            ->log('Document routes updated');

        $this->actingAs($budget)
            ->get(route('platform.documents.workspace', $document))
            ->assertSee('Document routes updated');
        $this->get(route('platform.documents.view', $document))
            ->assertSee('Document routes updated');

        $this->actingAs($legal)
            ->get(route('platform.documents.workspace', $document))
            ->assertDontSee('Document routes updated');
        $this->get(route('platform.documents.view', $document))
            ->assertDontSee('Document routes updated');
    }

    public function test_older_route_edit_history_uses_changed_offices_instead_of_its_old_current_office_tag(): void
    {
        $legal = User::factory()->create(['office' => 'Legal Unit', 'permissions' => ['platform.index' => true]]);
        $budget = User::factory()->create(['office' => 'Budget Unit', 'permissions' => ['platform.index' => true]]);
        $document = Document::factory()->incoming()->create(['current_office' => 'Legal Unit']);
        $document->officeAssignments()->create(['office' => 'Budget Unit', 'status' => DocumentMovement::STATUS_AWAITING_RECEIPT]);
        activity('documents')->performedOn($document)
            ->withProperties(['office' => 'Legal Unit', 'added' => ['Budget Unit'], 'removed' => []])
            ->log('Document routes updated');

        $this->actingAs($budget)
            ->get(route('platform.documents.workspace', $document))
            ->assertSee('Document routes updated');
        $this->get(route('platform.documents.view', $document))
            ->assertSee('Document routes updated');

        $this->actingAs($legal)
            ->get(route('platform.documents.workspace', $document))
            ->assertDontSee('Document routes updated');
        $this->get(route('platform.documents.view', $document))
            ->assertDontSee('Document routes updated');
    }

    public function test_unfiltered_history_never_shows_another_offices_actions(): void
    {
        $legal = User::factory()->create(['office' => 'Legal Unit', 'permissions' => ['platform.index' => true]]);
        $budgetStaff = User::factory()->create(['name' => 'Budget Clerk', 'office' => 'Budget Unit']);
        $document = Document::factory()->received()->create([
            'current_office' => 'Records Unit',
            // Fixed dates, so neither can collide with the Budget receipt date checked below.
            'received_at' => '2026-09-28',
        ]);
        $document->officeAssignments()->create(['office' => 'Legal Unit', 'status' => DocumentMovement::STATUS_RECEIVED]);
        $document->officeAssignments()->create(['office' => 'Budget Unit', 'status' => DocumentMovement::STATUS_RECEIVED]);

        $movement = fn (string $from, string $to, string $action, string $remarks, string $at) => DocumentMovement::factory()->create([
            'document_id' => $document->id,
            'from_office' => $from,
            'to_office' => $to,
            'action' => $action,
            'remarks' => $remarks,
            'acted_by' => $budgetStaff->id,
            'created_at' => $at,
        ]);
        $movement('Records Unit', 'Legal Unit', DocumentMovement::ACTION_FORWARDED, 'For legal review', '2026-10-01 08:00:00');
        $movement('Records Unit', 'Budget Unit', DocumentMovement::ACTION_FORWARDED, 'BUDGET-ROUTE-NOTE', '2026-10-01 08:05:00');
        $movement('Budget Unit', 'Budget Unit', DocumentMovement::ACTION_RECEIVED, 'BUDGET-RECEIPT-NOTE', '2026-10-02 09:00:00');
        $movement('Budget Unit', 'Accounting Unit', DocumentMovement::ACTION_FORWARDED, 'BUDGET-TO-ACCOUNTING', '2026-10-03 10:00:00');
        activity('documents')->causedBy($budgetStaff)->performedOn($document)
            ->withProperties(['office' => 'Budget Unit', 'remark' => 'BUDGET-PRIVATE-REMARK'])->log('Remark added');

        $workspace = $this->actingAs($legal)->get(route('platform.documents.workspace', $document))->assertOk();
        $workspace->assertSee('For legal review')
            ->assertDontSee('BUDGET-ROUTE-NOTE')
            ->assertDontSee('BUDGET-RECEIPT-NOTE')
            ->assertDontSee('BUDGET-TO-ACCOUNTING')
            ->assertDontSee('BUDGET-PRIVATE-REMARK')
            ->assertDontSee('Forwarded to Accounting Unit')
            // Budget's route card shows its status but not when it was sent or received.
            ->assertDontSee('02 Oct 2026');
        // Not even loaded: the route-card dates only cover the viewer's own offices.
        $this->assertSame(['Legal Unit'], $workspace->viewData('sentDates')->keys()->all());
        $this->assertFalse($workspace->viewData('receivedDates')->has('Budget Unit'));

        foreach ([1, 2] as $page) {
            $this->get(route('platform.documents.workspace', $document).'?history_page='.$page)
                ->assertOk()
                ->assertDontSee('BUDGET-RECEIPT-NOTE')
                ->assertDontSee('BUDGET-TO-ACCOUNTING')
                ->assertDontSee('BUDGET-PRIVATE-REMARK');
        }

        $this->get(route('platform.documents.view', $document))
            ->assertOk()
            ->assertDontSee('BUDGET-ROUTE-NOTE')
            ->assertDontSee('BUDGET-RECEIPT-NOTE')
            ->assertDontSee('BUDGET-TO-ACCOUNTING')
            ->assertDontSee('BUDGET-PRIVATE-REMARK');
    }
}
