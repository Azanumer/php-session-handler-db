<?php

declare(strict_types=1);

require __DIR__ . '/../src/DbSessionHandler.php';

use Azanumer\DbSessionHandler;

// SQLite in-memory database so the demo runs anywhere, no server needed.
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE sessions (
    id         VARCHAR(128) NOT NULL PRIMARY KEY,
    data       BLOB         NOT NULL,
    last_used  INTEGER      NOT NULL,
    created_at INTEGER      NOT NULL
)');
$pdo->exec('CREATE INDEX idx_last_used ON sessions (last_used)');

$handler = new DbSessionHandler($pdo, 'sessions');
session_set_save_handler($handler, true);
session_start();

$_SESSION['visits'] = ($_SESSION['visits'] ?? 0) + 1;
$_SESSION['last_page'] = 'home';

echo 'Visits this session: ' . $_SESSION['visits'] . PHP_EOL;
echo 'Sessions stored in DB: ' . $handler->count() . PHP_EOL;

// Simulate garbage collection of sessions idle > 1 hour.
$removed = $handler->gc(3600);
echo 'Expired sessions removed by gc(): ' . $removed . PHP_EOL;

// Destroy the session and prove it is gone.
session_destroy();
echo 'Sessions stored after destroy: ' . $handler->count() . PHP_EOL;
