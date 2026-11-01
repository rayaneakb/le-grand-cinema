#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Service\ReservationAccess;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Process\Process;

require dirname(__DIR__) . '/vendor/autoload.php';
(new Dotenv())->bootEnv(dirname(__DIR__) . '/.env');
umask(0077);
$directory = dirname(__DIR__);
$report = json_decode(file_get_contents($directory . '/var/backup-check/latest.json'), true, flags: JSON_THROW_ON_ERROR);
$name = $report['restoredDatabase'];
if (!preg_match('/^cinema_restore_[a-f0-9]{12}$/D', $name) || !$report['keptForApplicationCheck']) {
    throw new RuntimeException('Exécutez d’abord verify-backup.php --keep.');
}
$params = (new DsnParser(['postgresql' => 'pdo_pgsql', 'postgres' => 'pdo_pgsql']))->parse($_SERVER['DATABASE_URL']);
if (!in_array($params['host'] ?? '', ['127.0.0.1', 'localhost', '::1'], true) || $params['dbname'] === $name) {
    throw new RuntimeException('Une copie locale distincte est obligatoire.');
}
$params['dbname'] = $name;
$db = DriverManager::getConnection($params);
$base = 'http://127.0.0.1:8002';
$secret = bin2hex(random_bytes(32));
$url = 'postgresql://' . rawurlencode($params['user']) . ':' . rawurlencode($params['password'] ?? '')
    . '@' . $params['host'] . ':' . ($params['port'] ?? 5432) . '/' . $name . '?serverVersion=17&charset=utf8';
$environment = [
    'APP_ENV' => 'loadcheck', 'APP_DEBUG' => '0', 'APP_SECRET' => $secret,
    'SYMFONY_DOTENV_VARS' => '',
    'DATABASE_URL' => $url, 'MAILER_DSN' => 'null://null',
    'MAILER_FROM' => 'cinema@example.com', 'DEFAULT_URI' => $base,
    'PHP_CLI_SERVER_WORKERS' => '4',
];
$router = $directory . '/var/backup-check/router.php';
file_put_contents($router, <<<'ROUTER'
<?php
foreach (['APP_ENV', 'APP_DEBUG', 'APP_SECRET', 'DATABASE_URL', 'MAILER_DSN', 'MAILER_FROM', 'DEFAULT_URI'] as $key) {
    $_SERVER[$key] = $_ENV[$key] = getenv($key);
}
$_SERVER['APP_RUNTIME_OPTIONS'] = ['disable_dotenv' => true];
if ($_SERVER['APP_ENV'] !== 'loadcheck' || $_SERVER['MAILER_DSN'] !== 'null://null') {
    http_response_code(503);
    exit;
}
return require dirname(__DIR__, 2) . '/router.php';
ROUTER);
$server = new Process([PHP_BINARY, '-S', '127.0.0.1:8002', '-t', 'public', $router], $directory, $environment);
$server->setTimeout(null);
$server->disableOutput();
$server->start();
$http = HttpClient::create(['timeout' => 5]);
try {
    $ready = false;
    for ($attempt = 0; $attempt < 30; ++$attempt) {
        if (!$server->isRunning()) throw new RuntimeException('Le serveur de test n’a pas démarré. Vérifiez que le port 8002 est libre.');
        try {
            if ($http->request('GET', $base . '/api/status')->getStatusCode() === 200) { $ready = true; break; }
        } catch (Throwable) {}
        usleep(200000);
    }
    if (!$ready) throw new RuntimeException('Le serveur de test ne répond pas.');
    // Relire une réservation restaurée par l’API, pas seulement par SQL.
    $booking = $db->fetchAssociative('SELECT reference, starts_at, b.status, total_amount_cents FROM reservation b JOIN screening s ON s.id = b.screening_id ORDER BY b.id LIMIT 1');
    if (!$booking) throw new RuntimeException('Une réservation source est nécessaire pour contrôler sa restauration dans l’API.');
    $access = new ReservationAccess($secret, $base);
    $personal = $access->createUrl(['reference' => $booking['reference'], 'startsAt' => $booking['starts_at'] . ' UTC']);
    $query = parse_url($personal, PHP_URL_QUERY);
    $restored = $http->request('GET', $base . '/api/reservations/' . $booking['reference'] . '?' . $query)->toArray()['reservation'];
    if ($restored['status'] !== $booking['status'] || $restored['totalAmountCents'] !== (int) $booking['total_amount_cents']) {
        throw new RuntimeException('La réservation restaurée ne correspond pas dans l’API.');
    }
    $report['applicationReadVerified'] = true;
    file_put_contents($directory . '/var/backup-check/latest.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");
    $room = (int) $db->fetchOne('SELECT id FROM room ORDER BY id LIMIT 1');
    $film = (int) $db->fetchOne('SELECT id FROM film WHERE is_showing = TRUE ORDER BY id LIMIT 1');
    $screening = (int) $db->fetchOne("INSERT INTO screening (room_id, film_id, status, starts_at, ends_at) VALUES (?, ?, 'scheduled', NOW() AT TIME ZONE 'UTC' + INTERVAL '7 days', NOW() AT TIME ZONE 'UTC' + INTERVAL '7 days 2 hours') RETURNING id", [$room, $film]);
    $load = new Process(['k6', 'run', '--quiet', 'tests/Load/cinema.js'], $directory, ['BASE_URL' => $base, 'SCREENING_ID' => (string) $screening]);
    $load->setTimeout(150);
    $load->run(static function (string $type, string $buffer): void { echo $buffer; });
    $active = (int) $db->fetchOne("SELECT COUNT(*) FROM reservation WHERE screening_id = ? AND status = 'confirmed'", [$screening]);
    $duplicates = (int) $db->fetchOne('SELECT COUNT(*) FROM (SELECT screening_id, seat_id FROM reserved_seat WHERE released_at IS NULL GROUP BY screening_id, seat_id HAVING COUNT(*) > 1) d');
    $inconsistent = (int) $db->fetchOne(
        'SELECT COUNT(*) FROM reservation b WHERE b.screening_id = ? AND (b.total_amount_cents <> b.unit_price_cents * (SELECT COUNT(*) FROM reserved_seat rs WHERE rs.reservation_id = b.id) OR EXISTS (SELECT 1 FROM reserved_seat rs JOIN seat ON seat.id = rs.seat_id JOIN screening s ON s.id = b.screening_id WHERE rs.reservation_id = b.id AND (rs.screening_id <> b.screening_id OR seat.room_id <> s.room_id)))',
        [$screening]
    );
    $report['loadCheck'] = [
        'exitCode' => $load->getExitCode(), 'activeTestReservations' => $active,
        'duplicateActiveSeats' => $duplicates, 'inconsistentTestReservations' => $inconsistent,
    ];
    file_put_contents($directory . '/var/backup-check/latest.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");
    if (!$load->isSuccessful() || $active !== 0 || $duplicates !== 0 || $inconsistent !== 0) throw new RuntimeException('Un contrôle de charge a échoué. Consultez les rapports locaux.');
    echo "Restauration relue par l’API. Test de charge terminé sans réservation active résiduelle ni siège actif en double.\n";
} finally {
    $server->stop(3);
    $db->close();
}
