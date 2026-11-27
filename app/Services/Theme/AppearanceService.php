<?php

declare(strict_types=1);

namespace App\Services\Theme;

use App\Core\Database;
use App\Core\Http\Request;
use App\Core\Validation\ValidationException;
use App\Services\Audit\AuditLogger;
use RuntimeException;

/**
 * Écrans « Apparence » : choix du template et personnalisation de ses jetons de design.
 *
 * Les réglages modifiés vont dans template_settings.draft_settings (visible seulement en aperçu) ;
 * « Publier » les recopie dans settings. Seules les valeurs différentes du manifest sont stockées,
 * pour qu'une mise à jour du template profite des nouvelles valeurs par défaut.
 */
final class AppearanceService
{
    /** Libellés des jetons connus ; un jeton inconnu est affiché avec sa clé. */
    public const LABELS = [
        'color.bg' => 'Fond de page',
        'color.surface' => 'Fond des cartes',
        'color.surface-alt' => 'Fond alterné',
        'color.border' => 'Bordures',
        'color.accent' => 'Couleur d\'accent',
        'color.accent-light' => 'Accent clair',
        'color.accent-dark' => 'Accent foncé (liens)',
        'color.text' => 'Texte',
        'color.text-muted' => 'Texte secondaire',
        'color.heading' => 'Titres',
        'color.on-accent' => 'Texte sur l\'accent (boutons)',
        'font.heading' => 'Police des titres',
        'font.body' => 'Police du texte',
        'font.script' => 'Police manuscrite',
        'radius.card' => 'Arrondi des cartes',
        'radius.image' => 'Arrondi des images',
        'radius.button' => 'Arrondi des boutons',
        'space.section' => 'Espace entre les sections',
        'size.container' => 'Largeur maximale du contenu',
        'motion.level' => 'Animations',
    ];

    public const MOTION_LABELS = ['none' => 'Aucune', 'subtle' => 'Discrètes', 'lively' => 'Prononcées'];

    public const LAYOUT_LABELS = [
        'header' => ['label' => 'En-tête', 'values' => ['sticky' => 'Reste visible en haut de l\'écran', 'static' => 'Défile avec la page']],
        'container' => ['label' => 'Largeur du contenu', 'values' => ['narrow' => 'Étroite', 'wide' => 'Large']],
    ];

    /** Bornes des réglages en pixels, par préfixe. */
    private const PX_RANGES = ['radius' => [0, 60], 'space' => [24, 200], 'size' => [720, 1600]];

    /** Couples texte / fond vérifiés (seuil WCAG AA : 4,5 pour le texte courant, 3 pour les grands titres). */
    private const CONTRAST_PAIRS = [
        ['color.text', 'color.bg', 4.5, 'Texte sur le fond de page'],
        ['color.text-muted', 'color.bg', 4.5, 'Texte secondaire sur le fond de page'],
        ['color.text', 'color.surface', 4.5, 'Texte sur les cartes'],
        ['color.heading', 'color.bg', 3.0, 'Titres sur le fond de page'],
        ['color.accent-dark', 'color.bg', 4.5, 'Liens sur le fond de page'],
        ['color.on-accent', 'color.accent', 4.5, 'Texte des boutons'],
    ];

    public function __construct(
        private readonly Database $db,
        private readonly TemplateManager $templates,
        private readonly AuditLogger $audit,
    ) {
    }

    // ---------- Templates ----------

    /**
     * Templates enregistrés et/ou présents sur le disque.
     *
     * @return list<array{slug: string, name: string, version: string, description: string, is_active: bool, installed: bool}>
     */
    public function templates(): array
    {
        $rows = [];
        foreach ($this->db->fetchAll('SELECT slug, name, version, is_active FROM templates ORDER BY is_active DESC, id') as $row) {
            $rows[(string) $row['slug']] = $row;
        }
        foreach ($this->templates->availableSlugs() as $slug) {
            $rows[$slug] ??= ['slug' => $slug, 'name' => $slug, 'version' => '', 'is_active' => false];
        }

        $list = [];
        foreach ($rows as $slug => $row) {
            $manifest = $this->templates->manifestFor($slug);
            $list[] = [
                'slug' => $slug,
                'name' => $manifest?->name ?? (string) $row['name'],
                'version' => $manifest?->version ?? (string) $row['version'],
                'description' => $manifest?->description ?? '',
                'is_active' => (bool) $row['is_active'],
                'installed' => $manifest !== null,
                'swatches' => $manifest !== null ? array_values(array_filter([
                    $manifest->tokens['color.bg'] ?? null,
                    $manifest->tokens['color.surface-alt'] ?? null,
                    $manifest->tokens['color.accent'] ?? null,
                    $manifest->tokens['color.accent-dark'] ?? null,
                    $manifest->tokens['color.heading'] ?? null,
                ])) : [],
                'fonts' => $manifest !== null ? array_values(array_unique(array_filter([$manifest->tokens['font.heading'] ?? null, $manifest->tokens['font.body'] ?? null]))) : [],
            ];
        }

        return $list;
    }

