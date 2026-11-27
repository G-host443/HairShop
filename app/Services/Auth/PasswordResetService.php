<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Core\Config;
use App\Core\Http\Request;
use App\Core\Router;
use App\Core\View;
use App\Models\User;
use App\Repositories\PasswordResetRepository;
use App\Repositories\UserRepository;
use App\Services\Audit\AuditLogger;
use App\Services\Mail\MailerInterface;
use App\Services\Mail\MailMessage;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Réinitialisation du mot de passe par lien envoyé par e-mail, pour l'administration et l'espace cliente.
 *
 * - Le jeton (64 caractères hexadécimaux) n'est stocké que sous forme d'empreinte SHA-256.
 * - Il expire après 60 minutes et ne sert qu'une fois.
 * - La réponse est identique que le compte existe ou non (pas d'énumération des comptes).
 */
final class PasswordResetService
{
    public const AREA_ADMIN = 'admin';
    public const AREA_CUSTOMER = 'customer';

    public function __construct(
        private readonly UserRepository $users,
        private readonly PasswordResetRepository $resets,
        private readonly MailerInterface $mailer,
        private readonly View $view,
        private readonly Router $router,
        private readonly Config $config,
        private readonly AuditLogger $audit,
        private readonly LoggerInterface $logger,
        private readonly AuthService $auth,
    ) {
    }

    /**
     * Envoie le lien si l'adresse correspond à un compte actif de l'espace concerné.
     * Ne révèle jamais si le compte existe.
     */
    public function sendResetLink(string $email, Request $request, string $area = self::AREA_ADMIN): void
    {
        $user = $this->users->findByEmail($email);
        if ($user === null || !self::eligible($user, $area)) {
            return;
        }

        $maxPerHour = (int) $this->config->get('security.password_reset.max_requests_per_hour', 3);
        if ($this->resets->countRecentForUser($user->id, 60) >= $maxPerHour) {
            return;
        }

        $token = bin2hex(random_bytes(32));
        $minutes = (int) $this->config->get('security.password_reset.expires_minutes', 60);
        $this->resets->create($user->id, self::hashToken($token), $minutes);

        $route = $area === self::AREA_CUSTOMER ? 'account.password.reset' : 'admin.password.reset';
        $url = $this->config->get('app.url') . $this->router->url($route, ['token' => $token]);
        $data = ['user' => $user, 'url' => $url, 'minutes' => $minutes];

        try {
            $this->mailer->send(new MailMessage(
                to: $user->email,
                subject: 'Réinitialisation de votre mot de passe',
                html: $this->view->render('emails/password-reset.html.twig', $data),
                text: $this->view->render('emails/password-reset.txt.twig', $data),
                toName: $user->fullName(),
            ));
        } catch (Throwable $e) {
            // L'utilisateur reçoit le même message ; l'erreur est journalisée pour l'administratrice.
            $this->logger->error('Échec d\'envoi de l\'e-mail de réinitialisation : ' . $e->getMessage(), ['user_id' => $user->id]);
        }

        $this->audit->log('auth.password_reset_requested', $user->id, $request, 'user', $user->id, ['area' => $area]);
    }

    public function isValidToken(string $token): bool
    {
        return $this->resets->findValid(self::hashToken($token)) !== null;
    }

    /**
     * @return bool false si le lien est invalide, expiré ou déjà utilisé
     */
    public function reset(string $token, string $newPassword, Request $request): bool
    {
        $reset = $this->resets->findValid(self::hashToken($token));
        if ($reset === null || !$this->resets->consume($reset['id'])) {
            return false;
        }

        $user = $this->users->findById($reset['user_id']);
        if ($user === null || !$user->isActive) {
            return false;
        }

        // Invalide aussi les autres liens en attente et déconnecte les sessions existantes.
        $this->auth->changePassword($user, $newPassword, $request);
        // Recevoir le lien prouve la possession de l'adresse e-mail.
        $this->users->markEmailVerified($user->id);
        $this->audit->log('auth.password_reset', $user->id, $request, 'user', $user->id);

        return true;
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    private static function eligible(User $user, string $area): bool
    {
        return $area === self::AREA_CUSTOMER ? $user->isCustomer() : $user->canAccessAdmin();
    }
}
