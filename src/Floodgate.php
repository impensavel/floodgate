<?php
/**
 * This file is part of the Floodgate library.
 *
 * @author     Quetzy Garcia <quetzyg@impensavel.com>
 * @copyright  2015
 *
 * For the full copyright and license information,
 * please view the LICENSE.md file that was distributed
 * with this source code.
 */

namespace Impensavel\Floodgate;

use Closure;

use GuzzleHttp\Client;
use GuzzleHttp\Event\AbstractTransferEvent;
use GuzzleHttp\Stream\StreamInterface;
use GuzzleHttp\Stream\Utils;
use GuzzleHttp\Subscriber\Oauth\Oauth1;
use GuzzleHttp\Subscriber\Retry\RetrySubscriber;

class Floodgate implements FloodgateInterface
{
    /**
     * Twitter Streaming API URL
     *
     * @var  string
     */
    const STREAM_URL = 'https://stream.twitter.com/1.1/statuses/';

    /**
     * Reconnection delay (in seconds)
     *
     * @var  int
     */
    const RECONNECTION_DELAY = 300;

    /**
     * Reconnection attempts
     *
     * @var  int
     */
    const RECONNECTION_ATTEMPTS = 6;

    /**
     * Reconnection back off strategy values
     *
     * @access  protected
     * @var     array
     */
    protected static $backOff = [
        420 => 60, // Enhance Your Calm
        503 => 5,  // Service Unavailable
    ];

    /**
     * Twitter message as associative array?
     *
     * @var  bool
     */
    const MESSAGE_AS_ASSOC = false;

    /**
     * Last connection timestamp
     *
     * @access  protected
     * @var     int
     */
    protected $lastConnection = 0;

    /**
     * Streaming API parameter generators
     *
     * @access  protected
     * @var     array
     */
    protected $generators = [];

    /**
     * Streaming API parameter cache
     *
     * @access  protected
     * @var     array
     */
    protected $cache = [];

    /**
     * HTTP Client object
     *
     * @access  protected
     * @var     \GuzzleHttp\Client
     */
    protected $http;

    /**
     * Floodgate constructor
     *
     * @access  public
     * @param   \GuzzleHttp\Client                           $http
     * @param   \GuzzleHttp\Subscriber\Oauth\Oauth1          $oauth
     * @param   \GuzzleHttp\Subscriber\Retry\RetrySubscriber $retry
     * @return  Floodgate
     */
    public function __construct(Client $http, Oauth1 $oauth, RetrySubscriber $retry)
    {
        $this->http = $http;

        $this->http->getEmitter()->attach($oauth);
        $this->http->getEmitter()->attach($retry);
    }

    /**
     * Create a Floodgate
     *
     * @static
     * @access  public
     * @param   array  $config Twitter OAuth configuration
     * @return  Floodgate
     */
    public static function create(array $config)
    {
        $http = new Client([
            'base_url' => static::STREAM_URL,
            'defaults' => [
                'exceptions' => false,
                'stream'     => true,
                'auth'       => 'oauth',
                'headers'    => [
                    'User-Agent' => 'Floodgate/1.0',
                ],
            ],
        ]);

        $oauth = new Oauth1($config);

        $retry = new RetrySubscriber([
            'filter' => static::applyBackOffStrategy(),
            'delay'  => static::backOffStrategyDelay(),
            'max'    => static::RECONNECTION_ATTEMPTS,
        ]);

        return new static($http, $oauth, $retry);
    }

    /**
     * Register a generator
     *
     * @access  protected
     * @param   string    $endpoint
     * @param   Closure   $generator
     * @return  void
     */
    protected function register($endpoint, Closure $generator)
    {
        $this->generators[$endpoint] = $generator;
        $this->cache[$endpoint] = $generator();
    }

    /**
     * Generate API endpoint parameters
     *
     * @access  protected
     * @param   string    $endpoint
     * @throws  FloodgateException
     * @return  array
     */
    protected function generate($endpoint)
    {
        if (! isset($this->generators[$endpoint])) {
            throw new FloodgateException('Invalid endpoint: '.$endpoint);
        }

        return $this->generators[$endpoint]();
    }