    /** Active un template installé. Le contenu et les réglages des autres templates sont conservés. */
    public function activate(string $slug, ?int $userId, ?Request $request = null): void
    {
        $manifest = $this->templates->manifestFor($slug) ?? throw new RuntimeException("Le template « {$slug} » n'est pas installé.");

        $this->db->transaction(function () use ($manifest): void {
            $this->db->execute(
                'INSERT INTO templates (slug, name, version) VALUES (:slug, :name, :version)
                 ON CONFLICT (slug) DO UPDATE SET name = EXCLUDED.name, version = EXCLUDED.version',
                ['slug' => $manifest->slug, 'name' => $manifest->name, 'version' => $manifest->version],
            );
            $this->db->execute('UPDATE templates SET is_active = false WHERE is_active AND slug <> :slug', ['slug' => $manifest->slug]);
            $this->db->execute('UPDATE templates SET is_active = true WHERE slug = :slug', ['slug' => $manifest->slug]);
            $this->db->execute(
                'INSERT INTO template_settings (template_id) SELECT id FROM templates WHERE slug = :slug ON CONFLICT (template_id) DO NOTHING',
                ['slug' => $manifest->slug],
            );
        });
        $this->audit->log('appearance.template_activated', $userId, $request, 'template', null, ['slug' => $slug]);
    }

    // ---------- Personnalisation (template actif) ----------

    /** @return array{tokens: array<string, string>, layout: array<string, string>} réglages en brouillon, sinon publiés */
    public function draft(): array
    {
        $row = $this->settingsRow();
        $raw = $row['draft'] ?? $row['published'] ?? '{}';

        return self::normalize(json_decode((string) $raw, true));
    }

    public function hasDraft(): bool
    {
        return ($this->settingsRow()['draft'] ?? null) !== null;
    }

    /** Valeurs effectives (manifest + brouillon), validées. @return array<string, string> */
    public function effectiveTokens(): array
    {
        return (new ThemeTokens($this->templates->manifest()->tokens, $this->draft()['tokens']))->all();
    }

    /** @return array<string, string> option => valeur effective */
    public function effectiveLayout(): array
    {
        $manifest = $this->templates->manifest();
        $draft = $this->draft()['layout'];
        $layout = [];
        foreach ($manifest->layout as $option => $values) {
            $layout[$option] = (string) $manifest->layoutValue($option, $draft[$option] ?? null);
        }

        return $layout;
    }

