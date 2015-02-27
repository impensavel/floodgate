# Floodgate
A PHP library for consuming [Twitter](http://www.twitter.com)'s Streaming API.

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
composer require "impensavel/floodgate:dev-master"
```

## Basic usage example
```php
<?php

require 'vendor/autoload.php';

use Impensavel\Floodgate\Floodgate;
use Impensavel\Floodgate\FloodgateException;

try {
	// Twitter OAuth configuration
	$config = [
		'consumer_key'    => 'OADYKJgKogkkYtzdIKLZEq77Z',
		'consumer_secret' => 'Z0mImnDYzH3Tbe4eyQLQEA0lyzXsWFmmZsQTAYHtBrSBX04bKK',
		'token'           => '456786512-D7pjlQ3U74wd40zXHRHa495wl00ogOyhJu9iqEhz',
		'token_secret'    => 'EUyz6MawvBlabLAb2gY6fgyTagtMMYny7GmzKfulGo3Di',
	];

	// create a Floodgate instance
	$fg = Floodgate::create($config);

	// call Twitter Streaming API filter endpoint
	$fg->filter(function ($data)
	{
		// dump each line from the stream
		// $data may be a plain old PHP object or null
		// null means a Keep Alive (blank line)
		var_dump($data);
	}, [
		'track' => 'php'
	]);

} catch(FloodgateException $e) {
	// handle exceptions
}
```

## Twitter Documentation
- [Public Streams](https://dev.twitter.com/streaming/public)
- [Message Types](https://dev.twitter.com/streaming/overview/messages-types)

## Warning
Until a major version of the library gets released, the API may **change**!

## License
The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
