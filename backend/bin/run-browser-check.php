#!/usr/bin/env php
<?php

declare(strict_types=1);

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Process\Process;

require dirname(__DIR__) . '/vendor/autoload.php';
(new Dotenv())->bootEnv(dirname(__DIR__) . '/.env');
umask(0077);
$backend = dirname(__DIR__);
$params = (new DsnParser(['postgresql' => 'pdo_pgsql', 'postgres' => 'pdo_pgsql']))
    ->parse($_SERVER['DATABASE_URL']);
if (!in_array($params['host'] ?? '', ['127.0.0.1', 'localhost', '::1'], true)) {
    throw new RuntimeException('Cette recette nécessite PostgreSQL en local.');
}
$name = 'cinema_browser_' . bin2hex(random_bytes(6)) . '_test';
$adminParams = $params;
$adminParams['dbname'] = 'postgres';
if (getenv('PG_ADMIN_USER')) {
    $adminParams['user'] = getenv('PG_ADMIN_USER');
    unset($adminParams['password']);
}
$admin = DriverManager::getConnection($adminParams);
$created = false;
$server = null;
$db = null;
$directory = $backend . '/var/browser-check';
if (!is_dir($directory)) {
    mkdir($directory, 0700, true);
}
$password = bin2hex(random_bytes(24));
$url = 'postgresql://' . rawurlencode($params['user']) . ':' . rawurlencode($params['password'] ?? '')
    . '@' . $params['host'] . ':' . ($params['port'] ?? 5432) . '/' . $name . '?serverVersion=17&charset=utf8';
$environment = [
    'APP_ENV' => 'browsercheck', 'APP_DEBUG' => '0', 'APP_SECRET' => bin2hex(random_bytes(32)),
    'SYMFONY_DOTENV_VARS' => '', 'DATABASE_URL' => $url,
    'MAILER_DSN' => 'null://null', 'MAILER_FROM' => 'cinema@example.test',
    'DEFAULT_URI' => 'http://127.0.0.1:5175',
];
try {
    $admin->executeStatement('CREATE DATABASE ' . $admin->quoteIdentifier($name)
        . ' OWNER ' . $admin->quoteIdentifier($params['user']));
    $created = true;
    $migration = new Process([PHP_BINARY, 'bin/console', 'doctrine:migrations:migrate', '--no-interaction'], $backend, $environment);
    $migration->setTimeout(60);
    $migration->run();
    if (!$migration->isSuccessful()) {
        throw new RuntimeException('Les migrations de la base temporaire ont échoué.');
    }
    $params['dbname'] = $name;
    $db = DriverManager::getConnection($params);
    $film = (int) $db->fetchOne("INSERT INTO film (title, synopsis, duration_minutes, main_actors, poster_url, poster_public_id, is_showing) VALUES ('Film de recette', 'Synopsis fictif pour la recette.', 110, 'Camille Test', '/demo/posters/lumiere.svg', 'demo/recette', TRUE) RETURNING id");
    $room = (int) $db->fetchOne("INSERT INTO room (name, type) VALUES ('Salle de recette', 'Standard') RETURNING id");
    for ($number = 1; $number <= 12; ++$number) {
        $db->insert('seat', ['room_id' => $room, 'row_label' => 'A', 'number' => $number]);
    }
    $screening = (int) $db->fetchOne("INSERT INTO screening (room_id, film_id, status, starts_at, ends_at) VALUES (?, ?, 'scheduled', NOW() AT TIME ZONE 'UTC' + INTERVAL '2 days', NOW() AT TIME ZONE 'UTC' + INTERVAL '2 days 110 minutes') RETURNING id", [$room, $film]);
    $db->insert('cinema_settings', ['id' => 1, 'ticket_price_cents' => 1050]);
    foreach (['client' => 'ROLE_USER', 'staff' => 'ROLE_EMPLOYEE', 'direction' => 'ROLE_DIRECTOR'] as $account => $role) {
        $db->insert('"user"', [
            'email' => $account . '@example.test', 'roles' => json_encode([$role]),
            'password' => password_hash($password, PASSWORD_BCRYPT),
            'first_name' => 'Camille', 'last_name' => 'Recette',
            'created_at' => gmdate('Y-m-d H:i:s'), 'email_verified_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }
    // Empêche le chargement du fichier local de remplacer la base et le transport mail de recette.
    $router = $directory . '/router.php';
    file_put_contents($router, <<<'ROUTER'
<?php
foreach (['APP_ENV', 'APP_DEBUG', 'APP_SECRET', 'DATABASE_URL', 'MAILER_DSN', 'MAILER_FROM', 'DEFAULT_URI'] as $key) {
    $_SERVER[$key] = $_ENV[$key] = getenv($key);
}
$_SERVER['APP_RUNTIME_OPTIONS'] = ['disable_dotenv' => true];
if ($_SERVER['APP_ENV'] !== 'browsercheck' || $_SERVER['MAILER_DSN'] !== 'null://null') {
    http_response_code(503);
    exit;
}
return require dirname(__DIR__, 2) . '/router.php';
ROUTER);
    $server = new Process([PHP_BINARY, '-S', '127.0.0.1:8003', '-t', 'public', $router], $backend, $environment);
    $server->setTimeout(null);
    $server->disableOutput();
    $server->start();
    $http = HttpClient::create(['timeout' => 2]);
    $ready = false;
    for ($attempt = 0; $attempt < 40; ++$attempt) {
        if (!$server->isRunning()) {
            throw new RuntimeException('Port 8003 indisponible pour la recette.');
        }
        try {
            if ($http->request('GET', 'http://127.0.0.1:8003/api/status')->getStatusCode() === 200) {
                $ready = true;
                break;
            }
        } catch (Throwable) {
        }
        usleep(200000);
    }
    if (!$ready) {
        throw new RuntimeException('Le serveur de recette ne répond pas.');
    }
    $tests = new Process(['npx', '--no-install', 'playwright', 'test', '--config=playwright.real.config.js'], dirname($backend) . '/frontend', [
        'CINEMA_API_TARGET' => 'http://127.0.0.1:8003',
        'CINEMA_TEST_PASSWORD' => $password, 'CINEMA_TEST_SCREENING' => (string) $screening,
    ]);
    $tests->setTimeout(180);
    $tests->run(static function (string $type, string $buffer): void {
        echo $buffer;
    });
    $consistent = (int) $db->fetchOne('SELECT COUNT(*) FROM reserved_seat WHERE released_at IS NULL') === 0
        && (int) $db->fetchOne('SELECT COUNT(*) FROM reservation WHERE total_amount_cents = 2100 AND status = ?', ['cancelled']) === 1;
    file_put_contents($directory . '/latest.json', json_encode([
        'checkedAt' => date(DATE_ATOM), 'exitCode' => $tests->getExitCode(),
        'databaseConsistent' => $consistent, 'realApi' => true, 'mailTransport' => 'null',
    ], JSON_PRETTY_PRINT) . "\n");
    if (!$tests->isSuccessful() || !$consistent) {
        throw new RuntimeException('La recette navigateur a échoué. Consultez les résultats Playwright.');
    }
    echo "Parcours navigateur, API et base vérifiés.\n";
} finally {
    $server?->stop(3);
    $db?->close();
    if ($created) {
        $admin->executeStatement('DROP DATABASE ' . $admin->quoteIdentifier($name) . ' WITH (FORCE)');
    }
    $admin->close();
}
