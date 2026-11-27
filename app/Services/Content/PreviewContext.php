<?php

declare(strict_types=1);

namespace App\Services\Content;

/**
 * Mode aperçu de la requête en cours (administration uniquement).
 *
 * - drafts : les pages sont rendues avec leurs brouillons (contenu, ordre, visibilité, variantes) ;
 * - theme  : le template utilise ses réglages en brouillon (couleurs, polices…) ;
 * - template : un autre template que le template actif (essai avant activation).
 *
 * Seuls les contrôleurs d'aperçu, protégés par l'authentification admin, activent ces modes :
 * un visiteur ne voit jamais de brouillon.
 */
final class PreviewContext
{
    private bool $drafts = false;
    private bool $theme = false;
    private ?string $template = null;

    public function enableDrafts(): void
    {
        $this->drafts = true;
    }

    public function enableTheme(): void
    {
        $this->theme = true;
    }

    public function useTemplate(string $slug): void
    {
        $this->template = $slug;
    }

    public function template(): ?string
    {
        return $this->template;
    }

    public function drafts(): bool
    {
        return $this->drafts;
    }

    public function theme(): bool
    {
        return $this->theme;
    }

    public function active(): bool
    {
        return $this->drafts || $this->theme || $this->template !== null;
    }
}
