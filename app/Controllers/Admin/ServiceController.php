<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Controller;
use App\Core\Exceptions\HttpException;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Router;
use App\Core\Session\Session;
use App\Core\Validation\ValidationException;
use App\Core\View;
use App\Helpers\Slug;
use App\Models\User;
use App\Repositories\CategoryRepository;
use App\Repositories\MediaRepository;
use App\Repositories\OptionRepository;
use App\Repositories\ServiceRepository;
use App\Services\Audit\AuditLogger;

final class ServiceController extends Controller
{
    public function __construct(
        View $view,
        Router $router,
        Session $session,
        private readonly ServiceRepository $services,
        private readonly CategoryRepository $categories,
        private readonly OptionRepository $options,
        private readonly MediaRepository $media,
        private readonly AuditLogger $audit,
    ) {
        parent::__construct($view, $router, $session);
    }

    public function index(Request $request): Response
    {
        $filters = [
            'category' => filter_var($request->query('categorie'), FILTER_VALIDATE_INT) ?: null,
            'q' => mb_substr(trim((string) $request->query('q', '')), 0, 80),
            'status' => in_array($request->query('statut'), ['active', 'inactive'], true) ? $request->query('statut') : null,
        ];
        $rows = $this->services->list($filters);

        return $this->render('admin/catalog/services.twig', [
            'services' => $rows,
            'covers' => $this->media->findMany(array_filter(array_column($rows, 'cover_media_id'))),
            'categories' => $this->categories->all(),
            'filters' => $filters,
            'tab' => 'services',
        ]);
    }

    public function create(Request $request): Response
    {
        if ($this->categories->all() === []) {
            $this->flash('info', 'Créez d\'abord une catégorie pour y ranger vos prestations.');

            return $this->redirectTo('admin.categories');
        }

        $categoryId = filter_var($request->query('categorie'), FILTER_VALIDATE_INT) ?: null;

        return $this->form([
            'id' => null, 'category_id' => $categoryId, 'name' => '', 'slug' => '', 'summary' => '', 'description' => '',
            'pricing_mode' => 'matrix', 'price_kind' => 'fixed', 'price' => null, 'duration_minutes' => 180,
            'alert_text' => '', 'note' => '', 'benefits' => [], 'steps' => [], 'recommendations' => '',
            'is_featured' => false, 'is_active' => true, 'seo_title' => '', 'seo_description' => '',
        ], [], [], []);
    }

    public function store(Request $request): Response
    {
        $data = $this->validated($request);
        $id = $this->services->create($data);
        $this->saveRelations($request, $id);
        $this->audit->log('catalog.service_created', $this->userId($request), $request, 'service', $id, ['name' => $data['name']]);

        if ($data['pricing_mode'] === 'matrix') {
            $this->flash('success', "Prestation « {$data['name']} » créée. Ajoutez maintenant ses tailles et ses prix.");

            return $this->redirectTo('admin.services.grid', ['id' => $id]);
        }

        $this->flash('success', "Prestation « {$data['name']} » créée.");

        return $this->redirectTo('admin.services.edit', ['id' => $id]);
    }

    public function edit(int $id): Response
    {
        $service = $this->services->find($id) ?? throw HttpException::notFound();

        return $this->form($service, $this->services->imageIds($id), $this->services->relatedIds($id), $this->services->optionGroupOverrides($id));
    }

    public function update(Request $request, int $id): Response
    {
        $service = $this->services->find($id) ?? throw HttpException::notFound();
        $data = $this->validated($request, $id);
        $this->services->update($id, $data);
        $this->saveRelations($request, $id);
        $this->audit->log('catalog.service_updated', $this->userId($request), $request, 'service', $id);
        $this->flash('success', 'Prestation enregistrée.');

        return $this->redirectTo('admin.services.edit', ['id' => $service['id']]);
    }

    public function destroy(Request $request, int $id): Response
    {
        $service = $this->services->find($id) ?? throw HttpException::notFound();

        if ($this->services->hasBookings($id)) {
            $this->flash('error', 'Cette prestation a déjà des réservations : désactivez-la plutôt que de la supprimer, pour conserver l\'historique.');

            return $this->redirectTo('admin.services.edit', ['id' => $id]);
        }

        $this->services->delete($id);
        $this->audit->log('catalog.service_deleted', $this->userId($request), $request, 'service', $id, ['name' => $service['name']]);
        $this->flash('success', "Prestation « {$service['name']} » supprimée.");

        return $this->redirectTo('admin.services');
    }

    public function reorder(Request $request): Response
    {
        $this->services->reorder(CategoryController::idList($request->input('ids')));

        return Response::json(['ok' => true]);
    }

