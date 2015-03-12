# Floodgate
This abstract class implements three of the four methods of the `FloodgateInterface` contract.

## Usage
In order to use the library, the abstract `Floodgate` class must be extended and the `getParameters()` method must be implemented.

## Implementation
The `getParameters()` method must return an associative `array`. The key/value pairs being returned should match the **GET** or **POST** parameters expected by the consumer method (`sample()`, `filter()`,`firehose()`) being called.

[Read](https://dev.twitter.com/streaming/reference/get/statuses/sample) [the](https://dev.twitter.com/streaming/reference/post/statuses/filter) [documentation](https://dev.twitter.com/streaming/reference/get/statuses/firehose) to know what parameters each method/API endpoint supports.

### Example #1
The following implementation is for use cases that **don't require** the Streaming API parameters to be updated. In this particular case, we want to continuously filter by the `php` keyword.

```php
class MyFloodgate extends Floodgate
{
   /**
    * {@inheritdoc}
    */
    public function getParameters()
    {
        return [
            'stall_warnings' => 'true',
            'track'          => 'php',
        ];
    }
}

```

### Example #2
On the other hand, there might be use cases where we need to update parameters on a regular basis (i.e. someone wants to add new keywords to the `track` predicate of a stream being consumed).

```php

// a Laravel Keyword model
use App\Keyword;

class MyFloodgate extends Floodgate
{
   /**
    * {@inheritdoc}
    */
    public function getParameters()
    {
        // get an array with all the keywords
        $keywords = Keyword::all()->lists('name');

        return [
            'stall_warnings' => 'true',
            'track'          => $keywords,
        ];
    }
}

```

In cases like this, reconnections to the Streaming API will be handled automatically by the library. 

To trigger a reconnection, the new and old parameters must be different and the elapsed time from the last (re)connection must be at least 300 seconds (5 minutes).

This delay is enforced to avoid many reconnections to the Streaming API in a short time period, which may get the account rate limited.

To change the delay value, override the `RECONNECTION_DELAY` constant in your implementation.

```php
class MyFloodgate extends Floodgate
{
    // delay reconnections for 10 minutes instead of 5
    const RECONNECTION_DELAY = 600;
}

```

## Reconnections
A reconnection is triggered when:

- The library detects that the parameters have changed and it's OK to do so
- We have been disconnected because we fell behind (most likely by using the `firehose.json` endpoint)
- We get an HTTP 503 response (server unavailable)
- We get an HTTP 420 response (rate limited)

In these two last cases, the library will apply a back off strategy, increasing the time between reconnections exponentially.

The default limit for those cases is `6` attempts before throwing a `FloodgateException`, but if needed, the value can be changed by overriding the `RECONNECTION_ATTEMPTS` constant.

```php
class MyFloodgate extends Floodgate
{
    // attempt only 3 reconnections before bailing out
    const RECONNECTION_ATTEMPTS = 3;
}

```

## Instantiation
The easiest way to create an instance of a class that extends from the `Floodgate` is to use the `create()` method.

```php
// Twitter OAuth configuration
$config = [
    // ...
];

// create a MyFloodgate instance
$stream = MyFloodgate::create($config);
```

## Consumer methods
The `sample()`, `filter()` and `firehose()` methods require a `Closure` argument. While consuming a stream, a Twitter message will be made available at each loop/cycle to it.

Twitter messages can either be `null` (keep-alive) or **Plain Old PHP Objects**, which can be a Tweet or one of the following message types:

- `delete`
- `scrub_geo`
- `limit`
- `status_withheld`
- `user_withheld`
- `disconnect`
- `warning`

For more information about the message types listed here, check the [documentation](https://dev.twitter.com/streaming/overview/messages-types).

By setting the `MESSAGE_AS_ASSOC` constant to `true`, Twitter messages will be passed as an associative `array` instead of a **Plain Old PHP Object**.

```php
class MyFloodgate extends Floodgate
{
    // pass Twitter messages as associative arrays
    const MESSAGE_AS_ASSOC = true;
}

```

### Handling data
The `Closure` each consumer method accepts as argument, is responsible for handling data from the stream.

Here's an example of how to save Tweets from verified users.
```php
$handler = function ($data) 
{
    // check if the message being passed is a Tweet
    // only Tweets have a created_at property
    if (isset($data->created_at)) {
        // check if the Tweet is from a verified User
        if ($data->user->verified) {
            // store the Tweet into the database
        }
    }
};
```

### Usage
Once the `Closure` is implemented, we can start using the consumer methods.

#### Sample
```php
$stream->sample($handler);
```

#### Filter
```php
$stream->filter($handler);
```

#### Firehose
```php
$stream->firehose($handler);
```
