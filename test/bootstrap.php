<?php

declare(strict_types=1);

namespace DiskQuotaTest;

// Silence vendor deprecations during tests (PHP 8.3 + Laminas)
// Prefer updating dev dependencies to PHP 8.3-compatible versions long-term.
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

require dirname(__DIR__) . '/vendor/autoload.php';

// Load Omeka stubs
require_once __DIR__ . '/stubs/Omeka/Module/AbstractModule.php';
require_once __DIR__ . '/stubs/Omeka/Settings.php';
require_once __DIR__ . '/stubs/Omeka/Settings/User.php';
require_once __DIR__ . '/stubs/Omeka/Api/Manager.php';
require_once __DIR__ . '/stubs/Omeka/Stdlib/Message.php';
require_once __DIR__ . '/stubs/Omeka/Stdlib/ErrorStore.php';
require_once __DIR__ . '/stubs/Omeka/Entity/User.php';

// Load Laminas DB stubs
require_once __DIR__ . '/stubs/Laminas/Db/Adapter/Driver/ConnectionInterface.php';
require_once __DIR__ . '/stubs/Laminas/Db/Adapter/Driver/StatementInterface.php';
