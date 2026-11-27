<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Controller;
use App\Core\Database;
use App\Core\Exceptions\HttpException;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Router;
use App\Core\Session\Session;
use App\Core\Validation\ValidationException;
use App\Core\View;
use App\Helpers\Slug;
use App\Models\User;
use App\Repositories\MediaRepository;
use App\Repositories\PricingRepository;
use App\Repositories\ServiceRepository;
use App\Services\Audit\AuditLogger;

/**
 * Grille de prix d'une prestation : une ligne par variante de taille, une colonne par longueur.
 * Chaque cellule : vide (longueur non proposée), un prix, ou « devis ».
 */
final class ServicePricingController extends Controller
{
    public function __construct(
        View $view,
        Router $router,
        Session $session,
        private readonly ServiceRepository $services,
        private readonly PricingRepository $pricing,
        private readonly MediaRepository $media,
        private readonly AuditLogger $audit,
        private readonly Database $db,
    ) {
        parent::__construct($view, $router, $session);
    }

    public function edit(int $id): Response
    {
        $service = $this->matrixService($id);
        $variants = $this->pricing->variants($id);

        return $this->render('admin/catalog/service-grid.twig', [
            'service' => $service,
            'variants' => $variants,
            'variant_images' => $this->media->findMany(array_filter(array_column($variants, 'image_media_id'))),
            'lengths' => $this->pricing->lengths(),
            'sizes' => $this->pricing->sizes(),
            'grid' => $this->pricing->grid($id),
            'tab' => 'services',
        ]);
    }

    public function update(Request $request, int $id): Response
    {
        $this->matrixService($id);
        $variants = $this->pricing->variants($id);
        $lengthIds = array_column($this->pricing->lengths(), 'id');
        $prices = (array) $request->input('prices', []);
        $durations = (array) $request->input('durations', []);
        $variantInput = (array) $request->input('variants', []);

        $errors = [];
        $cells = [];
        $variantData = [];

        foreach ($variants as $variant) {
            $vid = (int) $variant['id'];
            $cells[$vid] = [];

            foreach ($lengthIds as $lid) {
                $raw = trim((string) ($prices[$vid][$lid] ?? ''));
                if ($raw === '') {
                    continue; // longueur non proposée pour cette taille
                }
                $kind = in_array(mb_strtolower($raw), ['devis', 'sur devis', 'd'], true) ? 'quote' : 'fixed';
                $price = $kind === 'fixed' ? Slug::parsePrice($raw) : null;
                if ($kind === 'fixed' && $price === null) {
                    $errors["prices.{$vid}.{$lid}"] = "Prix invalide « {$raw} » (taille {$variant['size_code']}) : saisissez un montant ou « devis ».";
                    continue;
                }

                $rawDuration = trim((string) ($durations[$vid][$lid] ?? ''));
                $duration = null;
                if ($rawDuration !== '') {
                    $duration = filter_var($rawDuration, FILTER_VALIDATE_INT, ['options' => ['min_range' => 15, 'max_range' => 1440]]);
                    if ($duration === false) {
                        $errors["durations.{$vid}.{$lid}"] = "Durée invalide « {$rawDuration} » (taille {$variant['size_code']}) : entre 15 et 1440 minutes.";
                        continue;
                    }
                }

                $cells[$vid][$lid] = ['kind' => $kind, 'price' => $price, 'duration' => $duration];
            }

            $v = (array) ($variantInput[$vid] ?? []);
            $variantDuration = trim((string) ($v['duration_minutes'] ?? ''));
            $imageId = filter_var($v['image_media_id'] ?? '', FILTER_VALIDATE_INT) ?: null;
            if ($imageId !== null && !$this->media->find($imageId)?->isImage()) {
                $imageId = null;
            }
            $durationValue = null;
            if ($variantDuration !== '') {
                $durationValue = filter_var($variantDuration, FILTER_VALIDATE_INT, ['options' => ['min_range' => 15, 'max_range' => 1440]]);
                if ($durationValue === false) {
                    $errors["variants.{$vid}.duration_minutes"] = "Durée de la taille {$variant['size_code']} invalide (entre 15 et 1440 minutes).";
                    $durationValue = null;
                }
            }

            $variantData[$vid] = [
                'label' => self::nullable($v['label'] ?? null, 60),
                'note' => self::nullable($v['note'] ?? null, 200),
                'image_media_id' => $imageId,
                'duration_minutes' => $durationValue,
                'is_active' => ($v['is_active'] ?? '0') === '1',
            ];
        }

        // Deux variantes d'une même taille doivent garder des libellés différents (contrainte d'unicité).
        $seen = [];
        foreach ($variants as $variant) {
            $key = $variant['size_id'] . '|' . mb_strtolower((string) ($variantData[(int) $variant['id']]['label'] ?? ''));
            if (isset($seen[$key])) {
                $errors["variants.{$variant['id']}.label"] = "Deux variantes de la taille {$variant['size_code']} ont le même libellé : donnez-leur des libellés différents.";
            }
            $seen[$key] = true;
        }

        if ($errors !== []) {
            throw new ValidationException(array_map(static fn (string $m): array => [$m], $errors));
        }

        $this->db->transaction(function () use ($cells, $variantData): void {
            foreach ($variantData as $vid => $data) {
                $this->pricing->updateVariant($vid, $data);
            }
            $this->pricing->replaceCells($cells);
        });

        $this->audit->log('catalog.grid_updated', $this->userId($request), $request, 'service', $id);
        $this->flash('success', 'Grille de prix enregistrée.');

        return $this->redirectTo('admin.services.grid', ['id' => $id]);
    }

