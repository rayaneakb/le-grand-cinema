<?php

namespace App\Exception;

class ReservationProblem extends \RuntimeException
{
    public function __construct(string $message, int $status, public readonly array $details = [])
    {
        parent::__construct($message, $status);
    }
}
