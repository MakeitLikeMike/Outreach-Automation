<?php

namespace Tomba\Services;

use Tomba\TombaException;
use Tomba\Client;
use Tomba\Service;

class Status extends Service
{
    /**
     * Domain status
     *
     * Returns domain status if is webmail or disposable.
     *
     * @param string $domain
     * @throws TombaException
     * @return array
     */
    public function domainStatus(string $domain): array
    {
        if (!isset($domain)) {
            throw new TombaException('Missing required parameter: "domain"');
        }

        $path   = str_replace([], [], '/domain-status');
        $params = [];

        if (!is_null($domain)) {
            $params['domain'] = $domain;
        }

        return $this->client->call(Client::METHOD_GET, $path, [
            'content-type' => 'application/json',
        ], $params);
    }

    /**
     * get Company Autocomplete
     *
     * Company Autocomplete is an API that lets you auto-complete company names
     * and retrieve logo and domain information.
     *
     * @param string $query
     * @throws TombaException
     * @return array
     */
    public function autoComplete(string $query): array
    {
        if (!isset($query)) {
            throw new TombaException('Missing required parameter: "query"');
        }

        $path   = str_replace([], [], '/domains-suggestion');
        $params = [];

        if (!is_null($query)) {
            $params['query'] = $query;
        }

        return $this->client->call(Client::METHOD_GET, $path, [
            'content-type' => 'application/json',
        ], $params);
    }
}
