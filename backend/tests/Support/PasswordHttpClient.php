<?php

namespace App\Tests\Support;

use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class PasswordHttpClient
{
    public static function create(): MockHttpClient
    {
        return new MockHttpClient(static fn () => new MockResponse(''));
    }
}
