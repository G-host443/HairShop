<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Controller;
use App\Core\Config;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Router;
use App\Core\Session\Session;
use App\Core\View;
use App\Models\User;
use App\Repositories\NotificationRepository;
use App\Services\Notification\NotificationService;

/**
 * Journal des e-mails : état des envois, nouvel essai, e-mail de test.
 */
final class NotificationAdminController extends Controller
{
    public const EVENT_LABELS = [
        'booking.created' => 'Réservation',
        'booking.confirmed' => 'Confirmation',
        'booking.refused' => 'Refus',
        'booking.cancelled' => 'Annulation',
        'booking.cancelled_by_customer' => 'Annulation (cliente)',
        'booking.rescheduled' => 'Déplacement',
        'booking.reminder' => 'Rappel',
        'contact.message' => 'Message de contact',
        'account.verify' => 'Vérification d\'adresse',
        'booking.review_request' => 'Demande d\'avis',
        'review.submitted' => 'Nouvel avis (alerte)',
        'test' => 'Test',
    ];

    public function __construct(
        View $view,
        Router $router,
        Session $session,
        private readonly NotificationRepository $notifications,
        private readonly NotificationService $service,
        private readonly Config $config,
    ) {
        parent::__construct($view, $router, $session);
    }

    public function index(Request $request): Response
    {
        $status = in_array($request->query('statut'), ['queued', 'sent', 'failed', 'cancelled'], true) ? (string) $request->query('statut') : null;

        return $this->render('admin/notifications/index.twig', [
            'items' => $this->notifications->recent($status),
            'counts' => $this->notifications->countsByStatus(),
            'status' => $status,
            'labels' => self::EVENT_LABELS,
            'driver' => (string) $this->config->get('mail.driver'),
        ]);
    }

    public function retry(int $id): Response
    {
        if ($this->notifications->retry($id)) {
            $stats = $this->service->sendDue(5);
            $this->flash($stats['sent'] > 0 ? 'success' : 'warning', $stats['sent'] > 0 ? 'E-mail renvoyé.' : 'Nouvel essai programmé : l\'envoi a encore échoué, voir le détail de l\'erreur.');
        } else {
            $this->flash('info', 'Cet e-mail n\'est pas en échec.');
        }

        return $this->redirectTo('admin.notifications');
    }

    public function test(Request $request): Response
    {
        /** @var User $user */
        $user = $request->attribute('user');
        $this->service->test($user->email);
        $stats = $this->service->sendDue(5);

        $stats['sent'] > 0
            ? $this->flash('success', "E-mail de test envoyé à {$user->email}." . ($this->config->get('mail.driver') === 'log' ? ' (Mode développement : il est écrit dans storage/logs/mail-AAAA-MM-JJ.log.)' : ''))
            : $this->flash('error', 'L\'e-mail de test n\'a pas pu partir : vérifiez la configuration SMTP (voir l\'erreur ci-dessous).');

        return $this->redirectTo('admin.notifications');
    }
}
