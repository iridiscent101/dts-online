<?php

namespace App\Console\Commands;

use App\Console\SystemHealthAlerter;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('system:notify-health')]
#[Description('Notify administrators when system health changes or remains unhealthy past the reminder interval')]
class SendSystemHealthAlerts extends Command
{
    public function __construct(private readonly SystemHealthAlerter $alerter)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $outcome = $this->alerter->send();

        $message = match ($outcome['result']) {
            'alerted' => "System health {$outcome['status']} alert sent to {$outcome['recipients']} ".str('administrator')->plural($outcome['recipients']).'.',
            'recovered' => "System health recovery sent to {$outcome['recipients']} ".str('administrator')->plural($outcome['recipients']).'.',
            'no_recipients' => 'No administrators are available to receive system health alerts.',
            'unchanged' => 'System health is unchanged; no duplicate alert was sent.',
            default => 'System health is healthy; no alert was needed.',
        };

        $this->line($message);

        return self::SUCCESS;
    }
}
