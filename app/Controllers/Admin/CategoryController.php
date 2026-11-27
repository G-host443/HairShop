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
use App\Services\Audit\AuditLogger;

final class CategoryController extends Controller
{
    public function __construct(
        View $view,
        Router $router,
        Session $session,
        private readonly CategoryRepository $categories,
        private readonly OptionRepository $options,
        private readonly MediaRepository $media,
        private readonly AuditLogger $audit,
    ) {
        parent::__construct($view, $router, $session);
    }

    public function index(): Response
    {
        return $this->render('admin/catalog/categories.twig', [
            'categories' => $this->categories->all(),
            'groups' => $this->options->groupsWithOptions(),
            'tab' => 'categories',
        ]);
    }

    public function store(Request $request): Response
    {
        $data = $this->validated($request);
        $id = $this->categories->create($data);
        $this->categories->setOptionGroups($id, $this->groupIds($request));
        $this->audit->log('catalog.category_created', $this->userId($request), $request, 'service_category', $id, ['name' => $data['name']]);
        $this->flash('success', "Catégorie « {$data['name']} » créée.");

        return $this->redirectTo('admin.categories');
    }

    public function edit(int $id): Response
    {
        $category = $this->categories->find($id) ?? throw HttpException::notFound();

        return $this->render('admin/catalog/category-edit.twig', [
            'category' => $category,
            'groups' => $this->options->groupsWithOptions(),
            'selected_groups' => $this->categories->optionGroupIds($id),
            'image' => $category['image_media_id'] !== null ? $this->media->find((int) $category['image_media_id']) : null,
            'tab' => 'categories',
        ]);
    }

    public function update(Request $request, int $id): Response
    {
        $category = $this->categories->find($id) ?? throw HttpException::notFound();
        $data = $this->validated($request, $id);
        $this->categories->update($id, $data);
        $this->categories->setOptionGroups($id, $this->groupIds($request));
        $this->audit->log('catalog.category_updated', $this->userId($request), $request, 'service_category', $id);
        $this->flash('success', 'Catégorie enregistrée.');

        return $this->redirectTo('admin.categories.edit', ['id' => $category['id']]);
    }

    public function destroy(Request $request, int $id): Response
    {
        $category = $this->categories->find($id) ?? throw HttpException::notFound();

        if ($this->categories->servicesCount($id) > 0) {
            $this->flash('error', 'Cette catégorie contient des prestations : déplacez-les ou supprimez-les d\'abord.');

            return $this->redirectTo('admin.categories.edit', ['id' => $id]);
        }

        $this->categories->delete($id);
        $this->audit->log('catalog.category_deleted', $this->userId($request), $request, 'service_category', $id, ['name' => $category['name']]);
        $this->flash('success', "Catégorie « {$category['name']} » supprimée.");

        return $this->redirectTo('admin.categories');
    }

    public function reorder(Request $request): Response
    {
        $this->categories->reorder(self::idList($request->input('ids')));

        return Response::json(['ok' => true]);
    }

    /**
     * @return array{name: string, slug: string, description: ?string, header_note: ?string, image_media_id: ?int, is_active: bool}
     */
    private function validated(Request $request, ?int $id = null): array
    {
        $data = $this->validate($request->all(), [
            'name' => 'required|max:80',
            'slug' => 'nullable|max:80',
            'description' => 'nullable|max:1000',
            'header_note' => 'nullable|max:300',
            'image_media_id' => 'nullable|integer',
        ], ['name' => 'nom', 'slug' => 'adresse (slug)', 'description' => 'description', 'header_note' => 'note d\'en-tête', 'image_media_id' => 'image']);

        $slug = ($data['slug'] ?? '') !== '' ? Slug::make((string) $data['slug']) : Slug::make((string) $data['name']);
        if ($this->categories->slugExists($slug, $id)) {
            throw ValidationException::withMessages(['slug' => "L'adresse « {$slug} » est déjà utilisée par une autre catégorie."]);
        }

        $imageId = ($data['image_media_id'] ?? '') !== '' ? (int) $data['image_media_id'] : null;
        if ($imageId !== null && !$this->media->find($imageId)?->isImage()) {
            throw ValidationException::withMessages(['image_media_id' => 'Choisissez une image de la bibliothèque.']);
        }

        return [
            'name' => (string) $data['name'],
            'slug' => $slug,
            'description' => ($data['description'] ?? '') !== '' ? (string) $data['description'] : null,
            'header_note' => ($data['header_note'] ?? '') !== '' ? (string) $data['header_note'] : null,
            'image_media_id' => $imageId,
            'is_active' => $request->input('is_active') === '1',
        ];
    }

    /** @return list<int> */
    private function groupIds(Request $request): array
    {
        return array_values(array_intersect(self::idList($request->input('option_groups')), $this->options->groupIds()));
    }

    /** @return list<int> */
    public static function idList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_unique(array_filter(
            array_map(static fn ($v) => filter_var($v, FILTER_VALIDATE_INT), $value),
            static fn ($v) => is_int($v) && $v > 0,
        )));
    }

    private function userId(Request $request): ?int
    {
        $user = $request->attribute('user');

        return $user instanceof User ? $user->id : null;
    }
}
