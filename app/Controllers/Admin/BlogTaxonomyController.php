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
use App\Repositories\BlogRepository;

/**
 * Catégories et tags du blog. Les tags se créent aussi directement depuis un article.
 */
final class BlogTaxonomyController extends Controller
{
    public function __construct(
        View $view,
        Router $router,
        Session $session,
        private readonly BlogRepository $blog,
    ) {
        parent::__construct($view, $router, $session);
    }

    public function index(): Response
    {
        return $this->render('admin/blog/taxonomy.twig', [
            'categories' => $this->blog->categories(),
            'tags' => $this->blog->tags(),
        ]);
    }

    public function storeCategory(Request $request): Response
    {
        [$name, $slug] = $this->validatedCategory($request, null);
        $this->blog->createCategory($name, $slug);
        $this->flash('success', "Catégorie « {$name} » créée.");

        return $this->redirectTo('admin.blog.taxonomy');
    }

    public function updateCategory(Request $request, int $id): Response
    {
        $this->blog->findCategory($id) ?? throw HttpException::notFound();
        [$name, $slug] = $this->validatedCategory($request, $id);
        $this->blog->updateCategory($id, $name, $slug);
        $this->flash('success', 'Catégorie enregistrée.');

        return $this->redirectTo('admin.blog.taxonomy');
    }

    public function destroyCategory(int $id): Response
    {
        $category = $this->blog->findCategory($id) ?? throw HttpException::notFound();
        $this->blog->deleteCategory($id);
        $this->flash('success', "Catégorie « {$category['name']} » supprimée : ses articles restent, sans catégorie.");

        return $this->redirectTo('admin.blog.taxonomy');
    }

    public function reorderCategories(Request $request): Response
    {
        $this->blog->reorderCategories(CategoryController::idList($request->input('ids')));

        return Response::json(['ok' => true]);
    }

    public function updateTag(Request $request, int $id): Response
    {
        $this->blog->findTag($id) ?? throw HttpException::notFound();
        $name = trim(preg_replace('/\s+/u', ' ', (string) $request->input('name', '')) ?? '');
        if ($name === '' || mb_strlen($name) > 40) {
            throw ValidationException::withMessages(['tag_' . $id => 'Nom de tag invalide (40 caractères au plus).']);
        }
        // L'adresse du tag (/blog/tag/…) ne change pas : les liens existants restent valides.
        $this->blog->renameTag($id, $name);
        $this->flash('success', 'Tag renommé.');

        return $this->redirectTo('admin.blog.taxonomy');
    }

    public function destroyTag(int $id): Response
    {
        $tag = $this->blog->findTag($id) ?? throw HttpException::notFound();
        $this->blog->deleteTag($id);
        $this->flash('success', "Tag « {$tag['name']} » supprimé des articles.");

        return $this->redirectTo('admin.blog.taxonomy');
    }

    /** @return array{0: string, 1: string} */
    private function validatedCategory(Request $request, ?int $id): array
    {
        $name = trim(preg_replace('/\s+/u', ' ', (string) $request->input('name', '')) ?? '');
        $key = $id === null ? 'name' : 'category_' . $id;
        if ($name === '' || mb_strlen($name) > 60) {
            throw ValidationException::withMessages([$key => 'Indiquez un nom de 60 caractères au plus.']);
        }
        $slug = Slug::make($name, 60);
        if ($this->blog->categorySlugExists($slug, $id)) {
            throw ValidationException::withMessages([$key => "Une catégorie « {$name} » existe déjà."]);
        }

        return [$name, $slug];
    }
}
