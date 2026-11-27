<?php

declare(strict_types=1);

namespace App\Services\Audit;

/**
 * Libellés lisibles des actions du journal d'audit.
 */
final class AuditLabels
{
    /** Domaines (préfixe de l'action) proposés comme filtre. */
    public const DOMAINS = [
        'auth' => 'Connexions et mots de passe',
        'booking' => 'Rendez-vous',
        'customers' => 'Fiches clientes',
        'account' => 'Espaces clientes',
        'catalog' => 'Prestations et tarifs',
        'schedule' => 'Horaires',
        'content' => 'Pages',
        'appearance' => 'Apparence',
        'blog' => 'Blog',
        'gallery' => 'Galerie',
        'reviews' => 'Avis',
        'promotions' => 'Promotions',
        'media' => 'Médias',
        'messages' => 'Messages',
        'settings' => 'Paramètres',
        'user' => 'Comptes d\'administration',
    ];

    private const ACTIONS = [
        'auth.login' => 'Connexion',
        'auth.login_failed' => 'Échec de connexion',
        'auth.logout' => 'Déconnexion',
        'auth.password_changed' => 'Mot de passe modifié',
        'auth.password_reset' => 'Mot de passe réinitialisé',
        'auth.password_reset_requested' => 'Réinitialisation demandée',
        'account.registered' => 'Espace cliente créé',
        'account.profile_updated' => 'Profil cliente modifié',
        'account.deleted' => 'Espace cliente supprimé',
        'booking.created_manually' => 'Rendez-vous saisi',
        'booking.status_changed' => 'Statut de rendez-vous changé',
        'booking.rescheduled' => 'Rendez-vous déplacé',
        'bookings.exported' => 'Export des rendez-vous',
        'customers.viewed' => 'Fiche cliente consultée',
        'customers.updated' => 'Fiche cliente modifiée',
        'customers.note_added' => 'Note ajoutée',
        'customers.note_deleted' => 'Note supprimée',
        'catalog.service_created' => 'Prestation créée',
        'catalog.service_updated' => 'Prestation modifiée',
        'catalog.service_deleted' => 'Prestation supprimée',
        'catalog.grid_updated' => 'Grille de prix modifiée',
        'catalog.variant_deleted' => 'Taille retirée',
        'catalog.category_created' => 'Catégorie créée',
        'catalog.category_updated' => 'Catégorie modifiée',
        'catalog.category_deleted' => 'Catégorie supprimée',
        'schedule.weekly_updated' => 'Semaine type modifiée',
        'schedule.closure_added' => 'Fermeture ajoutée',
        'content.page_created' => 'Page créée',
        'content.page_updated' => 'Réglages de page modifiés',
        'content.page_published' => 'Page publiée',
        'content.page_unpublished' => 'Page retirée du site',
        'content.page_discarded' => 'Modifications de page annulées',
        'content.page_deleted' => 'Page supprimée',
        'content.section_added' => 'Section ajoutée',
        'content.section_updated' => 'Section modifiée',
        'content.section_removed' => 'Section retirée',
        'appearance.published' => 'Apparence publiée',
        'appearance.template_activated' => 'Template activé',
        'blog.post_created' => 'Article créé',
        'blog.post_updated' => 'Article modifié',
        'blog.post_deleted' => 'Article supprimé',
        'gallery.created' => 'Réalisation ajoutée',
        'gallery.updated' => 'Réalisation modifiée',
        'gallery.deleted' => 'Réalisation supprimée',
        'reviews.approved' => 'Avis publié',
        'reviews.rejected' => 'Avis refusé',
        'reviews.created' => 'Avis saisi',
        'reviews.updated' => 'Avis modifié',
        'reviews.deleted' => 'Avis supprimé',
        'promotions.created' => 'Promotion créée',
        'promotions.updated' => 'Promotion modifiée',
        'promotions.deleted' => 'Promotion supprimée',
        'media.uploaded' => 'Média importé',
        'media.updated' => 'Média modifié',
        'media.deleted' => 'Média supprimé',
        'messages.deleted' => 'Message supprimé',
        'settings.updated' => 'Paramètres modifiés',
        'user.created_from_console' => 'Compte créé (console)',
    ];

    private const ENTITIES = [
        'booking' => 'Rendez-vous',
        'customer' => 'Cliente',
        'service' => 'Prestation',
        'service_category' => 'Catégorie',
        'page' => 'Page',
        'page_section' => 'Section',
        'blog_post' => 'Article',
        'gallery_item' => 'Réalisation',
        'review' => 'Avis',
        'promotion' => 'Promotion',
        'media' => 'Média',
        'user' => 'Compte',
        'template' => 'Template',
        'contact_message' => 'Message',
    ];

    public static function action(string $action): string
    {
        return self::ACTIONS[$action] ?? $action;
    }

    public static function entity(?string $type): string
    {
        return $type !== null ? (self::ENTITIES[$type] ?? $type) : '';
    }

    /** Lien d'administration vers l'élément concerné, s'il existe un écran pour lui. */
    public static function link(?string $type, ?int $id): ?string
    {
        if ($type === null || $id === null) {
            return null;
        }

        return match ($type) {
            'booking' => "/admin/reservations/{$id}",
            'customer' => "/admin/clientes/{$id}",
            'service' => "/admin/prestations/{$id}",
            'page' => "/admin/pages/{$id}",
            'blog_post' => "/admin/blog/{$id}",
            'gallery_item' => "/admin/galerie/{$id}",
            'review' => "/admin/avis/{$id}",
            'promotion' => "/admin/promotions/{$id}",
            'media' => "/admin/medias/{$id}",
            default => null,
        };
    }
}
