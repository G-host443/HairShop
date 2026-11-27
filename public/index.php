<?php

declare(strict_types=1);

use App\Core\Http\Request;
use App\Core\Kernel;

/** @var DI\Container $container */
$container = require dirname(__DIR__) . '/bootstrap/app.php';

$trustedProxies = (array) $container->get(App\Core\Config::class)->get('security.trusted_proxies', []);

$container->get(Kernel::class)
    ->handle(Request::fromGlobals($trustedProxies))
    ->send();
