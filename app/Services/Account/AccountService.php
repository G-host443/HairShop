<?php

declare(strict_types=1);

namespace App\Services\Account;

use App\Core\Clock;
use App\Core\Database;
use App\Core\Http\Request;
use App\Core\Security\PasswordHasher;
use App\Models\User;
use App\Repositories\CustomerRepository;
use App\Repositories\NotificationRepository;
use App\Repositories\UserRepository;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\EmailVerification;
use App\Services\Notification\NotificationService;
use RuntimeException;

/**
 * Comptes clientes : inscription, vérification de l'e-mail, profil, export des données, suppression.
 */
final class AccountService
{
    public function __construct(
        private readonly Database $db,
        private readonly UserRepository $users,
        private readonly CustomerRepository $customers,
        private readonly PasswordHasher $hasher,
        private readonly EmailVerification $verification,
        private readonly NotificationRepository $notificationQueue,
        private readonly NotificationService $notifications,
        private readonly AuditLogger $audit,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @param array{first_name: string, last_name: string, email: string, phone: ?string, password: string, marketing: bool} $data
     */
    public function register(array $data, ?Request $request = null): User
    {
        $user = $this->db->transaction(function () use ($data): User {
            $user = $this->users->create($data['email'], $this->hasher->hash($data['password']), $data['first_name'], $data['last_name'], ['customer'], $data['phone']);
            // Fiche cliente propre au compte ; les anciennes réservations seront rattachées après vérification de l'e-mail.
            $this->customers->create($data['first_name'], $data['last_name'], $data['email'], $data['phone'], $data['marketing'], $user->id);
            $this->queueVerification($user);

            return $user;
        });

        $this->audit->log('account.registered', $user->id, $request, 'user', $user->id);
        $this->notifications->trySendNow();

        return $user;
    }

    public function queueVerification(User $user): void
    {
        $this->notificationQueue->queue('account.verify', $user->email, [
            'audience' => 'customer',
            'user_id' => $user->id,
            'expires' => $this->verification->expiry(),
        ], null);
    }

    public function resendVerification(User $user): void
    {
        $this->queueVerification($user);
        $this->notifications->trySendNow();
    }

    /**
     * @return User|null l'utilisateur vérifié, ou null si le lien est invalide ou expiré
     */
    public function verify(string $token): ?User
    {
        $id = $this->verification->userIdFrom($token);
        $user = $id !== null ? $this->users->findById($id) : null;
        if ($user === null || !$user->isCustomer() || !$this->verification->isValid($token, $user)) {
            return null;
        }

        $this->users->markEmailVerified($user->id);
        $user = $this->users->findById($user->id) ?? $user;
        $this->mergeGuestBookings($user);

        return $user;
    }

    /**
     * Rattache au compte (e-mail vérifié uniquement) les réservations faites sans compte avec la même adresse.
     */
    public function mergeGuestBookings(User $user): int
    {
        if (!$user->isEmailVerified()) {
            return 0;
        }
        $customer = $this->customerFor($user);

        return $this->customers->mergeGuestsInto((int) $customer['id'], $user->email);
    }

    /** Fiche cliente du compte (créée si elle manque, par exemple pour un ancien compte). */
    public function customerFor(User $user): array
    {
        $customer = $this->customers->findByUserId($user->id);
        if ($customer === null) {
            $this->customers->create($user->firstName, $user->lastName, $user->email, $user->phone, false, $user->id);
            $customer = $this->customers->findByUserId($user->id) ?? throw new RuntimeException('Fiche cliente introuvable.');
        }

        return $customer;
    }

    /**
     * @param array{first_name: string, last_name: string, email: string, phone: ?string, marketing: bool} $data
     * @return bool true si l'adresse e-mail a changé (une nouvelle vérification est envoyée)
     */
    public function updateProfile(User $user, array $data, ?Request $request = null): bool
    {
        $emailChanged = mb_strtolower($data['email']) !== mb_strtolower($user->email);

        $this->db->transaction(function () use ($user, $data): void {
            $customer = $this->customerFor($user);
            $this->users->updateIdentity($user->id, $data['first_name'], $data['last_name'], $data['email'], $data['phone']);
            $this->customers->updateProfile((int) $customer['id'], [
                'first_name' => $data['first_name'], 'last_name' => $data['last_name'], 'email' => $data['email'],
                'phone' => $data['phone'], 'marketing_consent' => $data['marketing'],
            ]);
        });

        if ($emailChanged) {
            $this->resendVerification($this->users->findById($user->id) ?? $user);
        }
        $this->audit->log('account.profile_updated', $user->id, $request, 'user', $user->id, ['email_changed' => $emailChanged]);

        return $emailChanged;
    }

    /**
     * Données personnelles du compte (droit d'accès RGPD), au format JSON.
     *
     * @return array<string, mixed>
     */
    public function export(User $user): array
    {
        $customer = $this->customerFor($user);

        return [
            'exporte_le' => $this->clock->now()->format(DATE_ATOM),
            'compte' => [
                'prenom' => $user->firstName, 'nom' => $user->lastName, 'email' => $user->email, 'telephone' => $user->phone,
                'email_verifie' => $user->isEmailVerified(), 'derniere_connexion' => $user->lastLoginAt,
            ],
            'fiche_cliente' => [
                'prenom' => $customer['first_name'], 'nom' => $customer['last_name'], 'email' => $customer['email'],
                'telephone' => $customer['phone'], 'accepte_les_nouvelles' => (bool) $customer['marketing_consent'], 'creee_le' => $customer['created_at'],
            ],
            'rendez_vous' => array_map(static fn (array $b): array => [
                'reference' => $b['reference'], 'prestation' => $b['service_label'], 'debut' => $b['starts_at'], 'fin' => $b['ends_at'],
                'statut' => $b['status'], 'prix_estime' => $b['estimated_total'], 'prix_final' => $b['final_total'], 'message' => $b['customer_comment'],
            ], $this->db->fetchAll('SELECT * FROM bookings WHERE customer_id = :c ORDER BY starts_at', ['c' => $customer['id']])),
            'favoris' => array_column($this->db->fetchAll(
                'SELECT s.name FROM favorites f JOIN services s ON s.id = f.service_id WHERE f.customer_id = :c ORDER BY s.name',
                ['c' => $customer['id']],
            ), 'name'),
        ];
    }

    /**
     * Supprime le compte. Les rendez-vous passés restent dans l'historique du salon (obligations comptables),
     * détachés de tout compte. Refusé s'il reste des rendez-vous à venir.
     *
     * @throws AccountException
     */
    public function delete(User $user, ?Request $request = null): void
    {
        $customer = $this->customerFor($user);
        $upcoming = (int) $this->db->fetchValue(
            "SELECT count(*) FROM bookings WHERE customer_id = :c AND status IN ('pending', 'confirmed', 'in_progress') AND ends_at > CAST(:now AS timestamptz)",
            ['c' => $customer['id'], 'now' => $this->clock->now()->format(DATE_ATOM)],
        );
        if ($upcoming > 0) {
            throw new AccountException('Vous avez encore un rendez-vous à venir : annulez-le avant de supprimer votre compte.');
        }

        $this->db->transaction(function () use ($user, $customer): void {
            $this->customers->detachFromUser((int) $customer['id']);
            $this->users->delete($user->id);
        });
        $this->audit->log('account.deleted', null, $request, 'user', $user->id);
    }
}
