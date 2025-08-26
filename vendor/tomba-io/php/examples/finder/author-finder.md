# Author finder

This API endpoint generates or retrieves the most likely email address from a blog post url.

```php
<?php

use Tomba\Client;
use Tomba\Services\Finder;

$client = new Client();

$client
    ->setKey('ta_xxxx') // Your API Key
    ->setSecret('ts_xxxx') // Your Secret
;

$finder = new Finder($client);

$result = $finder->authorFinder('https://clearbit.com/blog/company-name-to-domain-api');.
```
