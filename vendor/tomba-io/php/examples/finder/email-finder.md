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

$result = $finder->emailFinder('stripe.com', 'Moskoz', 'Dustin');.
```