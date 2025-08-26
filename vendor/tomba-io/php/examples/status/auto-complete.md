```php
<?php

use Tomba\Client;
use Tomba\Services\Status;

$client = new Client();

$client
    ->setKey('ta_xxxx') // Your API Key
    ->setSecret('ts_xxxx') // Your Secret
;

$status = new Status($client);

$result = $status->autoComplete('google');
```