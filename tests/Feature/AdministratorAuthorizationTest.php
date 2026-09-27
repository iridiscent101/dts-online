<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Orchid\Platform\Models\Role;
use Tests\TestCase;

class AdministratorAuthorizationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_admin_role_grants_every_permission_without_individual_permission_entries(): void
    {
        $adminRole = Role::create([
            'name' => 'Admin',
            'slug' => User::ADMIN_ROLE_SLUG,
            'permissions' => [],
        ]);
        $admin = User::factory()->create(['permissions' => []]);
        $admin->replaceRoles([$adminRole->id]);

        $this->assertTrue($admin->isAdministrator());
        $this->assertTrue($admin->hasAccess('platform.index'));
        $this->assertTrue($admin->hasAccess('platform.systems.users'));
        $this->assertTrue($admin->hasAccess('platform.systems.roles'));
        $this->assertTrue($admin->hasAccess('future.permission.not.created.yet'));

        $this->actingAs($admin)->get(route('platform.systems.users'))->assertOk();
        $this->actingAs($admin)->get(route('platform.systems.roles'))->assertOk();
    }

    public function test_regular_users_receive_only_the_permissions_granted_by_their_roles(): void
    {
        $staffRole = Role::create([
            'name' => 'Records Officer',
            'slug' => 'records-officer',
            'permissions' => ['platform.index' => true],
        ]);
        $staff = User::factory()->create(['permissions' => []]);
        $staff->replaceRoles([$staffRole->id]);

        $this->assertFalse($staff->isAdministrator());
        $this->assertTrue($staff->hasAccess('platform.index'));
        $this->assertFalse($staff->hasAccess('platform.systems.users'));
        $this->assertFalse($staff->hasAccess('platform.systems.roles'));
        $this->assertFalse($staff->hasAccess('future.permission.not.created.yet'));

        $this->actingAs($staff)->get(route('platform.systems.users'))->assertForbidden();
        $this->actingAs($staff)->get(route('platform.systems.roles'))->assertForbidden();
    }

    public function test_admin_can_process_a_document_assigned_to_any_office(): void
    {
        $adminRole = Role::create([
            'name' => 'Admin',
            'slug' => User::ADMIN_ROLE_SLUG,
            'permissions' => [],
        ]);
        $admin = User::factory()->create(['office' => null, 'permissions' => []]);
        $admin->replaceRoles([$adminRole->id]);
        $document = Document::factory()->incoming()->create(['current_office' => 'Records Unit']);

        $this->actingAs($admin)
            ->post(route('platform.documents.movements.store', $document), [
                'workflow' => [
                    'action' => 'acknowledge',
                    'remarks' => 'Administrator confirmed receipt.',
                ],
            ])
            ->assertRedirectToRoute('platform.documents.view', $document);

        $this->assertSame('Received', $document->fresh()->status);
    }

    public function test_admin_role_identity_cannot_be_renamed_or_deleted(): void
    {
        $adminRole = Role::create([
            'name' => 'Admin',
            'slug' => User::ADMIN_ROLE_SLUG,
            'permissions' => [],
        ]);
        $admin = User::factory()->create(['permissions' => []]);
        $admin->replaceRoles([$adminRole->id]);

        $this->actingAs($admin)
            ->post(route('platform.systems.roles.edit', $adminRole).'/save', [
                'role' => ['name' => 'System Administrators', 'slug' => 'renamed-admin'],
                'permissions' => [],
            ])
            ->assertRedirectToRoute('platform.systems.roles');

        $this->assertSame(User::ADMIN_ROLE_SLUG, $adminRole->fresh()->slug);

        $this->actingAs($admin)
            ->post(route('platform.systems.roles').'/remove?id='.$adminRole->id)
            ->assertForbidden();

        $this->assertModelExists($adminRole);
    }
}
