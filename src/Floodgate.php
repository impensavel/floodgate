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

abstract class Floodgate implements FloodgateInterface
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
        420 => 60, // too many reconnects
        503 => 5,  // server unavailable
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
     * Streaming API parameters
     *
     * @access  protected
     * @var     array
     */
    protected $parameters = [];

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
                'headers' => [
                    'User-Agent' => 'Floodgate/1.0',
                ],
            ],
        ]);

        $oauth = new Oauth1($config);

        $retry = new RetrySubscriber([
            'filter' => static::applyBackOffStrategy(),
            'delay'  => static::backOffStrategyDelay(),
            'max'    => static::RECONNECTION_ATTEMPTS + 1,
        ]);

        return new static($http, $oauth, $retry);
    }

    /**
     * Are we ready to reconnect?
     *
     * @access  protected
     * @return  bool
     */
    protected function readyToReconnect()
    {
        // check if we're allowed to reconnect
        if ((time() - $this->lastConnection) > static::RECONNECTION_DELAY) {
            $parameters = $this->getParameters();

            // if differences are found, update parameters
            if ($this->parameters != $parameters) {
                $this->parameters = $parameters;

                return true;
            }
        }

        return false;
    }

    /**
     * Consumption loop
     *
     * @access  protected
     * @param   string  $endpoint   Streaming API endpoint
     * @param   Closure $callback   Data handler callback
     * @param   string  $method     HTTP method
     * @throws  FloodgateException
     * @return  void
     */
    protected function consume($endpoint, Closure $callback, $method = 'GET')
    {
        $this->parameters = $this->getParameters();

        while (true) {
            $response = $this->open($endpoint, $method);

            // (re)set last connection timestamp
            $this->lastConnection = time();

            if ($response) {
                $this->processor($callback, $response);
            }
        }
    }

    /**
     * Stream processor
     *
     * @access  protected
     * @param   Closure                            $callback
     * @param   \GuzzleHttp\Stream\StreamInterface $stream
     * @return  void
     */
    protected function processor(Closure $callback, StreamInterface $stream)
    {
        while (($line = Utils::readline($stream)) !== false) {
            // pass each line to the callback
            $callback(json_decode($line, static::MESSAGE_AS_ASSOC));

            if ($this->readyToReconnect()) {
                break;
            }
        }
    }

    /**
     * Open the Floodgate
     *
     * @access  protected
     * @param   string $endpoint   Streaming API endpoint
     * @param   string $method     HTTP method
     * @throws  FloodgateException
     * @return  \GuzzleHttp\Stream\StreamInterface|bool
     */
    protected function open($endpoint, $method = 'GET')
    {
        $options = [
            'exceptions' => false,
            'stream'     => true,
            'auth'       => 'oauth',
        ];

        // set option name according to the HTTP method in use
        $name = (strtolower($method) == 'post') ? 'body' : 'query';

        // set endpoint parameters
        foreach ($this->parameters as $field => $value) {
            $options[$name][$field] = is_array($value) ? implode(',', $value) : $value;
        }

        $request = $this->http->createRequest($method, $endpoint, $options);

        $response = $this->http->send($request);

        return $response->getBody();
    }

    /**
     * {@inheritdoc}
     */
    public static function applyBackOffStrategy()
    {
        return function ($retries, AbstractTransferEvent $event)
        {
            $response = $event->getResponse();

            if ($response) {
                $status = $response->getStatusCode();

                if ($retries >= static::RECONNECTION_ATTEMPTS) {
                    throw new FloodgateException('Reached maximum reconnection attempts', $status);
                }

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
            $response = $event->getResponse();

            // back off exponentially
            if ($response) {
                return static::$backOff[$response->getStatusCode()] * pow(2, $retries);
            }

            return 0;
        };
    }

    /**
     * {@inheritdoc}
     */
    public function sample(Closure $callback)
    {
        $this->consume('sample.json', $callback);
    }

    /**
     * {@inheritdoc}
     */
    public function filter(Closure $callback)
    {
        $this->consume('filter.json', $callback, 'POST');
    }

    /**
     * {@inheritdoc}
     */
    public function firehose(Closure $callback)
    {
        $this->consume('firehose.json', $callback);
    }
}
