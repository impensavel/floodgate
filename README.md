# Floodgate
[![Latest Stable Version](https://poser.pugx.org/impensavel/floodgate/v/stable.svg)](https://packagist.org/packages/impensavel/floodgate)
[![Build Status](https://travis-ci.org/impensavel/floodgate.svg?branch=master)](https://travis-ci.org/impensavel/floodgate)

A PHP library for consuming the [Twitter](http://www.twitter.com) Streaming API v1.1 via OAuth.

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
composer require "impensavel/floodgate:~2.0"
```

## Basic usage example
```php
<?php

require 'vendor/autoload.php';

use Impensavel\Floodgate\Floodgate;
use Impensavel\Floodgate\FloodgateException;

try {
    $config = [
        'oauth' => [
            'consumer_key'    => 'OADYKJgKogkkYtzdIKLZEq77Z',
            'consumer_secret' => 'Z0mImnDYzH3Tbe4eyQLQEA0lyzXsWFmmZsQTAYHtBrSBX04bKK',
            'token'           => '456786512-D4MnYQ3U74wd40zXHRHa495wl00ogOyhJu9iqEhz',
            'token_secret'    => 'EUyz6MawvBlabLAb2gY6fgyTagtMMYny7GmzKfulGo3Di',
        ],
    ];

    $floodgate = Floodgate::create($config);

    // Data handler
    $handler = function ($message)
    {
        // dump each message from the stream
        var_dump($message);
    };

    // API endpoint parameter generator
    $generator = function ()
    {
        return [
            'track' => 'php',
        ];
    };

    // consume the Twitter Streaming API filter endpoint
    $floodgate->filter($handler, $generator);

} catch (FloodgateException $e) {
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
