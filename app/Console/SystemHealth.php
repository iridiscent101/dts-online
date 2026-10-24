<?php

namespace App\Console;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Number;
use Throwable;

class SystemHealth
{
    public const SCHEDULER_HEARTBEAT_KEY = 'system:scheduler-heartbeat';

    public const HEALTHY = 'healthy';

    public const WARNING = 'warning';

    public const CRITICAL = 'critical';

    public function __construct(private readonly SystemBackup $backups) {}

    /**
     * @return array{overall: string, checked_at: string, checks: array<int, array{id: string, label: string, status: string, summary: string, details: string}>}
     */
    public function report(): array
    {
        $checks = [
            $this->databaseCheck(),
            $this->schedulerCheck(),
            $this->queueCheck(),
            $this->backupCheck(),
            $this->storageCheck(),
            $this->configurationCheck(),
        ];

        $statuses = array_column($checks, 'status');
        $overall = in_array(self::CRITICAL, $statuses, true)
            ? self::CRITICAL
            : (in_array(self::WARNING, $statuses, true) ? self::WARNING : self::HEALTHY);

        return [
            'overall' => $overall,
            'checked_at' => now()->toIso8601String(),
            'checks' => $checks,
        ];
    }

    public function recordSchedulerHeartbeat(): void
    {
        Cache::put(
            self::SCHEDULER_HEARTBEAT_KEY,
            now()->toIso8601String(),
            now()->addMinutes(max(10, (int) config('health.scheduler_max_age_minutes', 5) * 3)),
        );
    }

    /**
     * @return array{id: string, label: string, status: string, summary: string, details: string}
     */
    private function databaseCheck(): array
    {
        return $this->check('database', 'Database', function (): array {
            DB::selectOne('SELECT 1 AS health_check');

            return $this->result(
                'database',
                'Database',
                self::HEALTHY,
                'Connected',
                'Connection: '.config('database.default'),
            );
        });
    }

    /**
     * @return array{id: string, label: string, status: string, summary: string, details: string}
     */
    private function schedulerCheck(): array
    {
        return $this->check('scheduler', 'Scheduler', function (): array {
            $heartbeat = Cache::get(self::SCHEDULER_HEARTBEAT_KEY);

            if (! is_string($heartbeat) || $heartbeat === '') {
                return $this->result(
                    'scheduler',
                    'Scheduler',
                    $this->environmentFailureStatus(),
                    'Heartbeat missing',
                    'The Laravel scheduler has not recorded a heartbeat.',
                );
            }

            $recordedAt = CarbonImmutable::parse($heartbeat);
            $ageMinutes = $recordedAt->diffInMinutes(now(), absolute: true);
            $maximumAge = (int) config('health.scheduler_max_age_minutes', 5);

            if ($ageMinutes > $maximumAge) {
                return $this->result(
                    'scheduler',
                    'Scheduler',
                    $this->environmentFailureStatus(),
                    'Heartbeat stale',
                    "Last heartbeat was {$ageMinutes} minutes ago.",
                );
            }

            return $this->result(
                'scheduler',
                'Scheduler',
                self::HEALTHY,
                'Running',
                'Last heartbeat: '.$recordedAt->diffForHumans(),
            );
        });
    }

    /**
     * @return array{id: string, label: string, status: string, summary: string, details: string}
     */
    private function queueCheck(): array
    {
        return $this->check('queue', 'Queue', function (): array {
            $pendingJobs = DB::table('jobs')->count();
            $failedJobs = DB::table('failed_jobs')->count();

            if ($failedJobs > 0) {
                return $this->result(
                    'queue',
                    'Queue',
                    self::CRITICAL,
                    "{$failedJobs} failed ".str('job')->plural($failedJobs),
                    "Pending jobs: {$pendingJobs}",
                );
            }

            $warningThreshold = (int) config('health.queue_backlog_warning', 100);
            $status = $pendingJobs >= $warningThreshold ? self::WARNING : self::HEALTHY;
            $summary = $status === self::WARNING ? 'Backlog elevated' : 'No failed jobs';

            return $this->result(
                'queue',
                'Queue',
                $status,
                $summary,
                "Pending jobs: {$pendingJobs}; connection: ".config('queue.default'),
            );
        });
    }

