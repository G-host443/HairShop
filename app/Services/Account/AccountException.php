<?php

declare(strict_types=1);

namespace App\Services\Account;

use RuntimeException;

/** Action impossible sur le compte, message affichable. */
final class AccountException extends RuntimeException
{
}
