<?php

declare(strict_types=1);

namespace App\Core\Console\Commands;

use App\Core\Clock;
use App\Core\Console\Command;
use App\Services\Notification\NotificationService;

/**
 * Programme les rappels des rendez-vous confirmés puis les envoie. À planifier toutes les heures (cron) :
 *   0 * * * * cd /chemin/du/site && php bin/console bookings:remind
 */
final class BookingsRemindCommand extends Command
{
    public function __construct(
        private readonly NotificationService $notifications,
        private readonly Clock $clock,
    ) {
    }

    public function name(): string
    {
        return 'bookings:remind';
    }

    public function description(): string
    {
        return 'Programme et envoie les rappels de rendez-vous — tâche planifiée toutes les heures';
    }

    public function handle(array $arguments, array $options): int
    {
        $queued = $this->notifications->queueReminders($this->clock->now());
        $stats = $this->notifications->sendDue(100);

        $this->output->line(sprintf('%d rappel(s) programmé(s) ; %d e-mail(s) envoyé(s), %d en échec.', $queued, $stats['sent'], $stats['failed']));

        return 0;
    }
}
