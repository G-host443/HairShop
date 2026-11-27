<?php

declare(strict_types=1);

namespace App\Services\Theme;

use App\Core\Config;
use App\Core\Database;
use App\Core\View;
use App\Services\Content\PreviewContext;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Template actif du site public : manifest, jetons de design, CSS généré, assets, gabarits de sections.
 *
 * Changer de template ne modifie aucun contenu : seules templates.is_active et les réglages
 * propres à chaque template (template_settings) entrent en jeu.
 */
final class TemplateManager
{
    public const FALLBACK = 'afro-natural';

    private ?TemplateManifest $manifest = null;
    private ?ThemeTokens $tokens = null;

    /** @var array<string, mixed>|null réglages enregistrés du template actif */
    private ?array $settings = null;

    private bool $registered = false;

    public function __construct(
        private readonly Database $db,
        private readonly View $view,
        private readonly Config $config,
        private readonly LoggerInterface $logger,
        private readonly PreviewContext $preview,
        private readonly string $templatesPath = '',
        private readonly string $publicPath = '',
    ) {
    }

    public function manifest(): TemplateManifest
    {
        if ($this->manifest !== null) {
            return $this->manifest;
        }

        // Aperçu d'un autre template (administration) ; sinon le template actif.
        $slug = $this->preview->template() ?? (string) ($this->db->fetchValue('SELECT slug FROM templates WHERE is_active') ?? self::FALLBACK);

        try {
            $this->manifest = TemplateManifest::load($this->templatesDir() . '/' . $slug);
        } catch (Throwable $e) {
            // Template actif absent ou cassé : le site reste en ligne avec le template de secours.
            if ($slug === self::FALLBACK) {
                throw $e;
            }
            $this->logger->error("Template « {$slug} » inutilisable, repli sur " . self::FALLBACK . ' : ' . $e->getMessage());
            $this->manifest = TemplateManifest::load($this->templatesDir() . '/' . self::FALLBACK);
        }

        return $this->manifest;
    }

    /**
     * Réglages enregistrés du template actif (en aperçu de l'apparence : le brouillon s'il existe).
     *
     * @return array<string, mixed>
     */
    public function settings(): array
    {
        if ($this->settings === null) {
            $column = $this->preview->theme() ? 'COALESCE(ts.draft_settings, ts.settings)' : 'ts.settings';
            $raw = $this->db->fetchValue(
                "SELECT {$column}::text FROM template_settings ts JOIN templates t ON t.id = ts.template_id WHERE t.slug = :slug",
                ['slug' => $this->manifest()->slug],
            );
            $decoded = $raw !== null ? json_decode((string) $raw, true) : [];
            $this->settings = is_array($decoded) ? $decoded : [];
        }

        return $this->settings;
    }

    public function tokens(): ThemeTokens
    {
        return $this->tokens ??= new ThemeTokens($this->manifest()->tokens, (array) ($this->settings()['tokens'] ?? []));
    }

    /** Option de mise en page (ex. header : sticky | static), validée par le manifest. */
    public function layout(string $option): ?string
    {
        $requested = $this->settings()['layout'][$option] ?? null;

        return $this->manifest()->layoutValue($option, is_string($requested) ? $requested : null);
    }

    /**
     * Rend les gabarits du template accessibles sous l'espace de noms Twig @theme.
     */
    public function register(): void
    {
        if ($this->registered) {
            return;
        }
        $this->view->addPath($this->manifest()->path, 'theme');
        $this->registered = true;
    }

    /**
     * Gabarit Twig d'une section, pour la variante proposée par le template :
     * 1. propre au template (templates/<slug>/sections/<type>/<variante>.twig) ;
     * 2. bibliothèque partagée (resources/views/sections/<type>/<variante>.twig) ;
     * 3. gabarit générique de repli (resources/views/sections/<type>/default.twig).
     */
    public function sectionTemplate(string $type, ?string $variant): string
    {
        $this->register();
        $effective = $this->manifest()->variantFor($type, $variant);

        if ($effective !== null) {
            foreach (["@theme/sections/{$type}/{$effective}.twig", "sections/{$type}/{$effective}.twig"] as $candidate) {
                if ($this->view->exists($candidate)) {
                    return $candidate;
                }
            }
        }

        return "sections/{$type}/default.twig";
    }

