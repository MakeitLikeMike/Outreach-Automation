```php
<?php

use Tomba\Client;
use Tomba\Services\LeadsLists;

$client = new Client();

$client
    ->setKey('ta_xxxx') // Your API Key
    ->setSecret('ts_xxxx') // Your Secret
;

$leadsLists = new LeadsLists($client);

$result = $leadsLists->deleteListId('[LIST_ID]');
```