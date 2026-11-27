<?php

declare(strict_types=1);

namespace App\Services\Catalog;

/**
 * Devis d'une prestation configurée : prix, durée et libellé figés au moment du calcul.
 * total = null signifie « sur devis » (au moins un élément sans prix fixe).
 * Une promotion éventuelle réduit le prix de la coiffure (pas celui des options).
 */
final class Quote
{
    /**
     * @param list<array{id: int, label: string, price: ?string, kind: string, minutes: int}> $options
     * @param array{id: int, name: string, discount: string, price: string, badge: string, ends_at: string}|null $promotion
     */
    public function __construct(
        public readonly int $serviceId,
        public readonly ?int $variantId,
        public readonly ?int $lengthId,
        public readonly string $label,
        public readonly string $baseKind,
        public readonly ?string $basePrice,
        public readonly array $options,
        public readonly int $durationMinutes,
        public readonly ?array $promotion = null,
    ) {
    }

    public function isQuote(): bool
    {
        if ($this->baseKind === 'quote') {
            return true;
        }
        foreach ($this->options as $option) {
            if ($option['kind'] === 'quote') {
                return true;
            }
        }

        return false;
    }

    /** Au moins un prix « à partir de » : le total est un minimum. */
    public function isFromPrice(): bool
    {
        return $this->baseKind === 'from' || in_array('from', array_column($this->options, 'kind'), true);
    }

    /** Total en euros (chaîne décimale) ou null si sur devis. */
    public function total(): ?string
    {
        if ($this->isQuote()) {
            return null;
        }

        $total = (float) $this->basePrice - $this->discount();
        foreach ($this->options as $option) {
            $total += (float) $option['price'];
        }

        return number_format($total, 2, '.', '');
    }

    /** Remise de la promotion appliquée (0 sans promotion ou sur devis). */
    public function discount(): float
    {
        return $this->promotion !== null && !$this->isQuote() ? (float) $this->promotion['discount'] : 0.0;
    }

    public function promotionId(): ?int
    {
        return $this->discount() > 0 ? $this->promotion['id'] : null;
    }

    /** Libellé complet : « Knotless taille M — Mi-dos + Perles ». */
    public function fullLabel(): string
    {
        $parts = [$this->label];
        foreach ($this->options as $option) {
            $parts[] = $option['label'];
        }

        return implode(' + ', $parts);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'label' => $this->fullLabel(),
            'base_price' => $this->basePrice,
            'total' => $this->total(),
            'is_quote' => $this->isQuote(),
            'is_from' => $this->isFromPrice(),
            'duration_minutes' => $this->durationMinutes,
            'options' => $this->options,
            'promotion' => $this->discount() > 0 ? $this->promotion : null,
            'discount' => number_format($this->discount(), 2, '.', ''),
        ];
    }
}
