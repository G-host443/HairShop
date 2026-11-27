<?php

declare(strict_types=1);

/**
 * Routes de l'administration (/admin).
 *
 * @var App\Core\Router $router
 */

use App\Controllers\Admin\AppearanceController;
use App\Controllers\Admin\AuditLogController;
use App\Controllers\Admin\AuthController;
use App\Controllers\Admin\CustomerAdminController;
use App\Controllers\Admin\MessageController;
use App\Controllers\Admin\BlogPostController;
use App\Controllers\Admin\BlogTaxonomyController;
use App\Controllers\Admin\BookingAdminController;
use App\Controllers\Admin\CatalogSettingsController;
use App\Controllers\Admin\CategoryController;
use App\Controllers\Admin\NotificationAdminController;
use App\Controllers\Admin\PageAdminController;
use App\Controllers\Admin\PageSectionController;
use App\Controllers\Admin\PreviewController;
use App\Controllers\Admin\ScheduleController;
use App\Controllers\Admin\ServiceController;
use App\Controllers\Admin\ServicePricingController;
use App\Controllers\Admin\DashboardController;
use App\Controllers\Admin\GalleryController;
use App\Controllers\Admin\PromotionController;
use App\Controllers\Admin\ReviewController;
use App\Controllers\Admin\MediaController;
use App\Controllers\Admin\MediaFolderController;
use App\Controllers\Admin\SettingsController;
use App\Controllers\Admin\PasswordResetController;
use App\Controllers\Admin\TwoFactorController;
use App\Controllers\Admin\ProfileController;
use App\Core\Router;
use App\Middleware\Authenticate;
use App\Middleware\RedirectIfAuthenticated;
use App\Middleware\RequirePermission;

