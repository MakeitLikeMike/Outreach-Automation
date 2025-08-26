```php
<?php

use Tomba\Client;
use Tomba\Services\Logs;

$client = new Client();

$client
    ->setKey('ta_xxxx') // Your API Key
    ->setSecret('ts_xxxx') // Your Secret
;

$logs = new Logs($client);

$result = $logs->getLogs();
```