<?php

declare(strict_types=1);

namespace App\Services\Mail;

use RuntimeException;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Throwable;

/**
 * Envoi réel par SMTP (Symfony Mailer).
 * DSN : smtp://utilisateur:motdepasse@smtp.exemple.fr:587 (caractères spéciaux du mot de passe encodés en URL).
 */
final class SmtpMailer implements MailerInterface
{
    private ?Mailer $mailer = null;

    public function __construct(
        private readonly string $dsn,
        private readonly string $fromAddress,
        private readonly string $fromName,
    ) {
    }

    public function send(MailMessage $message): void
    {
        $this->mailer ??= new Mailer(Transport::fromDsn($this->dsn));

        $email = (new Email())
            ->from(new Address($this->fromAddress, $this->fromName))
            ->to(new Address($message->to, $message->toName ?? ''))
            ->subject($message->subject)
            ->text($message->text)
            ->html($message->html);

        if ($message->replyTo !== null) {
            $email->replyTo($message->replyTo);
        }

        try {
            $this->mailer->send($email);
        } catch (Throwable $e) {
            throw new RuntimeException('Envoi SMTP impossible : ' . $e->getMessage(), 0, $e);
        }
    }
}
