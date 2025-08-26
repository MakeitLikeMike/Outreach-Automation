# Phone Finder

Search phone are based on the email You give one email and it returns phone data.

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

$result = $finder->phoneFinder('******@zapier.com');.
```
