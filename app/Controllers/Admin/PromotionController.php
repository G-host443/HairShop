<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Controller;
use App\Core\Clock;
use App\Core\Exceptions\HttpException;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Router;
use App\Core\Session\Session;
use App\Core\Validation\ValidationException;
use App\Core\View;
use App\Helpers\Slug;
use App\Models\User;
use App\Repositories\PromotionRepository;
use App\Repositories\ServiceRepository;
use App\Services\Audit\AuditLogger;
use App\Services\Catalog\PromotionService;
use DateTimeImmutable;

/**
 * Promotions : réduction en pourcentage, montant déduit ou prix promotionnel, sur une période,
 * pour une ou plusieurs prestations. Les dates sont saisies au jour près (fin incluse).
 */
final class PromotionController extends Controller
{
    public const STATUS_LABELS = [
        'active' => ['En cours', 'success'],
        'scheduled' => ['À venir', 'info'],
        'expired' => ['Terminée', 'secondary'],
        'disabled' => ['Désactivée', 'secondary'],
    ];

    public function __construct(
        View $view,
        Router $router,
        Session $session,
        private readonly PromotionRepository $promotions,
        private readonly ServiceRepository $services,
        private readonly Clock $clock,
        private readonly AuditLogger $audit,
    ) {
        parent::__construct($view, $router, $session);
    }

    public function index(): Response
    {
        $rows = array_map(fn (array $p): array => $p + [
            'status' => $this->status($p),
            'first_day' => $this->day((string) $p['starts_at']),
            'last_day' => $this->day((string) $p['ends_at'], true),
            'badge' => PromotionService::badge($p),
        ], $this->promotions->all());

        return $this->render('admin/promotions/index.twig', [
            'promotions' => $rows,
            'status_labels' => self::STATUS_LABELS,
        ]);
    }

    public function create(): Response
    {
        $today = $this->clock->now()->format('Y-m-d');

        return $this->form(null, [
            'name' => '', 'description' => '', 'discount_type' => 'percent', 'value' => '',
            'first_day' => $today, 'last_day' => $this->clock->now()->modify('+30 days')->format('Y-m-d'), 'is_active' => true,
        ], []);
    }

    public function store(Request $request): Response
    {
        [$data, $serviceIds] = $this->validated($request);
        $id = $this->promotions->create($data);
        $this->promotions->setServices($id, $serviceIds);
        $this->audit->log('promotions.created', self::userId($request), $request, 'promotion', $id, ['name' => $data['name']]);
        $this->flash('success', "Promotion « {$data['name']} » enregistrée.");

        return $this->redirectTo('admin.promotions');
    }

    public function edit(int $id): Response
    {
        $promotion = $this->promotions->find($id) ?? throw HttpException::notFound();

        return $this->form($promotion, [
            'name' => $promotion['name'],
            'description' => (string) $promotion['description'],
            'discount_type' => $promotion['discount_type'],
            'value' => str_replace('.', ',', rtrim(rtrim((string) $promotion['value'], '0'), '.')),
            'first_day' => $this->day((string) $promotion['starts_at']),
            'last_day' => $this->day((string) $promotion['ends_at'], true),
            'is_active' => (bool) $promotion['is_active'],
        ], $this->promotions->serviceIds($id));
    }

    public function update(Request $request, int $id): Response
    {
        $this->promotions->find($id) ?? throw HttpException::notFound();
        [$data, $serviceIds] = $this->validated($request);
        $this->promotions->update($id, $data);
        $this->promotions->setServices($id, $serviceIds);
        $this->audit->log('promotions.updated', self::userId($request), $request, 'promotion', $id);
        $this->flash('success', 'Promotion enregistrée.');

        return $this->redirectTo('admin.promotions');
    }

    public function toggle(Request $request, int $id): Response
    {
        $promotion = $this->promotions->find($id) ?? throw HttpException::notFound();
        $this->promotions->setActive($id, !$promotion['is_active']);
        $this->flash('success', $promotion['is_active'] ? 'Promotion désactivée : elle n\'apparaît plus sur le site.' : 'Promotion réactivée.');

        return $this->redirectTo('admin.promotions');
    }

    public function destroy(Request $request, int $id): Response
    {
        $promotion = $this->promotions->find($id) ?? throw HttpException::notFound();
        $this->promotions->delete($id);
        $this->audit->log('promotions.deleted', self::userId($request), $request, 'promotion', $id, ['name' => $promotion['name']]);
        $this->flash('success', "Promotion « {$promotion['name']} » supprimée. Les rendez-vous déjà pris gardent leur prix.");

        return $this->redirectTo('admin.promotions');
    }

