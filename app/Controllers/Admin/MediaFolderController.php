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
use App\Repositories\MediaFolderRepository;

final class MediaFolderController extends Controller
{
    public function __construct(View $view, Router $router, Session $session, private readonly MediaFolderRepository $folders)
    {
        parent::__construct($view, $router, $session);
    }

    public function store(Request $request): Response
    {
        [$name, $parentId] = $this->validated($request);

        if ($this->folders->nameTaken($name, $parentId)) {
            throw ValidationException::withMessages(['name' => 'Un dossier porte déjà ce nom à cet emplacement.']);
        }

        $id = $this->folders->create($name, $parentId);
        $this->flash('success', "Dossier « {$name} » créé.");

        return $this->redirectTo('admin.media', ['dossier' => $id]);
    }

    public function update(Request $request, int $id): Response
    {
        $folder = $this->folders->find($id) ?? throw HttpException::notFound();
        [$name, $parentId] = $this->validated($request);

        if ($parentId !== null && $this->folders->isSelfOrDescendant($parentId, $folder['id'])) {
            throw ValidationException::withMessages(['parent_id' => 'Un dossier ne peut pas être placé dans lui-même ou dans un de ses sous-dossiers.']);
        }
        if ($this->folders->nameTaken($name, $parentId, $folder['id'])) {
            throw ValidationException::withMessages(['name' => 'Un dossier porte déjà ce nom à cet emplacement.']);
        }

        $this->folders->update($folder['id'], $name, $parentId);
        $this->flash('success', 'Dossier mis à jour.');

        return $this->redirectTo('admin.media', ['dossier' => $folder['id']]);
    }

    public function destroy(int $id): Response
    {
        $folder = $this->folders->find($id) ?? throw HttpException::notFound();
        $this->folders->delete($folder['id']);
        $this->flash('success', "Dossier « {$folder['name']} » supprimé. Ses médias sont conservés, sans dossier.");

        return $this->redirectTo('admin.media', $folder['parent_id'] !== null ? ['dossier' => $folder['parent_id']] : []);
    }

    /** @return array{0: string, 1: ?int} */
    private function validated(Request $request): array
    {
        $data = $this->validate($request->all(), [
            'name' => 'required|max:80|regex:/^[^\/\\\\<>]+$/u',
            'parent_id' => 'nullable|integer',
        ], ['name' => 'nom du dossier', 'parent_id' => 'dossier parent']);

        $parentId = isset($data['parent_id']) && $data['parent_id'] !== '' ? (int) $data['parent_id'] : null;
        if ($parentId !== null && !$this->folders->exists($parentId)) {
            throw ValidationException::withMessages(['parent_id' => 'Le dossier parent n\'existe pas.']);
        }

        return [(string) $data['name'], $parentId];
    }
}
