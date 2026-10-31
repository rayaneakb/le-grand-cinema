<?php

namespace App\Validator;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class RegistrationValidator
{
    public function __construct(private ValidatorInterface $validator)
    {
    }

    /**
     * @return array<string, list<string>>
     */
    public function validate(array $data): array
    {
        $constraints = new Assert\Collection(
            fields: [
                'firstName' => $this->nameConstraints(),
                'lastName' => $this->nameConstraints(),
                'email' => new Assert\Sequentially([
                    new Assert\Type(type: 'string', message: 'Veuillez saisir une adresse mail.'),
                    new Assert\NotBlank(message: 'Veuillez saisir votre adresse mail.', normalizer: 'trim'),
                    new Assert\Length(max: 180, maxMessage: 'L’adresse mail ne doit pas dépasser 180 caractères.'),
                    new Assert\Email(message: 'Veuillez saisir une adresse mail valide.', normalizer: 'trim'),
                ]),
                'password' => new Assert\Sequentially([
                    new Assert\Type(type: 'string', message: 'Veuillez saisir un mot de passe.'),
                    new Assert\NotBlank(message: 'Veuillez saisir un mot de passe.', normalizer: 'trim'),
                    new Assert\Length(
                        min: 10,
                        max: 128,
                        minMessage: 'Le mot de passe doit contenir au moins 10 caractères.',
                        maxMessage: 'Le mot de passe ne doit pas dépasser 128 caractères.',
                    ),
                ]),
            ],
            missingFieldsMessage: 'Ce champ est obligatoire.',
            extraFieldsMessage: 'Ce champ n’est pas accepté pour l’inscription.',
        );

        $errors = [];

        foreach ($this->validator->validate($data, $constraints) as $violation) {
            $field = trim($violation->getPropertyPath(), '[]');
            $errors[$field][] = (string) $violation->getMessage();
        }

        return $errors;
    }

    private function nameConstraints(): Assert\Sequentially
    {
        return new Assert\Sequentially([
            new Assert\Type(type: 'string', message: 'Veuillez saisir du texte.'),
            new Assert\NotBlank(message: 'Veuillez compléter ce champ.', normalizer: 'trim'),
            new Assert\Length(max: 100, maxMessage: 'Ce champ ne doit pas dépasser 100 caractères.'),
        ]);
    }
}
