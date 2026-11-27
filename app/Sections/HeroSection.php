<?php

declare(strict_types=1);

namespace App\Sections;

use App\Repositories\MediaRepository;
use App\Services\Settings\SettingsService;

final class HeroSection implements SectionType
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly MediaRepository $media,
    ) {
    }

    public function type(): string
    {
        return 'hero';
    }

    public function label(): string
    {
        return 'Accueil (bannière principale)';
    }

    public function fields(): array
    {
        return [
            'eyebrow' => ['label' => 'Surtitre', 'type' => 'text', 'max' => 80],
            'title' => ['label' => 'Titre', 'type' => 'text', 'max' => 120],
            'text' => ['label' => 'Texte', 'type' => 'textarea', 'max' => 600],
            'image_media_id' => ['label' => 'Image (sinon, le logo)', 'type' => 'media'],
            'location_from_settings' => ['label' => 'Afficher la localisation', 'type' => 'bool', 'default' => true],
            'primary_cta' => ['label' => 'Bouton principal', 'type' => 'link'],
            'secondary_cta' => ['label' => 'Bouton secondaire', 'type' => 'link'],
        ];
    }

    public function prepare(array $content): ?array
    {
        $title = Content::text($content, 'title');
        if ($title === '') {
            return null;
        }

        $imageId = $content['image_media_id'] ?? $this->settings->get('general.logo_media_id');
        $image = is_numeric($imageId) ? $this->media->find((int) $imageId) : null;

        return [
            'eyebrow' => Content::text($content, 'eyebrow'),
            'title' => $title,
            'text' => Content::text($content, 'text'),
            'location' => Content::bool($content, 'location_from_settings') ? $this->settings->string('contact.public_location') : '',
            'image' => $image !== null && $image->isImage() ? $image : null,
            'image_is_logo' => !isset($content['image_media_id']),
            'primary_cta' => Content::link($content, 'primary_cta'),
            'secondary_cta' => Content::link($content, 'secondary_cta'),
        ];
    }
}
