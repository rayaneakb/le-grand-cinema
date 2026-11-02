<?php

namespace App\Security;

use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;

class EmailNotVerifiedException extends CustomUserMessageAccountStatusException
{
    public function getMessageKey(): string
    {
        return 'Vérifiez votre adresse mail avant de vous connecter.';
    }
}
