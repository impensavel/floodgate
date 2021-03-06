# Floodgate
The `Floodgate` class handles all data consuming aspects for the Twitter Streaming API.

## Usage
This document contains usage examples, along with a brief explanation of available methods and configuration options.

## Instantiation
The easiest way to create a `Floodgate` instance is to use the `create()` method.

```php
$config = [
    'oauth'     => [
        'consumer_key'    => 'OADYKJgKogkkYtzdIKLZEq77Z',
        'consumer_secret' => 'Z0mImnDYzH3Tbe4eyQLQEA0lyzXsWFmmZsQTAYHtBrSBX04bKK',
        'token'           => '456786512-D4MnYQ3U74wd40zXHRHa495wl00ogOyhJu9iqEhz',
        'token_secret'    => 'EUyz6MawvBlabLAb2gY6fgyTagtMMYny7GmzKfulGo3Di',
    ],
];

$floodgate = Floodgate::create($config);
```

## Consumer methods
There are currently five consumer methods available in the class:

- Public Stream:
    - `sample()`
    - `filter()`
    - `firehose()`
- User Stream:
    - `user()`
- Site Stream:
    - `site()`

All of the above methods share the same signature, which requires two `Closure` type arguments to be passed in.

The first argument is the data handler, while the second one is the API endpoint parameter generator.

### Data handler
The data handler `Closure` deals with each Twitter message received from the stream. It's signature requires one argument (`$message`), which will hold the Twitter message.

Depending on the stream type (Public, User or Site), message types will differ.

- [Public Stream](https://dev.twitter.com/streaming/overview/messages-types#public_stream_messages)
- [User Stream](https://dev.twitter.com/streaming/overview/messages-types#user_stream_messsages)
- [Site Stream](https://dev.twitter.com/streaming/overview/messages-types#site_stream_messages)

By default, most Twitter messages will be passed in as **Plain Old PHP Objects** or `null` in case of a keep alive.

When the `message_as_array` option value is set to `true`, Twitter messages will be passed in as associative arrays instead.

```php
$config = [
    'floodgate' => [
        'message_as_array' => true,
    ],
    'oauth' => [
        // ...
    ],
];

$floodgate = Floodgate::create($config);
```

Here's an example of how to process Tweets from verified users.
```php
$handler = function ($message) 
{
    // check if the message being passed is a Tweet
    // as only Tweets have a created_at property
    if (isset($message->created_at)) {
        // check if the Tweet is from a verified User
        if ($message->user->verified) {
            // do something with the Tweet
        }
    }
};
```

### API endpoint parameter generator
The parameter generator `Closure` must return an associative `array`. The key/value pairs should match the **GET** or **POST** parameters expected by their respective endpoints.
It's signature requires no argument.

Check each API endpoint ([sample](https://dev.twitter.com/streaming/reference/get/statuses/sample), [filter](https://dev.twitter.com/streaming/reference/post/statuses/filter), [firehose](https://dev.twitter.com/streaming/reference/get/statuses/firehose), [user](https://dev.twitter.com/streaming/reference/get/user), [site](https://dev.twitter.com/streaming/reference/get/site)) documentation, to know what parameters are supported.

### Example #1
The following implementation is for use cases that **don't require** the Streaming API parameters to be updated. In this particular case, we want to continuously filter by the `php` keyword.

```php
// API endpoint parameter generator
$generator = function ()
{
    return [
        'stall_warnings' => 'true',
        'track'          => 'php',
    ];
}
```

### Example #2
On the other hand, there might be use cases where we need to update parameters on a regular basis (i.e. someone wants to add new keywords to the `track` predicate of a stream being consumed).

```php
// a Laravel 5.1 model
use App\Keyword;

// API endpoint parameter generator
$generator = function ()
{
    // get an array with all the keywords
    $keywords = Keyword::lists('name')->all();

    return [
        'stall_warnings' => 'true',
        'track'          => $keywords,
    ];
}
```

In cases like this, reconnections to the Streaming API will be handled automatically by the library.

To trigger a reconnection, the new and old parameters must be different and the elapsed time from the last (re)connection must be at least 300 seconds (5 minutes).

This delay is enforced to avoid reconnections to the Streaming API in a short time period, which may get the account rate limited.

To change the delay, set the `reconnection_delay` option value when creating a `Floodgate` object.

```php
$config = [
    'floodgate' => [
        'reconnection_delay' => 600,
    ],
    'oauth' => [
        // ...
    ],
];

$floodgate = Floodgate::create($config);
```

>**TIP**: By passing an `array` as a value (like `$keywords` in Example #2), the library will convert it into a comma separated string.

## Reconnections
A reconnection is triggered when:

- The library detects that the parameters have changed and it's OK to do so
- We have been disconnected because we fell behind (most likely by using the `firehose.json` endpoint)
- We get an HTTP 503 response (server unavailable)
- We get an HTTP 420 response (rate limited)

In these two last cases, the library will apply a back off strategy, increasing the time between reconnections exponentially.

The default limit for those cases is `6` before throwing a `FloodgateException`, but if needed, the number of attempts can be changed by setting the `attempts` option value when creating a `Floodgate` object.

```php
$config = [
    'retry' => [
        'attempts' => 3,
    ],
    'oauth' => [
        // ...
    ],
];

$floodgate = Floodgate::create($config);
```

### Usage
Once the data handler and the parameter generator are implemented, the consumer methods can be executed.

#### Sample
```php
$stream->sample($handler, $generator);
```

#### Filter
```php
$stream->filter($handler, $generator);
```

#### Firehose
```php
$stream->firehose($handler, $generator);
```

#### User
```php
$stream->user($handler, $generator);
```

#### Site
```php
$stream->site($handler, $generator);
```
