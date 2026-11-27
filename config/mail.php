<?php

declare(strict_types=1);

return [
    // log  : les e-mails sont écrits dans storage/logs/mail-AAAA-MM-JJ.log (développement) ;
    // smtp : envoi réel via MAIL_DSN.
    'driver' => env('MAIL_DRIVER', 'log'),
    // Ex. : smtp://contact%40mondomaine.fr:motdepasse@smtp.mondomaine.fr:587
    'dsn' => env('MAIL_DSN', ''),
    // Expéditeur par défaut ; les paramètres « Notifications » du site sont prioritaires.
    'from_address' => env('MAIL_FROM_ADDRESS', 'no-reply@localhost'),
    'from_name' => env('MAIL_FROM_NAME', 'Hair_style by Garance'),
    // Nombre maximal de tentatives avant d'abandonner un envoi.
    'max_attempts' => 5,
];