$router->group(['prefix' => '/admin'], static function (Router $router): void {

    // --- Accès public (visiteurs non connectés) ---
    $router->group(['middleware' => [RedirectIfAuthenticated::class]], static function (Router $router): void {
        $router->get('/connexion', [AuthController::class, 'showLogin'], 'admin.login');
        $router->post('/connexion', [AuthController::class, 'login'], 'admin.login.attempt');
        $router->get('/mot-de-passe-oublie', [PasswordResetController::class, 'showRequestForm'], 'admin.password.request');
        $router->post('/mot-de-passe-oublie', [PasswordResetController::class, 'sendLink'], 'admin.password.email');
        // Deuxième étape de connexion (double authentification, si elle est activée)
        $router->get('/connexion/verification', [AuthController::class, 'showTwoFactor'], 'admin.login.two_factor');
        $router->post('/connexion/verification', [AuthController::class, 'verifyTwoFactor'], 'admin.login.two_factor.verify');
    });

    // Le lien reçu par e-mail fonctionne aussi pour une session déjà ouverte.
    $router->get('/reinitialisation/{token:[a-f0-9]{64}}', [PasswordResetController::class, 'showResetForm'], 'admin.password.reset');
    $router->post('/reinitialisation/{token:[a-f0-9]{64}}', [PasswordResetController::class, 'reset'], 'admin.password.update');

    // --- Espace connecté ---
    $router->group(['middleware' => [Authenticate::class]], static function (Router $router): void {
        $router->post('/deconnexion', [AuthController::class, 'logout'], 'admin.logout');
        $router->get('/profil/mot-de-passe', [ProfileController::class, 'editPassword'], 'admin.profile.password');
        $router->post('/profil/mot-de-passe', [ProfileController::class, 'updatePassword'], 'admin.profile.password.update');
        $router->get('/profil/double-authentification', [TwoFactorController::class, 'show'], 'admin.profile.two_factor');
        $router->post('/profil/double-authentification', [TwoFactorController::class, 'confirm'], 'admin.profile.two_factor.confirm');
        $router->post('/profil/double-authentification/codes', [TwoFactorController::class, 'regenerateCodes'], 'admin.profile.two_factor.codes');
        $router->post('/profil/double-authentification/desactiver', [TwoFactorController::class, 'disable'], 'admin.profile.two_factor.disable');

        $router->get('', [DashboardController::class, 'index'], 'admin.dashboard', [RequirePermission::class . ':dashboard.view']);

        // Fiches clientes (consultation : customers.view ; modification et notes : customers.manage)
        $router->group(['prefix' => '/clientes', 'middleware' => [RequirePermission::class . ':customers.view']], static function (Router $router): void {
            $manage = [RequirePermission::class . ':customers.manage'];
            $router->get('', [CustomerAdminController::class, 'index'], 'admin.customers');
            $router->get('/{id:\d+}', [CustomerAdminController::class, 'show'], 'admin.customers.show');
            $router->post('/{id:\d+}', [CustomerAdminController::class, 'update'], 'admin.customers.update', $manage);
            $router->post('/{id:\d+}/notes', [CustomerAdminController::class, 'addNote'], 'admin.customers.notes.store', $manage);
            $router->post('/{id:\d+}/notes/{noteId:\d+}/supprimer', [CustomerAdminController::class, 'deleteNote'], 'admin.customers.notes.destroy', $manage);
        });

        // Messages du formulaire de contact
        $router->group(['prefix' => '/messages', 'middleware' => [RequirePermission::class . ':messages.view']], static function (Router $router): void {
            $router->get('', [MessageController::class, 'index'], 'admin.messages');
            $router->get('/{id:\d+}', [MessageController::class, 'show'], 'admin.messages.show');
            $router->post('/{id:\d+}/non-lu', [MessageController::class, 'markUnread'], 'admin.messages.unread');
            $router->post('/{id:\d+}/supprimer', [MessageController::class, 'destroy'], 'admin.messages.destroy');
        });

        // Journal d'activité
        $router->get('/journal', [AuditLogController::class, 'index'], 'admin.audit', [RequirePermission::class . ':audit.view']);

        // Rendez-vous
        $router->group(['prefix' => '/reservations', 'middleware' => [RequirePermission::class . ':bookings.view']], static function (Router $router): void {
            $manage = [RequirePermission::class . ':bookings.manage'];
            $router->get('', [BookingAdminController::class, 'index'], 'admin.bookings');
            $router->get('/calendrier', [BookingAdminController::class, 'calendar'], 'admin.bookings.calendar');
            $router->get('/export', [BookingAdminController::class, 'export'], 'admin.bookings.export', $manage);
            $router->get('/nouvelle', [BookingAdminController::class, 'create'], 'admin.bookings.create', $manage);
            $router->post('', [BookingAdminController::class, 'store'], 'admin.bookings.store', $manage);
            $router->get('/{id:\d+}', [BookingAdminController::class, 'show'], 'admin.bookings.show');
            $router->post('/{id:\d+}/statut', [BookingAdminController::class, 'updateStatus'], 'admin.bookings.status', $manage);
            $router->post('/{id:\d+}/deplacer', [BookingAdminController::class, 'reschedule'], 'admin.bookings.reschedule', $manage);
            $router->post('/{id:\d+}/prix', [BookingAdminController::class, 'updatePrice'], 'admin.bookings.price', $manage);
        });

        // Catalogue : prestations, catégories, grilles de prix, tailles / longueurs, options
        $router->group(['prefix' => '/prestations', 'middleware' => [RequirePermission::class . ':catalog.manage']], static function (Router $router): void {
            $router->get('', [ServiceController::class, 'index'], 'admin.services');
            $router->get('/nouvelle', [ServiceController::class, 'create'], 'admin.services.create');
            $router->post('', [ServiceController::class, 'store'], 'admin.services.store');
            $router->post('/ordre', [ServiceController::class, 'reorder'], 'admin.services.reorder');
            $router->get('/{id:\d+}', [ServiceController::class, 'edit'], 'admin.services.edit');
            $router->post('/{id:\d+}', [ServiceController::class, 'update'], 'admin.services.update');
            $router->post('/{id:\d+}/supprimer', [ServiceController::class, 'destroy'], 'admin.services.destroy');

            $router->get('/{id:\d+}/grille', [ServicePricingController::class, 'edit'], 'admin.services.grid');
            $router->post('/{id:\d+}/grille', [ServicePricingController::class, 'update'], 'admin.services.grid.update');
            $router->post('/{id:\d+}/variantes', [ServicePricingController::class, 'addVariant'], 'admin.services.variants.store');
            $router->post('/{id:\d+}/variantes/{variantId:\d+}/supprimer', [ServicePricingController::class, 'removeVariant'], 'admin.services.variants.destroy');

            $router->get('/categories', [CategoryController::class, 'index'], 'admin.categories');
            $router->post('/categories', [CategoryController::class, 'store'], 'admin.categories.store');
            $router->post('/categories/ordre', [CategoryController::class, 'reorder'], 'admin.categories.reorder');
            $router->get('/categories/{id:\d+}', [CategoryController::class, 'edit'], 'admin.categories.edit');
            $router->post('/categories/{id:\d+}', [CategoryController::class, 'update'], 'admin.categories.update');
            $router->post('/categories/{id:\d+}/supprimer', [CategoryController::class, 'destroy'], 'admin.categories.destroy');

            $router->get('/tailles-longueurs', [CatalogSettingsController::class, 'dimensions'], 'admin.catalog.dimensions');
            $router->post('/tailles', [CatalogSettingsController::class, 'storeSize'], 'admin.catalog.sizes.store');
            $router->post('/tailles/{id:\d+}', [CatalogSettingsController::class, 'updateSize'], 'admin.catalog.sizes.update');
            $router->post('/tailles/{id:\d+}/supprimer', [CatalogSettingsController::class, 'destroySize'], 'admin.catalog.sizes.destroy');
            $router->post('/longueurs', [CatalogSettingsController::class, 'storeLength'], 'admin.catalog.lengths.store');
            $router->post('/longueurs/{id:\d+}', [CatalogSettingsController::class, 'updateLength'], 'admin.catalog.lengths.update');
            $router->post('/longueurs/{id:\d+}/supprimer', [CatalogSettingsController::class, 'destroyLength'], 'admin.catalog.lengths.destroy');
            $router->post('/ordre/{kind:tailles|longueurs}', [CatalogSettingsController::class, 'reorder'], 'admin.catalog.reorder');

            $router->get('/options', [CatalogSettingsController::class, 'options'], 'admin.catalog.options');
            $router->post('/options/groupes', [CatalogSettingsController::class, 'storeGroup'], 'admin.catalog.groups.store');
            $router->post('/options/groupes/{id:\d+}', [CatalogSettingsController::class, 'updateGroup'], 'admin.catalog.groups.update');
            $router->post('/options/groupes/{id:\d+}/supprimer', [CatalogSettingsController::class, 'destroyGroup'], 'admin.catalog.groups.destroy');
            $router->post('/options', [CatalogSettingsController::class, 'storeOption'], 'admin.catalog.options.store');
            $router->post('/options/{id:\d+}', [CatalogSettingsController::class, 'updateOption'], 'admin.catalog.options.update');
            $router->post('/options/{id:\d+}/supprimer', [CatalogSettingsController::class, 'destroyOption'], 'admin.catalog.options.destroy');
        });

        // Horaires, fermetures, créneaux bloqués
        $router->group(['prefix' => '/horaires', 'middleware' => [RequirePermission::class . ':schedule.manage']], static function (Router $router): void {
            $router->get('', [ScheduleController::class, 'index'], 'admin.schedule');
            $router->post('/semaine', [ScheduleController::class, 'updateWeekly'], 'admin.schedule.weekly');
            $router->post('/exceptionnels', [ScheduleController::class, 'storeSpecialHours'], 'admin.schedule.special.store');
            $router->post('/exceptionnels/{id:\d+}/supprimer', [ScheduleController::class, 'destroySpecialHours'], 'admin.schedule.special.destroy');
            $router->post('/fermetures', [ScheduleController::class, 'storeBlockedDates'], 'admin.schedule.closures.store');
            $router->post('/fermetures/{id:\d+}/supprimer', [ScheduleController::class, 'destroyBlockedDates'], 'admin.schedule.closures.destroy');
            $router->post('/creneaux-bloques', [ScheduleController::class, 'storeBlockedSlot'], 'admin.schedule.slots.store');
            $router->post('/creneaux-bloques/{id:\d+}/supprimer', [ScheduleController::class, 'destroyBlockedSlot'], 'admin.schedule.slots.destroy');
        });

        // Galerie de réalisations
        $router->group(['prefix' => '/galerie', 'middleware' => [RequirePermission::class . ':gallery.manage']], static function (Router $router): void {
            $router->get('', [GalleryController::class, 'index'], 'admin.gallery');
            $router->get('/nouvelle', [GalleryController::class, 'create'], 'admin.gallery.create');
            $router->post('', [GalleryController::class, 'store'], 'admin.gallery.store');
            $router->post('/ordre', [GalleryController::class, 'reorder'], 'admin.gallery.reorder');
            $router->get('/{id:\d+}', [GalleryController::class, 'edit'], 'admin.gallery.edit');
            $router->post('/{id:\d+}', [GalleryController::class, 'update'], 'admin.gallery.update');
            $router->post('/{id:\d+}/{flag:avant|publication}', [GalleryController::class, 'toggle'], 'admin.gallery.toggle');
            $router->post('/{id:\d+}/supprimer', [GalleryController::class, 'destroy'], 'admin.gallery.destroy');
        });

        // Avis clientes
        $router->group(['prefix' => '/avis', 'middleware' => [RequirePermission::class . ':reviews.moderate']], static function (Router $router): void {
            $router->get('', [ReviewController::class, 'index'], 'admin.reviews');
            $router->get('/nouveau', [ReviewController::class, 'create'], 'admin.reviews.create');
            $router->post('', [ReviewController::class, 'store'], 'admin.reviews.store');
            $router->get('/{id:\d+}', [ReviewController::class, 'edit'], 'admin.reviews.edit');
            $router->post('/{id:\d+}', [ReviewController::class, 'update'], 'admin.reviews.update');
            $router->post('/{id:\d+}/{decision:approuver|refuser}', [ReviewController::class, 'moderate'], 'admin.reviews.moderate');
            $router->post('/{id:\d+}/mise-en-avant', [ReviewController::class, 'feature'], 'admin.reviews.feature');
            $router->post('/{id:\d+}/supprimer', [ReviewController::class, 'destroy'], 'admin.reviews.destroy');
        });

        // Promotions
        $router->group(['prefix' => '/promotions', 'middleware' => [RequirePermission::class . ':promotions.manage']], static function (Router $router): void {
            $router->get('', [PromotionController::class, 'index'], 'admin.promotions');
            $router->get('/nouvelle', [PromotionController::class, 'create'], 'admin.promotions.create');
            $router->post('', [PromotionController::class, 'store'], 'admin.promotions.store');
            $router->get('/{id:\d+}', [PromotionController::class, 'edit'], 'admin.promotions.edit');
            $router->post('/{id:\d+}', [PromotionController::class, 'update'], 'admin.promotions.update');
            $router->post('/{id:\d+}/activation', [PromotionController::class, 'toggle'], 'admin.promotions.toggle');
            $router->post('/{id:\d+}/supprimer', [PromotionController::class, 'destroy'], 'admin.promotions.destroy');
        });

        // Blog
        $router->group(['prefix' => '/blog', 'middleware' => [RequirePermission::class . ':blog.manage']], static function (Router $router): void {
            $router->get('', [BlogPostController::class, 'index'], 'admin.blog');
            $router->get('/nouvel-article', [BlogPostController::class, 'create'], 'admin.blog.create');
            $router->post('', [BlogPostController::class, 'store'], 'admin.blog.store');
            $router->get('/{id:\d+}', [BlogPostController::class, 'edit'], 'admin.blog.edit');
            $router->post('/{id:\d+}', [BlogPostController::class, 'update'], 'admin.blog.update');
            $router->get('/{id:\d+}/apercu', [BlogPostController::class, 'preview'], 'admin.blog.preview');
            $router->post('/{id:\d+}/supprimer', [BlogPostController::class, 'destroy'], 'admin.blog.destroy');

            $router->get('/categories-et-tags', [BlogTaxonomyController::class, 'index'], 'admin.blog.taxonomy');
            $router->post('/categories', [BlogTaxonomyController::class, 'storeCategory'], 'admin.blog.categories.store');
            $router->post('/categories/ordre', [BlogTaxonomyController::class, 'reorderCategories'], 'admin.blog.categories.reorder');
            $router->post('/categories/{id:\d+}', [BlogTaxonomyController::class, 'updateCategory'], 'admin.blog.categories.update');
            $router->post('/categories/{id:\d+}/supprimer', [BlogTaxonomyController::class, 'destroyCategory'], 'admin.blog.categories.destroy');
            $router->post('/tags/{id:\d+}', [BlogTaxonomyController::class, 'updateTag'], 'admin.blog.tags.update');
            $router->post('/tags/{id:\d+}/supprimer', [BlogTaxonomyController::class, 'destroyTag'], 'admin.blog.tags.destroy');
        });

        // Pages et constructeur de sections (brouillon, aperçu, publication)
        $router->group(['prefix' => '/pages', 'middleware' => [RequirePermission::class . ':content.manage']], static function (Router $router): void {
            $router->get('', [PageAdminController::class, 'index'], 'admin.pages');
            $router->post('', [PageAdminController::class, 'store'], 'admin.pages.store');
            $router->get('/{id:\d+}', [PageAdminController::class, 'edit'], 'admin.pages.edit');
            $router->post('/{id:\d+}', [PageAdminController::class, 'update'], 'admin.pages.update');
            $router->post('/{id:\d+}/publier', [PageAdminController::class, 'publish'], 'admin.pages.publish');
            $router->post('/{id:\d+}/annuler-modifications', [PageAdminController::class, 'discard'], 'admin.pages.discard');
            $router->post('/{id:\d+}/retirer', [PageAdminController::class, 'unpublish'], 'admin.pages.unpublish');
            $router->post('/{id:\d+}/supprimer', [PageAdminController::class, 'destroy'], 'admin.pages.destroy');
            $router->get('/{id:\d+}/apercu', [PreviewController::class, 'page'], 'admin.pages.preview');
            $router->get('/{id:\d+}/apercu/cadre', [PreviewController::class, 'pageFrame'], 'admin.pages.preview.frame');

            $router->post('/{id:\d+}/sections', [PageSectionController::class, 'store'], 'admin.pages.sections.store');
            $router->post('/{id:\d+}/sections/ordre', [PageSectionController::class, 'reorder'], 'admin.pages.sections.reorder');
            $router->get('/{id:\d+}/sections/{sectionId:\d+}', [PageSectionController::class, 'edit'], 'admin.pages.sections.edit');
            $router->post('/{id:\d+}/sections/{sectionId:\d+}', [PageSectionController::class, 'update'], 'admin.pages.sections.update');
            $router->post('/{id:\d+}/sections/{sectionId:\d+}/visibilite', [PageSectionController::class, 'toggle'], 'admin.pages.sections.toggle');
            $router->post('/{id:\d+}/sections/{sectionId:\d+}/dupliquer', [PageSectionController::class, 'duplicate'], 'admin.pages.sections.duplicate');
            $router->post('/{id:\d+}/sections/{sectionId:\d+}/supprimer', [PageSectionController::class, 'destroy'], 'admin.pages.sections.destroy');
            $router->post('/{id:\d+}/sections/{sectionId:\d+}/restaurer', [PageSectionController::class, 'restore'], 'admin.pages.sections.restore');
        });

        // Apparence : templates et personnalisation
        $router->group(['prefix' => '/apparence', 'middleware' => [RequirePermission::class . ':appearance.manage']], static function (Router $router): void {
            $router->get('', [AppearanceController::class, 'templates'], 'admin.appearance');
            $router->post('/templates/{slug:[a-z0-9]+(?:-[a-z0-9]+)*}/activer', [AppearanceController::class, 'activate'], 'admin.appearance.activate');
            $router->get('/templates/{slug:[a-z0-9]+(?:-[a-z0-9]+)*}/apercu', [PreviewController::class, 'template'], 'admin.appearance.template.preview');
            $router->get('/templates/{slug:[a-z0-9]+(?:-[a-z0-9]+)*}/apercu/cadre', [PreviewController::class, 'templateFrame'], 'admin.appearance.template.frame');
            $router->get('/personnaliser', [AppearanceController::class, 'customize'], 'admin.appearance.customize');
            $router->post('/personnaliser', [AppearanceController::class, 'save'], 'admin.appearance.save');
            $router->post('/publier', [AppearanceController::class, 'publish'], 'admin.appearance.publish');
            $router->post('/annuler-modifications', [AppearanceController::class, 'discard'], 'admin.appearance.discard');
            $router->post('/valeurs-origine', [AppearanceController::class, 'reset'], 'admin.appearance.reset');
            $router->get('/apercu', [PreviewController::class, 'theme'], 'admin.appearance.preview');
            $router->get('/apercu/cadre', [PreviewController::class, 'themeFrame'], 'admin.appearance.preview.frame');
        });

        // Journal des e-mails
        $router->group(['prefix' => '/notifications', 'middleware' => [RequirePermission::class . ':settings.manage']], static function (Router $router): void {
            $router->get('', [NotificationAdminController::class, 'index'], 'admin.notifications');
            $router->post('/test', [NotificationAdminController::class, 'test'], 'admin.notifications.test');
            $router->post('/{id:\d+}/renvoyer', [NotificationAdminController::class, 'retry'], 'admin.notifications.retry');
        });

        // Paramètres du site
        $router->group(['prefix' => '/parametres', 'middleware' => [RequirePermission::class . ':settings.manage']], static function (Router $router): void {
            $router->get('[/{group:[a-z]+}]', [SettingsController::class, 'edit'], 'admin.settings');
            $router->post('/{group:[a-z]+}', [SettingsController::class, 'update'], 'admin.settings.update');
        });

        // Bibliothèque de médias
        $router->group(['prefix' => '/medias', 'middleware' => [RequirePermission::class . ':media.manage']], static function (Router $router): void {
            $router->get('', [MediaController::class, 'index'], 'admin.media');
            $router->post('', [MediaController::class, 'store'], 'admin.media.store');
            $router->get('/{id:\d+}', [MediaController::class, 'edit'], 'admin.media.edit');
            $router->post('/{id:\d+}', [MediaController::class, 'update'], 'admin.media.update');
            $router->post('/{id:\d+}/supprimer', [MediaController::class, 'destroy'], 'admin.media.destroy');

            $router->post('/dossiers', [MediaFolderController::class, 'store'], 'admin.media.folders.store');
            $router->post('/dossiers/{id:\d+}', [MediaFolderController::class, 'update'], 'admin.media.folders.update');
            $router->post('/dossiers/{id:\d+}/supprimer', [MediaFolderController::class, 'destroy'], 'admin.media.folders.destroy');
        });
    });
});
