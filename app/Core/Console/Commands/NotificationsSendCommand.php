<?php

declare(strict_types=1);

namespace App\Core\Console\Commands;

use App\Core\Console\Command;
use App\Services\Notification\NotificationService;

/**
 * Envoie la file d'e-mails. À planifier toutes les 5 minutes (cron) :
 *   *&#47;5 * * * * cd /chemin/du/site && php bin/console notifications:send
 */
final class NotificationsSendCommand extends Command
{
    public function __construct(private readonly NotificationService $notifications)
    {
    }

    public function name(): string
    {
        return 'notifications:send';
    }

    public function description(): string
    {
        return 'Envoie les e-mails en attente (--limit=50) — tâche planifiée toutes les 5 minutes';
    }

    public function handle(array $arguments, array $options): int
    {
        $limit = isset($options['limit']) ? max(1, min(500, (int) $options['limit'])) : 50;
        $stats = $this->notifications->sendDue($limit);

        $this->output->line(sprintf('%d envoyé(s), %d en échec (nouvel essai plus tard), %d devenu(s) inutile(s).', $stats['sent'], $stats['failed'], $stats['cancelled']));

        return 0;
    }
}
