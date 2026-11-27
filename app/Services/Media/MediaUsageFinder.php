<?php

declare(strict_types=1);

namespace App\Services\Media;

use App\Core\Database;

/**
 * Indique où un média est utilisé, pour empêcher de supprimer une image encore affichée sur le site.
 * Chaque nouveau module qui référence des médias ajoute ici sa vérification.
 */
final class MediaUsageFinder
{
    /** Réglages du site contenant un identifiant de média. */
    public const MEDIA_SETTINGS = [
        'general.logo_media_id' => 'Logo du site',
        'general.favicon_media_id' => 'Icône du site (favicon)',
        'seo.og_media_id' => 'Image de partage (réseaux sociaux)',
    ];

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @return list<string> descriptions lisibles des usages (vide = média libre)
     */
    public function usages(int $mediaId): array
    {
        $usages = [];

        $checks = [
            ['SELECT s.name FROM service_images si JOIN services s ON s.id = si.service_id WHERE si.media_id = :id', 'Photo de la prestation « %s »'],
            ['SELECT name FROM service_categories WHERE image_media_id = :id', 'Image de la catégorie « %s »'],
            ["SELECT s.name || ' ' || z.code FROM service_variants v JOIN services s ON s.id = v.service_id JOIN service_sizes z ON z.id = v.size_id WHERE v.image_media_id = :id", 'Photo de la variante « %s »'],
            ['SELECT title FROM gallery_items WHERE media_id = :id OR after_media_id = :id2', 'Réalisation « %s » de la galerie'],
            ['SELECT title FROM pages WHERE og_media_id = :id', 'Image de partage de la page « %s »'],
            ['SELECT title FROM blog_posts WHERE cover_media_id = :id', 'Couverture de l\'article « %s »'],
            ['SELECT author_name FROM reviews WHERE photo_media_id = :id', 'Photo de l\'avis de %s'],
            ['SELECT title FROM blog_posts WHERE strpos(content, \'data-media-id="\' || CAST(:id AS text) || \'"\') > 0', 'Image dans l\'article « %s »'],
        ];

        foreach ($checks as [$sql, $label]) {
            $params = ['id' => $mediaId];
            if (str_contains($sql, ':id2')) {
                $params['id2'] = $mediaId;
            }
            foreach ($this->db->fetchAll($sql, $params) as $row) {
                $usages[] = sprintf($label, (string) reset($row));
            }
        }

        foreach (self::MEDIA_SETTINGS as $key => $label) {
            $value = $this->db->fetchValue('SELECT value::text FROM site_settings WHERE key = :key', ['key' => $key]);
            if ($value !== null && (int) json_decode((string) $value, true) === $mediaId) {
                $usages[] = $label;
            }
        }

        return $usages;
    }
}
