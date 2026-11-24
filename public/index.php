<?php

declare(strict_types=1);

use App\Kernel;

// With variables_order=GPCS the web SAPI can omit process variables from both superglobals.
$_SERVER['APP_ENV'] = ($_SERVER['APP_ENV'] ?? $_ENV['APP_ENV'] ?? getenv('APP_ENV')) ?: 'prod';

require_once dirname(__DIR__).'/vendor/autoload_runtime.php';

return static fn (array $context): Kernel => new Kernel($context['APP_ENV'], (bool) $context['APP_DEBUG']);
