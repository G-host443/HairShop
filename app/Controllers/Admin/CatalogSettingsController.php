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
use App\Repositories\OptionRepository;
use App\Repositories\PricingRepository;

/**
 * Référentiels du catalogue : tailles, longueurs, groupes d'options et options.
 */
final class CatalogSettingsController extends Controller
{
    public function __construct(
        View $view,
        Router $router,
        Session $session,
        private readonly PricingRepository $pricing,
        private readonly OptionRepository $options,
    ) {
        parent::__construct($view, $router, $session);
    }

    // ---------- Tailles et longueurs ----------

    public function dimensions(): Response
    {
        return $this->render('admin/catalog/dimensions.twig', [
            'sizes' => $this->pricing->sizes(),
            'lengths' => $this->pricing->lengths(),
            'tab' => 'dimensions',
        ]);
    }

    public function storeSize(Request $request): Response
    {
        [$code, $label] = $this->sizeInput($request);
        $this->pricing->createSize($code, $label);
        $this->flash('success', "Taille {$code} ajoutée.");

        return $this->redirectTo('admin.catalog.dimensions');
    }

    public function updateSize(Request $request, int $id): Response
    {
        [$code, $label] = $this->sizeInput($request, $id);
        $this->pricing->updateSize($id, $code, $label);
        $this->flash('success', "Taille {$code} enregistrée.");

        return $this->redirectTo('admin.catalog.dimensions');
    }

    public function destroySize(int $id): Response
    {
        $this->pricing->deleteSize($id)
            ? $this->flash('success', 'Taille supprimée.')
            : $this->flash('error', 'Cette taille est utilisée par des prestations : retirez-la de leurs grilles d\'abord.');

        return $this->redirectTo('admin.catalog.dimensions');
    }

    public function storeLength(Request $request): Response
    {
        $label = $this->lengthLabel($request);
        $slug = Slug::make($label, 60);
        if ($this->pricing->lengthSlugExists($slug)) {
            throw ValidationException::withMessages(['length_label' => 'Cette longueur existe déjà.']);
        }
        $this->pricing->createLength($slug, $label);
        $this->flash('success', "Longueur « {$label} » ajoutée.");

        return $this->redirectTo('admin.catalog.dimensions');
    }

    public function updateLength(Request $request, int $id): Response
    {
        $label = $this->lengthLabel($request);
        $this->pricing->updateLength($id, $label);
        $this->flash('success', 'Longueur enregistrée.');

        return $this->redirectTo('admin.catalog.dimensions');
    }

    public function destroyLength(int $id): Response
    {
        $this->pricing->deleteLength($id)
            ? $this->flash('success', 'Longueur supprimée.')
            : $this->flash('error', 'Cette longueur a des prix dans certaines grilles : videz ces cellules d\'abord.');

        return $this->redirectTo('admin.catalog.dimensions');
    }

    public function reorder(Request $request, string $kind): Response
    {
        $table = match ($kind) {
            'tailles' => 'service_sizes',
            'longueurs' => 'service_lengths',
            default => throw HttpException::notFound(),
        };
        $this->pricing->reorder($table, CategoryController::idList($request->input('ids')));

        return Response::json(['ok' => true]);
    }

    // ---------- Options ----------

    public function options(): Response
    {
        return $this->render('admin/catalog/options.twig', [
            'groups' => $this->options->groupsWithOptions(),
            'tab' => 'options',
        ]);
    }

    public function storeGroup(Request $request): Response
    {
        $data = $this->groupInput($request);
        $code = Slug::make($data['label'], 40);
        $code = str_replace('-', '_', $code);
        $base = $code;
        for ($i = 2; $this->options->groupCodeExists($code); $i++) {
            $code = $base . '_' . $i;
        }
        $this->options->createGroup($code, $data['label'], $data['selection'], $data['required']);
        $this->flash('success', "Groupe « {$data['label']} » créé.");

        return $this->redirectTo('admin.catalog.options');
    }

    public function updateGroup(Request $request, int $id): Response
    {
        $this->options->findGroup($id) ?? throw HttpException::notFound();
        $data = $this->groupInput($request);
        $this->options->updateGroup($id, $data['label'], $data['selection'], $data['required']);
        $this->flash('success', 'Groupe enregistré.');

        return $this->redirectTo('admin.catalog.options');
    }

