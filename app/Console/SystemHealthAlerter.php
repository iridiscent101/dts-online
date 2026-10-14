<?php

namespace App\Console;

use App\Models\User;
use App\Notifications\SystemHealthNotification;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;

class SystemHealthAlerter
{
    public const STATE_KEY = 'system:health-alert-state';

    public function __construct(private readonly SystemHealth $health) {}

    /**
     * @return array{result: string, recipients: int, status: string}
     */
    public function send(): array
    {
        $report = $this->health->report();
        $state = Cache::get(self::STATE_KEY);
        $administrators = $this->administrators();

        if ($report['overall'] === SystemHealth::HEALTHY) {
            return $this->sendRecovery($report, is_array($state) ? $state : null, $administrators);
        }

        $fingerprint = $this->fingerprint($report);

        if ($this->isDuplicate($state, $fingerprint)) {
            return $this->result('unchanged', 0, $report['overall']);
        }

        if ($administrators->isEmpty()) {
            return $this->result('no_recipients', 0, $report['overall']);
        }

        Notification::send($administrators, new SystemHealthNotification($report, SystemHealthNotification::ISSUE));

        Cache::forever(self::STATE_KEY, [
            'status' => $report['overall'],
            'fingerprint' => $fingerprint,
            'notified_at' => now()->toIso8601String(),
        ]);

        return $this->result('alerted', $administrators->count(), $report['overall']);
    }

    /**
     * @param  array{overall: string, checked_at: string, checks: array<int, array{id: string, label: string, status: string, summary: string, details: string}>}  $report
     * @param  array<string, mixed>|null  $state
     * @param  Collection<int, User>  $administrators
     * @return array{result: string, recipients: int, status: string}
     */
    private function sendRecovery(array $report, ?array $state, Collection $administrators): array
    {
        if ($state === null) {
            return $this->result('healthy', 0, $report['overall']);
        }

        if ($administrators->isEmpty()) {
            return $this->result('no_recipients', 0, $report['overall']);
        }

        Notification::send($administrators, new SystemHealthNotification($report, SystemHealthNotification::RECOVERY));
        Cache::forget(self::STATE_KEY);

        return $this->result('recovered', $administrators->count(), $report['overall']);
    }

    /**
     * @return Collection<int, User>
     */
    private function administrators(): Collection
    {
        return User::query()
            ->whereHas('roles', fn (Builder $query) => $query->where('slug', User::ADMIN_ROLE_SLUG))
            ->get();
    }

    private function isDuplicate(mixed $state, string $fingerprint): bool
    {
        if (! is_array($state) || ($state['fingerprint'] ?? null) !== $fingerprint) {
            return false;
        }

        $notifiedAt = $state['notified_at'] ?? null;

        return is_string($notifiedAt)
            && CarbonImmutable::parse($notifiedAt)->diffInHours(now(), absolute: true) < (int) config('health.alert_repeat_hours', 12);
    }

    /**
     * @param  array{overall: string, checked_at: string, checks: array<int, array{id: string, label: string, status: string, summary: string, details: string}>}  $report
     */
    private function fingerprint(array $report): string
    {
        $issues = collect($report['checks'])
            ->reject(fn (array $check): bool => $check['status'] === SystemHealth::HEALTHY)
            ->map(fn (array $check): string => $check['id'].':'.$check['status'].':'.$check['summary'])
            ->sort()
            ->values()
            ->all();

        return hash('sha256', implode('|', $issues));
    }

    /**
     * @return array{result: string, recipients: int, status: string}
     */
    private function result(string $result, int $recipients, string $status): array
    {
        return compact('result', 'recipients', 'status');
    }
}
