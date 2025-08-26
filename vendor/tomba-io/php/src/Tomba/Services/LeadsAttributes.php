<?php

namespace Tomba\Services;

use Tomba\TombaException;
use Tomba\Client;
use Tomba\Service;

class LeadsAttributes extends Service
{
    /**
     * Get Lead Attributes
     *
     * Returns a list of Lead Attributes.
     *
     * @throws TombaException
     * @return array
     */
    public function getLeadAttributes(): array
    {
        $path   = str_replace([], [], '/leads/attributes/{id}');
        $params = [];

        return $this->client->call(Client::METHOD_GET, $path, [
            'content-type' => 'application/json',
        ], $params);
    }

    /**
     * Delete Lead Attribute
     *
     * Delete a specific Attributes by passing id.
     *
     * @param string $id
     * @throws TombaException
     * @return array
     */
    public function deleteLeadAttribute(string $id): array
    {
        if (!isset($id)) {
            throw new TombaException('Missing required parameter: "id"');
        }

        $path   = str_replace(['{id}'], [$id], '/leads/attributes/{id}');
        $params = [];

        return $this->client->call(Client::METHOD_DELETE, $path, [
            'content-type' => 'application/json',
        ], $params);
    }

    /**
     * Create Lead Attribute
     *
     * Create a new Attributes with the name and type request parameter.
     *
     * @throws TombaException
     * @return array
     */
    public function createLeadAttribute(): array
    {
        $path   = str_replace([], [], '/leads/attributes/{id}');
        $params = [];

        return $this->client->call(Client::METHOD_POST, $path, [
            'content-type' => 'application/json',
        ], $params);
    }

    /**
     * Update Lead Attribute
     *
     * Update the fields of a Attributes using id.
     *
     * @param string $id
     * @throws TombaException
     * @return array
     */
    public function updateLeadAttribute(string $id): array
    {
        if (!isset($id)) {
            throw new TombaException('Missing required parameter: "id"');
        }

        $path   = str_replace(['{id}'], [$id], '/leads/attributes/{id}');
        $params = [];

        return $this->client->call(Client::METHOD_PUT, $path, [
            'content-type' => 'application/json',
        ], $params);
    }
}
