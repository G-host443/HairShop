<?php

declare(strict_types=1);

namespace App\Sections;

use Psr\Container\ContainerInterface;

/**
 * Liste des types de section disponibles. Pour en ajouter un : créer la classe, l'inscrire ici,
 * puis fournir un gabarit resources/views/sections/<type>/default.twig (et des variantes par template).
 */
final class SectionRegistry
{
    /** @var list<class-string<SectionType>> */
    private const TYPES = [
        HeroSection::class,
        PricingGridSection::class,
        GallerySection::class,
        PracticalInfoSection::class,
        TestimonialsSection::class,
        PromotionsSection::class,
        BlogLatestSection::class,
        ContactSection::class,
        CtaSection::class,
        RichTextSection::class,
    ];

    /** @var array<string, SectionType>|null */
    private ?array $types = null;

    public function __construct(private readonly ContainerInterface $container)
    {
    }

    public function get(string $type): ?SectionType
    {
        return $this->all()[$type] ?? null;
    }

    /** @return array<string, SectionType> */
    public function all(): array
    {
        if ($this->types === null) {
            $this->types = [];
            foreach (self::TYPES as $class) {
                $instance = $this->container->get($class);
                $this->types[$instance->type()] = $instance;
            }
        }

        return $this->types;
    }
}
