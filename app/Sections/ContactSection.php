<?php

declare(strict_types=1);

namespace App\Sections;

use App\Services\Schedule\OpeningHoursService;
use App\Services\Settings\SettingsService;

/**
 * Prise de contact : boutons WhatsApp / SMS / réseaux sociaux (variante « buttons »)
 * et formulaire de contact (option show_form).
 */
final class ContactSection implements SectionType
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly OpeningHoursService $hours,
    ) {
    }

    public function type(): string
    {
        return 'contact';
    }

    public function label(): string
    {
        return 'Contact';
    }

    public function fields(): array
    {
        return [
            'anchor' => ['label' => 'Ancre (lien du menu)', 'type' => 'anchor'],
            'title' => ['label' => 'Titre', 'type' => 'text', 'max' => 80],
            'text' => ['label' => 'Texte (sinon, la phrase d\'invitation des paramètres)', 'type' => 'textarea', 'max' => 400],
            'show_form' => ['label' => 'Afficher le formulaire de contact', 'type' => 'bool'],
            'show_hours' => ['label' => 'Afficher les horaires', 'type' => 'bool'],
        ];
    }

    public function prepare(array $content): ?array
    {
        $channels = $this->channels();
        $showForm = Content::bool($content, 'show_form');

        if ($channels === [] && !$showForm) {
            return null;
        }

        $text = Content::text($content, 'text');
        if ($text === '' && Content::bool($content, 'text_from_settings', true)) {
            $text = $this->settings->string('contact.invitation_text');
        }

        return [
            'title' => Content::text($content, 'title', 'Contact'),
            'text' => $text,
            'channels' => $channels,
            'show_form' => $showForm,
            'hours' => Content::bool($content, 'show_hours') ? $this->hours->summary() : [],
        ];
    }

    /**
     * Canaux renseignés dans les paramètres, dans l'ordre d'affichage (WhatsApp en premier).
     *
     * @return list<array{key: string, label: string, url: string, icon: string, primary: bool, external: bool}>
     */
    public function channels(): array
    {
        $channels = [];

        $whatsapp = $this->settings->string('contact.whatsapp_number');
        if (preg_match('/^[1-9][0-9]{7,14}$/', $whatsapp)) {
            $message = $this->settings->string('contact.whatsapp_message');
            $channels[] = [
                'key' => 'whatsapp', 'label' => 'WhatsApp', 'icon' => 'whatsapp', 'primary' => true, 'external' => true,
                'url' => 'https://wa.me/' . $whatsapp . ($message !== '' ? '?text=' . rawurlencode($message) : ''),
            ];
        }

        $phone = preg_replace('/[^0-9+]/', '', $this->settings->string('contact.phone')) ?? '';
        if (strlen($phone) >= 6) {
            $channels[] = ['key' => 'sms', 'label' => 'SMS', 'icon' => 'chat-dots', 'primary' => false, 'external' => false, 'url' => 'sms:' . $phone];
            $channels[] = ['key' => 'phone', 'label' => 'Appeler', 'icon' => 'telephone', 'primary' => false, 'external' => false, 'url' => 'tel:' . $phone];
        }

        $email = $this->settings->string('contact.email');
        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $channels[] = ['key' => 'email', 'label' => 'E-mail', 'icon' => 'envelope', 'primary' => false, 'external' => false, 'url' => 'mailto:' . $email];
        }

        foreach (['instagram' => 'Instagram', 'facebook' => 'Facebook', 'tiktok' => 'TikTok'] as $key => $label) {
            $url = $this->settings->string('social.' . $key);
            if (preg_match('~^https?://~i', $url)) {
                $channels[] = ['key' => $key, 'label' => $label, 'icon' => $key, 'primary' => false, 'external' => true, 'url' => $url];
            }
        }

        // Le premier canal est mis en avant si WhatsApp n'est pas renseigné.
        if ($channels !== [] && !array_filter($channels, static fn (array $c): bool => $c['primary'])) {
            $channels[0]['primary'] = true;
        }

        return $channels;
    }
}
