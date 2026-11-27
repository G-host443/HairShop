<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Services\Settings\SettingsService;

/**
 * Où mène le bouton « Réserver » :
 * - réservation en ligne active → parcours /reserver, prestation déjà sélectionnée ;
 * - sinon WhatsApp avec un message pré-rempli si un numéro est renseigné ;
 * - sinon le formulaire de contact pré-rempli.
 */
final class BookingLinkBuilder
{
    public function __construct(private readonly SettingsService $settings)
    {
    }

    /**
     * @return array{mode: string, whatsapp: ?string, contact_url: string, booking_url: string, label: string}
     */
    public function config(): array
    {
        $whatsapp = $this->settings->string('contact.whatsapp_number');
        $hasWhatsapp = preg_match('/^[1-9][0-9]{7,14}$/', $whatsapp) === 1;
        $online = $this->settings->bool('booking.online_enabled', true);

        return [
            'mode' => $online ? 'online' : ($hasWhatsapp ? 'whatsapp' : 'contact'),
            'whatsapp' => $hasWhatsapp ? $whatsapp : null,
            'contact_url' => '/contact',
            'booking_url' => '/reserver',
            'label' => $online ? 'Réserver cette coiffure' :($hasWhatsapp ? 'Réserver via WhatsApp' : 'Demander ce rendez-vous'),
        ];
    }

    /**
     * Lien de réservation d'une prestation (pages prestations, sans JavaScript).
     */
    public function urlFor(int $serviceId, string $choice, bool $needsConfig = true): string
    {
        $config = $this->config();
        if ($config['mode'] === 'online') {
            return '/reserver?' . http_build_query(['service' => $serviceId] + ($needsConfig ? [] : ['c' => '1']));
        }

        $message = 'Bonjour, je souhaite réserver : ' . $choice . '.';

        return $config['mode'] === 'whatsapp'
            ? 'https://wa.me/' . $config['whatsapp'] . '?text=' . rawurlencode($message)
            : $config['contact_url'] . '?message=' . rawurlencode($message) . '#contact';
    }
}
