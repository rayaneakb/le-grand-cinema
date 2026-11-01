<?php

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class CloudinaryPoster
{
    public function __construct(
        private HttpClientInterface $http,
        private LoggerInterface $logger,
        #[Autowire(env: 'CLOUDINARY_CLOUD_NAME')] private string $cloud,
        #[Autowire(env: 'CLOUDINARY_API_KEY')] private string $key,
        #[Autowire(env: 'CLOUDINARY_API_SECRET')] private string $secret,
    ) {
    }

    public function validate(UploadedFile $file): void
    {
        if (!$file->isValid() || $file->getSize() > 5 * 1024 * 1024) {
            throw new \InvalidArgumentException('L’affiche doit peser au maximum 5 Mo. Vérifiez aussi que le transfert est terminé.');
        }
        $image = @getimagesize($file->getPathname());
        if (!$image || !in_array($image['mime'], ['image/jpeg', 'image/png', 'image/webp'], true)
            || $file->getMimeType() !== $image['mime']) {
            throw new \InvalidArgumentException('Choisissez une image JPEG, PNG ou WebP valide.');
        }
    }

    public function upload(UploadedFile $file): array
    {
        $this->validate($file);
        $id = 'le-grand-cinema/posters/' . bin2hex(random_bytes(16));
        $form = new FormDataPart([
            'file' => DataPart::fromPath($file->getPathname(), 'affiche', $file->getMimeType()),
            'public_id' => $id,
            'overwrite' => 'false',
        ]);
        try {
            $data = $this->http->request('POST', $this->endpoint('upload'), [
                'auth_basic' => [$this->key, $this->secret],
                'headers' => $form->getPreparedHeaders()->toArray(),
                'body' => $form->bodyToIterable(),
                'max_redirects' => 0,
                'timeout' => 15,
                'max_duration' => 25,
            ])->toArray();
            $url = $data['secure_url'] ?? '';
            if (($data['public_id'] ?? '') !== $id || !is_string($url)
                || !str_starts_with($url, 'https://res.cloudinary.com/' . $this->cloud . '/image/upload/')) {
                throw new \RuntimeException('Réponse Cloudinary invalide.');
            }
            return ['publicId' => $id, 'url' => $url];
        } catch (\Throwable) {
            $this->remove($id);
            throw new \RuntimeException('L’affiche n’a pas pu être enregistrée. Réessayez.');
        }
    }

    public function remove(string $id): bool
    {
        // Les affiches de démonstration et les médias hors du projet sont conservés.
        if (!str_starts_with($id, 'le-grand-cinema/posters/')) return true;
        try {
            $data = $this->http->request('POST', $this->endpoint('destroy'), [
                'auth_basic' => [$this->key, $this->secret],
                'body' => ['public_id' => $id, 'invalidate' => 'true'],
                'max_redirects' => 0,
                'timeout' => 5,
                'max_duration' => 8,
            ])->toArray();
            if (in_array($data['result'] ?? '', ['ok', 'not found'], true)) return true;
        } catch (\Throwable) {
        }
        $this->logger->warning('Une ancienne affiche Cloudinary reste à supprimer.', ['publicId' => $id]);
        return false;
    }

    private function endpoint(string $action): string
    {
        if (!preg_match('/^[a-zA-Z0-9_-]+$/D', $this->cloud) || $this->key === '' || $this->secret === '') {
            throw new \RuntimeException('Le stockage des affiches n’est pas configuré.');
        }
        return 'https://api.cloudinary.com/v1_1/' . $this->cloud . '/image/' . $action;
    }
}
