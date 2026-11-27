<?php

declare(strict_types=1);

namespace App\Core;

use ErrorException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Gestionnaires PHP globaux : les avertissements deviennent des exceptions,
 * les exceptions non interceptées et les erreurs fatales sont journalisées.
 */
final class ErrorHandler
{
    private static bool $registered = false;

    public static function register(LoggerInterface $logger, bool $debug): void
    {
        if (self::$registered) {
            return;
        }
        self::$registered = true;

        error_reporting(E_ALL);
        ini_set('display_errors', $debug && PHP_SAPI === 'cli' ? '1' : '0');
        ini_set('log_errors', '1');

        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            if (!(error_reporting() & $severity)) {
                return false; // erreur masquée par l'opérateur @
            }
            throw new ErrorException($message, 0, $severity, $file, $line);
        });

        set_exception_handler(static function (Throwable $e) use ($logger, $debug): void {
            $logger->critical($e->getMessage(), ['exception' => $e]);

            if (PHP_SAPI === 'cli') {
                fwrite(STDERR, $e::class . ': ' . $e->getMessage() . PHP_EOL);
                if ($debug) {
                    fwrite(STDERR, $e->getTraceAsString() . PHP_EOL);
                }
                exit(1);
            }

            if (!headers_sent()) {
                http_response_code(500);
                header('Content-Type: text/html; charset=UTF-8');
            }
            echo '<!doctype html><html lang="fr"><meta charset="utf-8"><title>Erreur</title>'
                . '<body style="font-family:sans-serif;padding:3rem"><h1>Une erreur est survenue</h1>'
                . ($debug ? '<pre>' . htmlspecialchars((string) $e, ENT_QUOTES, 'UTF-8') . '</pre>' : '')
                . '</body></html>';
        });

        register_shutdown_function(static function () use ($logger): void {
            $error = error_get_last();
            if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                $logger->critical('Erreur fatale : ' . $error['message'], ['file' => $error['file'], 'line' => $error['line']]);
            }
        });
    }
}
