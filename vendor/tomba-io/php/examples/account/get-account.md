```php
<?php

use Tomba\Client;
use Tomba\Services\Account;

$client = new Client();

$client
    ->setKey('ta_xxxx') // Your API Key
    ->setSecret('ts_xxxx') // Your Secret
;

$account = new Account($client);

$result = $account->getAccount();
```