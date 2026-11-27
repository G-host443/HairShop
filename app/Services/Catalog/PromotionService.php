<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Core\Clock;
use App\Repositories\PromotionRepository;

/**
 * Promotions en cours et calcul des remises.
 *
 * Règles :
 * - une promotion est en cours si elle est active et que la date de réservation (maintenant)
 *   est comprise dans sa période : le prix affiché est celui qui est appliqué ;
 * - elle s'applique au prix de la coiffure, pas aux options ;
 * - pourcentage → prix × taux ; montant → déduit (sans descendre sous 0) ;
 *   prix promotionnel → uniquement pour une prestation à prix unique (pas de grille), s'il est inférieur ;
 * - plusieurs promotions sur la même prestation : la plus avantageuse pour la cliente l'emporte ;
 * - un prix « sur devis » n'a pas de remise.
 */
final class PromotionService
{
    public const TYPES = ['percent' => 'Pourcentage de réduction', 'amount' => 'Montant déduit', 'fixed_price' => 'Prix promotionnel'];

    /** @var array<int, list<array<string, mixed>>>|null promotions en cours par prestation */
    private ?array $active = null;

    public function __construct(
        private readonly PromotionRepository $promotions,
        private readonly Clock $clock,
    ) {
    }

    /** @return array<int, list<array<string, mixed>>> */
    public function activeByService(): array
    {
        if ($this->active === null) {
            $this->active = [];
            foreach ($this->promotions->activeAt($this->clock->now()->format(DATE_ATOM)) as $row) {
                $this->active[(int) $row['service_id']][] = $row;
            }
        }

        return $this->active;
    }

    /**
     * Meilleure remise applicable à un prix de base.
     *
     * @return array{id: int, name: string, discount: string, price: string, badge: string, ends_at: string, last_day: string}|null
     */
    public function bestFor(int $serviceId, string $pricingMode, ?string $basePrice): ?array
    {
        if ($basePrice === null || (float) $basePrice <= 0) {
            return null;
        }

        $best = null;
        foreach ($this->activeByService()[$serviceId] ?? [] as $promotion) {
            $discount = self::discount((string) $promotion['discount_type'], (float) $promotion['value'], (float) $basePrice, $pricingMode);
            if ($discount > 0 && ($best === null || $discount > $best['amount'])) {
                $best = ['amount' => $discount, 'promotion' => $promotion];
            }
        }

        if ($best === null) {
            return null;
        }

        return [
            'id' => (int) $best['promotion']['id'],
            'name' => (string) $best['promotion']['name'],
            'discount' => number_format($best['amount'], 2, '.', ''),
            'price' => number_format((float) $basePrice - $best['amount'], 2, '.', ''),
            'badge' => self::badge($best['promotion']),
            'ends_at' => (string) $best['promotion']['ends_at'],
            // La fin est exclusive (lendemain 0 h) : dernier jour inclus de l'offre.
            'last_day' => (new \DateTimeImmutable((string) $best['promotion']['ends_at']))->setTimezone($this->clock->zone())->modify('-1 second')->format('Y-m-d'),
        ];
    }

    /** Remise en euros (arrondie au centime). */
    public static function discount(string $type, float $value, float $base, string $pricingMode): float
    {
        $amount = match ($type) {
            'percent' => round($base * min($value, 100) / 100, 2),
            'amount' => min($value, $base),
            'fixed_price' => $pricingMode === 'simple' && $value < $base ? $base - $value : 0.0,
            default => 0.0,
        };

        return round(max(0.0, $amount), 2);
    }

    /** Texte court affiché sur la prestation : « −20 % », « −10 € », « 40 € au lieu du prix habituel ». */
    public static function badge(array $promotion): string
    {
        $value = (float) $promotion['value'];

        return match ((string) $promotion['discount_type']) {
            'percent' => '−' . rtrim(rtrim(number_format($value, 2, ',', ''), '0'), ',') . ' %',
            'amount' => '−' . CatalogReader::euros($value),
            default => 'Prix spécial ' . CatalogReader::euros($value),
        };
    }
}
