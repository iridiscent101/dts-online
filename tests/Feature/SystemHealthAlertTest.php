<?php

namespace Tests\Feature;

use App\Console\SystemHealth;
use App\Console\SystemHealthAlerter;
use App\Models\User;
use App\Notifications\SystemHealthNotification;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Orchid\Platform\Models\Role;
use Orchid\Platform\Notifications\DashboardChannel;
use Tests\TestCase;

class SystemHealthAlertTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::forget(SystemHealthAlerter::STATE_KEY);
        config([
            'health.alert_mail_enabled' => false,
            'health.alert_repeat_hours' => 12,
        ]);
    }

    public function test_health_alerts_are_sent_only_to_administrators_and_duplicates_are_suppressed(): void
    {
        Notification::fake();
        $administrator = $this->createAdministrator();
        $regularUser = User::factory()->create();
        $health = $this->mock(SystemHealth::class);
        $health->shouldReceive('report')->twice()->andReturn($this->criticalReport());

        $this->artisan('system:notify-health')
            ->expectsOutput('System health critical alert sent to 1 administrator.')
            ->assertSuccessful();

        $this->artisan('system:notify-health')
            ->expectsOutput('System health is unchanged; no duplicate alert was sent.')
            ->assertSuccessful();

        Notification::assertSentTo(
            $administrator,
            SystemHealthNotification::class,
            fn (SystemHealthNotification $notification): bool => $notification->event === SystemHealthNotification::ISSUE,
        );
        Notification::assertNotSentTo($regularUser, SystemHealthNotification::class);
        Notification::assertSentTimes(SystemHealthNotification::class, 1);
        $this->assertIsArray(Cache::get(SystemHealthAlerter::STATE_KEY));
    }

    public function test_recovery_notification_is_sent_once_and_clears_the_incident(): void
    {
        Notification::fake();
        $administrator = $this->createAdministrator();
        $health = $this->mock(SystemHealth::class);
        $health->shouldReceive('report')
            ->twice()
            ->andReturn($this->criticalReport(), $this->healthyReport());

        $this->artisan('system:notify-health')->assertSuccessful();
        $this->artisan('system:notify-health')
            ->expectsOutput('System health recovery sent to 1 administrator.')
            ->assertSuccessful();

        Notification::assertSentTo(
            $administrator,
            SystemHealthNotification::class,
            fn (SystemHealthNotification $notification): bool => $notification->event === SystemHealthNotification::RECOVERY,
        );
        Notification::assertSentTimes(SystemHealthNotification::class, 2);
        $this->assertNull(Cache::get(SystemHealthAlerter::STATE_KEY));
    }

    public function test_unchanged_incident_is_repeated_after_the_reminder_interval(): void
    {
        Notification::fake();
        $this->createAdministrator();
        $health = $this->mock(SystemHealth::class);
        $health->shouldReceive('report')->twice()->andReturn($this->criticalReport());

        $this->artisan('system:notify-health')->assertSuccessful();
        $this->travel(13)->hours();
        $this->artisan('system:notify-health')->assertSuccessful();

        Notification::assertSentTimes(SystemHealthNotification::class, 2);
    }

    public function test_opening_a_health_notification_marks_it_read_and_opens_system_health(): void
    {
        $administrator = $this->createAdministrator();
        $administrator->notify(new SystemHealthNotification(
            $this->criticalReport(),
            SystemHealthNotification::ISSUE,
        ));
        $notification = $administrator->unreadNotifications()->firstOrFail();

        $this->actingAs($administrator)
            ->get(route('platform.notifications.open', $notification->id))
            ->assertRedirectToRoute('platform.systems.health');

        $this->assertNotNull($notification->fresh()->read_at);
    }

    public function test_mail_delivery_can_be_enabled_without_disabling_dashboard_notifications(): void
    {
        config(['health.alert_mail_enabled' => true]);

        $channels = (new SystemHealthNotification(
            $this->criticalReport(),
            SystemHealthNotification::ISSUE,
        ))->via(User::factory()->make());

        $this->assertSame([DashboardChannel::class, 'mail'], $channels);
    }

    private function createAdministrator(): User
    {
        $role = Role::query()->firstOrCreate(
            ['slug' => User::ADMIN_ROLE_SLUG],
            ['name' => 'Admin', 'permissions' => []],
        );
        $administrator = User::factory()->create(['permissions' => []]);
        $administrator->replaceRoles([$role->id]);

        return $administrator;
    }

    /**
     * @return array{overall: string, checked_at: string, checks: array<int, array{id: string, label: string, status: string, summary: string, details: string}>}
     */
    private function criticalReport(): array
    {
        return [
            'overall' => SystemHealth::CRITICAL,
            'checked_at' => now()->toIso8601String(),
            'checks' => [
                [
                    'id' => 'queue',
                    'label' => 'Queue',
                    'status' => SystemHealth::CRITICAL,
                    'summary' => '1 failed job',
                    'details' => 'Pending jobs: 0',
                ],
            ],
        ];
    }

    /**
     * @return array{overall: string, checked_at: string, checks: array<int, array{id: string, label: string, status: string, summary: string, details: string}>}
     */
    private function healthyReport(): array
    {
        return [
            'overall' => SystemHealth::HEALTHY,
            'checked_at' => now()->toIso8601String(),
            'checks' => [
                [
                    'id' => 'queue',
                    'label' => 'Queue',
                    'status' => SystemHealth::HEALTHY,
                    'summary' => 'No failed jobs',
                    'details' => 'Pending jobs: 0',
                ],
            ],
        ];
    }
}
