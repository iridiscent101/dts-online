<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentMovement;
use App\Models\Office;
use App\Models\User;
use Database\Seeders\DocumentTypeSeeder;
use Database\Seeders\SupervisorRoleSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Orchid\Attachment\File;
use Orchid\Platform\Models\Role;
use Tests\TestCase;

class DepEdDashboardTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_guests_are_sent_to_sign_in(): void
    {
        $this->get(route('platform.main'))->assertRedirectToRoute('platform.login');
    }

    public function test_document_views_render_their_empty_states(): void
    {
        $user = User::factory()->create(['name' => 'DTS Preview', 'email' => 'preview@example.test', 'permissions' => ['platform.index' => true]]);

        $this->actingAs($user);

        foreach ([
            'main' => 'Document overview',
            'documents' => 'No documents registered yet',
            'incoming' => 'No incoming documents',
            'outgoing' => 'No outgoing documents',
            'archived' => 'No finished documents',
        ] as $route => $heading) {
            $this->get(route('platform.'.$route))
                ->assertSee($heading)
                ->assertDontSee('Sample Screen');
        }
    }

    public function test_recent_activity_only_shows_the_users_office_movements(): void
    {
        $user = User::factory()->create(['office' => 'Records Unit', 'permissions' => ['platform.index' => true]]);
        $document = Document::factory()->received()->create(['current_office' => 'Records Unit']);
        DocumentMovement::factory()->create([
            'document_id' => $document->id,
            'acted_by' => $user->id,
            'action' => 'reviewed',
            'from_office' => 'Records Unit',
            'to_office' => 'Records Unit',
        ]);
        DocumentMovement::factory()->create([
            'document_id' => $document->id,
            'acted_by' => $user->id,
            'action' => 'approved',
            'from_office' => 'Budget Unit',
            'to_office' => 'Budget Unit',
        ]);

        $this->actingAs($user)
            ->get(route('platform.main'))
            ->assertSee('Document reviewed')
            ->assertDontSee('Document approved');
    }

    public function test_empty_filtered_document_views_offer_a_clear_filters_action(): void
    {
        $user = User::factory()->create(['permissions' => ['platform.index' => true]]);

        foreach (['documents', 'incoming'] as $section) {
            $url = route('platform.'.$section);

            $this->actingAs($user)
                ->get(route('platform.'.$section, ['search' => 'no-matching-document']))
                ->assertOk()
                ->assertSee('No documents match the current filters')
                ->assertSee('href="'.$url.'" data-turbo-frame="document-registry"', false)
                ->assertSee('Clear filters');

            $this->actingAs($user)
                ->get($url)
                ->assertOk()
                ->assertDontSee('Clear filters');
        }
    }

    public function test_login_shows_deped_branding(): void
    {
        $this->get(route('platform.login'))
            ->assertSee(asset('images/icon.png'))
            ->assertSee('SDO – Nueva Vizcaya');
    }

    public function test_failed_login_shows_why(): void
    {
        $this->followingRedirects()
            ->from(route('platform.login'))
            ->post(route('platform.login.auth'), ['email' => 'nobody@example.test', 'password' => 'wrong-password'])
            ->assertSee('id="login-errors"', false)
            ->assertSee('The details you entered did not match our records.')
            ->assertSee('value="nobody@example.test"', false);
    }

    public function test_registration_requires_sign_in(): void
    {
        $this->get(route('platform.documents.create'))->assertRedirectToRoute('platform.login');
    }

    public function test_registration_shows_the_secure_upload_form_for_authorized_users(): void
    {
        $user = User::factory()->create(['name' => 'DTS Preview', 'email' => 'preview@example.test', 'permissions' => ['platform.index' => true]]);

        $response = $this->actingAs($user)->get(route('platform.documents.create'));

        $response->assertSee('Document information')
            ->assertSee('data-options="document-types"', false)
            ->assertSee('data-options="destination-offices"', false)
            ->assertSee('Register document')
            ->assertDontSee('Review document')
            ->assertDontSee('Attachments are stored privately')
            ->assertDontSee('Movement timeline');
    }

    public function test_user_list_can_be_searched_and_sorted_from_the_table_header(): void
    {
        $admin = User::factory()->create(['permissions' => ['platform.index' => true, 'platform.systems.users' => true]]);
        $role = Role::create(['name' => 'Records Officer', 'slug' => 'records-officer']);
        $alpha = User::factory()->create(['name' => 'Alex Alpha', 'email' => 'alpha@example.test']);
        $alpha->replaceRoles([$role->id]);
        User::factory()->create(['name' => 'Alex Zulu', 'email' => 'zulu@example.test']);
        User::factory()->create(['name' => 'Unrelated Person', 'email' => 'other@example.test']);

        $response = $this->actingAs($admin)->get(route('platform.systems.users', [
            'user_search' => 'Alex',
            'sort' => '-name',
        ]));

        $response->assertSee('Search users')
            ->assertDontSee('Sort by')
            ->assertSee('Role')
            ->assertSee('Records Officer')
            ->assertSee('Date created')
            ->assertSee('Edit')
            ->assertSee('Delete')
            ->assertSeeInOrder(['Alex Zulu', 'Alex Alpha'])
            ->assertDontSee('Unrelated Person');
    }

    public function test_user_access_is_managed_only_through_assigned_roles(): void
    {
        $admin = User::factory()->create(['permissions' => [
            'platform.index' => true,
            'platform.systems.users' => true,
        ]]);
        $user = User::factory()->create([
            'office' => 'Records Unit',
            'permissions' => ['platform.index' => true],
        ]);
        $role = Role::create([
            'name' => 'Records Officer',
            'slug' => 'records-officer',
            'permissions' => ['platform.index' => true],
        ]);

        $this->actingAs($admin)
            ->get(route('platform.systems.users.create'))
            ->assertOk()
            ->assertSee('Roles')
            ->assertSee('autocomplete="off"', false)
            ->assertSee('autocomplete="new-password"', false)
            ->assertDontSee('admin@test.com')
            ->assertDontSee('value="password"', false)
            ->assertDontSee('Permissions');

        $this->actingAs($admin)
            ->post(route('platform.systems.users.edit', $user).'/save', [
                'user' => [
                    'name' => $user->name,
                    'email' => $user->email,
                    'roles' => [$role->id],
                ],
                'permissions' => [base64_encode('platform.systems.users') => true],
            ])
            ->assertRedirectToRoute('platform.systems.users');

        $user->refresh();

        $this->assertSame([], $user->permissions);
        $this->assertTrue($user->roles()->whereKey($role->id)->exists());
    }

    public function test_administrator_can_assign_a_user_to_an_office(): void
    {
        $adminRole = Role::create(['name' => 'Admin', 'slug' => User::ADMIN_ROLE_SLUG]);
        $admin = User::factory()->create(['permissions' => []]);
        $admin->replaceRoles([$adminRole->id]);
        $user = User::factory()->create(['office' => null]);
        $recordsOffice = Office::create(['name' => 'Records Unit', 'sort_order' => 1, 'is_active' => true]);
        $administrativeOffice = Office::create(['name' => 'Administrative Unit', 'sort_order' => 2, 'is_active' => true]);

        $this->actingAs($admin)
            ->get(route('platform.systems.users.edit', $user))
            ->assertOk()
            ->assertSee('Assigned offices')
            ->assertSee('Select offices')
            ->assertSee('Records Unit');

        $this->actingAs($admin)
            ->post(route('platform.systems.users.edit', $user).'/save', [
                'user' => [
                    'name' => $user->name,
                    'email' => $user->email,
                    'offices_submitted' => '1',
                    'office_ids' => [$recordsOffice->id, $administrativeOffice->id],
                    'roles' => [],
                ],
            ])
            ->assertRedirectToRoute('platform.systems.users');

        $user->refresh();

        $this->assertSame('Records Unit', $user->office);
        $this->assertEqualsCanonicalizing(
            ['Records Unit', 'Administrative Unit'],
            $user->offices()->pluck('name')->all(),
        );

        $this->actingAs($admin)
            ->get(route('platform.systems.users'))
            ->assertOk()
            ->assertSee('Office')
            ->assertSee('Records Unit, Administrative Unit');
    }

    public function test_user_cannot_change_their_own_office_from_the_profile(): void
    {
        $user = User::factory()->create([
            'office' => 'Records Unit',
            'permissions' => ['platform.index' => true],
        ]);

        $this->actingAs($user)
            ->from(route('platform.profile'))
            ->post(route('platform.profile').'/save', [
                'user' => [
                    'name' => $user->name,
                    'email' => $user->email,
                    'office' => 'Administrative Unit',
                ],
            ])
            ->assertRedirect(route('platform.profile'));

        $this->assertSame('Records Unit', $user->fresh()->office);
    }

    public function test_user_and_role_forms_use_primary_save_and_destructive_delete_actions(): void
    {
        $admin = User::factory()->create(['permissions' => [
            'platform.index' => true,
            'platform.systems.users' => true,
            'platform.systems.roles' => true,
        ]]);
        $user = User::factory()->create();
        $role = Role::create(['name' => 'Records Officer', 'slug' => 'records-officer']);

        foreach ([
            route('platform.systems.users.create'),
            route('platform.systems.roles.create'),
        ] as $createUrl) {
            $this->actingAs($admin)
                ->get($createUrl)
                ->assertOk()
                ->assertSee('Save')
                ->assertSee('dts-button-primary', false);
        }

        foreach ([
            route('platform.systems.users.edit', $user),
            route('platform.systems.roles.edit', $role),
        ] as $editUrl) {
            $this->actingAs($admin)
                ->get($editUrl)
                ->assertOk()
                ->assertSee('Delete')
                ->assertSee('dts-button-danger', false)
                ->assertDontSee('>Remove<', false);
        }

        $userEditResponse = $this->actingAs($admin)
            ->get(route('platform.systems.users.edit', $user))
            ->assertDontSee('Impersonate user');

        $this->assertSame(1, preg_match_all('/>\s*Save\s*</', $userEditResponse->getContent()));

        $this->actingAs($admin)
            ->get(route('platform.systems.roles.edit', $role))
            ->assertSeeInOrder(['Delete', 'Save']);
    }

    public function test_user_list_bulk_deletes_selected_accounts_only(): void
    {
        $manager = User::factory()->create(['permissions' => [
            'platform.index' => true,
            'platform.systems.users' => true,
        ]]);
        $selected = User::factory()->count(2)->create();
        $unselected = User::factory()->create();

        $this->actingAs($manager)
            ->get(route('platform.systems.users'))
            ->assertOk()
            ->assertSee('data-admin-bulk-delete-kind="user"', false)
            ->assertSee('<div class="dts-registry-table-toolbar">', false)
            ->assertSeeInOrder(['<dts-column-menu>', 'Rows per page', 'data-table-select-all'], false)
            ->assertSee('data-table-row-select', false);

        $this->post(route('platform.systems.users').'/bulkRemove', [
            'selected' => $selected->modelKeys(),
        ])->assertRedirect();

        foreach ($selected as $user) {
            $this->assertDatabaseMissing('users', ['id' => $user->id]);
        }
        $this->assertDatabaseHas('users', ['id' => $manager->id]);
        $this->assertDatabaseHas('users', ['id' => $unselected->id]);
    }

    public function test_user_bulk_delete_rejects_self_and_administrator_accounts(): void
    {
        $manager = User::factory()->create(['permissions' => [
            'platform.index' => true,
            'platform.systems.users' => true,
        ]]);
        $selected = User::factory()->create();
        $administrator = User::factory()->create();
        $adminRole = Role::create(['name' => 'Administrator', 'slug' => User::ADMIN_ROLE_SLUG]);
        $administrator->replaceRoles([$adminRole->id]);

        $response = $this->actingAs($manager)->get(route('platform.systems.users'));
        $this->assertMatchesRegularExpression('/<input[^>]*value="'.$manager->id.'"[^>]*data-table-row-select[^>]*disabled/s', $response->getContent());
        $this->assertMatchesRegularExpression('/<input[^>]*value="'.$administrator->id.'"[^>]*data-table-row-select[^>]*disabled/s', $response->getContent());

        foreach ([$manager->id, $administrator->id] as $protectedId) {
            $this->actingAs($manager)
                ->from(route('platform.systems.users'))
                ->post(route('platform.systems.users').'/bulkRemove', [
                    'selected' => [$selected->id, $protectedId],
                ])
                ->assertRedirect(route('platform.systems.users'))
                ->assertSessionHasErrors('selected');

            $this->assertDatabaseHas('users', ['id' => $selected->id]);
            $this->assertDatabaseHas('users', ['id' => $protectedId]);
        }
    }

    public function test_user_manager_cannot_grant_admin_role_or_edit_an_administrator(): void
    {
        $manager = User::factory()->create(['permissions' => ['platform.index' => true, 'platform.systems.users' => true]]);
        $administrator = User::factory()->create();
        $adminRole = Role::create(['name' => 'Admin', 'slug' => User::ADMIN_ROLE_SLUG]);
        $privilegedRole = Role::create([
            'name' => 'Role Manager',
            'slug' => 'role-manager',
            'permissions' => ['platform.systems.roles' => true],
        ]);
        $administrator->replaceRoles([$adminRole->id]);

        $this->actingAs($manager)
            ->post(route('platform.systems.users.edit', $manager).'/save', [
                'user' => ['name' => $manager->name, 'email' => $manager->email, 'roles' => [$adminRole->id]],
            ])
            ->assertForbidden();

        $this->post(route('platform.systems.users.edit', $manager).'/save', [
            'user' => ['name' => $manager->name, 'email' => $manager->email, 'roles' => [$privilegedRole->id]],
        ])->assertForbidden();

        $this->post(route('platform.systems.users.edit', $administrator).'/save', [
            'user' => ['name' => 'Changed administrator', 'email' => $administrator->email, 'roles' => []],
        ])->assertForbidden();

        $this->post(route('platform.systems.users').'/saveUser?user='.$administrator->id, [
            'user' => ['name' => 'Changed administrator', 'email' => $administrator->email],
        ])->assertForbidden();

        $this->assertFalse($manager->fresh()->isAdministrator());
        $this->assertSame($administrator->name, $administrator->fresh()->name);
        $this->assertTrue($administrator->fresh()->isAdministrator());
    }

    public function test_single_user_delete_rejects_self_and_administrator_accounts(): void
    {
        $manager = User::factory()->create(['permissions' => ['platform.index' => true, 'platform.systems.users' => true]]);
        $administrator = User::factory()->create();
        $adminRole = Role::create(['name' => 'Admin', 'slug' => User::ADMIN_ROLE_SLUG]);
        $administrator->replaceRoles([$adminRole->id]);

        foreach ([$manager, $administrator] as $protectedUser) {
            $this->actingAs($manager)
                ->post(route('platform.systems.users').'/remove', ['id' => $protectedUser->id])
                ->assertForbidden();
            $this->post(route('platform.systems.users.edit', $protectedUser).'/remove')
                ->assertForbidden();

            $this->assertModelExists($protectedUser);
        }
    }

    public function test_single_user_delete_still_removes_an_ordinary_account(): void
    {
        $manager = User::factory()->create(['permissions' => ['platform.index' => true, 'platform.systems.users' => true]]);
        $user = User::factory()->create();

        $this->actingAs($manager)
            ->post(route('platform.systems.users').'/remove', ['id' => $user->id])
            ->assertRedirect();

        $this->assertDatabaseMissing('users', ['id' => $user->id]);
    }

    public function test_deleting_user_clears_private_account_data_but_keeps_document_history(): void
    {
        $manager = User::factory()->create(['permissions' => ['platform.index' => true, 'platform.systems.users' => true]]);
        $user = User::factory()->create();
        $document = Document::factory()->create(['registered_by' => $user->id]);
        $movement = DocumentMovement::factory()->create(['document_id' => $document->id, 'acted_by' => $user->id]);
        DB::table('notifications')->insert([
            'id' => '11111111-1111-4111-8111-111111111111',
            'type' => 'test',
            'notifiable_type' => User::class,
            'notifiable_id' => $user->id,
            'data' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('sessions')->insert([
            'id' => 'deleted-user-session',
            'user_id' => $user->id,
            'payload' => '',
            'last_activity' => now()->timestamp,
        ]);
        DB::table('password_reset_tokens')->insert([
            'email' => $user->email,
            'token' => 'unused-token',
            'created_at' => now(),
        ]);

        $this->actingAs($manager)
            ->post(route('platform.systems.users').'/remove', ['id' => $user->id])
            ->assertRedirect();

        $this->assertModelMissing($user);
        $this->assertDatabaseMissing('notifications', ['notifiable_type' => User::class, 'notifiable_id' => $user->id]);
        $this->assertDatabaseMissing('sessions', ['user_id' => $user->id]);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
        $this->assertModelExists($document);
        $this->assertModelExists($movement);
        $this->assertNull($document->fresh()->registered_by);
        $this->assertNull($movement->fresh()->acted_by);
    }

    public function test_user_bulk_delete_requires_permission_and_valid_selection(): void
    {
        $manager = User::factory()->create(['permissions' => [
            'platform.index' => true,
            'platform.systems.users' => true,
        ]]);
        $unprivileged = User::factory()->create(['permissions' => ['platform.index' => true]]);
        $selected = User::factory()->create();
        $url = route('platform.systems.users').'/bulkRemove';

        $this->actingAs($unprivileged)
            ->post($url, ['selected' => [$selected->id]])
            ->assertForbidden();

        foreach ([
            [[], 'selected'],
            [[$selected->id, $selected->id], 'selected.0'],
            [[999999], 'selected.0'],
        ] as [$invalidSelection, $errorKey]) {
            $this->actingAs($manager)
                ->from(route('platform.systems.users'))
                ->post($url, ['selected' => $invalidSelection])
                ->assertRedirect(route('platform.systems.users'))
                ->assertSessionHasErrors($errorKey);

            $this->assertDatabaseHas('users', ['id' => $selected->id]);
        }
    }

    public function test_role_list_can_be_searched_and_sorted_from_the_table_header(): void
    {
        $admin = User::factory()->create(['permissions' => ['platform.index' => true, 'platform.systems.roles' => true]]);
        Role::create(['name' => 'Office Alpha', 'slug' => 'office-alpha']);
        Role::create(['name' => 'Office Zulu', 'slug' => 'office-zulu']);
        Role::create(['name' => 'Unrelated Role', 'slug' => 'unrelated-role']);

        $response = $this->actingAs($admin)->get(route('platform.systems.roles', [
            'role_search' => 'Office',
            'sort' => '-name',
        ]));

        $response->assertSee('Search roles')
            ->assertDontSee('Sort by')
            ->assertSee('Edit')
            ->assertSee('Delete')
            ->assertSee('data-turbo="false"', false)
            ->assertSeeInOrder(['Office Zulu', 'Office Alpha'])
            ->assertDontSee('Unrelated Role');
    }

    public function test_roles_use_descriptions_while_role_codes_are_managed_automatically(): void
    {
        $admin = User::factory()->create(['permissions' => ['platform.index' => true, 'platform.systems.roles' => true]]);

        $this->actingAs($admin)
            ->get(route('platform.systems.roles.create'))
            ->assertOk()
            ->assertSee('Role description')
            ->assertDontSee('Role code');

        $this->actingAs($admin)
            ->post(route('platform.systems.roles.create').'/save', [
                'role' => [
                    'name' => 'Records Officer',
                    'description' => 'Receives, routes, and archives official records.',
                ],
                'permissions' => [],
            ])
            ->assertRedirectToRoute('platform.systems.roles');

        $role = Role::where('name', 'Records Officer')->firstOrFail();

        $this->assertSame('records-officer', $role->slug);
        $this->assertSame('Receives, routes, and archives official records.', $role->description);

        $this->actingAs($admin)
            ->get(route('platform.systems.roles', ['role_search' => 'archives official']))
            ->assertOk()
            ->assertSee('Receives, routes, and archives official records.')
            ->assertSee('Description')
            ->assertDontSee('Role code');

        $this->actingAs($admin)
            ->post(route('platform.systems.roles.edit', $role).'/save', [
                'role' => [
                    'name' => 'Senior Records Officer',
                    'description' => 'Supervises document routing and archiving.',
                ],
                'permissions' => [],
            ])
            ->assertRedirectToRoute('platform.systems.roles');

        $this->assertSame('records-officer', $role->fresh()->slug);
        $this->assertSame('Supervises document routing and archiving.', $role->fresh()->description);
    }

    public function test_role_can_be_removed_from_the_role_list(): void
    {
        $admin = User::factory()->create(['permissions' => ['platform.index' => true, 'platform.systems.roles' => true]]);
        $role = Role::create(['name' => 'Temporary Role', 'slug' => 'temporary-role']);

        $this->actingAs($admin)
            ->post(route('platform.systems.roles').'/remove?id='.$role->id)
            ->assertRedirect();

        $this->assertDatabaseMissing('roles', ['id' => $role->id]);
    }

    public function test_role_list_bulk_deletes_selected_roles_only(): void
    {
        $manager = User::factory()->create(['permissions' => [
            'platform.index' => true,
            'platform.systems.roles' => true,
        ]]);
        $selected = collect([
            Role::create(['name' => 'Temporary A', 'slug' => 'temporary-a']),
            Role::create(['name' => 'Temporary B', 'slug' => 'temporary-b']),
        ]);
        $unselected = Role::create(['name' => 'Permanent', 'slug' => 'permanent']);

        $this->actingAs($manager)
            ->get(route('platform.systems.roles'))
            ->assertSee('data-admin-bulk-delete-kind="role"', false);

        $this->post(route('platform.systems.roles').'/bulkRemove', [
            'selected' => $selected->pluck('id')->all(),
        ])->assertRedirect();

        foreach ($selected as $role) {
            $this->assertDatabaseMissing('roles', ['id' => $role->id]);
        }
        $this->assertDatabaseHas('roles', ['id' => $unselected->id]);
    }

    public function test_role_bulk_delete_protects_administrator_role_and_requires_permission(): void
    {
        $manager = User::factory()->create(['permissions' => [
            'platform.index' => true,
            'platform.systems.roles' => true,
        ]]);
        $viewer = User::factory()->create(['permissions' => ['platform.index' => true]]);
        $temporary = Role::create(['name' => 'Temporary', 'slug' => 'temporary']);
        $administrator = Role::create(['name' => 'Administrator', 'slug' => User::ADMIN_ROLE_SLUG]);
        $url = route('platform.systems.roles').'/bulkRemove';

        $this->actingAs($viewer)
            ->post($url, ['selected' => [$temporary->id]])
            ->assertForbidden();

        $this->actingAs($manager)
            ->from(route('platform.systems.roles'))
            ->post($url, ['selected' => [$temporary->id, $administrator->id]])
            ->assertRedirect(route('platform.systems.roles'))
            ->assertSessionHasErrors('selected');

        $this->assertDatabaseHas('roles', ['id' => $temporary->id]);
        $this->assertDatabaseHas('roles', ['id' => $administrator->id]);
    }

    public function test_document_registry_uses_orchid_table_with_server_side_filters_and_sorting(): void
    {
        $user = User::factory()->create([
            'office' => 'Records Unit',
            'permissions' => ['platform.index' => true],
        ]);
        Document::factory()->create([
            'tracking_number' => 'DTS-2026-0101',
            'subject' => 'Alpha memorandum',
            'document_type' => 'Memorandum',
            'current_office' => 'Records Unit',
            'status' => 'Awaiting receipt',
            'received_at' => '2026-09-20',
        ]);
        Document::factory()->create([
            'tracking_number' => 'DTS-2026-0102',
            'subject' => 'Zulu report',
            'document_type' => 'Report',
            'current_office' => 'Administrative Unit',
            'status' => 'Forwarded',
            'received_at' => '2026-09-21',
        ]);

        $response = $this->actingAs($user)->get(route('platform.documents', [
            'office' => 'Records Unit',
            'type' => 'Memorandum',
            'sort' => '-subject',
        ]));

        $response->assertOk()
            ->assertSee('DTS-2026-0101')
            ->assertDontSee('DTS-2026-0102')
            ->assertSee('Search documents')
            ->assertSee('data-filter-toggle aria-expanded="true"', false)
            ->assertDontSee('Find a document')
            ->assertSeeInOrder(['Reset', 'Apply', 'Columns'])
            ->assertSee('data-table-select-all', false)
            ->assertSee('data-table-row-select', false)
            ->assertSee('data-column="number"', false)
            ->assertDontSee('data-column="status"', false)
            ->assertSee('Actions')
            ->assertDontSee('dts-row-action-view', false)
            ->assertDontSee('dts-row-action-delete', false)
            ->assertDontSee('dts-row-action-edit', false)
            ->assertSee('<dts-column-menu>', false)
            ->assertDontSee('data-column="due-at"', false)
            ->assertSee('Configure columns')
            ->assertSee('Displayed records: 1-1 of 1');

        $editor = User::factory()->create([
            'office' => 'Records Unit',
            'permissions' => [
                'platform.index' => true,
                'platform.documents.edit' => true,
            ],
        ]);

        $this->actingAs($editor)
            ->get(route('platform.documents', [
                'office' => 'Records Unit',
                'type' => 'Memorandum',
            ]))
            ->assertOk()
            ->assertDontSee('dts-row-action-delete', false)
            ->assertSee('dts-row-action-edit', false);
    }

    public function test_registry_status_and_office_filters_match_the_same_assigned_branch(): void
    {
        $adminRole = Role::create([
            'name' => 'Admin',
            'slug' => User::ADMIN_ROLE_SLUG,
            'permissions' => [],
        ]);
        $admin = User::factory()->create(['office' => null, 'permissions' => []]);
        $admin->replaceRoles([$adminRole->id]);

        $document = Document::factory()->outgoing()->create([
            'tracking_number' => 'DTS-BRANCH-FILTER',
            'current_office' => 'Records Unit',
        ]);
        $document->officeAssignments()->where('office', 'Records Unit')->update(['status' => 'Awaiting receipt']);
        $document->officeAssignments()->create(['office' => 'Administrative Unit', 'status' => 'Forwarded']);

        $this->actingAs($admin)
            ->get(route('platform.documents', ['status' => 'Awaiting receipt']))
            ->assertOk()
            ->assertSee('DTS-BRANCH-FILTER');

        $this->get(route('platform.documents', ['office' => 'Records Unit', 'status' => 'Forwarded']))
            ->assertOk()
            ->assertDontSee('DTS-BRANCH-FILTER');

        $this->get(route('platform.documents', ['office' => 'Records Unit', 'status' => 'Awaiting receipt']))
            ->assertOk()
            ->assertSee('DTS-BRANCH-FILTER');

        $this->get(route('platform.documents', ['office' => 'Administrative Unit', 'status' => 'Forwarded']))
            ->assertOk()
            ->assertSee('DTS-BRANCH-FILTER');
    }

    public function test_registry_pending_filter_matches_both_waiting_statuses_and_legacy_documents(): void
    {
        $adminRole = Role::create(['name' => 'Admin', 'slug' => User::ADMIN_ROLE_SLUG, 'permissions' => []]);
        $admin = User::factory()->create(['office' => null, 'permissions' => []]);
        $admin->replaceRoles([$adminRole->id]);

        Document::factory()->create([
            'tracking_number' => 'DTS-PENDING-PAPER',
            'current_office' => 'Records Unit',
            'status' => DocumentMovement::STATUS_AWAITING_PHYSICAL_DOCUMENT,
        ]);
        Document::factory()->create([
            'tracking_number' => 'DTS-PENDING-OTHER',
            'current_office' => 'Administrative Unit',
            'status' => DocumentMovement::STATUS_AWAITING_RECEIPT,
        ]);
        $legacy = Document::factory()->create([
            'tracking_number' => 'DTS-PENDING-LEGACY',
            'current_office' => 'Records Unit',
            'status' => DocumentMovement::STATUS_AWAITING_RECEIPT,
        ]);
        $legacy->officeAssignments()->delete();
        Document::factory()->received()->create(['tracking_number' => 'DTS-NOT-PENDING']);

        $this->actingAs($admin)
            ->get(route('platform.documents', ['status' => 'Pending']))
            ->assertOk()
            ->assertSee('DTS-PENDING-PAPER')
            ->assertSee('DTS-PENDING-OTHER')
            ->assertSee('DTS-PENDING-LEGACY')
            ->assertDontSee('DTS-NOT-PENDING');

        $this->get(route('platform.documents', ['office' => 'Records Unit', 'status' => 'Pending']))
            ->assertOk()
            ->assertSee('DTS-PENDING-PAPER')
            ->assertSee('DTS-PENDING-LEGACY')
            ->assertDontSee('DTS-PENDING-OTHER');

        $this->get(route('platform.documents', ['status' => DocumentMovement::STATUS_AWAITING_RECEIPT]))
            ->assertOk()
            ->assertDontSee('DTS-PENDING-PAPER')
            ->assertSee('DTS-PENDING-OTHER')
            ->assertSee('DTS-PENDING-LEGACY');
    }

    public function test_registry_can_filter_a_visible_legacy_type_without_adding_it_to_registration(): void
    {
        $this->seed(DocumentTypeSeeder::class);

        $user = User::factory()->create([
            'office' => 'Records Unit',
            'permissions' => ['platform.index' => true],
        ]);
        Document::factory()->create([
            'tracking_number' => 'DTS-LEGACY-TYPE',
            'current_office' => 'Records Unit',
            'document_type' => 'Memorandum',
        ]);
        Document::factory()->create([
            'current_office' => 'Administrative Unit',
            'document_type' => 'Other Office Type',
        ]);

        $this->actingAs($user)
            ->get(route('platform.documents', ['type' => 'Memorandum']))
            ->assertOk()
            ->assertSee('DTS-LEGACY-TYPE')
            ->assertSee('value="Memorandum"', false)
            ->assertDontSee('Other Office Type');

        $this->get(route('platform.documents.create'))
            ->assertOk()
            ->assertDontSee('value="Memorandum"', false);
    }

    public function test_registry_office_and_status_filters_keep_legacy_documents_without_assignments(): void
    {
        $user = User::factory()->create([
            'office' => 'Records Unit',
            'permissions' => ['platform.index' => true],
        ]);
        $document = Document::factory()->archived()->create([
            'tracking_number' => 'DTS-LEGACY-FILTER',
            'current_office' => 'Records Unit',
        ]);
        $document->officeAssignments()->delete();

        $this->actingAs($user)
            ->get(route('platform.documents', ['office' => 'Records Unit', 'status' => 'Archived']))
            ->assertOk()
            ->assertSee('DTS-LEGACY-FILTER');
    }

    public function test_registry_can_filter_an_inactive_office_with_a_visible_document(): void
    {
        Office::query()->create(['name' => 'Records Unit', 'is_active' => false]);
        $user = User::factory()->create([
            'office' => 'Records Unit',
            'permissions' => ['platform.index' => true],
        ]);
        Document::factory()->create([
            'tracking_number' => 'DTS-INACTIVE-OFFICE',
            'current_office' => 'Records Unit',
        ]);

        $this->actingAs($user)
            ->get(route('platform.documents', ['office' => 'Records Unit']))
            ->assertOk()
            ->assertSee('DTS-INACTIVE-OFFICE')
            ->assertSee('id="registry-office" data-filter="office" list="registry-offices" value="Records Unit"', false);

        $this->get(route('platform.documents.create'))
            ->assertOk()
            ->assertDontSee('value="Records Unit"', false);
    }

    public function test_document_registry_does_not_load_movement_history_or_repeat_office_lookups_for_each_row(): void
    {
        $user = User::factory()->create([
            'office' => 'Records Unit',
            'permissions' => [
                'platform.index' => true,
                'platform.documents.edit' => true,
            ],
        ]);

        Document::factory()->count(3)->create([
            'current_office' => 'Records Unit',
        ]);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->actingAs($user)
            ->get(route('platform.documents'))
            ->assertOk();

        $queries = collect(DB::getQueryLog())
            ->pluck('query')
            ->map(fn (string $query): string => strtolower($query));

        DB::disableQueryLog();

        // Visibility may check the routing history inside the registry query (EXISTS), but no query may read
        // the movement history itself.
        $this->assertSame(0, $queries->filter(fn (string $query): bool => (bool) preg_match('/^select (?:(?!\bfrom\b).)*\bfrom [`"]?document_movements\b/', $query))->count());
        $this->assertLessThanOrEqual(2, $queries->filter(fn (string $query): bool => str_contains($query, 'office_user'))->count());
    }

    public function test_document_registry_does_not_show_route_plan_controls(): void
    {
        $adminRole = Role::create([
            'name' => 'Admin',
            'slug' => User::ADMIN_ROLE_SLUG,
            'permissions' => [],
        ]);
        $admin = User::factory()->create(['office' => null, 'permissions' => []]);
        $admin->replaceRoles([$adminRole->id]);
        Document::factory()->received()->create([
            'tracking_number' => 'DTS-REGISTRY',
            'subject' => 'Document for registry',
            'current_office' => 'Records Unit',
        ]);

        $this->actingAs($admin)
            ->get(route('platform.documents'))
            ->assertOk()
            ->assertDontSee('Route plan')
            ->assertDontSee('data-column="route-plan"', false)
            ->assertDontSee('On planned route')
            ->assertDontSee('Outside plan')
            ->assertDontSee('No plan');
    }

    public function test_document_can_be_removed_from_the_registry_by_an_administrator(): void
    {
        $adminRole = Role::create(['name' => 'Admin', 'slug' => User::ADMIN_ROLE_SLUG]);
        $admin = User::factory()->create(['permissions' => []]);
        $admin->replaceRoles([$adminRole->id]);
        $document = Document::factory()->create([
            'current_office' => 'Records Unit',
        ]);

        $this->actingAs($admin)
            ->post(route('platform.documents.destroy', $document))
            ->assertRedirectToRoute('platform.documents');

        $this->assertSoftDeleted($document);
    }

    public function test_administrator_can_bulk_remove_only_selected_documents(): void
    {
        $adminRole = Role::create(['name' => 'Admin', 'slug' => User::ADMIN_ROLE_SLUG]);
        $admin = User::factory()->create(['permissions' => []]);
        $admin->replaceRoles([$adminRole->id]);
        $selected = Document::factory()->count(2)->create(['current_office' => 'Records Unit']);
        $unselected = Document::factory()->create(['current_office' => 'Records Unit']);

        $this->actingAs($admin)
            ->get(route('platform.documents'))
            ->assertSee('data-bulk-delete-url="'.route('platform.documents.bulk-destroy').'"', false);

        $this->post(route('platform.documents.bulk-destroy'), [
            'selected' => $selected->modelKeys(),
        ])->assertRedirectToRoute('platform.documents');

        foreach ($selected as $document) {
            $this->assertSoftDeleted($document);
            $this->assertDatabaseHas('document_office_assignments', ['document_id' => $document->id]);
        }
        $this->assertNotSoftDeleted($unselected);
    }

    public function test_bulk_delete_rejects_mixed_authorization_without_deleting_any_document(): void
    {
        $editor = User::factory()->create([
            'office' => 'Records Unit',
            'permissions' => ['platform.index' => true, 'platform.documents.edit' => true],
        ]);
        $editable = Document::factory()->create(['current_office' => 'Records Unit']);
        $creatorOnly = Document::factory()->create([
            'current_office' => 'Administrative Unit',
            'registered_by' => $editor->id,
        ]);

        $this->actingAs($editor)
            ->post(route('platform.documents.bulk-destroy'), [
                'selected' => [$editable->id, $creatorOnly->id],
            ])
            ->assertForbidden();

        $this->assertDatabaseHas('documents', ['id' => $editable->id]);
        $this->assertDatabaseHas('documents', ['id' => $creatorOnly->id]);
    }

    public function test_bulk_delete_keeps_an_attachment_shared_with_another_document(): void
    {
        Storage::fake('local');
        $adminRole = Role::create(['name' => 'Admin', 'slug' => User::ADMIN_ROLE_SLUG]);
        $admin = User::factory()->create(['permissions' => []]);
        $admin->replaceRoles([$adminRole->id]);
        $selected = Document::factory()->create(['current_office' => 'Records Unit']);
        $remaining = Document::factory()->create(['current_office' => 'Records Unit']);
        $attachment = (new File(
            UploadedFile::fake()->createWithContent('shared.pdf', '%PDF-1.4 shared document'),
            'local',
            'documents',
        ))->load();
        $selected->attachments()->attach($attachment);
        $remaining->attachments()->attach($attachment);

        $this->actingAs($admin)
            ->post(route('platform.documents.bulk-destroy'), [
                'selected' => [$selected->id],
            ])
            ->assertRedirectToRoute('platform.documents');

        $this->assertDatabaseHas('attachments', ['id' => $attachment->id]);
        $this->assertSame([$attachment->id], $selected->attachments()->pluck('attachments.id')->all());
        $this->assertSame([$attachment->id], $remaining->attachments()->pluck('attachments.id')->all());
        Storage::disk('local')->assertExists($attachment->physicalPath());
    }

    public function test_bulk_delete_requires_administrator_and_valid_selection(): void
    {
        $viewer = User::factory()->create([
            'office' => 'Records Unit',
            'permissions' => ['platform.index' => true],
        ]);
        $editor = User::factory()->create([
            'office' => 'Records Unit',
            'permissions' => ['platform.index' => true, 'platform.documents.edit' => true],
        ]);
        $adminRole = Role::create(['name' => 'Admin', 'slug' => User::ADMIN_ROLE_SLUG]);
        $admin = User::factory()->create(['permissions' => []]);
        $admin->replaceRoles([$adminRole->id]);
        $document = Document::factory()->create(['current_office' => 'Records Unit']);

        $this->actingAs($viewer)
            ->get(route('platform.documents'))
            ->assertDontSee('data-bulk-delete-url', false);
        $this->post(route('platform.documents.bulk-destroy'), [
            'selected' => [$document->id],
        ])->assertForbidden();

        $this->actingAs($editor)
            ->get(route('platform.documents'))
            ->assertDontSee('data-bulk-delete-url', false);
        $this->post(route('platform.documents.bulk-destroy'), [
            'selected' => [$document->id],
        ])->assertForbidden();

        $this->actingAs($admin)
            ->from(route('platform.documents'))
            ->post(route('platform.documents.bulk-destroy'), [
                'selected' => [$document->id, $document->id],
            ])
            ->assertRedirect(route('platform.documents'))
            ->assertSessionHasErrors();

        $this->assertDatabaseHas('documents', ['id' => $document->id]);
    }

    public function test_paginated_tables_allow_supported_row_counts_and_reject_unsupported_values(): void
    {
        $admin = User::factory()->create([
            'office' => 'Records Unit',
            'permissions' => [
                'platform.index' => true,
                'platform.systems.users' => true,
                'platform.systems.roles' => true,
            ],
        ]);

        Document::factory()->count(12)->create(['current_office' => 'Records Unit']);
        User::factory()->count(26)->create();

        foreach (range(1, 12) as $index) {
            Role::create([
                'name' => "Pagination Role {$index}",
                'slug' => "pagination-role-{$index}",
            ]);
        }

        $this->actingAs($admin)
            ->get(route('platform.documents', ['per_page' => 25]))
            ->assertOk()
            ->assertSee('Rows per page')
            ->assertSee('Displayed records: 1-12 of 12');

        $this->actingAs($admin)
            ->get(route('platform.documents', ['per_page' => 999]))
            ->assertOk()
            ->assertSee('Displayed records: 1-10 of 12');

        $this->actingAs($admin)
            ->get(route('platform.systems.users', ['per_page' => 25]))
            ->assertOk()
            ->assertSee('<turbo-frame id="admin-users-table"', false)
            ->assertSee('data-table-select-all', false)
            ->assertSee('data-table-row-select', false)
            ->assertSee('data-column="number"', false)
            ->assertSee('Rows per page')
            ->assertSee('Displayed records: 1-25 of 27')
            ->assertSee('&gt;', false)
            ->assertSee('&gt;&gt;', false)
            ->assertDontSee('&raquo;', false);

        $this->actingAs($admin)
            ->get(route('platform.systems.roles', ['per_page' => 25]))
            ->assertOk()
            ->assertSee('<turbo-frame id="admin-roles-table"', false)
            ->assertSee('Rows per page')
            ->assertSee('Displayed records: 1-12 of 12');
    }

    public function test_document_queue_pages_only_show_their_status(): void
    {
        $user = User::factory()->create([
            'office' => 'Records Unit',
            'permissions' => ['platform.index' => true],
        ]);
        Document::factory()->incoming()->create(['tracking_number' => 'DTS-INCOMING', 'current_office' => 'Records Unit']);
        // "Forwarded" is the status of the office a document was forwarded to: it arrives in that office's Incoming.
        Document::factory()->outgoing()->create(['tracking_number' => 'DTS-FORWARDED-IN', 'current_office' => 'Records Unit']);
        // Outgoing lists what the office sent on, even though the document now sits with another office.
        $sentOn = Document::factory()->outgoing()->create(['tracking_number' => 'DTS-SENT-ON', 'current_office' => 'Administrative Unit']);
        $sentOn->movements()->create([
            'action' => DocumentMovement::ACTION_FORWARDED,
            'from_office' => 'Records Unit',
            'to_office' => 'Administrative Unit',
            'from_status' => DocumentMovement::STATUS_RECEIVED,
            'to_status' => DocumentMovement::STATUS_FORWARDED,
            'acted_by' => $user->id,
        ]);
        Document::factory()->archived()->create(['tracking_number' => 'DTS-ARCHIVED', 'current_office' => 'Records Unit']);
        Document::factory()->create(['tracking_number' => 'DTS-RELEASED', 'current_office' => 'Records Unit', 'status' => DocumentMovement::STATUS_RELEASED]);

        $this->actingAs($user)->get(route('platform.incoming'))
            ->assertOk()
            ->assertSee('DTS-INCOMING')
            ->assertSee('DTS-FORWARDED-IN')
            ->assertDontSee('DTS-SENT-ON')
            ->assertDontSee('DTS-ARCHIVED');

        $this->actingAs($user)->get(route('platform.outgoing'))
            ->assertOk()
            ->assertSee('DTS-SENT-ON')
            ->assertDontSee('DTS-INCOMING')
            ->assertDontSee('DTS-FORWARDED-IN')
            ->assertDontSee('DTS-ARCHIVED');

        $this->actingAs($user)->get(route('platform.archived'))
            ->assertOk()
            ->assertSee('DTS-ARCHIVED')
            ->assertSee('DTS-RELEASED')
            ->assertDontSee('DTS-INCOMING')
            ->assertDontSee('DTS-SENT-ON');

        // The sending office can still open what it sent on, but has nothing left to do there.
        $this->get(route('platform.documents.view', $sentOn))
            ->assertOk()
            ->assertSee('Action unavailable');
    }

    public function test_dashboard_uses_the_users_assigned_office_status_for_parallel_routes(): void
    {
        $user = User::factory()->create([
            'office' => 'Curriculum Implementation Division',
            'permissions' => ['platform.index' => true],
        ]);
        $document = Document::factory()->received()->create([
            'tracking_number' => 'DTS-PARALLEL-001',
            'subject' => 'Parallel routed document',
            'current_office' => 'Administrative Unit',
            'status' => 'Received',
        ]);
        $document->officeAssignments()->where('office', 'Administrative Unit')->update(['status' => 'Received']);
        $document->officeAssignments()->create([
            'office' => 'Curriculum Implementation Division',
            'status' => 'Forwarded',
        ]);

        $this->actingAs($user)
            ->get(route('platform.main'))
            ->assertOk()
            ->assertSee('<th scope="col">Offices and status</th>', false)
            ->assertSee('1 action')
            ->assertSee('DTS-PARALLEL-001')
            ->assertSee('Curriculum Implementation Division')
            ->assertSee('Forwarded')
            ->assertSee('data-receive-routes="[&quot;Curriculum Implementation Division&quot;]"', false)
            ->assertDontSee('data-receive-all-office', false)
            ->assertDontSee('<th scope="col">Overall status</th>', false)
            ->assertDontSee('Document queues')
            ->assertDontSee('Total registered records');

        $this->get(route('platform.incoming'))
            ->assertOk()
            ->assertSee('DTS-PARALLEL-001')
            ->assertSee('Curriculum Implementation Division')
            ->assertSee('Forwarded');
    }

    public function test_summary_cards_replace_recent_documents_for_office_staff(): void
    {
        $records = User::factory()->create(['office' => 'SDO-Records Unit', 'permissions' => ['platform.index' => true]]);
        $budget = User::factory()->create(['office' => 'SDO-Budget Unit', 'permissions' => ['platform.index' => true]]);
        $paper = Document::factory()->create([
            'current_office' => 'SDO-Records Unit',
            'status' => DocumentMovement::STATUS_AWAITING_PHYSICAL_DOCUMENT,
        ]);
        $paper->officeAssignments()->updateOrCreate(['office' => 'SDO-Records Unit'], ['status' => DocumentMovement::STATUS_AWAITING_PHYSICAL_DOCUMENT]);
        $finished = Document::factory()->received()->create(['current_office' => 'SDO-Budget Unit']);
        $finished->movements()->create([
            'action' => DocumentMovement::ACTION_COMPLETED,
            'from_office' => 'SDO-Budget Unit',
            'to_office' => 'SDO-Budget Unit',
            'from_status' => DocumentMovement::STATUS_RECEIVED,
            'to_status' => DocumentMovement::STATUS_COMPLETED,
            'acted_by' => $budget->id,
        ]);

        $recordsDashboard = $this->actingAs($records)
            ->get(route('platform.main'))
            ->assertOk()
            ->assertSeeInOrder(['<span>Needs action</span>', '<strong>1</strong>', '<span>Paper waiting</span>', '<strong>1</strong>'], false)
            // Queue: "Quick action" column without a View icon.
            ->assertSee('<th scope="col">Quick action</th>', false)
            ->assertDontSee('data-tooltip="View"', false)
            ->assertDontSee('Done this week')
            ->assertDontSee('Recent documents');
        // The tracking number opens the workspace on its Details tab: no office, which would jump to Routing.
        $this->assertMatchesRegularExpression('/<div class="dts-record-code"><a href="[^"]+" data-workspace-url="[^"]+"><strong>/', $recordsDashboard->getContent());

        $this->actingAs($budget)
            ->get(route('platform.main'))
            ->assertOk()
            ->assertSeeInOrder(['<span>Finished this month</span>', '<strong>1</strong>'], false)
            ->assertDontSee('Paper waiting')
            ->assertDontSee('Recent documents');
    }

    public function test_summary_cards_count_hand_offs_between_offices(): void
    {
        $budget = User::factory()->create(['office' => 'SDO-Budget Unit', 'permissions' => ['platform.index' => true]]);
        $legal = User::factory()->create(['office' => 'SDO-Legal Unit', 'permissions' => ['platform.index' => true]]);
        $document = Document::factory()->received()->create(['current_office' => 'SDO-Legal Unit', 'status' => DocumentMovement::STATUS_FORWARDED]);
        $document->officeAssignments()->updateOrCreate(['office' => 'SDO-Legal Unit'], ['status' => DocumentMovement::STATUS_FORWARDED]);
        $document->movements()->create([
            'action' => DocumentMovement::ACTION_FORWARDED,
            'from_office' => 'SDO-Budget Unit',
            'to_office' => 'SDO-Legal Unit',
            'from_status' => DocumentMovement::STATUS_RECEIVED,
            'to_status' => DocumentMovement::STATUS_FORWARDED,
            'acted_by' => $budget->id,
        ]);

        // MySQL (online) rejects DISTINCT with ORDER BY on an unselected column; SQLite does not, so check the SQL.
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });

        // Budget sent it and Legal has not received it: a stuck hand-off for Budget, the first job for Legal.
        $this->actingAs($budget)
            ->get(route('platform.main'))
            ->assertOk()
            ->assertSeeInOrder(['<span>Sent, still waiting</span>', '<strong>1</strong>'], false)
            ->assertSee(route('platform.outgoing'), false);

        $this->actingAs($legal)
            ->get(route('platform.main'))
            ->assertOk()
            ->assertSeeInOrder(['<span>Not received yet</span>', '<strong>1</strong>', '<span>Sent, still waiting</span>', '<strong>0</strong>'], false);

        $this->assertEmpty(array_filter($queries, fn (string $sql): bool => str_contains($sql, 'distinct') && str_contains($sql, 'order by')));
    }

    public function test_supervisors_keep_recent_documents_instead_of_summary_cards(): void
    {
        $this->seed(SupervisorRoleSeeder::class);
        $supervisor = User::factory()->create(['office' => 'SDO-Records Unit']);
        $supervisor->replaceRoles([Role::query()->where('slug', 'supervisor')->sole()->id]);
        $document = Document::factory()->received()->create(['current_office' => 'SDO-Budget Unit']);

        $this->actingAs($supervisor)
            ->get(route('platform.main'))
            ->assertOk()
            ->assertSee('Recent documents')
            ->assertSee($document->tracking_number)
            ->assertDontSee('data-summary-card', false)
            ->assertDontSee('My office queue');
    }

    public function test_action_queue_shows_a_whole_page_and_pages_the_rest(): void
    {
        $user = User::factory()->create(['office' => 'Records Unit', 'permissions' => ['platform.index' => true]]);
        Document::factory()->count(7)->incoming()->create(['current_office' => 'Records Unit'])
            ->each(fn (Document $document) => $document->officeAssignments()->updateOrCreate(['office' => 'Records Unit'], ['status' => 'Awaiting receipt']));

        // One page: every row shows, with no pager and no "Show more" step.
        $response = $this->actingAs($user)
            ->get(route('platform.main'))
            ->assertOk()
            ->assertDontSee('Action queue pages')
            ->assertDontSee('data-office-queue-more', false);
        $this->assertSame(7, substr_count($response->getContent(), 'data-office-queue-row'));

        Document::factory()->count(23)->incoming()->create(['current_office' => 'Records Unit'])
            ->each(fn (Document $document) => $document->officeAssignments()->updateOrCreate(['office' => 'Records Unit'], ['status' => 'Awaiting receipt']));

        // Thirty documents: 25 on page one, the registry's pager, and page two holds the other five.
        $firstPage = $this->get(route('platform.main'))
            ->assertOk()
            ->assertSee('aria-label="Action queue pages"', false)
            ->assertSee('Showing 1–25 of 30 documents')
            ->assertSee('queue_page=2#office-queue-title', false);
        $this->assertSame(25, substr_count($firstPage->getContent(), 'data-office-queue-row'));

        $secondPage = $this->get(route('platform.main', ['queue_page' => 2]))
            ->assertOk()
            ->assertSee('Showing 26–30 of 30 documents');
        $this->assertSame(5, substr_count($secondPage->getContent(), 'data-office-queue-row'));
        // Rows per page: 10 a page, and the pager links keep that choice.
        $tenRows = $this->get(route('platform.main', ['queue_per_page' => 10, 'queue_page' => 3]))
            ->assertOk()
            ->assertSee('Showing 21–30 of 30 documents')
            ->assertSee('<option value="10" selected>10</option>', false)
            ->assertSee('queue_per_page=10', false);
        $this->assertSame(10, substr_count($tenRows->getContent(), 'data-office-queue-row'));

        // An unsupported size falls back to 25.
        $this->get(route('platform.main', ['queue_per_page' => 7]))
            ->assertOk()
            ->assertSee('Showing 1–25 of 30 documents');
    }

    public function test_dashboard_counts_each_assigned_office_action_for_multi_office_users(): void
    {
        $recordsOffice = Office::create(['name' => 'Records Unit', 'sort_order' => 1, 'is_active' => true]);
        $administrativeOffice = Office::create(['name' => 'Administrative Unit', 'sort_order' => 2, 'is_active' => true]);
        $user = User::factory()->create([
            'office' => null,
            'permissions' => ['platform.index' => true],
        ]);
        $user->offices()->sync([$recordsOffice->id, $administrativeOffice->id]);
        $document = Document::factory()->received()->create([
            'tracking_number' => 'DTS-PARALLEL-002',
            'subject' => 'Two assigned office actions',
            'current_office' => 'Records Unit',
            'status' => 'Forwarded',
        ]);
        $document->officeAssignments()->where('office', 'Records Unit')->update(['status' => 'Forwarded']);
        $document->officeAssignments()->create([
            'office' => 'Administrative Unit',
            'status' => 'Forwarded',
        ]);

        $response = $this->actingAs($user)
            ->get(route('platform.main'))
            ->assertOk()
            ->assertSee('2 actions')
            ->assertSee('Records Unit')
            ->assertSee('Administrative Unit')
            ->assertDontSee('Document queues');

        // One document row listing both offices that still have work to do.
        $this->assertSame(1, substr_count($response->getContent(), 'data-office-queue-row'));
        $this->assertSame(2, substr_count($response->getContent(), 'data-office-queue-office'));
    }

    public function test_admin_tabs_and_action_queue_reflect_each_active_office_branch(): void
    {
        $adminRole = Role::create([
            'name' => 'Admin',
            'slug' => User::ADMIN_ROLE_SLUG,
            'permissions' => [],
        ]);
        $admin = User::factory()->create(['office' => null, 'permissions' => []]);
        $admin->replaceRoles([$adminRole->id]);

        $document = Document::factory()->outgoing()->create([
            'tracking_number' => 'DTS-MIXED-BRANCHES',
            'current_office' => 'Records Unit',
        ]);
        $document->officeAssignments()->where('office', 'Records Unit')->update(['status' => 'Awaiting receipt']);
        $document->officeAssignments()->create(['office' => 'Administrative Unit', 'status' => 'Forwarded']);
        $document->officeAssignments()->create(['office' => 'Curriculum Implementation Division', 'status' => 'Archived']);
        $document->movements()->create([
            'action' => DocumentMovement::ACTION_FORWARDED,
            'from_office' => 'Records Unit',
            'to_office' => 'Administrative Unit',
            'from_status' => DocumentMovement::STATUS_RECEIVED,
            'to_status' => DocumentMovement::STATUS_FORWARDED,
            'acted_by' => $admin->id,
        ]);

        $response = $this->actingAs($admin)
            ->get(route('platform.main'))
            ->assertOk()
            ->assertSee('2 actions')
            ->assertDontSee('Total registered records')
            ->assertDontSee('Document queues');

        // One document row listing both offices that still have work to do.
        $this->assertSame(1, substr_count($response->getContent(), 'data-office-queue-row'));
        $this->assertSame(2, substr_count($response->getContent(), 'data-office-queue-office'));

        foreach (['incoming', 'outgoing', 'archived'] as $section) {
            $this->get(route('platform.'.$section))
                ->assertOk()
                ->assertSee('DTS-MIXED-BRANCHES')
                ->assertSee('Displayed records: 1-1 of 1');
        }
    }

    public function test_action_queue_includes_reviewed_and_on_hold_office_routes(): void
    {
        $adminRole = Role::create(['name' => 'Admin', 'slug' => User::ADMIN_ROLE_SLUG]);
        $admin = User::factory()->create(['office' => null, 'permissions' => []]);
        $admin->replaceRoles([$adminRole->id]);
        $document = Document::factory()->create([
            'current_office' => 'Records Unit',
            'status' => 'Reviewed',
        ]);
        $document->officeAssignments()->create(['office' => 'Administrative Unit', 'status' => 'On Hold']);

        $response = $this->actingAs($admin)
            ->get(route('platform.main'))
            ->assertSee('2 actions');

        // One document row listing both offices that still have work to do.
        $this->assertSame(1, substr_count($response->getContent(), 'data-office-queue-row'));
        $this->assertSame(2, substr_count($response->getContent(), 'data-office-queue-office'));
    }

    public function test_admin_status_tabs_keep_legacy_documents_without_office_assignments(): void
    {
        $adminRole = Role::create([
            'name' => 'Admin',
            'slug' => User::ADMIN_ROLE_SLUG,
            'permissions' => [],
        ]);
        $admin = User::factory()->create(['office' => null, 'permissions' => []]);
        $admin->replaceRoles([$adminRole->id]);

        $document = Document::factory()->archived()->create([
            'tracking_number' => 'DTS-LEGACY-ARCHIVE',
        ]);
        $document->officeAssignments()->delete();

        $this->actingAs($admin)
            ->get(route('platform.archived'))
            ->assertOk()
            ->assertSee('DTS-LEGACY-ARCHIVE');
    }
}
