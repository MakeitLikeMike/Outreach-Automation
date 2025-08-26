```php
<?php

use Tomba\Client;
use Tomba\Services\Count;

$client = new Client();

$client
    ->setKey('ta_xxxx') // Your API Key
    ->setSecret('ts_xxxx') // Your Secret
;

$count = new Count($client);

$result = $count->emailCount('tomba.io');
```