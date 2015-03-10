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
use GuzzleHttp\Stream\StreamInterface;
use GuzzleHttp\Stream\Utils;
use GuzzleHttp\Subscriber\Oauth\Oauth1;

abstract class Floodgate implements FloodgateInterface
{
    /**
     * Twitter Streaming API URL
     */
    const STREAM_URL = 'https://stream.twitter.com/1.1/statuses/';

    /**
     * Reconnection delay (in seconds)
     */
    const RECONNECTION_DELAY = 300;

    /**
     * Reconnection attempts
     */
    const RECONNECTION_ATTEMPTS = 6;

    /**
     * Twitter message as associative array?
     */
    const MESSAGE_AS_ASSOC = false;

    /**
     * Back off values for reconnection
     *
     * @static
     * @access  protected
     * @var     array
     */
    protected static $backOff = [
        200 => 0,  // OK
        420 => 60, // too many reconnects
        503 => 5,  // server unavailable
    ];

    /**
     * Last connection timestamp
     *
     * @access  protected
     * @var     int
     */
    protected $lastConnection = 0;

    /**
     * Last HTTP status
     *
     * @access  protected
     * @var     int
     */
    protected $lastStatus = 200;

    /**
     * Reconnection attempts
     *
     * @access  protected
     * @var     int
     */
    protected $attempts = 0;

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
     * @param   \GuzzleHttp\Client                  $http
     * @param   \GuzzleHttp\Subscriber\Oauth\Oauth1 $oauth
     * @return  Floodgate
     */
    public function __construct(Client $http, Oauth1 $oauth)
    {
        $this->http = $http;

        $this->http->getEmitter()->attach($oauth);
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

        return new static($http, $oauth);
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
     * @param   Closure $callback
     * @param   string  $method     HTTP method
     * @throws  FloodgateException
     * @return  void
     */
    protected function consume($endpoint, Closure $callback, $method = 'GET')
    {
        $this->parameters = $this->getParameters();

        while (true) {
            $response = $this->open($endpoint, $method);

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
            if ($this->readyToReconnect()) {
                break;
            }

            // pass each line to the callback
            $callback(json_decode($line, static::MESSAGE_AS_ASSOC));
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

        // back off exponentially
        sleep(static::$backOff[$this->lastStatus] * pow(2, $this->attempts++));

        $response = $this->http->send($request);

        // save latest HTTP status code
        $this->lastStatus = $response->getStatusCode();

        // (re)set last connection timestamp
        $this->lastConnection = time();

        // act according to HTTP status
        switch ($this->lastStatus) {
            case 200: // OK
                $this->attempts = 0;

                return $response->getBody();

            case 420: // too many reconnects
            case 503: // server unavailable
                if ($this->attempts > static::RECONNECTION_ATTEMPTS) {
                    throw new FloodgateException('Reached maximum connection attempts', $this->lastStatus);
                }

                return false;

            // everything else should be unrecoverable
            default:
                throw new FloodgateException($response->getReasonPhrase(), $this->lastStatus);
        }
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
