# Linkedin Finder

This API endpoint generates or retrieves the most likely email address from a Linkedin URL.

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

$result = $finder->linkedinFinder('https://www.linkedin.com/in/alex-maccaw-ab592978');.
```
