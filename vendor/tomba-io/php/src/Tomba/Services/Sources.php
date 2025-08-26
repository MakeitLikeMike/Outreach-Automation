<?php

namespace Tomba\Services;

use Tomba\TombaException;
use Tomba\Client;
use Tomba\Service;

class Sources extends Service
{
    /**
     * Email Sources
     *
     * Find email address source somewhere on the web.
     *
     * @param string $email
     * @throws TombaException
     * @return array
     */
    public function emailSources(string $email): array
    {
        if (!isset($email)) {
            throw new TombaException('Missing required parameter: "email"');
        }

        $path   = str_replace(['{email}'], [$email], '/email-sources/{email}');
        $params = [];

        return $this->client->call(Client::METHOD_GET, $path, [
            'content-type' => 'application/json',
        ], $params);
    }
}
