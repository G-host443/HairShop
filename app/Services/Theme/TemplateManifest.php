<?php

declare(strict_types=1);

namespace App\Services\Theme;

use RuntimeException;

/**
 * Contenu validé du fichier templates/<slug>/manifest.json.
 */
final class TemplateManifest
{
    /**
     * @param array<string, string>       $tokens     jetons de design par défaut
     * @param array<string, list<string>> $sections   type de section => variantes proposées (la première est celle par défaut)
     * @param list<string>                $homePreset composition d'accueil proposée
     * @param array<string, list<string>> $layout     options de mise en page => valeurs possibles (la première par défaut)
     */
    public function __construct(
        public readonly string $slug,
        public readonly string $name,
        public readonly string $version,
        public readonly string $description,
        public readonly array $tokens,
        public readonly array $sections,
        public readonly array $homePreset,
        public readonly array $layout,
        public readonly string $path,
    ) {
    }

    public static function load(string $directory): self
    {
        $file = rtrim($directory, '/\\') . '/manifest.json';
        if (!is_file($file)) {
            throw new RuntimeException("manifest.json introuvable dans {$directory}");
        }

        try {
            $data = json_decode((string) file_get_contents($file), true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new RuntimeException("manifest.json invalide ({$directory}) : " . $e->getMessage(), 0, $e);
        }

        foreach (['slug', 'name', 'version', 'tokens', 'sections'] as $key) {
            if (!isset($data[$key])) {
                throw new RuntimeException("manifest.json ({$directory}) : clé « {$key} » manquante.");
            }
        }

        if (basename(rtrim($directory, '/\\')) !== $data['slug']) {
            throw new RuntimeException("manifest.json ({$directory}) : le slug doit correspondre au nom du dossier.");
        }

        $sections = [];
        foreach ((array) $data['sections'] as $type => $variants) {
            $variants = array_values(array_filter((array) $variants, static fn ($v): bool => is_string($v) && preg_match('/^[a-z0-9-]+$/', $v) === 1));
            if (preg_match('/^[a-z][a-z_]*$/', (string) $type) && $variants !== []) {
                $sections[(string) $type] = $variants;
            }
        }

        $layout = [];
        foreach ((array) ($data['layout'] ?? []) as $option => $values) {
            $layout[(string) $option] = array_values(array_map('strval', (array) $values));
        }

        return new self(
            (string) $data['slug'],
            (string) $data['name'],
            (string) $data['version'],
            (string) ($data['description'] ?? ''),
            array_map('strval', (array) $data['tokens']),
            $sections,
            array_values(array_map('strval', (array) ($data['home_preset'] ?? []))),
            $layout,
            rtrim($directory, '/\\'),
        );
    }

    /** Variante effective : celle demandée si le template la propose, sinon sa variante par défaut. */
    public function variantFor(string $type, ?string $requested): ?string
    {
        $variants = $this->sections[$type] ?? [];
        if ($requested !== null && in_array($requested, $variants, true)) {
            return $requested;
        }

        return $variants[0] ?? null;
    }

    public function layoutValue(string $option, ?string $requested): ?string
    {
        $values = $this->layout[$option] ?? [];

        return $requested !== null && in_array($requested, $values, true) ? $requested : ($values[0] ?? null);
    }
}