    /**
     * Enregistre le formulaire de personnalisation dans le brouillon.
     *
     * @param array<string, mixed> $tokensInput clé du jeton => valeur saisie (nombre seul pour les réglages en px)
     * @param array<string, mixed> $layoutInput
     * @throws ValidationException
     */
    public function saveDraft(array $tokensInput, array $layoutInput): void
    {
        $manifest = $this->templates->manifest();
        $tokens = [];
        $errors = [];

        foreach ($manifest->tokens as $key => $default) {
            if (!array_key_exists($key, $tokensInput)) {
                continue;
            }
            $value = trim((string) (is_scalar($tokensInput[$key]) ? $tokensInput[$key] : ''));
            $prefix = explode('.', $key, 2)[0];
            $label = self::LABELS[$key] ?? $key;

            if ($prefix === 'color') {
                $value = strtoupper($value);
            } elseif (isset(self::PX_RANGES[$prefix]) && str_ends_with($default, 'px')) {
                [$min, $max] = self::PX_RANGES[$prefix];
                $number = filter_var($value, FILTER_VALIDATE_INT);
                if ($number === false || $number < $min || $number > $max) {
                    $errors[$key] = "« {$label} » : un nombre de pixels entre {$min} et {$max}.";
                    continue;
                }
                $value = $number . 'px';
            }

            if (!ThemeTokens::isValid($key, $value)) {
                $errors[$key] = "« {$label} » : valeur invalide.";
                continue;
            }
            if (strcasecmp($value, $default) !== 0) {
                $tokens[$key] = $value;
            }
        }

        $layout = [];
        foreach ($manifest->layout as $option => $values) {
            $value = $layoutInput[$option] ?? null;
            if ($value === null) {
                continue;
            }
            if (!in_array($value, $values, true)) {
                $errors['layout_' . $option] = 'Option de mise en page invalide.';
            } elseif ($value !== ($values[0] ?? null)) {
                $layout[$option] = $value;
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $this->writeDraft(['tokens' => $tokens, 'layout' => $layout]);
    }

    /** Brouillon vidé de toute personnalisation : publier remettra le template tel qu'il est livré. */
    public function resetDraftToDefaults(): void
    {
        $this->writeDraft(['tokens' => [], 'layout' => []]);
    }

    public function publish(?int $userId, ?Request $request = null): void
    {
        $this->db->execute(
            'UPDATE template_settings SET settings = COALESCE(draft_settings, settings), draft_settings = NULL WHERE template_id = :id',
            ['id' => $this->activeTemplateId()],
        );
        $this->audit->log('appearance.published', $userId, $request, 'template', $this->activeTemplateId());
    }

    public function discard(): void
    {
        $this->db->execute('UPDATE template_settings SET draft_settings = NULL WHERE template_id = :id', ['id' => $this->activeTemplateId()]);
    }

    /**
     * Couples de couleurs sous le seuil de lisibilité WCAG AA.
     *
     * @param array<string, string> $tokens
     * @return list<array{label: string, ratio: float, minimum: float}>
     */
    public static function contrastWarnings(array $tokens): array
    {
        $warnings = [];
        foreach (self::CONTRAST_PAIRS as [$fg, $bg, $minimum, $label]) {
            if (!isset($tokens[$fg], $tokens[$bg])) {
                continue;
            }
            $ratio = ThemeTokens::contrastRatio($tokens[$fg], $tokens[$bg]);
            if ($ratio < $minimum) {
                $warnings[] = ['label' => $label, 'ratio' => round($ratio, 2), 'minimum' => $minimum];
            }
        }

        return $warnings;
    }

    /**
     * Description des champs du formulaire, groupés (couleurs, polices, formes, animations).
     *
     * @return array<string, list<array{key: string, label: string, kind: string, value: string, default: string, min?: int, max?: int}>>
     */
    public function formFields(): array
    {
        $manifest = $this->templates->manifest();
        $effective = $this->effectiveTokens();
        $groups = ['color' => [], 'font' => [], 'shape' => [], 'motion' => []];

        foreach ($manifest->tokens as $key => $default) {
            $prefix = explode('.', $key, 2)[0];
            $field = ['key' => $key, 'label' => self::LABELS[$key] ?? $key, 'value' => $effective[$key] ?? $default, 'default' => $default];

            if ($prefix === 'color') {
                $groups['color'][] = $field + ['kind' => 'color'];
            } elseif ($prefix === 'font') {
                $groups['font'][] = $field + ['kind' => 'font'];
            } elseif ($prefix === 'motion') {
                $groups['motion'][] = $field + ['kind' => 'motion'];
            } elseif (isset(self::PX_RANGES[$prefix]) && str_ends_with($default, 'px')) {
                [$min, $max] = self::PX_RANGES[$prefix];
                $field['value'] = (string) (int) $field['value'];
                $field['default'] = (string) (int) $default;
                $groups['shape'][] = $field + ['kind' => 'px', 'min' => $min, 'max' => $max];
            }
        }

        return $groups;
    }

    // ---------- Interne ----------

    private function activeTemplateId(): int
    {
        $slug = $this->templates->manifest()->slug;
        $this->db->execute(
            'INSERT INTO template_settings (template_id) SELECT id FROM templates WHERE slug = :slug ON CONFLICT (template_id) DO NOTHING',
            ['slug' => $slug],
        );

        return (int) ($this->db->fetchValue('SELECT id FROM templates WHERE slug = :slug', ['slug' => $slug])
            ?? throw new RuntimeException("Template « {$slug} » absent de la base."));
    }

    /** @return array{published: ?string, draft: ?string}|null */
    private function settingsRow(): ?array
    {
        return $this->db->fetchOne(
            'SELECT settings::text AS published, draft_settings::text AS draft FROM template_settings WHERE template_id = :id',
            ['id' => $this->activeTemplateId()],
        );
    }

    /** @param array{tokens: array<string, string>, layout: array<string, string>} $settings */
    private function writeDraft(array $settings): void
    {
        $this->db->execute(
            'UPDATE template_settings SET draft_settings = CAST(:s AS jsonb) WHERE template_id = :id',
            ['id' => $this->activeTemplateId(), 's' => json_encode(['tokens' => (object) $settings['tokens'], 'layout' => (object) $settings['layout']], JSON_THROW_ON_ERROR)],
        );
    }

    /** @return array{tokens: array<string, string>, layout: array<string, string>} */
    private static function normalize(mixed $decoded): array
    {
        $decoded = is_array($decoded) ? $decoded : [];

        return [
            'tokens' => array_filter((array) ($decoded['tokens'] ?? []), 'is_string'),
            'layout' => array_filter((array) ($decoded['layout'] ?? []), 'is_string'),
        ];
    }
}
