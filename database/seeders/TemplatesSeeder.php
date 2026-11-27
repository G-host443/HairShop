<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Core\Seeding\Seeder;

/**
 * Enregistre les cinq templates. afro-natural est activé s'il n'y a encore aucun template actif.
 * Les réglages personnalisés sont vides : les valeurs par défaut viennent du manifest.json de chaque template.
 */
final class TemplatesSeeder extends Seeder
{
    public const DEFAULT = 'afro-natural';

    private const TEMPLATES = [
        'afro-natural' => 'Afro Hair & Natural Beauty',
        'elegance-premium' => 'Élégance Premium',
        'beauty-chic' => 'Beauty Chic',
        'modern-hair-studio' => 'Modern Hair Studio',
        'minimal-professional' => 'Minimal Professional',
    ];

    public function run(): void
    {
        foreach (self::TEMPLATES as $slug => $name) {
            $this->db->execute(
                'INSERT INTO templates (slug, name) VALUES (:slug, :name) ON CONFLICT (slug) DO UPDATE SET name = EXCLUDED.name',
                ['slug' => $slug, 'name' => $name],
            );
        }

        $this->db->execute(
            'INSERT INTO template_settings (template_id) SELECT id FROM templates ON CONFLICT (template_id) DO NOTHING'
        );

        if ((int) $this->db->fetchValue('SELECT count(*) FROM templates WHERE is_active') === 0) {
            $this->db->execute('UPDATE templates SET is_active = true WHERE slug = :slug', ['slug' => self::DEFAULT]);
        }
    }
}
