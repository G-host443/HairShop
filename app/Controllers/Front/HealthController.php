<?php

declare(strict_types=1);

namespace App\Controllers\Front;

use App\Core\Database;
use App\Core\Http\Response;
use Throwable;

/**
 * Sonde de supervision : GET /sante → 200 si l'application et la base répondent, 503 sinon.
 * Ne divulgue aucune information technique.
 */
final class HealthController
{
    public function __construct(private readonly Database $db)
    {
    }

    public function show(): Response
    {
        try {
            $this->db->fetchValue('SELECT 1');
            $database = 'ok';
        } catch (Throwable) {
            $database = 'indisponible';
        }

        $healthy = $database === 'ok';

        return Response::json(
            ['status' => $healthy ? 'ok' : 'degrade', 'database' => $database, 'time' => date(DATE_ATOM)],
            $healthy ? 200 : 503,
        )->withHeader('Cache-Control', 'no-store');
    }
}
