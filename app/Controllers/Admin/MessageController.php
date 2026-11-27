<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Controller;
use App\Core\Exceptions\HttpException;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Router;
use App\Core\Session\Session;
use App\Core\View;
use App\Models\User;
use App\Repositories\ContactMessageRepository;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\AuthService;

/**
 * Messages reçus par le formulaire de contact : lecture, marquer lu / non lu, suppression.
 */
final class MessageController extends Controller
{
    private const PER_PAGE = 30;

    public function __construct(
        View $view,
        Router $router,
        Session $session,
        private readonly ContactMessageRepository $messages,
        private readonly AuthService $auth,
        private readonly AuditLogger $audit,
    ) {
        parent::__construct($view, $router, $session);
    }

    public function index(Request $request): Response
    {
        $unread = $request->query('filtre') === 'non-lus';
        $page = max(1, (int) filter_var($request->query('page'), FILTER_VALIDATE_INT));
        $result = $this->messages->inbox($unread, $page, self::PER_PAGE);

        return $this->render('admin/messages/index.twig', [
            'items' => $result['items'],
            'total' => $result['total'],
            'unread_only' => $unread,
            'unread_count' => $this->messages->unreadCount(),
            'page' => $page,
            'pages' => max(1, (int) ceil($result['total'] / self::PER_PAGE)),
        ]);
    }

    public function show(int $id): Response
    {
        $message = $this->messages->find($id) ?? throw HttpException::notFound();
        if (!$message['is_read']) {
            $this->messages->setRead($id, true);
        }
        $customerId = $this->auth->can('customers.view') ? $this->messages->matchingCustomerId((string) $message['email']) : null;

        return $this->render('admin/messages/show.twig', ['message' => $message, 'customer_id' => $customerId]);
    }

    public function markUnread(int $id): Response
    {
        $this->messages->find($id) ?? throw HttpException::notFound();
        $this->messages->setRead($id, false);
        $this->flash('success', 'Message marqué comme non lu.');

        return $this->redirectTo('admin.messages');
    }

    public function destroy(Request $request, int $id): Response
    {
        $message = $this->messages->find($id) ?? throw HttpException::notFound();
        $this->messages->delete($id);
        $user = $request->attribute('user');
        $this->audit->log('messages.deleted', $user instanceof User ? $user->id : null, $request, 'contact_message', $id, ['from' => $message['email']]);
        $this->flash('success', 'Message supprimé.');

        return $this->redirectTo('admin.messages');
    }
}