    public function addVariant(Request $request, int $id): Response
    {
        $this->matrixService($id);
        $sizeId = filter_var($request->input('size_id'), FILTER_VALIDATE_INT) ?: 0;
        $label = self::nullable($request->input('label'), 60);

        if (!in_array($sizeId, array_column($this->pricing->sizes(), 'id'), true)) {
            throw ValidationException::withMessages(['size_id' => 'Choisissez une taille.']);
        }
        if ($this->pricing->variantExists($id, $sizeId, $label)) {
            throw ValidationException::withMessages(['size_id' => 'Cette taille existe déjà pour la prestation. Ajoutez un libellé pour créer une variante distincte (ex. « S – box braids »).']);
        }

        $this->pricing->createVariant($id, $sizeId, $label);
        $this->flash('success', 'Taille ajoutée : renseignez ses prix dans la grille.');

        return $this->redirectTo('admin.services.grid', ['id' => $id]);
    }

    public function removeVariant(Request $request, int $id, int $variantId): Response
    {
        $this->matrixService($id);
        $variant = $this->pricing->findVariant($variantId);
        if ($variant === null || (int) $variant['service_id'] !== $id) {
            throw HttpException::notFound();
        }

        $this->pricing->deleteVariant($variantId);
        $this->audit->log('catalog.variant_deleted', $this->userId($request), $request, 'service', $id, ['size' => $variant['size_code']]);
        $this->flash('success', "Taille {$variant['size_code']} retirée.");

        return $this->redirectTo('admin.services.grid', ['id' => $id]);
    }

    /** @return array<string, mixed> */
    private function matrixService(int $id): array
    {
        $service = $this->services->find($id) ?? throw HttpException::notFound();
        if ($service['pricing_mode'] !== 'matrix') {
            throw HttpException::notFound('Cette prestation a un prix unique : modifiez-le dans sa fiche.');
        }

        return $service;
    }

    private static function nullable(mixed $value, int $max): ?string
    {
        $value = is_string($value) ? trim($value) : '';

        return $value !== '' ? mb_substr($value, 0, $max) : null;
    }

    private function userId(Request $request): ?int
    {
        $user = $request->attribute('user');

        return $user instanceof User ? $user->id : null;
    }
}
