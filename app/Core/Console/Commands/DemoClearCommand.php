<?php

declare(strict_types=1);

namespace App\Core\Console\Commands;

use App\Core\Console\Command;
use App\Core\Database;
use App\Services\Media\MediaUsageFinder;
use Database\Seeders\DemoContentSeeder;

/**
 * Supprime le contenu d'exemple créé par « db:seed --demo » (hors catalogue) :
 * réalisations et images d'exemple, articles et catégories du blog d'exemple, avis et promotion d'exemple.
 *
 * Le catalogue de démonstration n'est pas supprimé : il sert de base aux vraies prestations
 * (modifiez les prix, désactivez ou supprimez ce qui ne vous concerne pas dans l'administration).
 * Un article ou une image modifié(e) depuis reste supprimé(e) si il ou elle porte la marque d'exemple :
 * l'option --dry-run affiche ce qui serait supprimé, sans rien toucher.
 */
final class DemoClearCommand extends Command
{
    public function __construct(
        private readonly Database $db,
        private readonly MediaUsageFinder $usage,
    ) {
    }

    public function name(): string
    {
        return 'demo:clear';
    }

    public function description(): string
    {
        return 'Supprime le contenu d\'exemple (galerie, blog, avis, promotion, images) — --dry-run pour vérifier d\'abord';
    }

    public function handle(array $arguments, array $options): int
    {
        $dryRun = isset($options['dry-run']);
        $media = 'demo/%';
        $slugs = '{' . implode(',', DemoContentSeeder::BLOG_SLUGS) . '}';

        $counts = [
            'réalisations' => (int) $this->db->fetchValue('SELECT count(*) FROM gallery_items g JOIN media m ON m.id = g.media_id WHERE m.disk_path LIKE :p', ['p' => $media]),
            'articles' => (int) $this->db->fetchValue('SELECT count(*) FROM blog_posts WHERE slug = ANY (CAST(:s AS text[]))', ['s' => $slugs]),
            'avis' => (int) $this->db->fetchValue('SELECT count(*) FROM reviews WHERE author_name LIKE :p', ['p' => DemoContentSeeder::REVIEW_PREFIX . '%']),
            'promotions' => (int) $this->db->fetchValue('SELECT count(*) FROM promotions WHERE name LIKE :p', ['p' => DemoContentSeeder::PROMOTION_PREFIX . '%']),
            'images' => (int) $this->db->fetchValue('SELECT count(*) FROM media WHERE disk_path LIKE :p', ['p' => $media]),
        ];
        foreach ($counts as $label => $count) {
            $this->output->line(sprintf('  %-14s %d', $label, $count));
        }
        if ($dryRun) {
            $this->output->line('Simulation : rien n\'a été supprimé.');

            return 0;
        }

        $files = [];
        $kept = 0;
        $this->db->transaction(function () use ($media, $slugs, &$files, &$kept): void {
            $this->db->execute('DELETE FROM gallery_items g USING media m WHERE m.id = g.media_id AND m.disk_path LIKE :p', ['p' => $media]);
            $this->db->execute('DELETE FROM gallery_items g USING media m WHERE m.id = g.after_media_id AND m.disk_path LIKE :p', ['p' => $media]);
            $this->db->execute('DELETE FROM blog_posts WHERE slug = ANY (CAST(:s AS text[]))', ['s' => $slugs]);
            $this->db->execute(
                'DELETE FROM blog_categories c WHERE c.slug = ANY (CAST(:s AS text[])) AND NOT EXISTS (SELECT 1 FROM blog_posts p WHERE p.category_id = c.id)',
                ['s' => '{' . implode(',', array_keys(DemoContentSeeder::BLOG_CATEGORIES)) . '}'],
            );
            $this->db->execute('DELETE FROM blog_tags t WHERE NOT EXISTS (SELECT 1 FROM blog_post_tags pt WHERE pt.tag_id = t.id)');
            $this->db->execute('DELETE FROM reviews WHERE author_name LIKE :p', ['p' => DemoContentSeeder::REVIEW_PREFIX . '%']);
            $this->db->execute('DELETE FROM promotions WHERE name LIKE :p', ['p' => DemoContentSeeder::PROMOTION_PREFIX . '%']);

            // Images d'exemple encore utilisées ailleurs (logo, prestation…) : conservées.
            foreach ($this->db->fetchAll('SELECT id, disk_path, variants::text AS variants FROM media WHERE disk_path LIKE :p', ['p' => $media]) as $row) {
                if ($this->usage->usages((int) $row['id']) !== []) {
                    $kept++;
                    continue;
                }
                $this->db->execute('DELETE FROM media WHERE id = :id', ['id' => $row['id']]);
                $files[] = (string) $row['disk_path'];
                foreach ((array) json_decode((string) $row['variants'], true) as $variant) {
                    $files[] = (string) $variant;
                }
            }
        });

        foreach ($files as $file) {
            $path = public_path('uploads/' . $file);
            if (str_starts_with($file, DemoContentSeeder::MEDIA_PREFIX) && !str_contains($file, '..') && is_file($path)) {
                unlink($path);
            }
        }

        $this->output->line('Contenu d\'exemple supprimé.' . ($kept > 0 ? " {$kept} image(s) conservée(s) car encore utilisée(s)." : ''));
        $this->output->line('Pensez aussi à remplacer les prix fictifs du catalogue et à rédiger la FAQ et les pages légales.');

        return 0;
    }
}
