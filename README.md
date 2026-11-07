# PHP Session Handler (Database)

Dependency-free PDO session handler that stores PHP sessions in a database table instead of files — so sessions survive across multiple web servers behind a load balancer and are not lost when the filesystem is wiped on deploy.

## Why

PHP's default file sessions break in two common setups:

- **Load-balanced servers** — a user logged in on server A hits server B next request and their session file isn't there.
- **Ephemeral filesystems** — container restarts and some deploy strategies wipe `/tmp`.

A database table is the simplest shared store that every PHP app already has.

## Usage

```php
use Azanumer\DbSessionHandler;

$pdo = new PDO('mysql:host=localhost;dbname=app', 'user', 'pass');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$handler = new DbSessionHandler($pdo, 'sessions');
session_set_save_handler($handler, true);
session_start();

$_SESSION['user_id'] = 42;
```

## Schema

MySQL / MariaDB:

```sql
CREATE TABLE sessions (
    id         VARCHAR(128) NOT NULL PRIMARY KEY,
    data       MEDIUMBLOB   NOT NULL,
    last_used  INT UNSIGNED NOT NULL,
    created_at INT UNSIGNED NOT NULL,
    INDEX idx_last_used (last_used)
) ENGINE=InnoDB;
```

SQLite works too — see `examples/session-example.php`, which runs against an in-memory database with no server at all.

## Notes

- **MySQL** uses `ON DUPLICATE KEY UPDATE` for a single-query upsert; other drivers (SQLite, PostgreSQL) use a transactional UPDATE-then-INSERT, chosen automatically from the PDO driver name.
- Session data is stored as a binary blob, exactly as PHP serializes it — no `unserialize()` of user input happens here.
- `gc($maxLifetime)` deletes sessions idle longer than the lifetime and returns how many rows it removed, so you can log it from cron if you want.
- `count()` and `touch($id)` are bonus helpers: count sessions for a status page, and touch to keep a session alive from a heartbeat endpoint without rewriting its data.

## Requirements

PHP 8.0+, PDO with any driver. No dependencies.

## License

MIT — see [LICENSE](LICENSE).
