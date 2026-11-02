<?php

namespace App\Validator;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;

class PasswordSafety
{
    public function __construct(private HttpClientInterface $client)
    {
    }

    public function error(string $password): ?string
    {
        $common = ['password123', 'password1234', 'motdepasse', 'motdepasse123', 'azerty12345', 'qwerty12345', '1234567890', '12345678901', 'legrandcinema'];
        if (in_array(mb_strtolower($password), $common, true)) {
            return 'Ce mot de passe est trop courant. Choisissez-en un autre.';
        }

        // Seul le préfixe du hachage part au service. La comparaison complète reste sur le serveur.
        $hash = strtoupper(sha1($password));
        try {
            $response = $this->client->request('GET', 'https://api.pwnedpasswords.com/range/' . substr($hash, 0, 5), [
                'headers' => ['Add-Padding' => 'true'],
                'timeout' => 3,
                'max_duration' => 5,
            ]);
            $body = $response->getContent();
            foreach (explode("\n", $body) as $line) {
                [$suffix, $count] = array_pad(explode(':', trim($line), 2), 2, '0');
                if ((int) $count > 0 && hash_equals(substr($hash, 5), $suffix)) {
                    return 'Ce mot de passe figure dans une fuite de données. Choisissez-en un autre.';
                }
            }
        } catch (ExceptionInterface) {
            return 'La vérification du mot de passe est indisponible. Réessayez dans quelques instants.';
        }

        return null;
    }
}
