<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Services\Mail\MailerInterface;
use App\Services\Mail\MailMessage;

/** Mailer de test : conserve les messages en mémoire. */
final class ArrayMailer implements MailerInterface
{
    /** @var list<MailMessage> */
    public array $sent = [];

    public function send(MailMessage $message): void
    {
        $this->sent[] = $message;
    }

    public function last(): ?MailMessage
    {
        return $this->sent === [] ? null : $this->sent[count($this->sent) - 1];
    }
}
