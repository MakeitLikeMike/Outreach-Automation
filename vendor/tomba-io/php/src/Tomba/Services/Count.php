<?php

namespace Tomba\Services;

use Tomba\TombaException;
use Tomba\Client;
use Tomba\Service;

class Count extends Service
{
    /**
     * get Email Count
     *
     * Domain name from which you want to find the email addresses.
     *
     * @param string $domain
     * @throws TombaException
     * @return array
     */
    public function emailCount(string $domain): array
    {
        if (!isset($domain)) {
            throw new TombaException('Missing required parameter: "domain"');
        }

        $path   = str_replace([], [], '/email-count');
        $params = [];

        if (!is_null($domain)) {
            $params['domain'] = $domain;
        }

        return $this->client->call(Client::METHOD_GET, $path, [
            'content-type' => 'application/json',
        ], $params);
    }
}
