<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Orchid\Platform\Models\Role;
use Tests\TestCase;

class UserBulkRemoveTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function administrator(): User
    {
        $administrator = User::factory()->create(['permissions' => ['platform.index' => true, 'platform.systems.users' => true]]);
        $role = Role::firstOrCreate(['slug' => User::ADMIN_ROLE_SLUG], ['name' => 'Administrator']);
        $administrator->replaceRoles([$role->id]);

        return $administrator->fresh();
    }

    private function deactivated(User $by): User
    {
        $user = User::factory()->create();
        $user->deactivate($by);

        return $user->fresh();
    }

    public function test_administrators_see_delete_selected_and_deactivated_rows_are_marked(): void
    {
        $administrator = $this->administrator();
        $inactive = $this->deactivated($administrator);
        $active = User::factory()->create();

        $html = $this->actingAs($administrator)->get(route('platform.systems.users'))
            ->assertOk()
            ->assertSee('data-admin-bulk-remove-url="'.route('platform.systems.users').'/bulkRemove"', false)
            ->getContent();

        $this->assertMatchesRegularExpression('/value="'.$inactive->id.'"[^>]*data-table-row-select[^>]*data-row-deactivated/s', $html);
        $this->assertDoesNotMatchRegularExpression('/value="'.$active->id.'"[^>]*data-table-row-select\s+data-row-deactivated/s', $html);
    }

    public function test_user_managers_who_are_not_administrators_cannot_bulk_delete(): void
    {
        $manager = User::factory()->create(['permissions' => ['platform.index' => true, 'platform.systems.users' => true]]);
        $inactive = $this->deactivated($manager);

        $this->actingAs($manager)->get(route('platform.systems.users'))
            ->assertOk()
            ->assertDontSee('data-admin-bulk-remove-url', false);

        $this->post(route('platform.systems.users').'/bulkRemove', ['selected' => [$inactive->id]])->assertForbidden();
        $this->assertNotSoftDeleted($inactive);
    }

    public function test_deactivated_accounts_are_deleted_and_their_files_and_signing_requests_move_to_the_administrator(): void
    {
        $administrator = $this->administrator();
        [$first, $second] = [$this->deactivated($administrator), $this->deactivated($administrator)];
        $untouched = $this->deactivated($administrator);
        DB::table('attachments')->insert([
            'name' => 'scan', 'original_name' => 'scan.pdf', 'mime' => 'application/pdf', 'extension' => 'pdf', 'size' => 1,
            'path' => 'x/', 'hash' => 'abc', 'disk' => 'public', 'user_id' => $first->id, 'sort' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($administrator)
            ->post(route('platform.systems.users').'/bulkRemove', ['selected' => [$first->id, $second->id]])
            ->assertRedirect();

        $this->assertSoftDeleted($first);
        $this->assertSoftDeleted($second);
        $this->assertNotSoftDeleted($untouched);
        $this->assertSame(0, DB::table('attachments')->where('user_id', $first->id)->count());
        $this->assertSame(1, DB::table('attachments')->where('user_id', $administrator->id)->count());
    }

    public function test_active_accounts_self_and_administrators_are_refused_and_nothing_is_deleted(): void
    {
        $administrator = $this->administrator();
        $inactive = $this->deactivated($administrator);
        $active = User::factory()->create();
        $otherAdministrator = $this->administrator();

        foreach ([$active->id, $administrator->id, $otherAdministrator->id] as $protectedId) {
            $this->actingAs($administrator)
                ->from(route('platform.systems.users'))
                ->post(route('platform.systems.users').'/bulkRemove', ['selected' => [$inactive->id, $protectedId]])
                ->assertRedirect(route('platform.systems.users'))
                ->assertSessionHasErrors('selected');

            $this->assertNotSoftDeleted($inactive);
            $this->assertNotSoftDeleted(User::find($protectedId));
        }
    }
}
