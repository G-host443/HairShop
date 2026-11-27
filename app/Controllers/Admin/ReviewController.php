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
use App\Repositories\MediaRepository;
use App\Repositories\ReviewRepository;
use App\Services\Audit\AuditLogger;
use App\Services\Review\ReviewService;

/**
 * Modération des avis : approuver, refuser, mettre en avant, nom affiché et photo, saisie d'un avis reçu ailleurs.
 */
final class ReviewController extends Controller
{
    public const TABS = ['pending' => 'À modérer', 'approved' => 'Publiés', 'rejected' => 'Refusés'];

    public function __construct(
        View $view,
        Router $router,
        Session $session,
        private readonly ReviewRepository $reviews,
        private readonly ReviewService $service,
        private readonly MediaRepository $media,
        private readonly AuditLogger $audit,
    ) {
        parent::__construct($view, $router, $session);
    }

    public function index(Request $request): Response
    {
        $tab = (string) $request->query('statut', 'pending');
        $tab = isset(self::TABS[$tab]) ? $tab : 'pending';
        $reviews = $this->reviews->byStatus($tab);

        return $this->render('admin/reviews/index.twig', [
            'reviews' => $reviews,
            'photos' => $this->media->findMany(array_filter(array_map(static fn (array $r) => $r['photo_media_id'] !== null ? (int) $r['photo_media_id'] : null, $reviews))),
            'tab' => $tab,
            'tabs' => self::TABS,
            'counts' => $this->reviews->counts(),
            'stats' => $this->reviews->approvedStats(),
        ]);
    }

    public function moderate(Request $request, int $id, string $decision): Response
    {
        $review = $this->reviews->find($id) ?? throw HttpException::notFound();
        $status = $decision === 'approuver' ? 'approved' : 'rejected';
        $this->service->moderate($id, $status, self::userId($request), $request);
        $this->flash('success', $status === 'approved' ? "Avis de {$review['author_name']} publié." : "Avis de {$review['author_name']} refusé : il n'apparaît pas sur le site.");

        return $this->back($review['status']);
    }

    public function feature(Request $request, int $id): Response
    {
        $review = $this->reviews->find($id) ?? throw HttpException::notFound();
        if ($review['status'] !== 'approved') {
            $this->flash('error', 'Seul un avis publié peut être mis en avant.');

            return $this->back($review['status']);
        }
        $this->reviews->setFeatured($id, !$review['is_featured']);
        $this->flash('success', $review['is_featured'] ? 'Avis retiré de la mise en avant.' : 'Avis mis en avant : il s\'affiche en premier.');

        return $this->back('approved');
    }

    public function edit(int $id): Response
    {
        $review = $this->reviews->find($id) ?? throw HttpException::notFound();

        return $this->render('admin/reviews/edit.twig', [
            'review' => $review,
            'photo' => $review['photo_media_id'] !== null ? $this->media->find((int) $review['photo_media_id']) : null,
        ]);
    }

    public function update(Request $request, int $id): Response
    {
        $review = $this->reviews->find($id) ?? throw HttpException::notFound();
        $author = trim(preg_replace('/\s+/u', ' ', (string) $request->input('author_name', '')) ?? '');
        $errors = [];
        if ($author === '' || mb_strlen($author) > 60) {
            $errors['author_name'] = 'Indiquez le nom affiché (60 caractères au plus).';
        }
        $photo = filter_var($request->input('photo_media_id'), FILTER_VALIDATE_INT);
        if ($photo !== false && !($this->media->find($photo)?->isImage() ?? false)) {
            $errors['photo_media_id'] = 'Photo introuvable dans la bibliothèque.';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        // Le texte de l'avis n'est pas modifiable : on ne fait pas dire à une cliente ce qu'elle n'a pas écrit.
        $this->reviews->updateDisplay($id, $author, $photo !== false ? $photo : null);
        $this->audit->log('reviews.updated', self::userId($request), $request, 'review', $id);
        $this->flash('success', 'Avis enregistré.');

        return $this->back((string) $review['status']);
    }

    public function create(): Response
    {
        return $this->render('admin/reviews/create.twig');
    }

    public function store(Request $request): Response
    {
        $this->service->createByAdmin($request->all(), self::userId($request), $request);
        $this->flash('success', 'Avis ajouté et publié.');

        return $this->back('approved');
    }

    public function destroy(Request $request, int $id): Response
    {
        $review = $this->reviews->find($id) ?? throw HttpException::notFound();
        $this->reviews->delete($id);
        $this->audit->log('reviews.deleted', self::userId($request), $request, 'review', $id, ['author' => $review['author_name']]);
        $this->flash('success', 'Avis supprimé définitivement.');

        return $this->back((string) $review['status']);
    }

    private function back(string $status): Response
    {
        return Response::redirect($this->router->url('admin.reviews') . '?statut=' . rawurlencode($status), 303);
    }

    private static function userId(Request $request): ?int
    {
        $user = $request->attribute('user');

        return $user instanceof User ? $user->id : null;
    }
}
