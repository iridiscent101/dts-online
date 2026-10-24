<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\Office;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
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

    public function test_delegated_role_manager_cannot_grant_permissions_they_do_not_have(): void
    {
        $manager = User::factory()->create([
            'permissions' => ['platform.index' => true, 'platform.systems.roles' => true],
        ]);
        $role = Role::create(['name' => 'Role Manager', 'slug' => 'role-manager', 'permissions' => []]);
        $manager->replaceRoles([$role->id]);

        $this->actingAs($manager)
            ->post(route('platform.systems.roles.edit', $role).'/save', [
                'role' => ['name' => 'Role Manager'],
                'permissions' => [
                    base64_encode('platform.systems.roles') => '1',
                    base64_encode('platform.documents.view-all') => '1',
                ],
            ])
            ->assertForbidden();

        $this->assertSame([], $role->fresh()->permissions);
    }

    public function test_delegated_role_manager_cannot_edit_or_delete_a_more_privileged_role(): void
    {
        $manager = User::factory()->create([
            'permissions' => ['platform.index' => true, 'platform.systems.roles' => true],
        ]);
        $privilegedRole = Role::create([
            'name' => 'Division Viewer',
            'slug' => 'division-viewer',
            'permissions' => ['platform.documents.view-all' => true],
        ]);
        $ordinaryRole = Role::create(['name' => 'Ordinary Role', 'slug' => 'ordinary-role', 'permissions' => []]);

        $this->actingAs($manager)
            ->get(route('platform.systems.roles.edit', $privilegedRole))
            ->assertForbidden();
        $this->actingAs($manager)
            ->post(route('platform.systems.roles.edit', $privilegedRole).'/save', [
                'role' => ['name' => 'Changed Role'],
                'permissions' => [],
            ])
            ->assertForbidden();
        $this->post(route('platform.systems.roles').'/remove?id='.$privilegedRole->id)
            ->assertForbidden();
        $this->post(route('platform.systems.roles').'/bulkRemove', [
            'selected' => [$ordinaryRole->id, $privilegedRole->id],
        ])->assertForbidden();

        $this->assertModelExists($ordinaryRole);
        $this->assertModelExists($privilegedRole);
        $this->assertSame('Division Viewer', $privilegedRole->fresh()->name);
    }

    public function test_reassigning_a_staff_member_removes_access_to_their_previous_office(): void
    {
        $adminRole = Role::create(['name' => 'Admin', 'slug' => User::ADMIN_ROLE_SLUG]);
        $admin = User::factory()->create(['permissions' => []]);
        $admin->replaceRoles([$adminRole->id]);
        $staffRole = Role::create(['name' => 'Staff', 'slug' => 'staff', 'permissions' => ['platform.index' => true]]);
        $staff = User::factory()->create(['office' => 'Records Unit', 'permissions' => []]);
        $staff->replaceRoles([$staffRole->id]);
        $newOffice = Office::create(['name' => 'Budget Unit', 'sort_order' => 2, 'is_active' => true]);
        $oldDocument = Document::factory()->incoming()->create(['current_office' => 'Records Unit']);
        $newDocument = Document::factory()->incoming()->create(['current_office' => 'Budget Unit']);

        $this->actingAs($staff)->get(route('platform.documents.workspace', $oldDocument))->assertOk();
        $this->get(route('platform.documents.workspace', $newDocument))->assertNotFound();

        $this->actingAs($admin)->post(route('platform.systems.users.edit', $staff).'/save', [
            'user' => [
                'name' => $staff->name,
                'email' => $staff->email,
                'offices_submitted' => '1',
                'office_ids' => [$newOffice->id],
                'roles' => [$staffRole->id],
            ],
        ])->assertRedirectToRoute('platform.systems.users');

        $this->assertSame(['Budget Unit'], $staff->fresh()->assignedOfficeNames()->all());
        $this->actingAs($staff->fresh())->get(route('platform.documents.workspace', $oldDocument))->assertNotFound();
        $this->get(route('platform.documents.workspace', $newDocument))->assertOk();
    }

    public function test_office_memberships_control_document_access(): void
    {
        $recordsOffice = Office::create(['name' => 'Records Unit', 'sort_order' => 1, 'is_active' => true]);
        $budgetOffice = Office::create(['name' => 'Budget Unit', 'sort_order' => 2, 'is_active' => true]);
        $staff = User::factory()->create(['office' => $budgetOffice->name]);
        $registrar = User::factory()->create(['office' => $budgetOffice->name]);
        $recordsDocument = Document::factory()->incoming()->create(['current_office' => $recordsOffice->name]);
        $budgetDocument = Document::factory()->incoming()->create(['current_office' => $budgetOffice->name]);
        $registeredDocument = Document::factory()->incoming()->create([
            'current_office' => 'Legal Unit',
            'registered_by' => $registrar->id,
        ]);
        $recordsViewer = User::factory()->create(['office' => $recordsOffice->name]);

        $this->assertSame([$budgetOffice->name], $staff->assignedOfficeNames()->all());
        $this->assertFalse($recordsDocument->isVisibleTo($staff));
        $this->assertTrue($budgetDocument->isVisibleTo($staff));
        $this->assertFalse($registeredDocument->isVisibleTo($recordsViewer));
        $this->assertFalse(Document::query()->visibleTo($recordsViewer)->whereKey($registeredDocument)->exists());
    }

    public function test_staff_without_an_office_membership_cannot_see_another_offices_document(): void
    {
        $staff = User::factory()->create(['permissions' => ['platform.index' => true]]);
        $document = Document::factory()->incoming()->create(['current_office' => 'Records Unit']);

        $this->assertSame([], $staff->assignedOfficeNames()->all());
        $this->assertFalse($document->isVisibleTo($staff));
        $this->assertFalse(Document::query()->visibleTo($staff)->whereKey($document)->exists());
        $this->actingAs($staff)->get(route('platform.documents.workspace', $document))->assertNotFound();
    }

    public function test_password_change_ends_an_existing_dashboard_session(): void
    {
        $staff = User::factory()->create(['permissions' => ['platform.index' => true]]);
        $oldPasswordHash = $staff->password;

        $this->actingAs($staff)
            ->withSession(['password_hash_web' => $oldPasswordHash])
            ->get(route('platform.main'))
            ->assertOk();

        $staff->forceFill(['password' => Hash::make('new-password')])->save();

        $this->actingAs($staff->fresh())
            ->withSession(['password_hash_web' => $oldPasswordHash])
            ->get(route('platform.main'))
            ->assertRedirectToRoute('platform.login');
    }

    public function test_delegated_user_manager_cannot_assign_offices_to_self_or_others(): void
    {
        $manager = User::factory()->create(['office' => 'Records Unit', 'permissions' => [
            'platform.index' => true,
            'platform.systems.users' => true,
        ]]);
        $otherUser = User::factory()->create(['office' => 'Records Unit']);
        $budgetOffice = Office::create(['name' => 'Budget Unit', 'sort_order' => 2, 'is_active' => true]);

        foreach ([$manager, $otherUser] as $target) {
            $this->actingAs($manager)
                ->post(route('platform.systems.users.edit', $target).'/save', [
                    'user' => [
                        'name' => $target->name,
                        'email' => $target->email,
                        'offices_submitted' => '1',
                        'office_ids' => [$budgetOffice->id],
                        'roles' => [],
                    ],
                ])
                ->assertForbidden();
            $this->post(route('platform.systems.users').'/saveUser?user='.$target->id, [
                'user' => [
                    'name' => $target->name,
                    'email' => $target->email,
                    'offices_submitted' => '1',
                    'office_ids' => [$budgetOffice->id],
                ],
            ])->assertNotFound(); // The list page no longer has its own edit dialog.

            $this->assertSame(['Records Unit'], $target->fresh()->assignedOfficeNames()->all());
        }
    }

    public function test_delegated_user_manager_can_save_profile_with_unchanged_office_selection(): void
    {
        $manager = User::factory()->create(['permissions' => ['platform.index' => true, 'platform.systems.users' => true]]);
        $target = User::factory()->create(['office' => 'Records Unit']);
        $recordsOffice = Office::query()->where('name', 'Records Unit')->sole();

        $this->actingAs($manager)
            ->post(route('platform.systems.users.edit', $target).'/save', [
                'user' => [
                    'name' => 'Updated staff name',
                    'email' => $target->email,
                    'offices_submitted' => '1',
                    'office_ids' => [$recordsOffice->id],
                    'roles' => [],
                ],
            ])
            ->assertRedirectToRoute('platform.systems.users');

        $this->assertSame('Updated staff name', $target->fresh()->name);
        $this->assertSame(['Records Unit'], $target->fresh()->assignedOfficeNames()->all());
    }

    public function test_delegated_user_manager_can_create_unassigned_user_but_cannot_assign_an_office(): void
    {
        $manager = User::factory()->create(['permissions' => ['platform.index' => true, 'platform.systems.users' => true]]);
        $office = Office::create(['name' => 'Records Unit', 'sort_order' => 1, 'is_active' => true]);
        $userDetails = ['name' => 'New clerk', 'email' => 'new-clerk@example.test', 'password' => 'Secure-Password-1!', 'roles' => []];

        $this->actingAs($manager)
            ->post(route('platform.systems.users.create').'/save', [
                'user' => $userDetails + ['offices_submitted' => '1', 'office_ids' => [$office->id]],
            ])
            ->assertForbidden();
        $this->assertDatabaseMissing('users', ['email' => 'new-clerk@example.test']);

        $this->post(route('platform.systems.users.create').'/save', [
            'user' => $userDetails + ['offices_submitted' => '1'],
        ])->assertRedirectToRoute('platform.systems.users');
        $this->assertSame([], User::query()->where('email', 'new-clerk@example.test')->sole()->assignedOfficeNames()->all());
    }

    public function test_administrator_can_assign_office_from_the_edit_page(): void
    {
        $adminRole = Role::create(['name' => 'Admin', 'slug' => User::ADMIN_ROLE_SLUG]);
        $admin = User::factory()->create(['permissions' => []]);
        $admin->replaceRoles([$adminRole->id]);
        $target = User::factory()->create(['office' => null]);
        $office = Office::create(['name' => 'Records Unit', 'sort_order' => 1, 'is_active' => true]);

        $this->actingAs($admin)
            ->post(route('platform.systems.users.edit', $target).'/save', [
                'user' => [
                    'name' => $target->name,
                    'email' => $target->email,
                    'offices_submitted' => '1',
                    'office_ids' => [$office->id],
                    'roles' => [],
                ],
            ])
            ->assertRedirectToRoute('platform.systems.users');

        $this->assertSame(['Records Unit'], $target->fresh()->assignedOfficeNames()->all());
        $this->assertSame(['Records Unit'], $target->fresh()->assignedOfficeNames()->all());
    }
}
