```php
<?php

use Tomba\Client;
use Tomba\Services\Keys;

$client = new Client();

$client
    ->setKey('ta_xxxx') // Your API Key
    ->setSecret('ts_xxxx') // Your Secret
;

$keys = new Keys($client);

$result = $keys->getKeys();
```