    /** @param list<int> $selected */
    private function form(?array $promotion, array $values, array $selected): Response
    {
        $groups = [];
        foreach ($this->services->list() as $service) {
            $groups[(string) $service['category_name']][] = $service;
        }

        return $this->render('admin/promotions/form.twig', [
            'promotion' => $promotion,
            'values' => $values,
            'selected' => $selected,
            'service_groups' => $groups,
            'types' => PromotionService::TYPES,
            'bookings_count' => $promotion !== null ? $this->promotions->bookingsCount((int) $promotion['id']) : 0,
        ]);
    }

    /**
     * @return array{0: array{name: string, description: ?string, discount_type: string, value: string, starts_at: string, ends_at: string, is_active: bool}, 1: list<int>}
     * @throws ValidationException
     */
    private function validated(Request $request): array
    {
        $errors = [];
        $name = trim((string) $request->input('name', ''));
        if ($name === '' || mb_strlen($name) > 80) {
            $errors['name'] = 'Indiquez un nom de 80 caractères au plus (ex. « Rentrée : −15 % sur les knotless »).';
        }
        $description = trim((string) $request->input('description', ''));
        if (mb_strlen($description) > 300) {
            $errors['description'] = 'La description ne doit pas dépasser 300 caractères.';
        }

        $type = (string) $request->input('discount_type', '');
        if (!isset(PromotionService::TYPES[$type])) {
            $errors['discount_type'] = 'Choisissez un type de réduction.';
        }
        $value = Slug::parsePrice($request->input('value'));
        if ($value === null || (float) $value <= 0) {
            $errors['value'] = 'Indiquez une valeur supérieure à 0.';
        } elseif ($type === 'percent' && (float) $value > 100) {
            $errors['value'] = 'Un pourcentage ne peut pas dépasser 100.';
        }

        $zone = $this->clock->zone();
        $first = DateTimeImmutable::createFromFormat('!Y-m-d', (string) $request->input('first_day', ''), $zone);
        $last = DateTimeImmutable::createFromFormat('!Y-m-d', (string) $request->input('last_day', ''), $zone);
        if ($first === false) {
            $errors['first_day'] = 'Date de début invalide.';
        }
        if ($last === false) {
            $errors['last_day'] = 'Date de fin invalide.';
        } elseif ($first !== false && $last < $first) {
            $errors['last_day'] = 'La fin doit être le même jour que le début ou après.';
        }

        $available = [];
        foreach ($this->services->list() as $service) {
            $available[(int) $service['id']] = $service;
        }
        $serviceIds = array_values(array_filter(
            CategoryController::idList($request->input('services')),
            static fn (int $id): bool => isset($available[$id]),
        ));
        if ($serviceIds === []) {
            $errors['services'] = 'Choisissez au moins une prestation.';
        } elseif ($type === 'fixed_price') {
            $matrix = array_filter($serviceIds, static fn (int $id): bool => $available[$id]['pricing_mode'] !== 'simple');
            if ($matrix !== []) {
                $names = implode(', ', array_map(static fn (int $id): string => (string) $available[$id]['name'], $matrix));
                $errors['services'] = "Un prix promotionnel ne s'applique qu'aux prestations à prix unique. Utilisez un pourcentage ou un montant pour : {$names}.";
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return [[
            'name' => $name,
            'description' => $description !== '' ? $description : null,
            'discount_type' => $type,
            'value' => (string) $value,
            'starts_at' => $first->format(DATE_ATOM),
            // Fin exclusive : le lendemain du dernier jour, à 0 h.
            'ends_at' => $last->modify('+1 day')->format(DATE_ATOM),
            'is_active' => in_array($request->input('is_active'), ['1', 1, true, 'on'], true),
        ], $serviceIds];
    }

    /** @param array<string, mixed> $promotion */
    private function status(array $promotion): string
    {
        $now = $this->clock->now();

        return match (true) {
            !$promotion['is_active'] => 'disabled',
            new DateTimeImmutable((string) $promotion['ends_at']) <= $now => 'expired',
            new DateTimeImmutable((string) $promotion['starts_at']) > $now => 'scheduled',
            default => 'active',
        };
    }

    /** Date locale (Y-m-d) ; pour une fin exclusive, le dernier jour inclus. */
    private function day(string $timestamp, bool $exclusiveEnd = false): string
    {
        $date = (new DateTimeImmutable($timestamp))->setTimezone($this->clock->zone());

        return ($exclusiveEnd ? $date->modify('-1 second') : $date)->format('Y-m-d');
    }

    private static function userId(Request $request): ?int
    {
        $user = $request->attribute('user');

        return $user instanceof User ? $user->id : null;
    }
}
