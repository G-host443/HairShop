<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Core\Database;
use App\Repositories\OptionRepository;
use App\Repositories\PricingRepository;

/**
 * Calcule le prix et la durée d'une prestation configurée, en vérifiant chaque choix côté serveur :
 * le prix affiché par le navigateur n'est jamais pris pour argent comptant.
 *
 * Durée = durée de la cellule taille × longueur, sinon de la variante, sinon de la prestation,
 * plus les minutes des options choisies.
 *
 * La promotion en cours la plus avantageuse (PromotionService) réduit le prix de la coiffure.
 */
final class PricingService
{
    public function __construct(
        private readonly Database $db,
        private readonly PricingRepository $pricing,
        private readonly OptionRepository $options,
        private readonly ?PromotionService $promotions = null,
    ) {
    }

    /**
     * @param list<int> $optionIds
     * @throws PricingException
     */
    public function quote(int $serviceId, ?int $variantId, ?int $lengthId, array $optionIds = []): Quote
    {
        $service = $this->db->fetchOne(
            'SELECT s.*, c.is_active AS category_active FROM services s JOIN service_categories c ON c.id = s.category_id WHERE s.id = :id',
            ['id' => $serviceId],
        );
        if ($service === null || !$service['is_active'] || !$service['category_active']) {
            throw new PricingException('Cette prestation n\'est pas disponible.');
        }

        $duration = (int) $service['duration_minutes'];

        if ($service['pricing_mode'] === 'matrix') {
            if ($variantId === null || $lengthId === null) {
                throw new PricingException('Choisissez une taille et une longueur.');
            }
            $variant = $this->pricing->findVariant($variantId);
            if ($variant === null || (int) $variant['service_id'] !== $serviceId || !$variant['is_active']) {
                throw new PricingException('Cette taille n\'est pas proposée pour cette prestation.');
            }
            $cell = $this->pricing->cell($variantId, $lengthId);
            if ($cell === null) {
                throw new PricingException('Cette longueur n\'est pas proposée pour cette taille.');
            }
            $lengthLabel = (string) $this->db->fetchValue('SELECT label FROM service_lengths WHERE id = :id', ['id' => $lengthId]);

            $label = sprintf('%s taille %s — %s', $service['name'], $variant['label'] ?: $variant['size_code'], $lengthLabel);
            $baseKind = $cell['kind'];
            $basePrice = $cell['price'];
            $duration = $cell['duration'] ?? ($variant['duration_minutes'] !== null ? (int) $variant['duration_minutes'] : $duration);
        } else {
            if ($variantId !== null || $lengthId !== null) {
                throw new PricingException('Cette prestation n\'a pas de taille ni de longueur à choisir.');
            }
            $label = (string) $service['name'];
            $baseKind = (string) $service['price_kind'];
            $basePrice = $service['price'] !== null ? (string) $service['price'] : null;
        }

        [$chosen, $extraMinutes] = $this->resolveOptions($serviceId, array_values(array_unique(array_map('intval', $optionIds))));

        $basePrice = $baseKind === 'quote' ? null : $basePrice;
        $promotion = $this->promotions?->bestFor($serviceId, (string) $service['pricing_mode'], $basePrice);

        return new Quote($serviceId, $variantId, $lengthId, $label, $baseKind, $basePrice, $chosen, $duration + $extraMinutes, $promotion);
    }

    /**
     * @param list<int> $optionIds
     * @return array{0: list<array{id: int, label: string, price: ?string, kind: string, minutes: int}>, 1: int}
     */
    private function resolveOptions(int $serviceId, array $optionIds): array
    {
        $groups = $this->options->applicableGroups($serviceId);
        $available = [];
        foreach ($groups as $group) {
            foreach ($group['options'] as $option) {
                $available[(int) $option['id']] = ['option' => $option, 'group' => $group];
            }
        }

        $chosen = [];
        $perGroup = [];
        $minutes = 0;
        foreach ($optionIds as $id) {
            if (!isset($available[$id])) {
                throw new PricingException('Une option choisie n\'est pas disponible pour cette prestation.');
            }
            $option = $available[$id]['option'];
            $group = $available[$id]['group'];
            $perGroup[(int) $group['id']] = ($perGroup[(int) $group['id']] ?? 0) + 1;

            if ($group['selection'] === 'single' && $perGroup[(int) $group['id']] > 1) {
                throw new PricingException("Une seule option possible pour « {$group['label']} ».");
            }

            $chosen[] = [
                'id' => $id,
                'label' => (string) $option['name'],
                'price' => $option['price_kind'] === 'quote' ? null : (string) $option['price'],
                'kind' => (string) $option['price_kind'],
                'minutes' => (int) $option['extra_minutes'],
            ];
            $minutes += (int) $option['extra_minutes'];
        }

        foreach ($groups as $group) {
            if ($group['is_required'] && !isset($perGroup[(int) $group['id']])) {
                throw new PricingException("Choisissez une option pour « {$group['label']} ».");
            }
        }

        return [$chosen, $minutes];
    }
}
