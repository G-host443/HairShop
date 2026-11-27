<?php

declare(strict_types=1);

namespace App\Core\Console\Commands;

use App\Core\Console\Command;
use App\Services\Blog\BlogService;

/**
 * Publie les articles programmés dont la date est arrivée. À planifier toutes les 15 minutes (cron) :
 *   *\/15 * * * * cd /chemin/du/site && php bin/console blog:publish-scheduled
 *
 * Le site affiche déjà un article programmé dès que sa date est passée ; la tâche met son statut à jour
 * pour que l'administration indique « Publié ».
 */
final class BlogPublishScheduledCommand extends Command
{
    public function __construct(private readonly BlogService $blog)
    {
    }

    public function name(): string
    {
        return 'blog:publish-scheduled';
    }

    public function description(): string
    {
        return 'Publie les articles programmés arrivés à échéance — tâche planifiée toutes les 15 minutes';
    }

    public function handle(array $arguments, array $options): int
    {
        $published = $this->blog->publishScheduled();
        foreach ($published as $post) {
            $this->output->line('Publié : ' . $post['title']);
        }
        $this->output->line(sprintf('%d article(s) publié(s).', count($published)));

        return 0;
    }
}
