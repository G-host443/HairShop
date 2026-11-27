<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Controller;
use App\Core\Clock;
use App\Core\Database;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Router;
use App\Core\Session\Session;
use App\Core\View;
use App\Services\Audit\AuditLabels;
use DateTimeImmutable;

/**
 * Journal d'audit : qui a fait quoi, quand (connexions, modifications, consultations de fiches clientes…).
 */
final class AuditLogController extends Controller
{
    private const PER_PAGE = 50;

    public function __construct(
        View $view,
        Router $router,
        Session $session,
        private readonly Database $db,
        private readonly Clock $clock,
    ) {
        parent::__construct($view, $router, $session);
    }

    public function index(Request $request): Response
    {
        $domain = (string) $request->query('domaine', '');
        $domain = isset(AuditLabels::DOMAINS[$domain]) ? $domain : '';
        $userId = filter_var($request->query('compte'), FILTER_VALIDATE_INT) ?: null;
        $zone = $this->clock->zone();
        $from = DateTimeImmutable::createFromFormat('!Y-m-d', (string) $request->query('du', ''), $zone) ?: null;
        $to = DateTimeImmutable::createFromFormat('!Y-m-d', (string) $request->query('au', ''), $zone) ?: null;
        $page = max(1, (int) filter_var($request->query('page'), FILTER_VALIDATE_INT));

        $where = ['TRUE'];
        $params = [];
        if ($domain !== '') {
            // « booking » couvre aussi « bookings.exported ».
            $where[] = '(a.action LIKE :d1 OR a.action LIKE :d2)';
            $params += ['d1' => $domain . '.%', 'd2' => $domain . 's.%'];
        }
        if ($userId !== null) {
            $where[] = 'a.user_id = :user';
            $params['user'] = $userId;
        }
        if ($from !== null) {
            $where[] = 'a.created_at >= CAST(:from AS timestamptz)';
            $params['from'] = $from->format(DATE_ATOM);
        }
        if ($to !== null) {
            $where[] = 'a.created_at < CAST(:to AS timestamptz)';
            $params['to'] = $to->modify('+1 day')->format(DATE_ATOM);
        }
        $sql = implode(' AND ', $where);

        $total = (int) $this->db->fetchValue("SELECT count(*) FROM audit_logs a WHERE {$sql}", $params);
        $rows = $this->db->fetchAll(
            "SELECT a.id, a.action, a.entity_type, a.entity_id, a.changes::text AS changes, host(a.ip) AS ip, a.created_at,
                    trim(u.first_name || ' ' || u.last_name) AS user_name, u.email AS user_email
             FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id
             WHERE {$sql} ORDER BY a.created_at DESC, a.id DESC LIMIT :limit OFFSET :offset",
            $params + ['limit' => self::PER_PAGE, 'offset' => ($page - 1) * self::PER_PAGE],
        );

        $entries = array_map(static function (array $r): array {
            $changes = json_decode((string) $r['changes'], true);

            return $r + [
                'label' => AuditLabels::action((string) $r['action']),
                'entity_label' => AuditLabels::entity($r['entity_type'] !== null ? (string) $r['entity_type'] : null),
                'link' => AuditLabels::link($r['entity_type'] !== null ? (string) $r['entity_type'] : null, $r['entity_id'] !== null ? (int) $r['entity_id'] : null),
                'details' => is_array($changes) ? self::details($changes) : '',
            ];
        }, $rows);

        return $this->render('admin/audit/index.twig', [
            'entries' => $entries,
            'total' => $total,
            'page' => $page,
            'pages' => max(1, (int) ceil($total / self::PER_PAGE)),
            'domains' => AuditLabels::DOMAINS,
            'users' => $this->db->fetchAll("SELECT u.id, trim(u.first_name || ' ' || u.last_name) AS name FROM users u WHERE EXISTS (SELECT 1 FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = u.id AND r.code <> 'customer') ORDER BY name"),
            'filters' => ['domaine' => $domain, 'compte' => $userId, 'du' => $from?->format('Y-m-d'), 'au' => $to?->format('Y-m-d')],
        ]);
    }

    /** Détails courts (« statut : confirmed → completed ») ; les valeurs longues sont tronquées. */
    private static function details(array $changes): string
    {
        $parts = [];
        foreach (array_slice($changes, 0, 4, true) as $key => $value) {
            if (is_array($value)) {
                $value = isset($value['from'], $value['to']) ? $value['from'] . ' → ' . $value['to'] : json_encode($value, JSON_UNESCAPED_UNICODE);
            }
            $parts[] = $key . ' : ' . mb_strimwidth((string) (is_bool($value) ? ($value ? 'oui' : 'non') : $value), 0, 60, '…');
        }

        return implode(' · ', $parts);
    }
}
