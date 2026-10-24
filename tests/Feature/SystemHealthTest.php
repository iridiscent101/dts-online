<?php

namespace Tests\Feature;

use App\Console\SystemBackup;
use App\Console\SystemHealth;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Orchid\Platform\Models\Role;
use Tests\TestCase;

class SystemHealthTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_scheduler_heartbeat_is_recorded_and_reported_as_healthy(): void
    {
        Cache::forget(SystemHealth::SCHEDULER_HEARTBEAT_KEY);
        config(['backup.path' => storage_path('framework/testing/no-system-backups')]);

        $this->artisan('system:heartbeat')
            ->expectsOutput('Scheduler heartbeat recorded.')
            ->assertSuccessful();

        $report = app(SystemHealth::class)->report();
        $scheduler = collect($report['checks'])->firstWhere('id', 'scheduler');

        $this->assertNotNull(Cache::get(SystemHealth::SCHEDULER_HEARTBEAT_KEY));
        $this->assertSame(SystemHealth::HEALTHY, $scheduler['status']);
    }

    public function test_failed_jobs_make_the_cli_health_check_fail(): void
    {
        app(SystemHealth::class)->recordSchedulerHeartbeat();
        config(['backup.path' => storage_path('framework/testing/no-system-backups')]);
        DB::table('failed_jobs')->insert([
            'uuid' => (string) Str::uuid(),
            'connection' => 'database',
            'queue' => 'default',
            'payload' => '{}',
            'exception' => 'Test failure',
            'failed_at' => now(),
        ]);

        $this->artisan('system:health')
            ->expectsOutput('System health: Critical')
            ->assertExitCode(Command::FAILURE);

        $queue = collect(app(SystemHealth::class)->report()['checks'])->firstWhere('id', 'queue');
        $this->assertSame(SystemHealth::CRITICAL, $queue['status']);
        $this->assertSame('1 failed job', $queue['summary']);
    }

    public function test_only_authorized_administrators_can_view_system_health(): void
    {
        config(['backup.path' => storage_path('framework/testing/no-system-backups')]);
        app(SystemHealth::class)->recordSchedulerHeartbeat();

        $regularUser = User::factory()->create([
            'permissions' => ['platform.index' => true],
        ]);

        $this->actingAs($regularUser)
            ->get(route('platform.systems.health'))
            ->assertForbidden();

        $adminRole = Role::create([
            'name' => 'Admin',
            'slug' => User::ADMIN_ROLE_SLUG,
            'permissions' => [],
        ]);
        $administrator = User::factory()->create(['permissions' => []]);
        $administrator->replaceRoles([$adminRole->id]);

        $this->actingAs($administrator)
            ->get(route('platform.systems.health'))
            ->assertOk()
            ->assertSee('System health')
            ->assertSee('Service checks')
            ->assertSee('Database')
            ->assertSee('Scheduler')
            ->assertSee('Latest backup')
            ->assertSee('Back up now');
    }

    public function test_only_administrators_can_create_a_backup_from_system_health(): void
    {
        config(['backup.path' => storage_path('framework/testing/no-system-backups')]);
        $backups = $this->mock(SystemBackup::class);
        $backups->shouldReceive('archives')->zeroOrMoreTimes()->andReturn([]);
        $backups->shouldReceive('create')->once()->andReturn([
            'path' => storage_path('framework/testing/backups/backup_22-32-08_2026-09-30/dts-backup.zip'),
            'files' => 2,
            'bytes' => 2048,
            'encrypted' => true,
            'pruned' => 0,
        ]);

        $regularUser = User::factory()->create([
            'permissions' => ['platform.index' => true],
        ]);

        $this->actingAs($regularUser)
            ->post(route('platform.systems.health').'/createBackup')
            ->assertForbidden();

        $adminRole = Role::create([
            'name' => 'Admin',
            'slug' => User::ADMIN_ROLE_SLUG,
            'permissions' => [],
        ]);
        $administrator = User::factory()->create(['permissions' => []]);
        $administrator->replaceRoles([$adminRole->id]);

        $this->actingAs($administrator)
            ->post(route('platform.systems.health').'/createBackup')
            ->assertRedirectToRoute('platform.systems.health');
    }

    public function test_debug_mode_is_critical_in_production(): void
    {
        $originalEnvironment = $this->app->environment();
        $this->app->instance('env', 'production');
        config([
            'app.debug' => true,
            'backup.path' => storage_path('framework/testing/no-system-backups'),
        ]);

        try {
            $configuration = collect(app(SystemHealth::class)->report()['checks'])
                ->firstWhere('id', 'configuration');

            $this->assertSame(SystemHealth::CRITICAL, $configuration['status']);
            $this->assertSame('Debug mode enabled', $configuration['summary']);
        } finally {
            $this->app->instance('env', $originalEnvironment);
        }

    }

    public function test_a_non_delivery_mailer_is_critical_in_production(): void
    {
        $originalEnvironment = $this->app->environment();
        $this->app->instance('env', 'production');
        config([
            'app.debug' => false,
            'mail.default' => 'failover',
            'mail.mailers.failover.mailers' => ['smtp', 'log'],
            'backup.path' => storage_path('framework/testing/no-system-backups'),
        ]);

        try {
            $configuration = collect(app(SystemHealth::class)->report()['checks'])
                ->firstWhere('id', 'configuration');

            $this->assertSame(SystemHealth::CRITICAL, $configuration['status']);
            $this->assertSame('Email delivery disabled', $configuration['summary']);
        } finally {
            $this->app->instance('env', $originalEnvironment);
        }
    }
}
