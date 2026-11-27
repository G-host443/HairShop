<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Controller;
use App\Core\Http\Response;
use App\Core\Router;
use App\Core\Session\Session;
use App\Core\View;
use App\Services\Auth\AuthService;
use App\Services\Dashboard\DashboardService;
use App\Services\Dashboard\LaunchChecklist;

/**
 * Tableau de bord : indicateurs, graphiques (Chart.js), rendez-vous du jour et éléments à traiter.
 * Chaque bloc n'est affiché qu'aux comptes qui ont la permission du module concerné.
 */
final class DashboardController extends Controller
{
    public function __construct(
        View $view,
        Router $router,
        Session $session,
        private readonly DashboardService $dashboard,
        private readonly AuthService $auth,
        private readonly LaunchChecklist $checklist,
    ) {
        parent::__construct($view, $router, $session);
    }

    public function index(): Response
    {
        $canBookings = $this->auth->can('bookings.view');
        // Chiffre d'affaires et fréquentation : informations de gestion, réservées à la permission stats.view.
        $canStats = $this->auth->can('stats.view');

        $charts = [];
        if ($canBookings) {
            $charts['bookings'] = $this->dashboard->bookingsPerWeek();
            $charts['services'] = $this->dashboard->popularServices();
        }
        if ($canStats) {
            $charts['revenue'] = $this->dashboard->revenuePerMonth();
            $charts['visits'] = $this->dashboard->visitsPerDay();
        }

        return $this->render('admin/dashboard.twig', [
            'kpis' => $this->dashboard->kpis(),
            'today' => $canBookings ? $this->dashboard->today() : [],
            'pending' => $canBookings ? $this->dashboard->pendingBookings() : [],
            'top_pages' => isset($charts['visits']) ? $this->dashboard->topPages() : [],
            'charts' => $charts,
            'charts_json' => json_encode($charts, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'can_stats' => $canStats,
            'now' => $this->dashboard->now(),
            'launch' => $this->auth->can('settings.manage') ? $this->checklist->items() : [],
        ]);
    }
}
