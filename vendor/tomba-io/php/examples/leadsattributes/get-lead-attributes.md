```php
<?php

use Tomba\Client;
use Tomba\Services\LeadsAttributes;

$client = new Client();

$client
    ->setKey('ta_xxxx') // Your API Key
    ->setSecret('ts_xxxx') // Your Secret
;

$leadsAttributes = new LeadsAttributes($client);

$result = $leadsAttributes->getLeadAttributes();
```