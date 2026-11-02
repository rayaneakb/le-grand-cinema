<?php

namespace App\Tests\Controller;

use App\Entity\User;
use App\Service\CloudinaryPoster;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Psr\Log\NullLogger;

class AdminFilmControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private Connection $db;
    private array $calls = [];
    private bool $uploadFails = false;
    private bool $deleteFails = false;
    private string $image;

    protected static function getKernelClass(): string { return \App\Kernel::class; }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->db = self::getContainer()->get(Connection::class);
        if (!str_ends_with($this->db->getDatabase(), '_test')) {
            throw new \RuntimeException('Base _test obligatoire.');
        }
        $this->db->beginTransaction();
        $http = new MockHttpClient(function ($method, $url, $options) {
            $this->calls[] = $url;
            if (str_ends_with($url, '/destroy')) {
                return new MockResponse(json_encode(['result' => 'ok']), ['http_code' => $this->deleteFails ? 500 : 200]);
            }
            $body = $options['body'];
            $content = '';
            if ($body instanceof \Closure) {
                while (($chunk = $body(16384)) !== '') {
                    $content .= $chunk;
                }
            } elseif (is_resource($body)) {
                $content = stream_get_contents($body);
            } elseif (is_iterable($body)) {
                foreach ($body as $chunk) {
                    $content .= $chunk;
                }
            } else {
                $content = $body;
            }
            preg_match('~le-grand-cinema/posters/[a-f0-9]{32}~', $content, $matches);
            $id = $matches[0] ?? 'missing';
            return new MockResponse(json_encode(['public_id' => $id, 'secure_url' => 'https://res.cloudinary.com/test/image/upload/' . $id . '.png']), ['http_code' => $this->uploadFails ? 503 : 200]);
        });
        self::getContainer()->set(CloudinaryPoster::class, new CloudinaryPoster($http, new NullLogger(), 'test', 'fake-key', 'fake-secret'));
        $this->image = tempnam(sys_get_temp_dir(), 'cinema-image-');
        file_put_contents($this->image, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aSAAAAABJRU5ErkJggg=='));
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->isTransactionActive()) {
            $this->db->rollBack();
        }
        if (isset($this->image)) @unlink($this->image);
        parent::tearDown();
    }

    private function login(string $role = 'ROLE_EMPLOYEE'): void
    {
        $user = (new User())->setEmail(uniqid('film') . '@example.com')->setFirstName('Test')->setLastName('Films')
            ->setRoles([$role])->setPassword('hash-test')->setEmailVerifiedAt(new \DateTimeImmutable());
        $manager = self::getContainer()->get(EntityManagerInterface::class);
        $manager->persist($user);
        $manager->flush();
        $this->client->loginUser($user);
    }

    private function save(?int $id = null, array $changes = [], bool $file = true, bool $csrf = true): array
    {
        $this->client->request('GET', '/api/csrf');
        $token = json_decode($this->client->getResponse()->getContent(), true)['csrfToken'];
        $this->client->request('POST', '/api/admin/films' . ($id ? '/' . $id : ''), [
            'title' => 'Film de test', 'synopsis' => 'Une histoire.', 'durationMinutes' => '100',
            'mainActors' => 'Actrice de test', 'isShowing' => '1', ...$changes,
        ], $file ? ['poster' => new UploadedFile($this->image, 'affiche.png', 'image/png', null, true)] : [], [
            'CONTENT_TYPE' => 'multipart/form-data', 'HTTP_X_CSRF_TOKEN' => $csrf ? $token : 'invalide',
        ]);
        return json_decode($this->client->getResponse()->getContent(), true) ?? [];
    }

    public function testOnlyStaffCanCreateAndCsrfIsRequired(): void
    {
        $this->login('ROLE_USER');
        $this->save();
        self::assertResponseStatusCodeSame(403);
        self::assertSame([], $this->calls);
    }

    public function testStaffCreatesEditsAndReplacesOnlyAfterSaving(): void
    {
        $this->login();
        $created = $this->save();
        self::assertResponseStatusCodeSame(201);
        $id = $created['film']['id'];
        $first = $this->db->fetchOne('SELECT poster_public_id FROM film WHERE id = ?', [$id]);
        $edited = $this->save($id, ['title' => 'Titre modifié'], false);
        self::assertResponseIsSuccessful();
        self::assertSame('Titre modifié', $edited['film']['title']);
        self::assertCount(1, $this->calls);
        $this->save($id);
        self::assertResponseIsSuccessful();
        self::assertNotSame($first, $this->db->fetchOne('SELECT poster_public_id FROM film WHERE id = ?', [$id]));
        self::assertCount(3, $this->calls);
        self::assertStringEndsWith('/destroy', $this->calls[2]);
        $this->save($id, csrf: false);
        self::assertResponseStatusCodeSame(403);
        self::assertCount(3, $this->calls);
    }

    public function testInvalidFormAndDisguisedFileDoNotReachCloudinary(): void
    {
        $this->login();
        foreach ([['title' => ' '], ['durationMinutes' => '0'], ['durationMinutes' => '10.5'], ['posterUrl' => 'https://example.com/image']] as $changes) {
            $this->save(changes: $changes);
            self::assertResponseStatusCodeSame(422);
        }
        $this->save(file: false);
        self::assertResponseStatusCodeSame(422);
        file_put_contents($this->image, '<?php echo "pas une image";');
        $this->save();
        self::assertResponseStatusCodeSame(422);
        file_put_contents($this->image, str_repeat('x', 5 * 1024 * 1024 + 1));
        $this->save();
        self::assertResponseStatusCodeSame(422);
        self::assertSame([], $this->calls);
    }

    public function testFailedUploadKeepsTheExistingFilmAndPoster(): void
    {
        $this->login('ROLE_DIRECTOR');
        $created = $this->save();
        $id = $created['film']['id'];
        $first = $this->db->fetchAssociative('SELECT * FROM film WHERE id = ?', [$id]);
        $this->uploadFails = true;
        $this->save($id, ['title' => 'Ne pas enregistrer']);
        self::assertResponseStatusCodeSame(503);
        self::assertSame($first, $this->db->fetchAssociative('SELECT * FROM film WHERE id = ?', [$id]));
    }

    public function testDurationChangeRecalculatesFutureEndsAndRollsBackOnOverlap(): void
    {
        $this->login();
        $id = $this->save()['film']['id'];
        $room = (int) $this->db->fetchOne("INSERT INTO room (name, type) VALUES ('Salle durée test', 'Standard') RETURNING id");
        $session = (int) $this->db->fetchOne("INSERT INTO screening (film_id, room_id, status, starts_at, ends_at) VALUES (?, ?, 'scheduled', '2035-01-01 10:00', '2035-01-01 11:40') RETURNING id", [$id, $room]);
        $this->db->executeStatement("INSERT INTO screening (film_id, room_id, status, starts_at, ends_at) VALUES (?, ?, 'scheduled', '2035-01-01 13:00', '2035-01-01 14:40')", [$id, $room]);
        $this->save($id, ['durationMinutes' => '120'], false);
        self::assertResponseIsSuccessful();
        self::assertSame('2035-01-01 12:00:00', $this->db->fetchOne('SELECT ends_at FROM screening WHERE id = ?', [$session]));
        $old = $this->db->fetchOne('SELECT poster_public_id FROM film WHERE id = ?', [$id]);
        $this->save($id, ['durationMinutes' => '121']);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('2035-01-01 12:00:00', $this->db->fetchOne('SELECT ends_at FROM screening WHERE id = ?', [$session]));
        self::assertSame(120, (int) $this->db->fetchOne('SELECT duration_minutes FROM film WHERE id = ?', [$id]));
        self::assertSame($old, $this->db->fetchOne('SELECT poster_public_id FROM film WHERE id = ?', [$id]));
        self::assertStringEndsWith('/destroy', end($this->calls));
    }

    public function testCleanupFailureDoesNotLoseTheSavedFilm(): void
    {
        $this->login();
        $id = $this->save()['film']['id'];
        $this->deleteFails = true;
        $data = $this->save($id);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('reste à supprimer', $data['message']);
    }
}
