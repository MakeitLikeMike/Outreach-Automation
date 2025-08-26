<?php

namespace Tomba\Services;

use Tomba\TombaException;
use Tomba\Client;
use Tomba\Service;

class Logs extends Service
{
    /**
     * get Logs
     *
     * Returns a your last 1,000 requests you made during the last 3 months.
     *
     * @throws TombaException
     * @return array
     */
    public function getLogs(): array
    {
        $path   = str_replace([], [], '/logs');
        $params = [];

        return $this->client->call(Client::METHOD_GET, $path, [
            'content-type' => 'application/json',
        ], $params);
    }
}
