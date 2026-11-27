<?php

declare(strict_types=1);

/**
 * Espace cliente (/compte).
 *
 * @var App\Core\Router $router
 */

use App\Controllers\Account\AccountAuthController;
use App\Controllers\Account\AccountController;
use App\Core\Router;
use App\Middleware\AuthenticateCustomer;
use App\Middleware\RedirectIfCustomer;

$router->group(['prefix' => '/compte'], static function (Router $router): void {

    // --- Visiteuses non connectées ---
    $router->group(['middleware' => [RedirectIfCustomer::class]], static function (Router $router): void {
        $router->get('/connexion', [AccountAuthController::class, 'showLogin'], 'account.login');
        $router->post('/connexion', [AccountAuthController::class, 'login'], 'account.login.attempt');
        $router->get('/inscription', [AccountAuthController::class, 'showRegister'], 'account.register');
        $router->post('/inscription', [AccountAuthController::class, 'register'], 'account.register.store');
        $router->get('/mot-de-passe-oublie', [AccountAuthController::class, 'showForgot'], 'account.password.request');
        $router->post('/mot-de-passe-oublie', [AccountAuthController::class, 'sendResetLink'], 'account.password.email');
    });

    // Liens reçus par e-mail : utilisables connectée ou non.
    $router->get('/reinitialisation/{token:[a-f0-9]{64}}', [AccountAuthController::class, 'showReset'], 'account.password.reset');
    $router->post('/reinitialisation/{token:[a-f0-9]{64}}', [AccountAuthController::class, 'reset'], 'account.password.update');
    $router->get('/verifier/{token:[A-Za-z0-9_-]+\.[a-f0-9]{64}}', [AccountAuthController::class, 'verify'], 'account.verify');

    // --- Espace connecté ---
    $router->group(['middleware' => [AuthenticateCustomer::class]], static function (Router $router): void {
        $router->get('', [AccountController::class, 'dashboard'], 'account');
        $router->post('/deconnexion', [AccountAuthController::class, 'logout'], 'account.logout');
        $router->post('/verifier/renvoyer', [AccountAuthController::class, 'resendVerification'], 'account.verify.resend');
        $router->get('/rendez-vous/{reference:HSG-[A-Z0-9]{6}}', [AccountController::class, 'showBooking'], 'account.booking');
        $router->post('/rendez-vous/{reference:HSG-[A-Z0-9]{6}}/annuler', [AccountController::class, 'cancelBooking'], 'account.booking.cancel');
        $router->post('/rendez-vous/{reference:HSG-[A-Z0-9]{6}}/avis', [AccountController::class, 'review'], 'account.bookings.review');
        $router->get('/favoris', [AccountController::class, 'favorites'], 'account.favorites');
        $router->post('/favoris/{serviceId:\d+}', [AccountController::class, 'toggleFavorite'], 'account.favorites.toggle');
        $router->get('/profil', [AccountController::class, 'profile'], 'account.profile');
        $router->post('/profil', [AccountController::class, 'updateProfile'], 'account.profile.update');
        $router->post('/mot-de-passe', [AccountController::class, 'updatePassword'], 'account.password.change');
        $router->get('/export', [AccountController::class, 'export'], 'account.export');
        $router->post('/supprimer', [AccountController::class, 'delete'], 'account.delete');
    });
});
