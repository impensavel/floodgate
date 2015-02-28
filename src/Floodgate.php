<?php
/**
 * This file is part of the Floodgate library.
 *
 * @author     Quetzy Garcia <quetzyg@impensavel.com>
 * @copyright  2015
 *
 * For the full copyright and license information,
 * please view the LICENSE file that was distributed
 * with this source code.
 */

namespace Impensavel\Floodgate;

use Closure;

use GuzzleHttp\Client;
use GuzzleHttp\Stream\StreamInterface;
use GuzzleHttp\Stream\Utils;
use GuzzleHttp\Subscriber\Oauth\Oauth1;

abstract class Floodgate
{
    /**
     * Twitter Streaming API URL
     */
    const STREAM_URL = 'https://stream.twitter.com/1.1/statuses/';

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
     * Last data received timestamp
     *
     * @access  protected
     * @var     int
     */
    protected $lastReception = 0;

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
     * Reconnect?
     *
     * @access  protected
     * @var     bool
     */
    protected $reconnect = true;

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
        $config = array_merge([
            'consumer_key'    => '',
            'consumer_secret' => '',
            'token'           => '',
            'token_secret'    => '',
        ], $config);

        $http = new Client([
            'base_url' => static::STREAM_URL,
        ]);

        $oauth = new Oauth1($config);

        return new static($http, $oauth);
    }

    /**
     * Check if the connection is stalled
     *
     * @access  protected
     * @return  bool
     */
    protected function isStalled()
    {
        // Twitter considers a connection stalled
        // when 90 seconds (3 cycles) have passed
        // since the last data was received
        return (time() - $this->lastReception) > 90;
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
        do {
            $this->parameters = $this->getParameters();

            $response = $this->open($endpoint, $method);

            if ($response) {
                $this->processor($callback, $response);
            }

        } while ($this->reconnect);
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
        // (re)set last received data timestamp
        $this->lastReception = time();

        while (($line = Utils::readline($stream)) !== false) {

            if ($this->isStalled()) {
                break;
            }

            // pass each line to the callback
            $callback(json_decode($line));

            // update last data received timestamp
            $this->lastReception = time();
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
                if ($this->attempts > 6) {
                    throw new FloodgateException('Reached maximum connection attempts', $this->lastStatus);
                }

                return false;

            // everything else should be unrecoverable
            default:
                throw new FloodgateException($response->getReasonPhrase(), $this->lastStatus);
        }
    }

    /**
     * Get the Twitter Streaming API parameters
     *
     * @access  public
     * @return  array
     */
    abstract public function getParameters();

    /**
     * Streaming API Sample endpoint
     *
     * @access  public
     * @param   Closure $callback
     * @throws  FloodgateException
     * @return  void
     */
    public function sample(Closure $callback)
    {
        $this->consume('sample.json', $callback);
    }

    /**
     * Streaming API Filter endpoint
     *
     * @access  public
     * @param   Closure $callback
     * @throws  FloodgateException
     * @return  void
     */
    public function filter(Closure $callback)
    {
        $this->consume('filter.json', $callback, 'POST');
    }

    /**
     * Streaming API Firehose endpoint
     *
     * @access  public
     * @param   Closure $callback  Callback to
     * @throws  FloodgateException
     * @return  void
     */
    public function firehose(Closure $callback)
    {
        $this->consume('firehose.json', $callback);
    }
}
