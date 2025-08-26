<?php

namespace Tomba\Services;

use Tomba\TombaException;
use Tomba\Client;
use Tomba\Service;

class Keys extends Service
{
    /**
     * Get your keys.
     *
     * Returns a list of your keys.
     *
     * @throws TombaException
     * @return array
     */
    public function getKeys(): array
    {
        $path   = str_replace([], [], '/keys/{id}');
        $params = [];

        return $this->client->call(Client::METHOD_GET, $path, [
            'content-type' => 'application/json',
        ], $params);
    }

    /**
     * Delete key
     *
     * Delete key
     *
     * @param string $id
     * @throws TombaException
     * @return array
     */
    public function deleteKey(string $id): array
    {
        if (!isset($id)) {
            throw new TombaException('Missing required parameter: "id"');
        }

        $path   = str_replace(['{id}'], [$id], '/keys/{id}');
        $params = [];

        return $this->client->call(Client::METHOD_DELETE, $path, [
            'content-type' => 'application/json',
        ], $params);
    }

    /**
     * Create Key
     *
     * Create a new Key.
     *
     * @throws TombaException
     * @return array
     */
    public function createKey(): array
    {
        $path   = str_replace([], [], '/keys/{id}');
        $params = [];

        return $this->client->call(Client::METHOD_POST, $path, [
            'content-type' => 'application/json',
        ], $params);
    }

    /**
     * Reset a key
     *
     * Rest your key.
     *
     * @param string $id
     * @throws TombaException
     * @return array
     */
    public function resetKey(string $id): array
    {
        if (!isset($id)) {
            throw new TombaException('Missing required parameter: "id"');
        }

        $path   = str_replace(['{id}'], [$id], '/keys/{id}');
        $params = [];

        return $this->client->call(Client::METHOD_PUT, $path, [
            'content-type' => 'application/json',
        ], $params);
    }
}
