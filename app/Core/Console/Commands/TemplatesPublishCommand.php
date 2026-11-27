<?php

declare(strict_types=1);

namespace App\Core\Console\Commands;

use App\Core\Console\Command;
use App\Services\Theme\TemplateManager;
use App\Services\Theme\TemplateManifest;
use Throwable;

/**
 * Copie les assets des templates (templates/<slug>/assets) vers public/themes/<slug>.
 * À lancer à chaque déploiement : en production, la copie automatique du mode debug est désactivée.
 */
final class TemplatesPublishCommand extends Command
{
    public function __construct(private readonly TemplateManager $templates)
    {
    }

    public function name(): string
    {
        return 'templates:publish';
    }

    public function description(): string
    {
        return 'Vérifie les manifest.json et publie les assets des templates dans public/themes';
    }

    public function handle(array $arguments, array $options): int
    {
        $ok = true;
        foreach ($this->templates->availableSlugs() as $slug) {
            try {
                TemplateManifest::load(base_path('templates/' . $slug));
                $count = $this->templates->publishAssets($slug);
                $this->output->info("  ✔ {$slug} : {$count} fichier(s) publié(s)");
            } catch (Throwable $e) {
                $ok = false;
                $this->output->error("  ✘ {$slug} : " . $e->getMessage());
            }
        }

        return $ok ? 0 : 1;
    }
}
