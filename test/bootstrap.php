<?php

declare(strict_types=1);

namespace DiskQuotaTest;

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