    /**
     * @return array{id: string, label: string, status: string, summary: string, details: string}
     */
    private function backupCheck(): array
    {
        return $this->check('backup', 'Latest backup', function (): array {
            $archives = $this->backups->archives();

            usort($archives, fn (string $left, string $right): int => File::lastModified($right) <=> File::lastModified($left));

            if ($archives === []) {
                return $this->result(
                    'backup',
                    'Latest backup',
                    $this->environmentFailureStatus(),
                    'No backup found',
                    'No verified system backup is available.',
                );
            }

            $latest = $archives[0];
            $verification = $this->backups->verify($latest);
            $createdAt = CarbonImmutable::parse($verification['created_at']);
            $ageHours = $createdAt->diffInHours(now(), absolute: true);
            $maximumAge = (int) config('health.backup_max_age_hours', 30);

            if ($ageHours > $maximumAge) {
                return $this->result(
                    'backup',
                    'Latest backup',
                    $this->environmentFailureStatus(),
                    'Backup is stale',
                    "The latest verified backup is {$ageHours} hours old.",
                );
            }

            if (app()->isProduction() && ! $verification['encrypted']) {
                return $this->result(
                    'backup',
                    'Latest backup',
                    self::CRITICAL,
                    'Backup is not encrypted',
                    'Production backups must use AES-256 encryption.',
                );
            }

            return $this->result(
                'backup',
                'Latest backup',
                self::HEALTHY,
                'Verified',
                Number::fileSize(File::size($latest)).' · '.$createdAt->diffForHumans().' · '.($verification['encrypted'] ? 'AES-256' : 'unencrypted local backup'),
            );
        });
    }

    /**
     * @return array{id: string, label: string, status: string, summary: string, details: string}
     */
    private function storageCheck(): array
    {
        return $this->check('storage', 'Private storage', function (): array {
            $path = (string) config('backup.private_path');

            if (! File::isDirectory($path) || ! is_writable($path)) {
                return $this->result(
                    'storage',
                    'Private storage',
                    self::CRITICAL,
                    'Storage is not writable',
                    $path,
                );
            }

            $freeBytes = disk_free_space($path);

            if ($freeBytes === false) {
                return $this->result('storage', 'Private storage', self::WARNING, 'Capacity unavailable', $path);
            }

            $minimumBytes = (int) config('health.minimum_free_space_mb', 1024) * 1024 * 1024;
            $status = $freeBytes < $minimumBytes ? self::CRITICAL : self::HEALTHY;

            return $this->result(
                'storage',
                'Private storage',
                $status,
                $status === self::HEALTHY ? 'Writable' : 'Low disk space',
                Number::fileSize((int) $freeBytes).' available',
            );
        });
    }

    /**
     * @return array{id: string, label: string, status: string, summary: string, details: string}
     */
    private function configurationCheck(): array
    {
        $environment = app()->environment();
        $debug = (bool) config('app.debug');

        if (app()->isProduction() && $debug) {
            return $this->result(
                'configuration',
                'Configuration',
                self::CRITICAL,
                'Debug mode enabled',
                'Disable APP_DEBUG before serving production traffic.',
            );
        }

        if (app()->isProduction() && $this->mailCanSilentlySkipDelivery()) {
            return $this->result(
                'configuration',
                'Configuration',
                self::CRITICAL,
                'Email delivery disabled',
                'Use a delivery mailer in production; log and array transports do not send email.',
            );
        }

        return $this->result(
            'configuration',
            'Configuration',
            app()->isProduction() ? self::HEALTHY : self::WARNING,
            app()->isProduction() ? 'Production mode' : ucfirst($environment).' mode',
            'Debug mode: '.($debug ? 'enabled' : 'disabled'),
        );
    }

    private function mailCanSilentlySkipDelivery(): bool
    {
        $mailer = (string) config('mail.default');
        $mailers = (array) config("mail.mailers.{$mailer}.mailers", [$mailer]);

        return collect($mailers)->contains(function (string $name): bool {
            $transport = (string) config("mail.mailers.{$name}.transport", $name);

            return in_array($transport, ['log', 'array'], true);
        });
    }

    /**
     * @param  callable(): array{id: string, label: string, status: string, summary: string, details: string}  $callback
     * @return array{id: string, label: string, status: string, summary: string, details: string}
     */
    private function check(string $id, string $label, callable $callback): array
    {
        try {
            return $callback();
        } catch (Throwable $exception) {
            report($exception);

            return $this->result($id, $label, self::CRITICAL, 'Check failed', $exception->getMessage());
        }
    }

    /**
     * @return array{id: string, label: string, status: string, summary: string, details: string}
     */
    private function result(string $id, string $label, string $status, string $summary, string $details): array
    {
        return compact('id', 'label', 'status', 'summary', 'details');
    }

    private function environmentFailureStatus(): string
    {
        return app()->isProduction() ? self::CRITICAL : self::WARNING;
    }
}