    /**
     * Are we ready to reconnect?
     *
     * @access  protected
     * @param   string    $endpoint
     * @return  bool
     */
    protected function readyToReconnect($endpoint)
    {
        // check if we're allowed to reconnect
        if ((time() - $this->lastConnection) > static::RECONNECTION_DELAY) {
            $parameters = $this->generate($endpoint);

            // if differences are found, update parameters
            if ($this->cache[$endpoint] != $parameters) {
                $this->cache[$endpoint] = $parameters;

                return true;
            }
        }

        return false;
    }

    /**
     * Open the Floodgate
     *
     * @access  protected
     * @param   string $endpoint Streaming API endpoint
     * @param   string $method   HTTP method
     * @throws  FloodgateException
     * @return  \GuzzleHttp\Stream\StreamInterface
     */
    protected function open($endpoint, $method = 'GET')
    {
        $parameters = [];

        foreach ($this->cache[$endpoint] as $field => $value) {
            $parameters[$field] = is_array($value) ? implode(',', $value) : $value;
        }

        $request = $this->http->createRequest($method, $endpoint, [
            (strtolower($method) == 'post') ? 'body' : 'query' => $parameters,
        ]);

        $response = $this->http->send($request);

        $status = $response->getStatusCode();

        if ($status != 200) {
            switch ($status) {
                case 420:
                    $reason = 'Enhance Your Calm';
                    break;

                default:
                    $reason = $response->getReasonPhrase();
                    break;
            }

            throw new FloodgateException($reason, $status);
        }

        return $response->getBody();
    }

    /**
     * Stream processor
     *
     * @access  protected
     * @param   string                             $endpoint
     * @param   Closure                            $callback
     * @param   \GuzzleHttp\Stream\StreamInterface $stream
     * @return  void
     */
    protected function processor($endpoint, Closure $callback, StreamInterface $stream)
    {
        while (($line = Utils::readline($stream)) !== false) {
            // pass each line to the callback
            $callback(json_decode($line, static::MESSAGE_AS_ASSOC));

            if ($this->readyToReconnect($endpoint)) {
                break;
            }
        }
    }

    /**
     * Consumption loop
     *
     * @access  protected
     * @param   string  $endpoint  Streaming API endpoint
     * @param   Closure $callback  Data handler callback
     * @param   Closure $generator API endpoint parameter generator
     * @param   string  $method    HTTP method
     * @throws  FloodgateException
     * @return  void
     */
    protected function consume($endpoint, Closure $callback, Closure $generator, $method = 'GET')
    {
        $this->register($endpoint, $generator);

        while (true) {
            $response = $this->open($endpoint, $method);

            // (re)set last connection timestamp
            $this->lastConnection = time();

            $this->processor($endpoint, $callback, $response);
        }
    }

    /**
     * {@inheritdoc}
     */
    public static function applyBackOffStrategy()
    {
        return function ($retries, AbstractTransferEvent $event)
        {
            if ($event->hasResponse()) {
                $status = $event->getResponse()->getStatusCode();

                return array_key_exists($status, static::$backOff);
            }

            return false;
        };
    }

    /**
     * {@inheritdoc}
     */
    public static function backOffStrategyDelay()
    {
        return function ($retries, AbstractTransferEvent $event)
        {
            if ($event->hasResponse()) {
                $status = $event->getResponse()->getStatusCode();

                // back off exponentially
                return static::$backOff[$status] * pow(2, $retries);
            }

            return 0;
        };
    }

    /**
     * {@inheritdoc}
     */
    public function sample(Closure $callback, Closure $generator)
    {
        $this->consume('sample.json', $callback, $generator);
    }

    /**
     * {@inheritdoc}
     */
    public function filter(Closure $callback, Closure $generator)
    {
        $this->consume('filter.json', $callback, $generator, 'POST');
    }

    /**
     * {@inheritdoc}
     */
    public function firehose(Closure $callback, Closure $generator)
    {
        $this->consume('firehose.json', $callback, $generator);
    }
}
