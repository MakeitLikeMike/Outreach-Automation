```php
<?php

use Tomba\Client;
use Tomba\Services\Sources;

$client = new Client();

$client
    ->setKey('ta_xxxx') // Your API Key
    ->setSecret('ts_xxxx') // Your Secret
;

$sources = new Sources($client);

$result = $sources->emailSources('b.mohamed@tomba.io');
```