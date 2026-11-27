<?php

declare(strict_types=1);

/**
 * Routes publiques du site.
 *
 * @var App\Core\Router $router
 */

use App\Controllers\Front\BlogController;
use App\Controllers\Front\BookingController;
use App\Controllers\Front\ContactController;
use App\Controllers\Front\GalleryPageController;
use App\Controllers\Front\ReviewsPageController;
use App\Controllers\Front\ManageBookingController;
use App\Controllers\Front\HealthController;
use App\Controllers\Front\HomeController;
use App\Controllers\Front\PageController;
use App\Controllers\Front\SeoController;
use App\Controllers\Front\ServicesController;

$router->get('/', [HomeController::class, 'index'], 'home');
$router->get('/sante', [HealthController::class, 'show'], 'health');
$router->get('/robots.txt', [SeoController::class, 'robots'], 'robots');
$router->get('/sitemap.xml', [SeoController::class, 'sitemap'], 'sitemap');
$router->post('/contact', [ContactController::class, 'store'], 'contact.send');
$router->get('/reserver', [BookingController::class, 'show'], 'booking');
$router->post('/reserver', [BookingController::class, 'store'], 'booking.store');
$router->get('/reserver/confirmation', [BookingController::class, 'confirmation'], 'booking.confirmation');
$router->get('/rdv/{token:[a-f0-9]{64}}', [ManageBookingController::class, 'show'], 'booking.manage');
$router->post('/rdv/{token:[a-f0-9]{64}}/annuler', [ManageBookingController::class, 'cancel'], 'booking.cancel');
$router->post('/rdv/{token:[a-f0-9]{64}}/avis', [ManageBookingController::class, 'review'], 'booking.review');
$router->get('/galerie', [GalleryPageController::class, 'index'], 'gallery');
$router->get('/blog', [BlogController::class, 'index'], 'blog');
$router->get('/blog/categorie/{slug:[a-z0-9]+(?:-[a-z0-9]+)*}', [BlogController::class, 'category'], 'blog.category');
$router->get('/blog/tag/{slug:[a-z0-9]+(?:-[a-z0-9]+)*}', [BlogController::class, 'tag'], 'blog.tag');
$router->get('/blog/{slug:[a-z0-9]+(?:-[a-z0-9]+)*}', [BlogController::class, 'show'], 'blog.show');
$router->get('/avis', [ReviewsPageController::class, 'index'], 'reviews');
$router->get('/prestations', [ServicesController::class, 'index'], 'services.index');
$router->get('/prestations/{slug:[a-z0-9]+(?:-[a-z0-9]+)*}', [ServicesController::class, 'show'], 'services.show');

// En dernier : pages de contenu publiées (/contact, /mentions-legales…).
$router->get('/{slug:[a-z0-9]+(?:-[a-z0-9]+)*}', [PageController::class, 'show'], 'page');
