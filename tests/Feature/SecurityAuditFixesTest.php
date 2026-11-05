<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentMovement;
use App\Models\Office;
use App\Models\User;
use App\Services\DocumentRoutingService;
use Database\Seeders\AdminUserSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Orchid\Platform\Models\Role;
use RuntimeException;
use Tests\TestCase;

/**
 * Regression tests for the leads raised by the run-2 security audit (Nov 2026).
 */
class SecurityAuditFixesTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_changing_the_recovery_email_needs_the_current_password(): void
    {
        $user = User::factory()->create(['permissions' => ['platform.index' => true], 'notification_email' => 'old@gmail.com', 'notification_email_verified_at' => now()]);
        $save = route('platform.profile').'/save';

        $this->actingAs($user)->post($save, ['user' => ['name' => $user->name, 'email' => $user->email, 'notification_email' => 'attacker@gmail.com']])
            ->assertSessionHasErrors('current_password');
        $this->post($save, ['current_password' => 'wrong-password', 'user' => ['name' => $user->name, 'email' => $user->email, 'notification_email' => 'attacker@gmail.com']])
            ->assertSessionHasErrors('current_password');
        $this->assertSame('old@gmail.com', $user->fresh()->notification_email);

        $this->post($save, ['user' => ['name' => $user->name, 'email' => 'someone-else@example.com', 'notification_email' => 'old@gmail.com']])
            ->assertSessionHasErrors('current_password');

        // A name-only change needs no password.
        $this->post($save, ['user' => ['name' => 'New Name', 'email' => $user->email, 'notification_email' => 'old@gmail.com']])
            ->assertSessionHasNoErrors();
        $this->assertSame('New Name', $user->fresh()->name);

        $this->post($save, ['current_password' => 'password', 'user' => ['name' => 'New Name', 'email' => $user->email, 'notification_email' => 'new@gmail.com']])
            ->assertSessionHasNoErrors();
        $this->assertSame('new@gmail.com', $user->fresh()->notification_email);
    }

    public function test_role_descriptions_are_escaped_on_the_role_list(): void
    {
        $admin = $this->administrator();
        Role::create(['name' => 'Probe', 'slug' => 'probe', 'permissions' => []])
            ->forceFill(['description' => '<b data-probe>x</b>'])->save();

        $this->actingAs($admin)->get(route('platform.systems.roles'))
            ->assertOk()
            ->assertDontSee('<b data-probe>', false)
            ->assertSee('&lt;b data-probe&gt;', false);
    }

    public function test_a_signed_in_post_does_not_clear_another_accounts_failed_sign_in_count(): void
    {
        $insider = User::factory()->create(['permissions' => ['platform.index' => true]]);
        $victim = User::factory()->create(['email' => 'victim@example.test']);
        $key = 'login:victim@example.test|127.0.0.1';

        for ($i = 0; $i < 3; $i++) {
            $this->post(route('platform.login.auth'), ['email' => $victim->email, 'password' => 'wrong-password']);
        }
        $this->assertSame(3, RateLimiter::attempts($key));

        $this->actingAs($insider)->post(route('platform.login.auth'), ['email' => $victim->email, 'password' => 'Anything-Long-1!']);

        $this->assertSame(3, RateLimiter::attempts($key));
    }

    public function test_delegated_user_manager_cannot_reset_credentials_outside_their_offices_or_authority(): void
    {
        $manager = User::factory()->create(['office' => 'Budget Unit', 'permissions' => ['platform.index' => true, 'platform.systems.users' => true]]);
        $records = User::factory()->create(['office' => 'SDO-Records Unit', 'permissions' => ['platform.index' => true]]);
        $sameOffice = User::factory()->create(['office' => 'Budget Unit', 'permissions' => ['platform.index' => true]]);
        $roleManager = User::factory()->create(['office' => 'Budget Unit']);
        $roleManager->replaceRoles([Role::create(['name' => 'Roles', 'slug' => 'roles-mgr', 'permissions' => ['platform.systems.roles' => true]])->id]);

        $payload = fn (User $target): array => ['user' => ['name' => $target->name, 'email' => $target->email, 'password' => 'Dummy#Pass-1x', 'password_confirmation' => 'Dummy#Pass-1x', 'roles' => []]];

        // Another office (here the Records Unit): signing in as them would hand over that office's documents.
        $this->actingAs($manager)->post(route('platform.systems.users.edit', $records).'/save', $payload($records))->assertForbidden();
        $this->assertFalse(Hash::check('Dummy#Pass-1x', $records->fresh()->password));

        // An account with a permission the manager lacks cannot be edited or deactivated.
        $this->post(route('platform.systems.users.edit', $roleManager).'/save', $payload($roleManager))->assertForbidden();
        $this->post(route('platform.systems.users.edit', $roleManager).'/deactivate')->assertForbidden();
        $this->assertTrue($roleManager->fresh()->inRole('roles-mgr'));
        $this->assertFalse($roleManager->fresh()->isDeactivated());

        // Same office, no extra access: allowed as before.
        $this->post(route('platform.systems.users.edit', $sameOffice).'/save', ['user' => ['name' => $sameOffice->name, 'email' => $sameOffice->email, 'password' => 'Dummy#Pass-1x', 'password_confirmation' => 'Dummy#Pass-1x']])
            ->assertRedirect(route('platform.systems.users'));
        $this->assertTrue(Hash::check('Dummy#Pass-1x', $sameOffice->fresh()->password));

        // Administrators keep full control.
        $this->actingAs($this->administrator())->post(route('platform.systems.users.edit', $records).'/save', $payload($records))
            ->assertRedirect(route('platform.systems.users'));
    }

    public function test_office_managers_cannot_name_an_office_records_unit_or_take_a_name_still_in_history(): void
    {
        $manager = User::factory()->create(['permissions' => ['platform.index' => true, 'platform.systems.offices' => true]]);
        $budget = Office::create(['name' => 'Budget Unit', 'sort_order' => 1, 'is_active' => true]);
        $fields = fn (string $name): array => ['office' => ['name' => $name, 'sort_order' => 1, 'is_active' => 1]];

        $this->actingAs($manager)->post(route('platform.systems.offices.edit', $budget).'/save', $fields('Records Unit'))
            ->assertSessionHasErrors('office.name');
        $this->post(route('platform.systems.offices.create').'/save', $fields('Records Unit'))->assertSessionHasErrors('office.name');

        $document = Document::factory()->create(['registered_office' => 'Old Deleted Office', 'current_office' => 'Old Deleted Office']);
        $this->post(route('platform.systems.offices.edit', $budget).'/save', $fields('Old Deleted Office'))
            ->assertSessionHasErrors('office.name');
        $this->assertSame('Budget Unit', $budget->fresh()->name);
        $this->assertNotNull($document);

        $this->actingAs($this->administrator())->post(route('platform.systems.offices.edit', $budget).'/save', $fields('Records Unit'))
            ->assertSessionHasNoErrors();
        $this->assertSame('Records Unit', $budget->fresh()->name);
    }

    public function test_admin_seeder_needs_a_password_from_the_environment_and_does_not_restore_a_removed_account(): void
    {
        $this->withEnv('ADMIN_SEEDER_PASSWORD', '', function (): void {
            $this->expectException(RuntimeException::class);
            $this->seed(AdminUserSeeder::class);
        });
    }

    public function test_admin_seeder_forces_a_new_password_and_keeps_a_removed_account_removed(): void
    {
        $this->withEnv('ADMIN_SEEDER_PASSWORD', 'Local-Seed-Pass-1', function (): void {
            $this->seed(AdminUserSeeder::class);
            $admin = User::query()->where('email', AdminUserSeeder::EMAIL)->firstOrFail();
            $this->assertTrue($admin->isAdministrator());
            $this->assertTrue((bool) $admin->must_change_password);
            $this->assertTrue(Hash::check('Local-Seed-Pass-1', $admin->password));

            $admin->delete();
            $this->seed(AdminUserSeeder::class);
            $this->assertNull(User::query()->where('email', AdminUserSeeder::EMAIL)->first());
        });
    }

    public function test_private_disk_is_not_served_by_the_framework_storage_routes(): void
    {
        $this->assertFalse(config('filesystems.disks.local.serve'));
        $this->assertFalse(Route::has('storage.local'));
        $this->assertFalse(Route::has('storage.local.upload'));
    }

    public function test_deleting_a_mistaken_re_forward_restores_the_other_offices_finished_route(): void
    {
        $sender = User::factory()->create(['office' => 'Administrative Unit', 'permissions' => ['platform.index' => true]]);
        $document = Document::factory()->outgoing()->create(['current_office' => 'Administrative Unit']);
        $document->officeAssignments()->updateOrCreate(['office' => 'Administrative Unit'], ['status' => DocumentMovement::STATUS_RECEIVED]);
        $completed = $document->movements()->create([
            'action' => DocumentMovement::ACTION_COMPLETED,
            'from_office' => 'SDO-Budget Unit',
            'to_office' => 'SDO-Budget Unit',
            'from_status' => DocumentMovement::STATUS_RECEIVED,
            'to_status' => DocumentMovement::STATUS_COMPLETED,
        ]);
        $forward = $document->movements()->create([
            'action' => DocumentMovement::ACTION_FORWARDED,
            'from_office' => 'Administrative Unit',
            'to_office' => 'SDO-Budget Unit',
            'from_status' => DocumentMovement::STATUS_RECEIVED,
            'to_status' => DocumentMovement::STATUS_FORWARDED,
            'acted_by' => $sender->id,
        ]);
        $document->officeAssignments()->updateOrCreate(['office' => 'SDO-Budget Unit'], ['status' => DocumentMovement::STATUS_FORWARDED, 'movement_id' => $forward->id]);

        app(DocumentRoutingService::class)->deletePendingRoute($document, $sender, 'Administrative Unit', 'SDO-Budget Unit', 'Sent by mistake.');

        $this->assertDatabaseHas('document_office_assignments', [
            'document_id' => $document->id,
            'office' => 'SDO-Budget Unit',
            'status' => DocumentMovement::STATUS_COMPLETED,
            'movement_id' => $completed->id,
        ]);
        $this->assertDatabaseMissing('document_movements', ['id' => $forward->id]);
    }

    private function administrator(): User
    {
        $role = Role::query()->firstOrCreate(['slug' => User::ADMIN_ROLE_SLUG], ['name' => 'Administrator', 'permissions' => []]);
        $admin = User::factory()->create(['office' => 'SDO-Records Unit']);
        $admin->replaceRoles([$role->id]);

        return $admin->fresh();
    }

    private function withEnv(string $name, string $value, callable $callback): void
    {
        $previous = getenv($name);
        putenv("{$name}={$value}");
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
        try {
            $callback();
        } finally {
            putenv($previous === false ? $name : "{$name}={$previous}");
            unset($_ENV[$name], $_SERVER[$name]);
        }
    }
}