    public function destroyGroup(int $id): Response
    {
        $group = $this->options->findGroup($id) ?? throw HttpException::notFound();
        if ($this->options->groupIsUsed($id)) {
            $this->flash('error', 'Des réservations utilisent les options de ce groupe : désactivez les options plutôt que de supprimer le groupe.');
        } else {
            $this->options->deleteGroup($id);
            $this->flash('success', "Groupe « {$group['label']} » supprimé.");
        }

        return $this->redirectTo('admin.catalog.options');
    }

    public function storeOption(Request $request): Response
    {
        $groupId = filter_var($request->input('group_id'), FILTER_VALIDATE_INT) ?: 0;
        $this->options->findGroup($groupId) ?? throw ValidationException::withMessages(['group_id' => 'Groupe inconnu.']);
        $data = $this->optionInput($request, $groupId);
        $this->options->createOption($groupId, $data);
        $this->flash('success', "Option « {$data['name']} » ajoutée.");

        return $this->redirectTo('admin.catalog.options');
    }

    public function updateOption(Request $request, int $id): Response
    {
        $option = $this->options->findOption($id) ?? throw HttpException::notFound();
        $data = $this->optionInput($request, (int) $option['group_id'], $id);
        $this->options->updateOption($id, $data);
        $this->flash('success', "Option « {$data['name']} » enregistrée.");

        return $this->redirectTo('admin.catalog.options');
    }

    public function destroyOption(int $id): Response
    {
        $option = $this->options->findOption($id) ?? throw HttpException::notFound();
        if ($this->options->optionIsUsed($id)) {
            $this->flash('error', 'Cette option figure dans des réservations : désactivez-la plutôt que de la supprimer.');
        } else {
            $this->options->deleteOption($id);
            $this->flash('success', "Option « {$option['name']} » supprimée.");
        }

        return $this->redirectTo('admin.catalog.options');
    }

    // ---------- Validation ----------

    /** @return array{0: string, 1: string} */
    private function sizeInput(Request $request, ?int $id = null): array
    {
        $data = $this->validate($request->all(), [
            'code' => 'required|max:6|regex:/^[A-Za-z0-9]+$/',
            'label' => 'required|max:40',
        ], ['code' => 'code', 'label' => 'description']);
        $code = strtoupper((string) $data['code']);
        if ($this->pricing->sizeCodeExists($code, $id)) {
            throw ValidationException::withMessages(['code' => "La taille {$code} existe déjà."]);
        }

        return [$code, (string) $data['label']];
    }

    private function lengthLabel(Request $request): string
    {
        return (string) $this->validate($request->all(), ['length_label' => 'required|max:60'], ['length_label' => 'longueur'])['length_label'];
    }

    /** @return array{label: string, selection: string, required: bool} */
    private function groupInput(Request $request): array
    {
        $data = $this->validate($request->all(), [
            'label' => 'required|max:80',
            'selection' => 'required|in:single,multiple',
        ], ['label' => 'nom du groupe', 'selection' => 'type de choix']);

        return ['label' => (string) $data['label'], 'selection' => (string) $data['selection'], 'required' => $request->input('is_required') === '1'];
    }

    /** @return array{name: string, description: ?string, price_kind: string, price: ?string, extra_minutes: int, is_active: bool} */
    private function optionInput(Request $request, int $groupId, ?int $id = null): array
    {
        $data = $this->validate($request->all(), [
            'name' => 'required|max:100',
            'description' => 'nullable|max:300',
            'price_kind' => 'required|in:fixed,from,quote',
            'price' => 'nullable|max:20',
            'extra_minutes' => 'nullable|integer|between:0,600',
        ], ['name' => 'nom', 'description' => 'description', 'price_kind' => 'type de prix', 'price' => 'prix', 'extra_minutes' => 'temps en plus']);

        if ($this->options->optionNameTaken($groupId, (string) $data['name'], $id)) {
            throw ValidationException::withMessages(['name' => 'Une option porte déjà ce nom dans ce groupe.']);
        }

        $price = null;
        if ($data['price_kind'] !== 'quote') {
            $price = ($data['price'] ?? '') === '' ? '0.00' : Slug::parsePrice($data['price']);
            if ($price === null) {
                throw ValidationException::withMessages(['price' => 'Prix invalide (0 = offert).']);
            }
        }

        return [
            'name' => (string) $data['name'],
            'description' => ($data['description'] ?? '') !== '' ? (string) $data['description'] : null,
            'price_kind' => (string) $data['price_kind'],
            'price' => $price,
            'extra_minutes' => (int) ($data['extra_minutes'] ?? 0),
            'is_active' => $request->input('is_active') === '1',
        ];
    }
}
