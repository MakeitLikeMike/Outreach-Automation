```php
<?php

use Tomba\Client;
use Tomba\Services\Domain;

$client = new Client();

$client
    ->setKey('ta_xxxx') // Your API Key
    ->setSecret('ts_xxxx') // Your Secret
;

$domain = new Domain($client);

$result = $domain->domainSearch('stripe.com');
```