    /**
     * @param array<string, mixed> $service
     * @param list<int>            $imageIds
     * @param list<int>            $relatedIds
     * @param array<int, bool>     $overrides
     */
    private function form(array $service, array $imageIds, array $relatedIds, array $overrides): Response
    {
        $inherited = $service['category_id'] !== null ? $this->categories->optionGroupIds((int) $service['category_id']) : [];
        $media = $this->media->findMany($imageIds);

        return $this->render('admin/catalog/service-form.twig', [
            'service' => $service,
            'categories' => $this->categories->all(),
            'images' => array_values(array_filter(array_map(static fn (int $id) => $media[$id] ?? null, $imageIds))),
            'all_services' => array_filter($this->services->list(), static fn (array $s): bool => (int) $s['id'] !== (int) ($service['id'] ?? 0)),
            'related_ids' => $relatedIds,
            'groups' => $this->options->groupsWithOptions(),
            'inherited_groups' => $inherited,
            'overrides' => $overrides,
            'tab' => 'services',
        ]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?int $id = null): array
    {
        $input = $request->all();
        $data = $this->validate($input, [
            'category_id' => 'required|integer',
            'name' => 'required|max:120',
            'slug' => 'nullable|max:120',
            'summary' => 'nullable|max:300',
            'description' => 'nullable|max:5000',
            'pricing_mode' => 'required|in:matrix,simple',
            'price_kind' => 'required|in:fixed,from,quote',
            'price' => 'nullable|max:20',
            'duration_minutes' => 'required|integer|between:15,1440',
            'alert_text' => 'nullable|max:300',
            'note' => 'nullable|max:300',
            'benefits' => 'nullable|max:3000',
            'steps' => 'nullable|max:3000',
            'recommendations' => 'nullable|max:2000',
            'seo_title' => 'nullable|max:70',
            'seo_description' => 'nullable|max:200',
        ], [
            'category_id' => 'catégorie', 'name' => 'nom', 'slug' => 'adresse (slug)', 'summary' => 'résumé',
            'description' => 'description', 'pricing_mode' => 'type de tarif', 'price_kind' => 'type de prix', 'price' => 'prix',
            'duration_minutes' => 'durée', 'alert_text' => 'alerte', 'note' => 'note', 'benefits' => 'avantages', 'steps' => 'étapes',
            'recommendations' => 'recommandations', 'seo_title' => 'titre SEO', 'seo_description' => 'description SEO',
        ]);

        $errors = [];
        if ($this->categories->find((int) $data['category_id']) === null) {
            $errors['category_id'] = 'Choisissez une catégorie existante.';
        }

        $slug = ($data['slug'] ?? '') !== '' ? Slug::make((string) $data['slug']) : Slug::make((string) $data['name']);
        if ($this->services->slugExists($slug, $id)) {
            $errors['slug'] = "L'adresse « {$slug} » est déjà utilisée par une autre prestation.";
        }

        $price = null;
        if ($data['pricing_mode'] === 'simple' && $data['price_kind'] !== 'quote') {
            $price = Slug::parsePrice($data['price'] ?? '');
            if ($price === null) {
                $errors['price'] = 'Indiquez un prix valide (ex. 45 ou 45,50).';
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return [
            'category_id' => (int) $data['category_id'],
            'name' => (string) $data['name'],
            'slug' => $slug,
            'summary' => self::nullable($data['summary'] ?? null),
            'description' => self::nullable($data['description'] ?? null),
            'pricing_mode' => (string) $data['pricing_mode'],
            'price_kind' => $data['pricing_mode'] === 'simple' ? (string) $data['price_kind'] : 'fixed',
            'price' => $price,
            'duration_minutes' => (int) $data['duration_minutes'],
            'alert_text' => self::nullable($data['alert_text'] ?? null),
            'note' => self::nullable($data['note'] ?? null),
            'benefits' => self::lines($data['benefits'] ?? ''),
            'steps' => self::lines($data['steps'] ?? ''),
            'recommendations' => self::nullable($data['recommendations'] ?? null),
            'is_featured' => $request->input('is_featured') === '1',
            'is_active' => $request->input('is_active') === '1',
            'seo_title' => self::nullable($data['seo_title'] ?? null),
            'seo_description' => self::nullable($data['seo_description'] ?? null),
        ];
    }

    private function saveRelations(Request $request, int $serviceId): void
    {
        // Photos : uniquement des images existantes de la bibliothèque, la première sert de couverture.
        $imageIds = CategoryController::idList($request->input('image_ids'));
        $existing = $this->media->findMany($imageIds);
        $this->services->setImages($serviceId, array_values(array_filter($imageIds, static fn (int $id) => isset($existing[$id]) && $existing[$id]->isImage())));

        $validServices = array_map('intval', array_column($this->services->list(), 'id'));
        $this->services->setRelated($serviceId, array_values(array_intersect(CategoryController::idList($request->input('related_ids')), $validServices)));

        // Groupes d'options : « hériter » (rien), « ajouter » ou « exclure » par rapport à la catégorie.
        $overrides = [];
        $choices = $request->input('option_groups');
        foreach ($this->options->groupIds() as $groupId) {
            $choice = is_array($choices) ? ($choices[$groupId] ?? 'inherit') : 'inherit';
            if ($choice === 'add') {
                $overrides[$groupId] = true;
            } elseif ($choice === 'exclude') {
                $overrides[$groupId] = false;
            }
        }
        $this->services->setOptionGroupOverrides($serviceId, $overrides);
    }

    private static function nullable(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : '';

        return $value !== '' ? $value : null;
    }

    /** @return list<string> une entrée par ligne non vide (20 au maximum) */
    private static function lines(mixed $value): array
    {
        $lines = array_values(array_filter(array_map('trim', preg_split('/\R/', (string) $value) ?: [])));

        return array_slice(array_map(static fn (string $l): string => mb_substr($l, 0, 200), $lines), 0, 20);
    }

    private function userId(Request $request): ?int
    {
        $user = $request->attribute('user');

        return $user instanceof User ? $user->id : null;
    }
}
