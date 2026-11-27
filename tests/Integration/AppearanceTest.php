<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Services\Theme\AppearanceService;

/**
 * Écrans Apparence : réglages en brouillon, aperçu, publication, validation, templates.
 */
final class AppearanceTest extends AdminTestCase
{
    /** URL du CSS des jetons dans une page HTML. */
    private static function tokensCss(string $html): string
    {
        self::assertSame(1, preg_match('#href="(/cache/theme-[a-z0-9-]+\.css)"#', $html, $m));

        return $m[1];
    }

    private function publicHome(): string
    {
        return $this->browser('10.0.0.50')->get('/')->content();
    }

    private function settings(): array
    {
        $row = $this->db->fetchOne(
            "SELECT ts.settings::text AS s, ts.draft_settings::text AS d FROM template_settings ts JOIN templates t ON t.id = ts.template_id WHERE t.slug = 'afro-natural'"
        );

        return ['published' => json_decode((string) $row['s'], true), 'draft' => $row['d'] !== null ? json_decode((string) $row['d'], true) : null];
    }

    public function testDraftPreviewAndPublish(): void
    {
        $admin = $this->loggedIn();
        $liveCss = self::tokensCss($this->publicHome());

        $admin->post('/admin/apparence/personnaliser', [
            'tokens' => ['color.accent' => '#c0392b', 'font.heading' => 'Playfair Display', 'radius.card' => '6', 'motion.level' => 'none', 'color.bg' => '#F6EFDF'],
            'layout' => ['header' => 'static'],
        ]);
        self::assertSame([], $admin->errors());

        $draft = $this->settings()['draft'];
        self::assertEquals(['color.accent' => '#C0392B', 'font.heading' => 'Playfair Display', 'radius.card' => '6px', 'motion.level' => 'none'], $draft['tokens'], 'Seules les valeurs modifiées sont stockées.');
        self::assertSame(['header' => 'static'], $draft['layout']);

        self::assertSame($liveCss, self::tokensCss($this->publicHome()), 'Site en ligne inchangé.');

        $frame = $admin->get('/admin/apparence/apercu/cadre')->content();
        $previewCss = self::tokensCss($frame);
        self::assertNotSame($liveCss, $previewCss);
        self::assertStringContainsString('--color-accent: #C0392B;', (string) file_get_contents(dirname(__DIR__, 2) . '/public' . $previewCss));
        self::assertStringContainsString('data-motion="none"', $frame);
        self::assertStringContainsString('Playfair+Display', $frame);
        self::assertStringNotContainsString('is-sticky', $frame);

        $admin->post('/admin/apparence/publier');
        $home = $this->publicHome();
        self::assertSame($previewCss, self::tokensCss($home));
        self::assertNull($this->settings()['draft']);

        // Retour aux valeurs d'origine (dans le brouillon), puis publication.
        $admin->post('/admin/apparence/valeurs-origine');
        self::assertEquals(['tokens' => [], 'layout' => []], $this->settings()['draft']);
        $admin->post('/admin/apparence/publier');
        self::assertSame($liveCss, self::tokensCss($this->publicHome()));
    }

    public function testDiscard(): void
    {
        $admin = $this->loggedIn();
        $admin->post('/admin/apparence/personnaliser', ['tokens' => ['color.accent' => '#123456']]);
        self::assertNotNull($this->settings()['draft']);

        $admin->post('/admin/apparence/annuler-modifications');
        self::assertNull($this->settings()['draft']);
    }

    public function testValidation(): void
    {
        $admin = $this->loggedIn();
        $admin->post('/admin/apparence/personnaliser', [
            'tokens' => ['color.accent' => 'red', 'font.body' => 'Comic Sans MS', 'space.section' => '5000', 'motion.level' => 'crazy'],
            'layout' => ['header' => 'flottant'],
        ]);

        $errors = $admin->errors();
        foreach (['color.accent', 'font.body', 'space.section', 'motion.level', 'layout_header'] as $key) {
            self::assertArrayHasKey($key, $errors, $key);
        }
        self::assertNull($this->settings()['draft'], 'Rien n\'est enregistré en cas d\'erreur.');
    }

    public function testContrastWarnings(): void
    {
        self::assertSame([], AppearanceService::contrastWarnings(['color.text' => '#5A4A44', 'color.bg' => '#F6EFDF']));

        $warnings = AppearanceService::contrastWarnings(['color.text' => '#CCCCCC', 'color.bg' => '#FFFFFF', 'color.on-accent' => '#17140F', 'color.accent' => '#DFB05E']);
        self::assertCount(1, $warnings);
        self::assertSame('Texte sur le fond de page', $warnings[0]['label']);

        $admin = $this->loggedIn();
        $admin->post('/admin/apparence/personnaliser', ['tokens' => ['color.text' => '#EEEEEE']]);
        self::assertStringContainsString('Lisibilité insuffisante', $admin->get('/admin/apparence/personnaliser')->content());
    }

    public function testTemplatesScreenAndActivation(): void
    {
        $admin = $this->loggedIn();
        $page = $admin->get('/admin/apparence')->content();
        self::assertStringContainsString('Template actif', $page);
        self::assertSame(4, substr_count($page, 'Essayer</a>'), 'Les quatre autres templates peuvent être essayés.');

        $admin->post('/admin/apparence/templates/inexistant/activer');
        self::assertSame('afro-natural', $this->db->fetchValue('SELECT slug FROM templates WHERE is_active'), 'Template absent du disque : refusé.');

        $admin->post('/admin/apparence/templates/afro-natural/activer');
        self::assertSame('afro-natural', $this->db->fetchValue('SELECT slug FROM templates WHERE is_active'));
        self::assertSame(200, $admin->get('/admin/apparence/personnaliser')->status());
        self::assertSame(200, $admin->get('/admin/apparence/apercu')->status());
    }
}
