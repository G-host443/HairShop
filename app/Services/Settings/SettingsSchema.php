<?php

declare(strict_types=1);

namespace App\Services\Settings;

/**
 * Description des paramètres modifiables dans l'administration : groupe, libellé, type, aide, contraintes.
 *
 * Types : text, textarea, email, tel, whatsapp, url, bool, int, select, media, timezone.
 * Une clé absente de ce schéma n'est pas modifiable depuis l'interface.
 */
final class SettingsSchema
{
    public const GROUPS = [
        'general' => ['label' => 'Général', 'icon' => 'shop'],
        'contact' => ['label' => 'Contact et lieu', 'icon' => 'geo-alt'],
        'social' => ['label' => 'Réseaux sociaux', 'icon' => 'instagram'],
        'booking' => ['label' => 'Réservation', 'icon' => 'calendar-check'],
        'notifications' => ['label' => 'Notifications', 'icon' => 'bell'],
        'seo' => ['label' => 'Référencement', 'icon' => 'search'],
        'security' => ['label' => 'Sécurité', 'icon' => 'shield-lock'],
    ];

    /**
     * @return array<string, array{group: string, label: string, type: string, help?: string, required?: bool,
     *                              max?: int, min?: int, options?: array<string, string>, placeholder?: string, suffix?: string}>
     */
    public static function fields(): array
    {
        return [
            // --- Général ---
            'general.site_name' => ['group' => 'general', 'label' => 'Nom du salon', 'type' => 'text', 'required' => true, 'max' => 80],
            'general.tagline' => ['group' => 'general', 'label' => 'Slogan', 'type' => 'text', 'max' => 160],
            'general.description' => ['group' => 'general', 'label' => 'Présentation courte', 'type' => 'textarea', 'max' => 500,
                'help' => 'Une ou deux phrases qui décrivent votre activité.'],
            'general.logo_media_id' => ['group' => 'general', 'label' => 'Logo', 'type' => 'media',
                'help' => 'Format conseillé : PNG ou WebP à fond transparent.'],
            'general.favicon_media_id' => ['group' => 'general', 'label' => 'Icône du site (favicon)', 'type' => 'media',
                'help' => 'Petite image carrée affichée dans l\'onglet du navigateur (au moins 192 × 192 px).'],
            'general.currency' => ['group' => 'general', 'label' => 'Devise', 'type' => 'select', 'required' => true,
                'options' => ['EUR' => 'Euro (€)', 'CHF' => 'Franc suisse (CHF)', 'XOF' => 'Franc CFA (XOF)', 'CAD' => 'Dollar canadien (CAD)']],
            'general.timezone' => ['group' => 'general', 'label' => 'Fuseau horaire', 'type' => 'timezone', 'required' => true],
            'general.locale' => ['group' => 'general', 'label' => 'Langue', 'type' => 'select', 'required' => true,
                'options' => ['fr_FR' => 'Français']],

            // --- Contact et lieu ---
            'contact.location_type' => ['group' => 'contact', 'label' => 'Lieu des prestations', 'type' => 'select', 'required' => true,
                'options' => ['home' => 'À mon domicile', 'salon' => 'En salon']],
            'contact.public_location' => ['group' => 'contact', 'label' => 'Localisation affichée sur le site', 'type' => 'text', 'max' => 160,
                'placeholder' => 'À 5 min à pied de la gare de…',
                'help' => 'Zone approximative visible par tous.'],
            'contact.private_address' => ['group' => 'contact', 'label' => 'Adresse exacte', 'type' => 'textarea', 'max' => 300,
                'help' => 'À domicile, elle n\'est jamais affichée sur le site : elle est envoyée uniquement dans la confirmation de rendez-vous. En salon, elle est publique.'],
            'contact.phone' => ['group' => 'contact', 'label' => 'Téléphone', 'type' => 'tel', 'placeholder' => '06 12 34 56 78'],
            'contact.whatsapp_number' => ['group' => 'contact', 'label' => 'Numéro WhatsApp', 'type' => 'whatsapp', 'placeholder' => '33612345678',
                'help' => 'Format international sans « + » ni espaces : 33 pour la France, puis le numéro sans le 0.'],
            'contact.whatsapp_message' => ['group' => 'contact', 'label' => 'Message WhatsApp pré-rempli', 'type' => 'textarea', 'max' => 300],
            'contact.email' => ['group' => 'contact', 'label' => 'E-mail de contact', 'type' => 'email'],
            'contact.invitation_text' => ['group' => 'contact', 'label' => 'Phrase d\'invitation (section Contact)', 'type' => 'textarea', 'max' => 300],

            // --- Réseaux sociaux ---
            'social.instagram' => ['group' => 'social', 'label' => 'Instagram', 'type' => 'url', 'placeholder' => 'https://www.instagram.com/…'],
            'social.facebook' => ['group' => 'social', 'label' => 'Facebook', 'type' => 'url', 'placeholder' => 'https://www.facebook.com/…'],
            'social.tiktok' => ['group' => 'social', 'label' => 'TikTok', 'type' => 'url', 'placeholder' => 'https://www.tiktok.com/@…'],

            // --- Réservation ---
            'booking.online_enabled' => ['group' => 'booking', 'label' => 'Réservation en ligne active', 'type' => 'bool',
                'help' => 'Désactivée, chaque bouton « Réserver » ouvre WhatsApp avec la prestation choisie.'],
            'booking.auto_confirm' => ['group' => 'booking', 'label' => 'Confirmer automatiquement les réservations', 'type' => 'bool',
                'help' => 'Sinon, chaque demande reste « En attente » jusqu\'à votre validation. Les prestations sur devis restent toujours en attente.'],
            'booking.require_rules_acceptance' => ['group' => 'booking', 'label' => 'Faire accepter les règles avant de réserver', 'type' => 'bool'],
            'booking.min_notice_hours' => ['group' => 'booking', 'label' => 'Délai minimum avant un rendez-vous', 'type' => 'int', 'min' => 0, 'max' => 720, 'suffix' => 'heures'],
            'booking.horizon_days' => ['group' => 'booking', 'label' => 'Réservation possible jusqu\'à', 'type' => 'int', 'min' => 1, 'max' => 365, 'suffix' => 'jours à l\'avance'],
            'booking.cancellation_hours' => ['group' => 'booking', 'label' => 'Annulation en ligne jusqu\'à', 'type' => 'int', 'min' => 0, 'max' => 720, 'suffix' => 'heures avant'],
            'booking.buffer_minutes' => ['group' => 'booking', 'label' => 'Temps de battement entre deux rendez-vous', 'type' => 'int', 'min' => 0, 'max' => 240, 'suffix' => 'minutes'],
            'booking.slot_interval_minutes' => ['group' => 'booking', 'label' => 'Intervalle entre les créneaux proposés', 'type' => 'select', 'required' => true,
                'options' => ['15' => '15 minutes', '30' => '30 minutes', '60' => '1 heure']],

            // --- Notifications ---
            'notifications.sender_name' => ['group' => 'notifications', 'label' => 'Nom de l\'expéditeur', 'type' => 'text', 'max' => 80],
            'notifications.sender_email' => ['group' => 'notifications', 'label' => 'Adresse d\'expédition', 'type' => 'email',
                'help' => 'Adresse utilisée pour envoyer les confirmations et rappels.'],
            'notifications.admin_email' => ['group' => 'notifications', 'label' => 'Recevoir les alertes à', 'type' => 'email',
                'help' => 'Nouvelle réservation, nouveau message, nouvel avis.'],
            'notifications.reminder_hours' => ['group' => 'notifications', 'label' => 'Rappel envoyé', 'type' => 'int', 'min' => 1, 'max' => 168, 'suffix' => 'heures avant le rendez-vous'],
            'notifications.review_request' => ['group' => 'notifications', 'label' => 'Demander un avis après le rendez-vous', 'type' => 'bool',
                'help' => 'Un e-mail invite la cliente à laisser un avis quand le rendez-vous passe en « Terminée ».'],

            // --- Référencement ---
            'seo.indexing' => ['group' => 'seo', 'label' => 'Autoriser les moteurs de recherche', 'type' => 'bool',
                'help' => 'Décochez pendant la préparation du site : Google et les autres moteurs ne l\'indexeront pas.'],
            'seo.default_title' => ['group' => 'seo', 'label' => 'Titre par défaut', 'type' => 'text', 'max' => 70,
                'help' => 'Affiché dans Google et l\'onglet du navigateur. 60 caractères environ.'],
            'seo.default_description' => ['group' => 'seo', 'label' => 'Description par défaut', 'type' => 'textarea', 'max' => 200,
                'help' => 'Texte affiché sous le titre dans Google. 155 caractères environ.'],
            'seo.og_media_id' => ['group' => 'seo', 'label' => 'Image de partage', 'type' => 'media',
                'help' => 'Image affichée quand un lien du site est partagé (WhatsApp, Facebook…). Idéalement 1200 × 630 px.'],

            // --- Sécurité ---
            'security.two_factor_enabled' => ['group' => 'security', 'label' => 'Double authentification (comptes d\'administration)', 'type' => 'bool',
                'help' => 'Désactivée par défaut. Une fois cochée, chaque compte peut l\'activer depuis « Double authentification » : un code de téléphone est alors demandé à chaque connexion.'],
            'security.max_login_attempts' => ['group' => 'security', 'label' => 'Tentatives de connexion avant blocage', 'type' => 'int', 'min' => 3, 'max' => 20],
            'security.lockout_minutes' => ['group' => 'security', 'label' => 'Durée du blocage', 'type' => 'int', 'min' => 1, 'max' => 1440, 'suffix' => 'minutes'],
            'security.password_min_length' => ['group' => 'security', 'label' => 'Longueur minimale des mots de passe', 'type' => 'int', 'min' => 10, 'max' => 64, 'suffix' => 'caractères'],
        ];
    }

