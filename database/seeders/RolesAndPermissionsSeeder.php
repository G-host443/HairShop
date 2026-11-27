<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Core\Seeding\Seeder;

final class RolesAndPermissionsSeeder extends Seeder
{
    private const ROLES = [
        'admin' => 'Administratrice',
        'staff' => 'Collaborateur',
        'customer' => 'Cliente',
    ];

    private const PERMISSIONS = [
        'dashboard.view' => 'Voir le tableau de bord',
        'stats.view' => 'Voir le chiffre d\'affaires et la fréquentation',
        'bookings.view' => 'Voir les réservations',
        'bookings.manage' => 'Gérer les réservations',
        'customers.view' => 'Voir les clientes',
        'customers.manage' => 'Gérer les clientes et leurs notes',
        'catalog.manage' => 'Gérer les prestations, tarifs et options',
        'schedule.manage' => 'Gérer les horaires et fermetures',
        'content.manage' => 'Gérer les pages et sections',
        'appearance.manage' => 'Gérer les templates et l\'apparence',
        'media.manage' => 'Gérer la bibliothèque de médias',
        'gallery.manage' => 'Gérer la galerie',
        'reviews.moderate' => 'Modérer les avis',
        'promotions.manage' => 'Gérer les promotions',
        'blog.manage' => 'Gérer le blog',
        'messages.view' => 'Lire les messages de contact',
        'settings.manage' => 'Gérer les paramètres du site',
        'users.manage' => 'Gérer les comptes administrateurs',
        'audit.view' => 'Consulter le journal d\'audit',
    ];

    /** Le rôle admin reçoit toutes les permissions ; les autres, cette liste. */
    private const ROLE_PERMISSIONS = [
        'staff' => ['dashboard.view', 'bookings.view', 'bookings.manage', 'customers.view', 'messages.view'],
        'customer' => [],
    ];

    public function run(): void
    {
        foreach (self::ROLES as $code => $label) {
            $this->db->execute(
                'INSERT INTO roles (code, label) VALUES (:code, :label) ON CONFLICT (code) DO UPDATE SET label = EXCLUDED.label',
                ['code' => $code, 'label' => $label],
            );
        }

        foreach (self::PERMISSIONS as $code => $label) {
            $this->db->execute(
                'INSERT INTO permissions (code, label) VALUES (:code, :label) ON CONFLICT (code) DO UPDATE SET label = EXCLUDED.label',
                ['code' => $code, 'label' => $label],
            );
        }

        $this->db->execute(
            "INSERT INTO role_permissions (role_id, permission_id)
             SELECT r.id, p.id FROM roles r CROSS JOIN permissions p WHERE r.code = 'admin'
             ON CONFLICT DO NOTHING"
        );

        foreach (self::ROLE_PERMISSIONS as $role => $permissions) {
            foreach ($permissions as $permission) {
                $this->db->execute(
                    'INSERT INTO role_permissions (role_id, permission_id)
                     SELECT r.id, p.id FROM roles r, permissions p WHERE r.code = :role AND p.code = :permission
                     ON CONFLICT DO NOTHING',
                    ['role' => $role, 'permission' => $permission],
                );
            }
        }
    }
}
