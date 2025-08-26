```php
<?php

use Tomba\Client;
use Tomba\Services\Verifier;

$client = new Client();

$client
    ->setKey('ta_xxxx') // Your API Key
    ->setSecret('ts_xxxx') // Your Secret
;

$verifier = new Verifier($client);

$result = $verifier->emailVerifier('b.mohamed@tomba.io');
```