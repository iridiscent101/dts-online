<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
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
            'archived' => 'No archived documents',
        ] as $route => $heading) {
            $this->get(route('platform.'.$route))
                ->assertSee($heading)
                ->assertDontSee('Sample Screen');
        }
    }

    public function test_login_shows_deped_branding(): void
    {
        $this->get(route('platform.login'))->assertSee('DOCUMENT TRACKING SYSTEM');
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
            ->assertSee('Review document')
            ->assertSee('Attachments are stored privately')
            ->assertSee('Register document')
            ->assertSee('Movement timeline');
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
        $admin = User::factory()->create(['permissions' => [
            'platform.index' => true,
            'platform.systems.users' => true,
        ]]);
        $user = User::factory()->create(['office' => null]);

        $this->actingAs($admin)
            ->get(route('platform.systems.users.edit', $user))
            ->assertOk()
            ->assertSee('Office Assignment')
            ->assertSee('Records Unit');

        $this->actingAs($admin)
            ->post(route('platform.systems.users.edit', $user).'/save', [
                'user' => [
                    'name' => $user->name,
                    'email' => $user->email,
                    'office' => 'Records Unit',
                    'roles' => [],
                ],
            ])
            ->assertRedirectToRoute('platform.systems.users');

        $this->assertSame('Records Unit', $user->fresh()->office);

        $this->actingAs($admin)
            ->get(route('platform.systems.users'))
            ->assertOk()
            ->assertSee('Office')
            ->assertSee('Records Unit');
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
            ->assertSeeInOrder(['Reset', 'Apply', 'Columns'])
            ->assertSee('data-column-proxy="due-at"', false)
            ->assertSee('Configure columns')
            ->assertSee('Displayed records: 1-1 of 1');
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
        User::factory()->count(11)->create();

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
            ->assertSee('Rows per page')
            ->assertSee('Displayed records: 1-12 of 12');

        $this->actingAs($admin)
            ->get(route('platform.systems.roles', ['per_page' => 25]))
            ->assertOk()
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
        Document::factory()->outgoing()->create(['tracking_number' => 'DTS-OUTGOING', 'current_office' => 'Records Unit']);
        Document::factory()->archived()->create(['tracking_number' => 'DTS-ARCHIVED', 'current_office' => 'Records Unit']);

        $this->actingAs($user)->get(route('platform.incoming'))
            ->assertOk()
            ->assertSee('DTS-INCOMING')
            ->assertDontSee('DTS-OUTGOING')
            ->assertDontSee('DTS-ARCHIVED');

        $this->actingAs($user)->get(route('platform.outgoing'))
            ->assertOk()
            ->assertSee('DTS-OUTGOING')
            ->assertDontSee('DTS-INCOMING')
            ->assertDontSee('DTS-ARCHIVED');

        $this->actingAs($user)->get(route('platform.archived'))
            ->assertOk()
            ->assertSee('DTS-ARCHIVED')
            ->assertDontSee('DTS-INCOMING')
            ->assertDontSee('DTS-OUTGOING');
    }
}
