<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Core\Seeding\Seeder;

/**
 * Paramètres par défaut du site. ON CONFLICT DO NOTHING : une valeur modifiée
 * dans l'administration n'est jamais écrasée par un nouveau db:seed.
 */
final class SiteSettingsSeeder extends Seeder
{
    /** clé => [groupe, valeur] */
    private const SETTINGS = [
        // Général
        'general.site_name' => ['general', 'Hair_style by Garance'],
        'general.tagline' => ['general', 'Sublimez votre beauté, révélez votre style.'],
        'general.description' => ['general', 'Tresses, braids et coiffures protectrices réalisées avec soin.'],
        'general.logo_media_id' => ['general', null],
        'general.favicon_media_id' => ['general', null],
        'general.currency' => ['general', 'EUR'],
        'general.timezone' => ['general', 'Europe/Paris'],
        'general.locale' => ['general', 'fr_FR'],

        // Contact et lieu
        'contact.email' => ['contact', ''],
        'contact.phone' => ['contact', ''],
        'contact.whatsapp_number' => ['contact', ''],          // format international sans « + », ex. 33612345678
        'contact.whatsapp_message' => ['contact', 'Bonjour, je souhaite prendre rendez-vous chez Hair_style by Garance.'],
        'contact.location_type' => ['contact', 'home'],         // home (domicile) | salon
        'contact.public_location' => ['contact', ''],           // ex. « À 5 min à pied de la gare de … »
        'contact.private_address' => ['contact', ''],           // envoyée uniquement à la confirmation si domicile
        'contact.invitation_text' => ['contact', 'Écrivez-moi avec la coiffure, la taille et la longueur souhaitées : je vous réponds avec mes disponibilités.'],

        // Réseaux sociaux
        'social.instagram' => ['social', ''],
        'social.facebook' => ['social', ''],
        'social.tiktok' => ['social', ''],

        // Réservation
        'booking.online_enabled' => ['booking', true],          // false → les boutons « Réserver » ouvrent WhatsApp
        'booking.auto_confirm' => ['booking', false],
        'booking.min_notice_hours' => ['booking', 12],
        'booking.horizon_days' => ['booking', 60],
        'booking.cancellation_hours' => ['booking', 48],
        'booking.buffer_minutes' => ['booking', 15],
        'booking.slot_interval_minutes' => ['booking', 30],
        'booking.require_rules_acceptance' => ['booking', true],

        // Notifications
        'notifications.sender_name' => ['notifications', 'Hair_style by Garance'],
        'notifications.sender_email' => ['notifications', ''],
        'notifications.admin_email' => ['notifications', ''],
        'notifications.reminder_hours' => ['notifications', 24],
        'notifications.review_request' => ['notifications', true],
        'seo.indexing' => ['seo', true],

        // SEO
        'seo.default_title' => ['seo', 'Hair_style by Garance — Tresses et coiffures protectrices'],
        'seo.default_description' => ['seo', 'Knotless, box braids, fulani, faux locs, nattes : choisissez votre coiffure, votre taille et votre longueur, puis réservez en ligne.'],
        'seo.og_media_id' => ['seo', null],

        // Sécurité
        'security.two_factor_enabled' => ['security', false],      // double authentification désactivée par défaut
        'security.max_login_attempts' => ['security', 5],
        'security.lockout_minutes' => ['security', 15],
        'security.password_min_length' => ['security', 12],
    ];

    public function run(): void
    {
        foreach (self::SETTINGS as $key => [$group, $value]) {
            $this->db->execute(
                'INSERT INTO site_settings (key, value, group_name) VALUES (:key, CAST(:value AS jsonb), :group)
                 ON CONFLICT (key) DO NOTHING',
                [
                    'key' => $key,
                    'value' => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    'group' => $group,
                ],
            );
        }
    }
}
