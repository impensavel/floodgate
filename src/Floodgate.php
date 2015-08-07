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
     * Stall timeout (in seconds)
     *
     * @var  int
     */
    const STALL_TIMEOUT = 90;

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
     * @param   string    $endpoint  Streaming API endpoint
     * @param   Closure   $generator API endpoint parameter generator
     * @return  void
     */
    protected function register($endpoint, Closure $generator)
    {
        // store endpoint generator
        $this->generators[$endpoint] = $generator;

        // cache the generated endpoint arguments
        $this->cache[$endpoint] = $generator();
    }

    /**
     * Generate API endpoint parameters
     *
     * @access  protected
     * @param   string    $endpoint Streaming API endpoint
     * @throws  FloodgateException
     * @return  array
     */
    protected function generate($endpoint)
    {
        if (! isset($this->generators[$endpoint])) {
            throw new FloodgateException('Unregistered endpoint: '.$endpoint);
        }

        return $this->generators[$endpoint]();
    }

    /**
     * Trigger a reconnection
     *
     * @access  protected
     * @param   string    $endpoint Streaming API endpoint
     * @return  bool
     */
    protected function triggerReconnection($endpoint)
    {
        // check if we're allowed to reconnect
        if ((time() - $this->lastConnection) > static::RECONNECTION_DELAY) {
            $parameters = $this->generate($endpoint);

            $this->lastConnection = time();

            // trigger a reconnection if differences
            // exist between new and cached arguments
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
            $reason = ($status == 420) ? 'Enhance Your Calm' : $response->getReasonPhrase();

            throw new FloodgateException($reason, $status);
        }

        return $response->getBody();
    }

    /**
     * Stream processor
     *
     * @access  protected
     * @param   string                             $endpoint Streaming API endpoint
     * @param   Closure                            $handler  Data handler
     * @param   \GuzzleHttp\Stream\StreamInterface $stream
     * @return  void
     */
    protected function processor($endpoint, Closure $handler, StreamInterface $stream)
    {
        $stalled = time();

        while (($line = Utils::readline($stream)) !== false) {
            if (empty($line)) {
                if ((time() - $stalled) > static::STALL_TIMEOUT) {
                    break;
                }

                continue;
            }

            // pass each line to the data handler
            $handler(json_decode($line, static::MESSAGE_AS_ASSOC));

            if ($this->triggerReconnection($endpoint)) {
                break;
            }

            $stalled = time();
        }
    }

    /**
     * Consumption loop
     *
     * @access  protected
     * @param   string  $endpoint  Streaming API endpoint
     * @param   Closure $handler   Data handler
     * @param   Closure $generator API endpoint parameter generator
     * @param   string  $method    HTTP method
     * @throws  FloodgateException
     * @return  void
     */
    protected function consume($endpoint, Closure $handler, Closure $generator, $method = 'GET')
    {
        $this->register($endpoint, $generator);

        while (true) {
            $response = $this->open($endpoint, $method);

            // (re)set last connection timestamp
            $this->lastConnection = time();

            $this->processor($endpoint, $handler, $response);
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
    public function sample(Closure $handler, Closure $generator)
    {
        $this->consume('sample.json', $handler, $generator);
    }

    /**
     * {@inheritdoc}
     */
    public function filter(Closure $handler, Closure $generator)
    {
        $this->consume('filter.json', $handler, $generator, 'POST');
    }

    /**
     * {@inheritdoc}
     */
    public function firehose(Closure $handler, Closure $generator)
    {
        $this->consume('firehose.json', $handler, $generator);
    }
}
