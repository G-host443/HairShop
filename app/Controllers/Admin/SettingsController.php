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
use App\Services\Audit\AuditLogger;
use App\Services\Settings\SettingsSchema;
use App\Services\Settings\SettingsService;

final class SettingsController extends Controller
{
    public function __construct(
        View $view,
        Router $router,
        Session $session,
        private readonly SettingsService $settings,
        private readonly MediaRepository $media,
        private readonly AuditLogger $audit,
    ) {
        parent::__construct($view, $router, $session);
    }

    public function edit(string $group = 'general'): Response
    {
        $this->assertGroup($group);
        $fields = SettingsSchema::group($group);

        // Aperçu des images déjà choisies (logo, favicon, image de partage).
        $mediaIds = [];
        foreach ($fields as $key => $field) {
            if ($field['type'] === 'media' && $this->settings->get($key) !== null) {
                $mediaIds[] = (int) $this->settings->get($key);
            }
        }

        $values = [];
        foreach (array_keys($fields) as $key) {
            $values[$key] = $this->settings->get($key);
        }

        return $this->render('admin/settings/edit.twig', [
            'groups' => SettingsSchema::GROUPS,
            'group' => $group,
            'fields' => $fields,
            'values' => $values,
            'selected_media' => $this->media->findMany($mediaIds),
            'timezones' => timezone_identifiers_list(),
        ]);
    }

    public function update(Request $request, string $group): Response
    {
        $this->assertGroup($group);

        $result = SettingsSchema::validateGroup($group, $request->all(), function (int $id): bool {
            $media = $this->media->find($id);

            return $media !== null && $media->isImage();
        });

        if ($result['errors'] !== []) {
            throw new ValidationException($result['errors']);
        }

        $changes = $this->settings->update($result['values']);

        if ($changes !== []) {
            /** @var User $user */
            $user = $request->attribute('user');
            $this->audit->log('settings.updated', $user->id, $request, 'site_settings', null, $changes);
            $this->flash('success', count($changes) . ' paramètre' . (count($changes) > 1 ? 's enregistrés.' : ' enregistré.'));
        } else {
            $this->flash('info', 'Aucune modification.');
        }

        return $this->redirectTo('admin.settings', ['group' => $group]);
    }

    private function assertGroup(string $group): void
    {
        if (!isset(SettingsSchema::GROUPS[$group])) {
            throw HttpException::notFound();
        }
    }
}
