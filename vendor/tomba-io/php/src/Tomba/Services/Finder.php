<?php

namespace Tomba\Services;

use Tomba\TombaException;
use Tomba\Client;
use Tomba\Service;

class Finder extends Service
{
    /**
     * Email Finder
     *
     * generates or retrieves the most likely email address from a domain name, a
     * first name and a last name.
     *
     * @param string $domain
     * @param string $firstName
     * @param string $lastName
     * @throws TombaException
     * @return array
     */
    public function emailFinder(string $domain, string $firstName, string $lastName): array
    {
        if (!isset($domain)) {
            throw new TombaException('Missing required parameter: "domain"');
        }

        if (!isset($firstName)) {
            throw new TombaException('Missing required parameter: "firstName"');
        }

        if (!isset($lastName)) {
            throw new TombaException('Missing required parameter: "lastName"');
        }

        $path   = str_replace([], [], '/email-finder');
        $params = [];

        if (!is_null($firstName)) {
            $params['first_name'] = $firstName;
        }

        if (!is_null($lastName)) {
            $params['last_name'] = $lastName;
        }
        if (!is_null($domain)) {
            $params['domain'] = $domain;
        }

        return $this->client->call(Client::METHOD_GET, $path, [
            'content-type' => 'application/json',
        ], $params);
    }

    /**
     * Author finder
     *
     * This API endpoint generates or retrieves the most likely email address from a blog post url.
     *
     * @param string $url
     * @throws TombaException
     * @return array
     */
    public function authorFinder(string $url): array
    {
        if (!isset($url)) {
            throw new TombaException('Missing required parameter: "url"');
        }


        $path   = str_replace([], [], '/author-finder');
        $params = [];

        
        if (!is_null($url)) {
            $params['url'] = $url;
        }

        return $this->client->call(Client::METHOD_GET, $path, [
            'content-type' => 'application/json',
        ], $params);
    }

     /**
     * Linkedin finder
     *
     * This API endpoint generates or retrieves the most likely email address from a Linkedin URL.
     *
     * @param string $url
     * @throws TombaException
     * @return array
     */
    public function linkedinFinder(string $url): array
    {
        if (!isset($url)) {
            throw new TombaException('Missing required parameter: "url"');
        }


        $path   = str_replace([], [], '/linkedin');
        $params = [];

        
        if (!is_null($url)) {
            $params['url'] = $url;
        }

        return $this->client->call(Client::METHOD_GET, $path, [
            'content-type' => 'application/json',
        ], $params);
    }

     /**
     * Phone Finder
     *
     * Search phone are based on the email You give one email and it returns phone data
     *
     * @param string $email
     * @throws TombaException
     * @return array
     */
    public function phoneFinder(string $email): array
    {
        if (!isset($email)) {
            throw new TombaException('Missing required parameter: "email"');
        }

        $path   = str_replace(['{email}'], [$email], '/phone/{email}');
        $params = [];

        return $this->client->call(Client::METHOD_GET, $path, [
            'content-type' => 'application/json',
        ], $params);
    }
}
