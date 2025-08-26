```php
<?php

use Tomba\Client;
use Tomba\Services\Usage;

$client = new Client();

$client
    ->setKey('ta_xxxx') // Your API Key
    ->setSecret('ts_xxxx') // Your Secret
;

$usage = new Usage($client);

$result = $usage->getUsage();
```