    /** @return array<string, array<string, mixed>> champs d'un groupe, dans l'ordre */
    public static function group(string $group): array
    {
        return array_filter(self::fields(), static fn (array $f): bool => $f['group'] === $group);
    }

    /**
     * Valide et convertit les valeurs saisies pour un groupe.
     *
     * @param array<string, mixed> $input  clés avec « . » remplacés par « __ » (noms de champs HTML)
     * @param callable(int): bool  $mediaIsImage  vérifie qu'un id correspond à une image existante
     * @return array{values: array<string, mixed>, errors: array<string, list<string>>}
     */
    public static function validateGroup(string $group, array $input, callable $mediaIsImage): array
    {
        $values = [];
        $errors = [];

        foreach (self::group($group) as $key => $field) {
            $name = self::inputName($key);
            $raw = $input[$name] ?? null;
            $raw = is_string($raw) ? trim($raw) : $raw;
            $label = '« ' . $field['label'] . ' »';

            if ($field['type'] === 'bool') {
                $values[$key] = in_array($raw, ['1', 'on', 'true', true, 1], true);
                continue;
            }

            if ($raw === null || $raw === '') {
                if ($field['required'] ?? false) {
                    $errors[$name][] = "Le champ {$label} est obligatoire.";
                } else {
                    $values[$key] = in_array($field['type'], ['media', 'int'], true) ? null : '';
                }
                continue;
            }

            if (!is_string($raw)) {
                $errors[$name][] = "Le champ {$label} est invalide.";
                continue;
            }

            $error = null;
            $value = $raw;

            switch ($field['type']) {
                case 'text':
                case 'textarea':
                    $max = $field['max'] ?? ($field['type'] === 'text' ? 255 : 2000);
                    if (mb_strlen($raw) > $max) {
                        $error = "Le champ {$label} ne doit pas dépasser {$max} caractères.";
                    }
                    break;
                case 'email':
                    if (filter_var($raw, FILTER_VALIDATE_EMAIL) === false) {
                        $error = "Le champ {$label} doit être une adresse e-mail valide.";
                    }
                    break;
                case 'tel':
                    if (!preg_match('/^\+?[0-9 .()-]{6,20}$/', $raw)) {
                        $error = "Le champ {$label} doit être un numéro de téléphone valide.";
                    }
                    break;
                case 'whatsapp':
                    $value = preg_replace('/[\s.+-]/', '', $raw) ?? '';
                    if (!preg_match('/^[1-9][0-9]{7,14}$/', $value)) {
                        $error = "Le champ {$label} doit être au format international, sans « + » ni 0 initial (ex. 33612345678).";
                    }
                    break;
                case 'url':
                    $scheme = strtolower((string) parse_url($raw, PHP_URL_SCHEME));
                    if (filter_var($raw, FILTER_VALIDATE_URL) === false || !in_array($scheme, ['http', 'https'], true)) {
                        $error = "Le champ {$label} doit être une adresse web complète commençant par https://.";
                    }
                    break;
                case 'int':
                    if (filter_var($raw, FILTER_VALIDATE_INT) === false) {
                        $error = "Le champ {$label} doit être un nombre entier.";
                        break;
                    }
                    $value = (int) $raw;
                    if (isset($field['min']) && $value < $field['min'] || isset($field['max']) && $value > $field['max']) {
                        $error = "Le champ {$label} doit être compris entre {$field['min']} et {$field['max']}.";
                    }
                    break;
                case 'select':
                    if (!array_key_exists($raw, $field['options'])) {
                        $error = "La valeur du champ {$label} est invalide.";
                    }
                    $value = ctype_digit($raw) ? (int) $raw : $raw;
                    break;
                case 'timezone':
                    if (!in_array($raw, timezone_identifiers_list(), true)) {
                        $error = "Le champ {$label} doit être un fuseau horaire valide.";
                    }
                    break;
                case 'media':
                    if (!ctype_digit($raw) || !$mediaIsImage((int) $raw)) {
                        $error = "Le champ {$label} doit être une image de la bibliothèque.";
                    }
                    $value = (int) $raw;
                    break;
            }

            if ($error !== null) {
                $errors[$name][] = $error;
            } else {
                $values[$key] = $value;
            }
        }

        return ['values' => $values, 'errors' => $errors];
    }

    /** « general.site_name » → « general__site_name » (les points sont transformés par PHP dans $_POST). */
    public static function inputName(string $key): string
    {
        return str_replace('.', '__', $key);
    }
}
