<?php

namespace App\Validator;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class ReservationValidator
{
    public function __construct(private ValidatorInterface $validator)
    {
    }

    public function validate(array $data): array
    {
        $name = static fn () => new Assert\Sequentially([
            new Assert\Type('string'),
            new Assert\NotBlank(message: 'Veuillez compléter ce champ.', normalizer: 'trim'),
            new Assert\Length(max: 100),
        ]);
        $constraints = new Assert\Collection(fields: [
            'screeningId' => new Assert\Sequentially([new Assert\Type('integer'), new Assert\Range(min: 1, max: 2147483647)]),
            'seatIds' => new Assert\Sequentially([
                new Assert\Type('array'),
                new Assert\Count(min: 1, max: 1000, minMessage: 'Sélectionnez au moins un siège.'),
                new Assert\All([new Assert\Sequentially([new Assert\Type('integer'), new Assert\Range(min: 1, max: 2147483647)])]),
                new Assert\Unique(message: 'Un siège ne peut être sélectionné qu’une fois.'),
            ]),
            'firstName' => $name(),
            'lastName' => $name(),
            'email' => new Assert\Sequentially([
                new Assert\Type('string'),
                new Assert\NotBlank(normalizer: 'trim'),
                new Assert\Length(max: 180),
                new Assert\Email(message: 'Veuillez saisir une adresse mail valide.', normalizer: 'trim'),
            ]),
            'expectedUnitPriceCents' => new Assert\Sequentially([new Assert\Type('integer'), new Assert\Positive()]),
            'confirmationKey' => new Assert\Sequentially([new Assert\Type('string'), new Assert\Uuid()]),
        ], missingFieldsMessage: 'Ce champ est obligatoire.', extraFieldsMessage: 'Ce champ n’est pas accepté.');

        $errors = [];
        foreach ($this->validator->validate($data, $constraints) as $violation) {
            $errors[trim($violation->getPropertyPath(), '[]')][] = (string) $violation->getMessage();
        }

        return $errors;
    }
}
