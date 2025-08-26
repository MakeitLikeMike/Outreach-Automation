<?php

namespace Tomba\Services;

use Tomba\TombaException;
use Tomba\Client;
use Tomba\Service;

class Usage extends Service
{
    /**
     * get Usage
     *
     * Returns a your monthly requests
     *
     * @throws TombaException
     * @return array
     */
    public function getUsage(): array
    {
        $path   = str_replace([], [], '/usage');
        $params = [];

        return $this->client->call(Client::METHOD_GET, $path, [
            'content-type' => 'application/json',
        ], $params);
    }
}
