<?php

declare(strict_types=1);

namespace App\Services\Mail;

/**
 * Pilote de développement : écrit chaque e-mail (version texte) dans storage/logs/mail-AAAA-MM-JJ.log
 * au lieu de l'envoyer. Remplacé par le pilote SMTP en production (étape 8).
 */
final class LogMailer implements MailerInterface
{
    public function __construct(
        private readonly string $directory,
        private readonly string $fromAddress,
        private readonly string $fromName,
    ) {
    }

    public function send(MailMessage $message): void
    {
        if (!is_dir($this->directory)) {
            mkdir($this->directory, 0770, true);
        }

        $entry = implode(PHP_EOL, [
            str_repeat('=', 72),
            'Date    : ' . date('Y-m-d H:i:s'),
            "De      : {$this->fromName} <{$this->fromAddress}>",
            'À       : ' . ($message->toName !== null ? "{$message->toName} <{$message->to}>" : $message->to),
            'Sujet   : ' . $message->subject,
            str_repeat('-', 72),
            $message->text,
            '',
        ]);

        $file = $this->directory . DIRECTORY_SEPARATOR . 'mail-' . date('Y-m-d') . '.log';
        if (file_put_contents($file, $entry . PHP_EOL, FILE_APPEND | LOCK_EX) === false) {
            throw new \RuntimeException("Impossible d'écrire l'e-mail dans {$file}");
        }
    }
}
