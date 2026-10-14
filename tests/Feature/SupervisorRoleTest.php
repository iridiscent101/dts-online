<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\User;
use Database\Seeders\SupervisorRoleSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Orchid\Platform\Models\Role;
use Tests\TestCase;

class SupervisorRoleTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_supervisor_can_view_every_offices_documents_without_changing_them(): void
    {
        config(['app.document_uploads_enabled' => true]);
        $this->seed(SupervisorRoleSeeder::class);
        $supervisorRole = Role::query()->where('slug', 'supervisor')->sole();
        $this->assertSame([
            'platform.index' => true,
            'platform.documents.view-all' => true,
        ], $supervisorRole->permissions);

        $supervisor = User::factory()->create([
            'office' => 'SDO-Records Unit',
            'permissions' => [
                'platform.documents.edit' => true,
                'platform.documents.attachments.manage' => true,
            ],
        ]);
        $supervisor->replaceRoles([$supervisorRole->id]);
        $document = Document::factory()->incoming()->create([
            'current_office' => 'Administrative Unit',
            'subject' => 'Administrative report',
        ]);
        $recordsDocument = Document::factory()->incoming()->create([
            'current_office' => 'SDO-Records Unit',
        ]);

        $this->actingAs($supervisor)
            ->get(route('platform.documents'))
            ->assertOk()
            ->assertSee($document->tracking_number)
            ->assertSee($recordsDocument->tracking_number)
            ->assertDontSee('New document');
        $this->get(route('platform.incoming'))
            ->assertOk()
            ->assertSee($document->tracking_number)
            ->assertSee($recordsDocument->tracking_number);
        $this->get(route('platform.documents.view', $document))
            ->assertOk()
            ->assertSee('Administrative report')
            ->assertDontSee('Edit document');
        $this->get(route('platform.documents.view', $recordsDocument))
            ->assertOk()
            ->assertSee('Action unavailable');
        $this->get(route('platform.documents.create'))->assertForbidden();
        $this->post(route('platform.documents.create').'/save')->assertForbidden();
        $this->post(route('platform.documents.movements.store', $recordsDocument), [
            'workflow' => ['action' => 'received', 'remarks' => 'Review completed.'],
        ])->assertForbidden();
        $this->get(route('platform.documents.edit', $recordsDocument))->assertForbidden();
        $this->post(route('platform.documents.destroy', $recordsDocument))->assertForbidden();
        $this->post(route('platform.documents.attachments.store', $recordsDocument))->assertForbidden();

        $this->assertDatabaseCount('document_movements', 0);
        $this->assertDatabaseCount('documents', 2);
    }

    public function test_staff_without_view_all_permission_cannot_open_another_offices_document(): void
    {
        $staff = User::factory()->create([
            'office' => 'SDO-Records Unit',
            'permissions' => ['platform.index' => true],
        ]);
        $document = Document::factory()->create(['current_office' => 'Administrative Unit']);

        $this->actingAs($staff)
            ->get(route('platform.documents'))
            ->assertOk()
            ->assertDontSee($document->tracking_number);
        $this->get(route('platform.documents.view', $document))->assertNotFound();
    }
}
