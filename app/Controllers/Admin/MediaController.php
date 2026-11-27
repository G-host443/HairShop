<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Controller;
use App\Core\Config;
use App\Core\Exceptions\HttpException;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Router;
use App\Core\Session\Session;
use App\Core\View;
use App\Models\Media;
use App\Models\User;
use App\Repositories\MediaFolderRepository;
use App\Repositories\MediaRepository;
use App\Services\Audit\AuditLogger;
use App\Services\Media\MediaException;
use App\Services\Media\MediaService;
use App\Services\Media\MediaUploader;
use App\Services\Media\MediaUsageFinder;

final class MediaController extends Controller
{
    public function __construct(
        View $view,
        Router $router,
        Session $session,
        private readonly MediaRepository $media,
        private readonly MediaFolderRepository $folders,
        private readonly MediaUploader $uploader,
        private readonly MediaService $service,
        private readonly MediaUsageFinder $usage,
        private readonly AuditLogger $audit,
        private readonly Config $config,
    ) {
        parent::__construct($view, $router, $session);
    }

    public function index(Request $request): Response
    {
        $filters = [
            'folder' => $this->folderFilter($request->query('dossier')),
            'type' => in_array($request->query('type'), ['image', 'video'], true) ? $request->query('type') : null,
            'q' => mb_substr(trim((string) $request->query('q', '')), 0, 100),
        ];
        $perPage = (int) $this->config->get('media.per_page', 24);
        $page = max(1, (int) $request->query('page', 1));
        $result = $this->media->search($filters, $page, $perPage);

        return $this->render('admin/media/index.twig', [
            'items' => $result['items'],
            'total' => $result['total'],
            'page' => $page,
            'pages' => max(1, (int) ceil($result['total'] / $perPage)),
            'filters' => $filters,
            'folders' => $this->folders->tree(),
            'current_folder' => is_int($filters['folder']) ? $this->folders->find($filters['folder']) : null,
            'total_size' => $this->media->totalSize(),
            'limits' => $this->limits(),
        ]);
    }

    /**
     * Import d'un ou plusieurs fichiers (formulaire classique ou glisser-déposer en JavaScript).
     */
    public function store(Request $request): Response
    {
        /** @var User $user */
        $user = $request->attribute('user');
        $folderId = $this->validFolderId($request->input('folder_id'));
        $files = $request->files('files');

        $created = [];
        $errors = [];

        if ($files === []) {
            $errors[] = ['file' => '', 'message' => 'Aucun fichier sélectionné.'];
        }

        foreach ($files as $file) {
            try {
                $media = $this->uploader->upload($file, $folderId, $user->id);
                $created[] = $media;
                $this->audit->log('media.uploaded', $user->id, $request, 'media', $media->id, ['name' => $media->originalName]);
            } catch (MediaException $e) {
                $errors[] = ['file' => $file->clientName, 'message' => $e->getMessage()];
            }
        }

        if ($request->wantsJson()) {
            return Response::json([
                'created' => array_map(static fn (Media $m): array => $m->toArray(), $created),
                'errors' => $errors,
            ], $created === [] ? 422 : 200);
        }

        if ($created !== []) {
            $this->flash('success', count($created) . ' fichier' . (count($created) > 1 ? 's importés.' : ' importé.'));
        }
        if ($errors !== []) {
            $this->flash('error', implode(' — ', array_map(
                static fn (array $e): string => ($e['file'] !== '' ? $e['file'] . ' : ' : '') . $e['message'],
                $errors,
            )));
        }

        return $this->redirectTo('admin.media', $folderId !== null ? ['dossier' => $folderId] : []);
    }

    public function edit(int $id): Response
    {
        $media = $this->findOrFail($id);

        return $this->render('admin/media/edit.twig', [
            'media' => $media,
            'folders' => $this->folders->tree(),
            'usages' => $this->usage->usages($media->id),
            'variant_labels' => ['thumb' => 'Miniature', 'medium' => 'Moyenne', 'large' => 'Grande'],
        ]);
    }

    public function update(Request $request, int $id): Response
    {
        $media = $this->findOrFail($id);
        $data = $this->validate($request->all(), [
            'original_name' => 'required|max:150',
            'alt_text' => 'nullable|max:250',
            'folder_id' => 'nullable|integer',
        ], ['original_name' => 'nom', 'alt_text' => 'texte alternatif', 'folder_id' => 'dossier']);

        $folderId = $this->validFolderId($data['folder_id'] ?? null);
        $this->media->updateDetails($media->id, (string) $data['original_name'], (string) ($data['alt_text'] ?? ''), $folderId);

        /** @var User $user */
        $user = $request->attribute('user');
        $this->audit->log('media.updated', $user->id, $request, 'media', $media->id);
        $this->flash('success', 'Média mis à jour.');

        return $this->redirectTo('admin.media.edit', ['id' => $media->id]);
    }

    public function destroy(Request $request, int $id): Response
    {
        $media = $this->findOrFail($id);

        try {
            $this->service->delete($media);
        } catch (MediaException $e) {
            $this->flash('error', $e->getMessage());

            return $this->redirectTo('admin.media.edit', ['id' => $media->id]);
        }

        /** @var User $user */
        $user = $request->attribute('user');
        $this->audit->log('media.deleted', $user->id, $request, 'media', $media->id, ['name' => $media->originalName]);
        $this->flash('success', '« ' . $media->originalName . ' » a été supprimé.');

        return $this->redirectTo('admin.media', $media->folderId !== null ? ['dossier' => $media->folderId] : []);
    }

    private function findOrFail(int $id): Media
    {
        return $this->media->find($id) ?? throw HttpException::notFound('Ce média n\'existe pas.');
    }

    private function folderFilter(mixed $value): int|string|null
    {
        if ($value === 'none') {
            return 'none';
        }

        return is_string($value) && ctype_digit($value) ? (int) $value : null;
    }

    private function validFolderId(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $id = filter_var($value, FILTER_VALIDATE_INT);

        return $id !== false && $this->folders->exists($id) ? $id : null;
    }

    /** @return array{image_mb: int, video_mb: int, post_mb: string} */
    private function limits(): array
    {
        $iniBytes = self::iniBytes((string) ini_get('upload_max_filesize'));

        return [
            'image_mb' => intdiv(min((int) $this->config->get('media.max_image_bytes'), $iniBytes), 1024 * 1024),
            'video_mb' => intdiv(min((int) $this->config->get('media.max_video_bytes'), $iniBytes), 1024 * 1024),
            'post_mb' => (string) ini_get('post_max_size'),
        ];
    }

    private static function iniBytes(string $value): int
    {
        $number = (int) $value;

        return match (strtoupper(substr(trim($value), -1))) {
            'G' => $number * 1024 ** 3,
            'M' => $number * 1024 ** 2,
            'K' => $number * 1024,
            default => $number,
        };
    }
}
