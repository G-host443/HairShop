<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Services\Mail\MailerInterface;
use App\Services\Mail\MailMessage;
use RuntimeException;

/** Mailer de test qui échoue toujours (serveur SMTP indisponible). */
final class FailingMailer implements MailerInterface
{
    public int $attempts = 0;

    public function send(MailMessage $message): void
    {
        $this->attempts++;
        throw new RuntimeException('Connexion SMTP refusée');
    }
}
