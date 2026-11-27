<?php

declare(strict_types=1);

namespace App\Services\Notification;

use RuntimeException;

/** La notification n'a plus lieu d'être (rendez-vous annulé, passé, supprimé…). */
final class NotificationObsolete extends RuntimeException
{
}
