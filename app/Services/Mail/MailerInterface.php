<?php

declare(strict_types=1);

namespace App\Services\Mail;

interface MailerInterface
{
    /**
     * @throws \RuntimeException si l'envoi échoue
     */
    public function send(MailMessage $message): void;
}
