# Deprecation notice
Please note that this is an **old** and **unsupported** version.

Use [**version 2.x**](https://github.com/impensavel/floodgate/tree/2.0) instead!

# Floodgate
A PHP library that makes consuming the [Twitter](http://www.twitter.com) Streaming API, straightforward.

This library aims for [PSR-1][], [PSR-2][] and [PSR-4][] standards compliance.

[PSR-1]: https://github.com/php-fig/fig-standards/blob/master/accepted/PSR-1-basic-coding-standard.md
[PSR-2]: https://github.com/php-fig/fig-standards/blob/master/accepted/PSR-2-coding-style-guide.md
[PSR-4]: https://github.com/php-fig/fig-standards/blob/master/accepted/PSR-4-autoloader.md

## Requirements
* [PHP](http://www.php.net) 5.4+
* [Guzzle](https://packagist.org/packages/guzzlehttp/guzzle)
* [Guzzle Oauth](https://packagist.org/packages/guzzlehttp/oauth-subscriber)

## Installation
``` bash
composer require "impensavel/floodgate:1.0.*"
```

## Basic usage example
```php
<?php

require 'vendor/autoload.php';

use Impensavel\Floodgate\Floodgate;
use Impensavel\Floodgate\FloodgateException;

class MyFloodgate extends Floodgate
{
   /**
    * {@inheritdoc}
    */
    public function getParameters()
    {
        return [
            'track' => 'php',
        ];
    }
}

try {
    // Twitter OAuth configuration
    $config = [
        'consumer_key'    => 'OADYKJgKogkkYtzdIKLZEq77Z',
        'consumer_secret' => 'Z0mImnDYzH3Tbe4eyQLQEA0lyzXsWFmmZsQTAYHtBrSBX04bKK',
        'token'           => '456786512-D4MmYQ3U74wd40zXHRHa495wl00ogOyhJu9iqEhz',
        'token_secret'    => 'EUyz6MawvBlabLAb2gY6fgyTagtMMYny7GmzKfulGo3Di',
    ];

    // create a MyFloodgate instance
    $stream = MyFloodgate::create($config);

    // consume the Twitter Streaming API filter endpoint
    $stream->filter(function ($data)
    {
        // dump each line from the stream
        var_dump($data);
    });

} catch(FloodgateException $e) {
    // handle exceptions
}
```

## Class documentation
- [Floodgate](docs/Floodgate.md)

## Twitter Documentation
- [Public Streams](https://dev.twitter.com/streaming/public)
- [Connecting to a streaming endpoint](https://dev.twitter.com/streaming/overview/connecting)
- [Message Types](https://dev.twitter.com/streaming/overview/messages-types)

## License
The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
