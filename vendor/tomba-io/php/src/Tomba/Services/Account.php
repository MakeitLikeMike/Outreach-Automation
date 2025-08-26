<?php

namespace Tomba\Services;

use Tomba\TombaException;
use Tomba\Client;
use Tomba\Service;

class Account extends Service
{
    /**
     * Get Account
     *
     * Returns information about the current account.
     *
     * @throws TombaException
     * @return array
     */
    public function getAccount(): array
    {
        $path   = str_replace([], [], '/me');
        $params = [];

        return $this->client->call(Client::METHOD_GET, $path, [
            'content-type' => 'application/json',
        ], $params);
    }
}
