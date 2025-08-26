<?php

namespace Tomba\Services;

use Tomba\TombaException;
use Tomba\Client;
use Tomba\Service;

class Domain extends Service
{
    /**
     * Domain Search
     *
     * You can use this endpoint to show different browser icons to your users.
     * The code argument receives the browser code as it appears in your user
     * /account/sessions endpoint. Use width, height and quality arguments to
     * change the output settings.
     *
     * @param string $domain
     * @param int $page
     * @param int $limit
     * @param string $department
     * @throws TombaException
     * @return array
     */
    public function domainSearch(string $domain, int $page = null, int $limit = null, string $department = null): array
    {
        if (!isset($domain)) {
            throw new TombaException('Missing required parameter: "domain"');
        }

        $path   = str_replace([], [], '/domain-search');
        $params = [];

        if (!is_null($domain)) {
            $params['domain'] = $domain;
        }
        if (!is_null($page)) {
            $params['page'] = $page;
        }

        if (!is_null($limit)) {
            $params['limit'] = $limit;
        }

        if (!is_null($department)) {
            $params['department'] = $department;
        }

        return $this->client->call(Client::METHOD_GET, $path, [
            'content-type' => 'application/json',
        ], $params);
    }
}
