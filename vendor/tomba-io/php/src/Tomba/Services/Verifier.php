<?php

namespace Tomba\Services;

use Tomba\TombaException;
use Tomba\Client;
use Tomba\Service;

class Verifier extends Service
{
    /**
     * Email Verifier
     *
     * verify the deliverability of an email address.
     *
     * @param string $email
     * @throws TombaException
     * @return array
     */
    public function emailVerifier(string $email): array
    {
        if (!isset($email)) {
            throw new TombaException('Missing required parameter: "email"');
        }

        $path   = str_replace(['{email}'], [$email], '/email-verifier/{email}');
        $params = [];

        return $this->client->call(Client::METHOD_GET, $path, [
            'content-type' => 'application/json',
        ], $params);
    }
}
