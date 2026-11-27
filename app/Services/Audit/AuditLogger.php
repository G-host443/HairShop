<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Core\Database;
use App\Core\Http\Request;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Journal des actions importantes (connexion, changement de mot de passe, publication…).
 * Une erreur d'écriture du journal ne doit jamais faire échouer l'action elle-même.
 */
final class AuditLogger
{
    public function __construct(
        private readonly Database $db,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** @param array<string, mixed> $changes */
    public function log(
        string $action,
        ?int $userId,
        ?Request $request = null,
        ?string $entityType = null,
        ?int $entityId = null,
        array $changes = [],
    ): void {
        try {
            $ip = $request?->ip();
            $this->db->execute(
                'INSERT INTO audit_logs (user_id, action, entity_type, entity_id, changes, ip, user_agent)
                 VALUES (:user, :action, :type, :entity, CAST(:changes AS jsonb), CAST(:ip AS inet), :agent)',
                [
                    'user' => $userId,
                    'action' => $action,
                    'type' => $entityType,
                    'entity' => $entityId,
                    'changes' => json_encode($changes, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    'ip' => $ip !== null && filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : null,
                    'agent' => $request?->userAgent(),
                ],
            );
        } catch (Throwable $e) {
            $this->logger->error('Échec d\'écriture du journal d\'audit : ' . $e->getMessage(), ['action' => $action]);
        }
    }
}
