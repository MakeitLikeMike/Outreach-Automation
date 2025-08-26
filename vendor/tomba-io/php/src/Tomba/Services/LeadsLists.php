<?php

namespace Tomba\Services;

use Tomba\TombaException;
use Tomba\Client;
use Tomba\Service;

class LeadsLists extends Service
{
    /**
     * Get Leads Lists
     *
     * Returns a list of leads lists..
     *
     * @throws TombaException
     * @return array
     */
    public function getLists(): array
    {
        $path   = str_replace([], [], '/leads_lists/{id}');
        $params = [];

        return $this->client->call(Client::METHOD_GET, $path, [
            'content-type' => 'application/json',
        ], $params);
    }

    /**
     * Delete List ID
     *
     * Delete a specific list by passing id.
     *
     * @param string $id
     * @throws TombaException
     * @return array
     */
    public function deleteListId(string $id): array
    {
        if (!isset($id)) {
            throw new TombaException('Missing required parameter: "id"');
        }

        $path   = str_replace(['{id}'], [$id], '/leads_lists/{id}');
        $params = [];

        return $this->client->call(Client::METHOD_DELETE, $path, [
            'content-type' => 'application/json',
        ], $params);
    }

    /**
     * Create new List
     *
     * Create a new leads list with the name request parameter
     *
     * @throws TombaException
     * @return array
     */
    public function createList(): array
    {
        $path   = str_replace([], [], '/leads_lists/{id}');
        $params = [];

        return $this->client->call(Client::METHOD_POST, $path, [
            'content-type' => 'application/json',
        ], $params);
    }

    /**
     * Update List ID
     *
     * Update the fields of a list using id.
     *
     * @param string $id
     * @throws TombaException
     * @return array
     */
    public function updateListId(string $id): array
    {
        if (!isset($id)) {
            throw new TombaException('Missing required parameter: "id"');
        }

        $path   = str_replace(['{id}'], [$id], '/leads_lists/{id}');
        $params = [];

        return $this->client->call(Client::METHOD_PUT, $path, [
            'content-type' => 'application/json',
        ], $params);
    }
}
