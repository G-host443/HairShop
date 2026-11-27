<?php

declare(strict_types=1);

return [
    // Dossier physique des fichiers envoyés et URL publique correspondante.
    'upload_path' => public_path('uploads'),
    'upload_url' => '/uploads',

    // Types acceptés, détectés par le contenu du fichier (jamais par l'extension ou le navigateur).
    // Le SVG est volontairement exclu : il peut contenir du JavaScript.
    'allowed' => [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
        'image/avif' => 'avif',
        'video/mp4' => 'mp4',
        'video/webm' => 'webm',
    ],

    'max_image_bytes' => 15 * 1024 * 1024,
    'max_video_bytes' => 60 * 1024 * 1024,   // limité aussi par upload_max_filesize de php.ini
    'max_megapixels' => 40,                   // protège la mémoire contre les images géantes

    // Images : l'original est ré-encodé (métadonnées supprimées) à cette largeur maximale,
    // puis des déclinaisons WebP sont produites pour l'affichage.
    'original_max_width' => 2560,
    'variants' => [
        'thumb' => 400,
        'medium' => 1024,
        'large' => 1920,
    ],
    'quality' => [
        'jpeg' => 85,
        'webp' => 80,
    ],

    'per_page' => 24,
];
