<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

final class ContactMessageRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    public function create(string $name, string $email, ?string $phone, ?string $subject, string $body, string $ip): int
    {
        return (int) $this->db->fetchValue(
            'INSERT INTO contact_messages (name, email, phone, subject, body, ip)
             VALUES (:name, :email, :phone, :subject, :body, CAST(:ip AS inet)) RETURNING id',
            [
                'name' => $name,
                'email' => $email,
                'phone' => $phone,
                'subject' => $subject,
                'body' => $body,
                'ip' => filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : null,
            ],
        );
    }

    /** @return array{items: list<array<string, mixed>>, total: int} */
    public function inbox(bool $unreadOnly, int $page, int $perPage): array
    {
        $where = $unreadOnly ? 'WHERE NOT is_read' : '';

        return [
            'items' => $this->db->fetchAll(
                "SELECT id, name, email, phone, subject, left(body, 160) AS preview, is_read, created_at FROM contact_messages {$where}
                 ORDER BY created_at DESC, id DESC LIMIT :limit OFFSET :offset",
                ['limit' => $perPage, 'offset' => ($page - 1) * $perPage],
            ),
            'total' => (int) $this->db->fetchValue("SELECT count(*) FROM contact_messages {$where}"),
        ];
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->fetchOne('SELECT * FROM contact_messages WHERE id = :id', ['id' => $id]);
    }

    public function setRead(int $id, bool $read): void
    {
        $this->db->execute('UPDATE contact_messages SET is_read = :r WHERE id = :id', ['id' => $id, 'r' => $read]);
    }

    public function delete(int $id): void
    {
        $this->db->execute('DELETE FROM contact_messages WHERE id = :id', ['id' => $id]);
    }

    public function unreadCount(): int
    {
        return (int) $this->db->fetchValue('SELECT count(*) FROM contact_messages WHERE NOT is_read');
    }

    /** Fiche cliente correspondant à l'expéditeur (même adresse e-mail), s'il y en a une. */
    public function matchingCustomerId(string $email): ?int
    {
        $id = $this->db->fetchValue('SELECT id FROM customers WHERE email = :e ORDER BY user_id IS NULL, id LIMIT 1', ['e' => $email]);

        return $id !== null ? (int) $id : null;
    }

    public function countRecentFromIp(string $ip, int $minutes): int
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return 0;
        }

        return (int) $this->db->fetchValue(
            'SELECT count(*) FROM contact_messages WHERE ip = CAST(:ip AS inet) AND created_at > now() - make_interval(mins => :minutes)',
            ['ip' => $ip, 'minutes' => $minutes],
        );
    }
}
