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
use App\Models\User;
use App\Repositories\CategoryRepository;
use App\Repositories\GalleryRepository;
use App\Repositories\MediaRepository;
use App\Repositories\ServiceRepository;
use App\Services\Audit\AuditLogger;

/**
 * Galerie de réalisations : photos (avec avant / après facultatif), catégorie, prestation, tags,
 * mise en avant, publication et ordre d'affichage.
 */
final class GalleryController extends Controller
{
    private const MAX_TAGS = 8;

    public function __construct(
        View $view,
        Router $router,
        Session $session,
        private readonly GalleryRepository $gallery,
        private readonly MediaRepository $media,
        private readonly CategoryRepository $categories,
        private readonly ServiceRepository $services,
        private readonly AuditLogger $audit,
    ) {
        parent::__construct($view, $router, $session);
    }

    public function index(Request $request): Response
    {
        $category = filter_var($request->query('categorie'), FILTER_VALIDATE_INT) ?: null;
        $items = $this->gallery->all($category);
        $media = $this->media->findMany(array_column($items, 'media_id'));

        return $this->render('admin/gallery/index.twig', [
            'items' => $items,
            'media' => $media,
            'categories' => $this->categories->all(),
            'current_category' => $category,
        ]);
    }

    public function create(): Response
    {
        return $this->form(null);
    }

    public function store(Request $request): Response
    {
        $data = $this->validated($request);
        $id = $this->gallery->create($data);
        $this->audit->log('gallery.created', self::userId($request), $request, 'gallery_item', $id);
        $this->flash('success', "Réalisation « {$data['title']} » ajoutée" . ($data['is_published'] ? ' et publiée.' : ' (non publiée).'));

        return $this->redirectTo('admin.gallery');
    }

    public function edit(int $id): Response
    {
        return $this->form($this->gallery->find($id) ?? throw HttpException::notFound());
    }

    public function update(Request $request, int $id): Response
    {
        $this->gallery->find($id) ?? throw HttpException::notFound();
        $this->gallery->update($id, $this->validated($request));
        $this->audit->log('gallery.updated', self::userId($request), $request, 'gallery_item', $id);
        $this->flash('success', 'Réalisation enregistrée.');

        return $this->redirectTo('admin.gallery');
    }

    public function toggle(Request $request, int $id, string $flag): Response
    {
        $item = $this->gallery->find($id) ?? throw HttpException::notFound();
        $column = $flag === 'avant' ? 'featured' : 'published';
        $value = !$item['is_' . $column];
        $this->gallery->setFlag($id, $column, $value);
        $this->flash('success', match ($column) {
            'featured' => $value ? 'Réalisation mise en avant.' : 'Réalisation retirée des mises en avant.',
            default => $value ? 'Réalisation publiée.' : 'Réalisation masquée du site.',
        });

        return $this->redirectTo('admin.gallery');
    }

    public function destroy(Request $request, int $id): Response
    {
        $item = $this->gallery->find($id) ?? throw HttpException::notFound();
        $this->gallery->delete($id);
        $this->audit->log('gallery.deleted', self::userId($request), $request, 'gallery_item', $id, ['title' => $item['title']]);
        $this->flash('success', "Réalisation « {$item['title']} » supprimée (les photos restent dans la bibliothèque).");

        return $this->redirectTo('admin.gallery');
    }

    public function reorder(Request $request): Response
    {
        $this->gallery->reorder(CategoryController::idList($request->input('ids')));

        return Response::json(['ok' => true]);
    }

    private function form(?array $item): Response
    {
        $groups = [];
        foreach ($this->services->list() as $service) {
            $groups[(string) $service['category_name']][] = $service;
        }

        return $this->render('admin/gallery/form.twig', [
            'item' => $item,
            'image' => $item !== null ? $this->media->find($item['media_id']) : null,
            'after' => $item !== null && $item['after_media_id'] !== null ? $this->media->find($item['after_media_id']) : null,
            'categories' => $this->categories->all(),
            'service_groups' => $groups,
        ]);
    }

    /**
     * @return array{media_id: int, after_media_id: ?int, title: string, description: ?string, category_id: ?int, service_id: ?int, tags: list<string>, is_featured: bool, is_published: bool}
     * @throws ValidationException
     */
    private function validated(Request $request): array
    {
        $errors = [];

        $mediaId = filter_var($request->input('media_id'), FILTER_VALIDATE_INT);
        if ($mediaId === false || !($this->media->find($mediaId)?->isImage() ?? false)) {
            $errors['media_id'] = 'Choisissez une photo dans la bibliothèque.';
        }
        $afterId = filter_var($request->input('after_media_id'), FILTER_VALIDATE_INT);
        if ($afterId !== false) {
            if (!($this->media->find($afterId)?->isImage() ?? false)) {
                $errors['after_media_id'] = 'Photo « après » introuvable.';
            } elseif ($afterId === $mediaId) {
                $errors['after_media_id'] = 'Choisissez deux photos différentes pour l\'avant / après.';
            }
        }

        $title = trim((string) $request->input('title', ''));
        if ($title === '' || mb_strlen($title) > 100) {
            $errors['title'] = 'Indiquez un titre de 100 caractères au plus (ex. « Knotless mi-dos »).';
        }
        $description = trim((string) $request->input('description', ''));
        if (mb_strlen($description) > 500) {
            $errors['description'] = 'La description ne doit pas dépasser 500 caractères.';
        }

        $categoryId = filter_var($request->input('category_id'), FILTER_VALIDATE_INT) ?: null;
        if ($categoryId !== null && $this->categories->find($categoryId) === null) {
            $errors['category_id'] = 'Catégorie introuvable.';
        }
        $serviceId = filter_var($request->input('service_id'), FILTER_VALIDATE_INT) ?: null;
        $service = $serviceId !== null ? $this->services->find($serviceId) : null;
        if ($serviceId !== null && $service === null) {
            $errors['service_id'] = 'Prestation introuvable.';
        }
        // La catégorie se déduit de la prestation si elle n'est pas choisie.
        if ($categoryId === null && $service !== null) {
            $categoryId = (int) $service['category_id'];
        }

        $tags = [];
        foreach (preg_split('/[,;]/', (string) $request->input('tags', '')) ?: [] as $tag) {
            $tag = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $tag) ?? ''));
            if ($tag === '') {
                continue;
            }
            if (preg_match("/^[\\p{L}\\p{N}][\\p{L}\\p{N} '’-]{0,29}$/u", $tag) !== 1) {
                $errors['tags'] = "Tag « {$tag} » invalide : lettres, chiffres, espaces et tirets, 30 caractères au plus.";
                break;
            }
            $tags[$tag] = $tag;
        }
        if (count($tags) > self::MAX_TAGS) {
            $errors['tags'] = 'Pas plus de ' . self::MAX_TAGS . ' tags par réalisation.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $published = $request->input('is_published', '1');

        return [
            'media_id' => $mediaId,
            'after_media_id' => $afterId !== false ? $afterId : null,
            'title' => $title,
            'description' => $description !== '' ? $description : null,
            'category_id' => $categoryId,
            'service_id' => $serviceId,
            'tags' => array_values($tags),
            'is_featured' => in_array($request->input('is_featured'), ['1', 1, true, 'on'], true),
            'is_published' => in_array($published, ['1', 1, true, 'on'], true),
        ];
    }

    private static function userId(Request $request): ?int
    {
        $user = $request->attribute('user');

        return $user instanceof User ? $user->id : null;
    }
}
