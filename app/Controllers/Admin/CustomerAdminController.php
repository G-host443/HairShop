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
use App\Repositories\CustomerAdminRepository;
use App\Repositories\CustomerRepository;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\AuthService;

/**
 * Fiches clientes : recherche, historique, prestations réalisées, notes internes, modification.
 *
 * Données personnelles : consultation réservée à la permission customers.view, modification et notes
 * à customers.manage ; chaque ouverture de fiche est inscrite au journal d'audit.
 */
final class CustomerAdminController extends Controller
{
    private const PER_PAGE = 30;
    private const NOTE_MAX = 2000;

    public function __construct(
        View $view,
        Router $router,
        Session $session,
        private readonly CustomerAdminRepository $customers,
        private readonly AuthService $auth,
        private readonly AuditLogger $audit,
    ) {
        parent::__construct($view, $router, $session);
    }

    public function index(Request $request): Response
    {
        $q = mb_substr(trim((string) $request->query('q', '')), 0, 100);
        $sort = (string) $request->query('tri', 'recent');
        $sort = isset(CustomerAdminRepository::SORTS[$sort]) ? $sort : 'recent';
        $page = max(1, (int) filter_var($request->query('page'), FILTER_VALIDATE_INT));
        $result = $this->customers->search($q, $sort, $page, self::PER_PAGE);

        return $this->render('admin/customers/index.twig', [
            'items' => $result['items'],
            'total' => $result['total'],
            'page' => $page,
            'pages' => max(1, (int) ceil($result['total'] / self::PER_PAGE)),
            'q' => $q,
            'sort' => $sort,
            'sorts' => CustomerAdminRepository::SORTS,
        ]);
    }

    public function show(Request $request, int $id): Response
    {
        $customer = $this->customers->find($id) ?? throw HttpException::notFound('Fiche cliente introuvable.');
        $this->audit->log('customers.viewed', self::userId($request), $request, 'customer', $id);

        return $this->render('admin/customers/show.twig', [
            'customer' => $customer,
            'bookings' => $this->customers->bookings($id),
            'services_done' => $this->customers->servicesDone($id),
            'favorites' => $this->customers->favorites($id),
            'reviews' => $this->customers->reviews($id),
            'notes' => $this->customers->notes($id),
            'can_manage' => $this->auth->can('customers.manage'),
            'whatsapp' => self::whatsapp((string) $customer['phone']),
        ]);
    }

    public function update(Request $request, int $id): Response
    {
        $customer = $this->customers->find($id) ?? throw HttpException::notFound();
        $errors = [];

        $first = trim((string) $request->input('first_name', ''));
        $last = trim((string) $request->input('last_name', ''));
        if ($first === '' || mb_strlen($first) > 80) {
            $errors['first_name'] = 'Indiquez le prénom (80 caractères au plus).';
        }
        if ($last === '' || mb_strlen($last) > 80) {
            $errors['last_name'] = 'Indiquez le nom (80 caractères au plus).';
        }

        // L'adresse d'un compte cliente est gérée par la cliente elle-même (elle sert à se connecter).
        $email = $customer['user_id'] !== null ? $customer['email'] : (trim((string) $request->input('email', '')) ?: null);
        if ($email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'] = 'Adresse e-mail invalide.';
        }
        $phoneInput = trim((string) $request->input('phone', ''));
        $phone = $phoneInput !== '' ? CustomerRepository::normalizePhone($phoneInput) : null;
        if ($phone !== null && preg_match('/^\+?[0-9]{6,15}$/', $phone) !== 1) {
            $errors['phone'] = 'Numéro de téléphone invalide.';
        }
        if ($email === null && $phone === null) {
            $errors['email'] = 'Gardez au moins une adresse e-mail ou un téléphone pour joindre la cliente.';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $this->customers->update($id, [
            'first_name' => $first,
            'last_name' => $last,
            'email' => $email,
            'phone' => $phone,
            'marketing_consent' => in_array($request->input('marketing_consent'), ['1', 1, true, 'on'], true),
        ]);
        $this->audit->log('customers.updated', self::userId($request), $request, 'customer', $id);
        $this->flash('success', 'Fiche enregistrée.');
        if ($email !== null && $customer['user_id'] === null && $this->customers->emailUsedByOther((string) $email, $id)) {
            $this->flash('warning', 'Une autre fiche utilise la même adresse e-mail : il s\'agit peut-être de la même cliente.');
        }

        return $this->redirectTo('admin.customers.show', ['id' => $id]);
    }

    public function addNote(Request $request, int $id): Response
    {
        $this->customers->find($id) ?? throw HttpException::notFound();
        $body = trim(str_replace(["\r\n", "\r"], "\n", (string) $request->input('body', '')));
        if ($body === '' || mb_strlen($body) > self::NOTE_MAX) {
            throw ValidationException::withMessages(['body' => 'Écrivez une note de ' . self::NOTE_MAX . ' caractères au plus.']);
        }
        $noteId = $this->customers->addNote($id, self::userId($request), $body);
        $this->audit->log('customers.note_added', self::userId($request), $request, 'customer', $id, ['note_id' => $noteId]);
        $this->flash('success', 'Note ajoutée.');

        return $this->redirectTo('admin.customers.show', ['id' => $id]);
    }

    public function deleteNote(Request $request, int $id, int $noteId): Response
    {
        $this->customers->findNote($id, $noteId) ?? throw HttpException::notFound();
        $this->customers->deleteNote($noteId);
        $this->audit->log('customers.note_deleted', self::userId($request), $request, 'customer', $id, ['note_id' => $noteId]);
        $this->flash('success', 'Note supprimée.');

        return $this->redirectTo('admin.customers.show', ['id' => $id]);
    }

    /** Numéro au format international pour un lien WhatsApp (06… → 336…), ou null. */
    private static function whatsapp(string $phone): ?string
    {
        $digits = preg_replace('/\D/', '', $phone) ?? '';
        if (preg_match('/^0[67]\d{8}$/', $digits)) {
            return '33' . substr($digits, 1);
        }

        return preg_match('/^[1-9]\d{9,14}$/', $digits) ? $digits : null;
    }

    private static function userId(Request $request): ?int
    {
        $user = $request->attribute('user');

        return $user instanceof User ? $user->id : null;
    }
}
