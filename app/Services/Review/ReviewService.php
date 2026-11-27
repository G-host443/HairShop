<?php

declare(strict_types=1);

namespace App\Services\Review;

use App\Core\Database;
use App\Core\Http\Request;
use App\Core\Validation\ValidationException;
use App\Repositories\ReviewRepository;
use App\Services\Audit\AuditLogger;
use App\Services\Booking\BookingStatus;
use App\Services\Notification\NotificationService;

/**
 * Avis clientes.
 *
 * - Une cliente peut laisser un avis sur un rendez-vous terminé (un seul par rendez-vous), depuis son lien
 *   « gérer mon rendez-vous » ou son espace : l'avis est donc forcément lié à une vraie prestation.
 * - Tout avis déposé en ligne attend la modération ; seuls les avis approuvés sont publics.
 * - L'administratrice peut aussi saisir un avis reçu ailleurs (WhatsApp, Instagram…), publié directement.
 */
final class ReviewService
{
    public const MAX_COMMENT = 1500;

    public function __construct(
        private readonly Database $db,
        private readonly ReviewRepository $reviews,
        private readonly NotificationService $notifications,
        private readonly AuditLogger $audit,
    ) {
    }

    /** @param array<string, mixed> $booking */
    public function canReview(array $booking): bool
    {
        return $booking['status'] === BookingStatus::COMPLETED && $this->reviews->findByBooking((int) $booking['id']) === null;
    }

    /** Nom affiché proposé par défaut : prénom et initiale du nom (« Awa D. »). */
    public static function defaultAuthorName(array $booking): string
    {
        $first = trim((string) ($booking['first_name'] ?? ''));
        $last = trim((string) ($booking['last_name'] ?? ''));

        return trim($first . ($last !== '' ? ' ' . mb_strtoupper(mb_substr($last, 0, 1)) . '.' : ''));
    }

    /**
     * @param array<string, mixed> $booking
     * @param array<string, mixed> $input rating, comment, author_name
     * @throws ValidationException
     */
    public function submitForBooking(array $booking, array $input): int
    {
        if (!$this->canReview($booking)) {
            throw ValidationException::withMessages(['review' => 'Un avis a déjà été laissé pour ce rendez-vous, ou il n\'est pas encore terminé.']);
        }
        $data = self::validate($input, self::defaultAuthorName($booking));

        $id = $this->db->transaction(function () use ($booking, $data): int {
            $id = $this->reviews->create([
                'customer_id' => (int) $booking['customer_id'],
                'booking_id' => (int) $booking['id'],
                'status' => 'pending',
            ] + $data);
            $this->notifications->reviewSubmitted($id);

            return $id;
        });
        $this->notifications->trySendNow();

        return $id;
    }

    /**
     * Avis reçu hors du site, saisi et publié par l'administratrice.
     *
     * @param array<string, mixed> $input
     * @throws ValidationException
     */
    public function createByAdmin(array $input, ?int $userId, ?Request $request = null): int
    {
        $data = self::validate($input, null);
        $id = $this->reviews->create(['customer_id' => null, 'booking_id' => null, 'status' => 'approved', 'moderated_by' => $userId] + $data);
        $this->audit->log('reviews.created', $userId, $request, 'review', $id);

        return $id;
    }

    public function moderate(int $id, string $status, ?int $userId, ?Request $request = null): void
    {
        $this->reviews->moderate($id, $status, $userId);
        $this->audit->log('reviews.' . $status, $userId, $request, 'review', $id);
    }

    /**
     * @param array<string, mixed> $input
     * @return array{author_name: string, rating: int, comment: string}
     * @throws ValidationException
     */
    public static function validate(array $input, ?string $defaultName): array
    {
        $errors = [];
        $rating = filter_var($input['rating'] ?? null, FILTER_VALIDATE_INT);
        if ($rating === false || $rating < 1 || $rating > 5) {
            $errors['rating'] = 'Choisissez une note de 1 à 5 étoiles.';
        }

        $comment = trim(str_replace(["\r\n", "\r"], "\n", (string) ($input['comment'] ?? '')));
        $comment = preg_replace('/[\x00-\x09\x0B-\x1F\x7F]/u', '', $comment) ?? '';
        $comment = preg_replace("/\n{3,}/", "\n\n", $comment) ?? $comment;
        if (mb_strlen($comment) < 10) {
            $errors['comment'] = 'Quelques mots de plus, s\'il vous plaît (10 caractères au moins).';
        } elseif (mb_strlen($comment) > self::MAX_COMMENT) {
            $errors['comment'] = 'Votre avis ne doit pas dépasser ' . self::MAX_COMMENT . ' caractères.';
        }

        $name = trim(preg_replace('/\s+/u', ' ', (string) ($input['author_name'] ?? '')) ?? '');
        if ($name === '') {
            $name = (string) $defaultName;
        }
        if ($name === '' || mb_strlen($name) > 60) {
            $errors['author_name'] = 'Indiquez le nom à afficher (60 caractères au plus).';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return ['author_name' => $name, 'rating' => $rating, 'comment' => $comment];
    }
}