    /**
     * URL du CSS des jetons de design. Le nom du fichier contient l'empreinte du contenu :
     * toute modification produit une nouvelle URL, le cache du navigateur n'est jamais périmé.
     */
    public function tokensCssUrl(): string
    {
        $css = "/* Généré automatiquement depuis les réglages du template — ne pas modifier. */\n" . $this->tokens()->toCss();
        $name = 'theme-' . $this->manifest()->slug . '-' . substr(hash('sha256', $css), 0, 12) . '.css';
        $file = $this->publicDir() . '/cache/' . $name;

        if (!is_file($file)) {
            if (!is_dir(dirname($file))) {
                mkdir(dirname($file), 0775, true);
            }
            file_put_contents($file . '.tmp', $css, LOCK_EX);
            rename($file . '.tmp', $file);
        }

        return '/cache/' . $name;
    }

    /**
     * URL d'un asset du template (CSS, JS, images), copié dans public/themes/<slug>/.
     * En mode debug, la copie est resynchronisée automatiquement quand le fichier source change.
     */
    public function assetUrl(string $path): string
    {
        $slug = $this->manifest()->slug;
        $path = ltrim(str_replace('\\', '/', $path), '/');
        if (str_contains($path, '..')) {
            throw new RuntimeException('Chemin d\'asset invalide.');
        }

        $source = $this->manifest()->path . '/assets/' . $path;
        $target = $this->publicDir() . "/themes/{$slug}/{$path}";

        if ((bool) $this->config->get('app.debug') && is_file($source) && (!is_file($target) || filemtime($source) > filemtime($target))) {
            if (!is_dir(dirname($target))) {
                mkdir(dirname($target), 0775, true);
            }
            copy($source, $target);
            touch($target, (int) filemtime($source));
        }

        return "/themes/{$slug}/{$path}" . (is_file($target) ? '?v=' . filemtime($target) : '');
    }

    /**
     * Copie tous les assets d'un template vers public/themes/<slug>/ (déploiement).
     *
     * @return int nombre de fichiers copiés
     */
    public function publishAssets(string $slug): int
    {
        $source = $this->templatesDir() . "/{$slug}/assets";
        if (!is_dir($source)) {
            return 0;
        }

        $count = 0;
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            $relative = substr(str_replace('\\', '/', $file->getPathname()), strlen(str_replace('\\', '/', $source)) + 1);
            $target = $this->publicDir() . "/themes/{$slug}/{$relative}";
            if (!is_dir(dirname($target))) {
                mkdir(dirname($target), 0775, true);
            }
            copy($file->getPathname(), $target);
            touch($target, (int) $file->getMTime());
            $count++;
        }

        return $count;
    }

    /** @return list<string> slugs des templates présents sur le disque */
    public function availableSlugs(): array
    {
        $slugs = [];
        foreach (glob($this->templatesDir() . '/*/manifest.json') ?: [] as $manifest) {
            $slugs[] = basename(dirname($manifest));
        }
        sort($slugs);

        return $slugs;
    }

    /** Manifest d'un template présent sur le disque (null s'il est absent ou invalide). */
    public function manifestFor(string $slug): ?TemplateManifest
    {
        if (preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $slug) !== 1) {
            return null;
        }
        try {
            return TemplateManifest::load($this->templatesDir() . '/' . $slug);
        } catch (Throwable) {
            return null;
        }
    }

    private function templatesDir(): string
    {
        return $this->templatesPath !== '' ? $this->templatesPath : base_path('templates');
    }

    private function publicDir(): string
    {
        return $this->publicPath !== '' ? $this->publicPath : public_path();
    }
}
