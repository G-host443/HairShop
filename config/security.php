<?php

declare(strict_types=1);

return [
    // Proxys de confiance (adresses ou plages CIDR, séparées par des virgules), par exemple le répartiteur
    // de charge de l'hébergeur : leurs en-têtes X-Forwarded-For / X-Forwarded-Proto sont alors pris en compte.
    'trusted_proxies' => array_values(array_filter(array_map('trim', explode(',', (string) env('TRUSTED_PROXIES', ''))))),

    'session' => [
        'name' => 'hsg_session',
        'path' => storage_path('sessions'),
        // Déconnexion après cette durée d'inactivité (minutes).
        'idle_timeout' => (int) env('SESSION_IDLE_MINUTES', 120),
        'same_site' => 'Lax',
    ],

    // Valeurs par défaut ; les réglages « security.* » de site_settings sont prioritaires.
    'login' => [
        'max_attempts' => 5,          // échecs autorisés par e-mail sur la fenêtre
        'max_attempts_per_ip' => 20,  // échecs autorisés par adresse IP sur la fenêtre
        'lockout_minutes' => 15,
    ],

    'password' => [
        'min_length' => 12,
        'max_length' => 128,
    ],

    'password_reset' => [
        'expires_minutes' => 60,
        'max_requests_per_hour' => 3,
    ],
];
