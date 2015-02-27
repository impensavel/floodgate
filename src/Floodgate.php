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

class Floodgate
{
    /**
     * Twitter Streaming API URL
     */
    const STREAM_URL = 'https://stream.twitter.com/1.1/statuses/';

    /**
     * HTTP sleep intervals
     *
     * @static
     * @access  private
     * @var     array
     */
    private static $intervals = [
        200 => 0,  // OK
        420 => 60, // too many reconnects
        503 => 5,  // server unavailable
    ];

    /**
     * Last connection timestamp
     *
     * @access  private
     * @var     int
     */
    private $lastConnection = 0;

    /**
     * Last data received timestamp
     *
     * @access  private
     * @var     int
     */
    private $lastReception = 0;

    /**
     * Last HTTP status
     *
     * @access  private
     * @var     int
     */
    private $lastStatus = 200;

    /**
     * Reconnection attempts
     *
     * @access  private
     * @var     int
     */
    private $attempts = 0;

    /**
     * Enable connection persistence
     *
     * @access  private
     * @var     bool
     */
    private $persist = true;

    /**
     * HTTP Client object
     *
     * @access  private
     * @var     \GuzzleHttp\Client
     */
    private $http;

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
     * @access  private
     * @return  bool
     */
    private function isStalled()
    {
        // Twitter considers a connection stalled
        // when 90 seconds (3 cycles) have passed
        // since the last data was received
        return (time() - $this->lastReception) > 90;
    }

    /**
     * Consumption loop
     *
     * @access  private
     * @param   string  $endpoint   Streaming API endpoint
     * @param   Closure $callback
     * @param   array   $parameters Streaming API parameters
     * @param   string  $method     HTTP method
     * @return  void
     */
    private function loop($endpoint, Closure $callback, array $parameters = [], $method = 'GET')
    {
        do {
            $response = $this->open($endpoint, $parameters, $method);

            if ($response) {
                $this->processor($callback, $response);
            }

        } while ($this->persist);
    }

    /**
     * Stream processor
     *
     * @access  private
     * @param   Closure                            $callback
     * @param   \GuzzleHttp\Stream\StreamInterface $stream
     * @throws  FloodgateException
     * @return  void
     */
    private function processor(Closure $callback, StreamInterface $stream)
    {
        // (re)set last received data timestamp
        $this->lastReception = time();

        while (! $stream->eof()) {
            $line = Utils::readline($stream);

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
     * @access  public
     * @param   string $endpoint   Streaming API endpoint
     * @param   array  $parameters Streaming API parameters
     * @param   string $method     HTTP method
     * @return  \GuzzleHttp\Stream\StreamInterface|bool
     */
    public function open($endpoint, array $parameters = [], $method = 'GET')
    {
        $options = [
            'exceptions' => false,
            'stream'     => true,
            'auth'       => 'oauth',
        ];

        // set option name according to the HTTP method in use
        $name = (strtolower($method) == 'post') ? 'body' : 'query';

        // set endpoint parameters
        foreach ($parameters as $field => $value) {
            $options[$name][$field] = is_array($value) ? implode(',', $value) : $value;
        }

        $request = $this->http->createRequest($method, $endpoint, $options);

        // back off exponentially
        sleep(static::$intervals[$this->lastStatus] * pow(2, $this->attempts++));

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

            // assume everything else is unrecoverable
            default:
                throw new FloodgateException($response->getReasonPhrase(), $this->lastStatus);
        }
    }

    /**
     * Streaming API Sample endpoint
     *
     * @access  public
     * @param   Closure $callback
     * @param   array   $parameters Streaming API parameters
     * @throws  FloodgateException
     * @return  void
     */
    public function sample(Closure $callback, array $parameters = [])
    {
        $this->loop('sample.json', $callback, $parameters);
    }

    /**
     * Streaming API Filter endpoint
     *
     * @access  public
     * @param   Closure $callback
     * @param   array   $parameters Streaming API parameters
     * @throws  FloodgateException
     * @return  void
     */
    public function filter(Closure $callback, array $parameters = [])
    {
        $this->loop('filter.json', $callback, $parameters, 'POST');
    }

    /**
     * Streaming API Firehose endpoint
     *
     * @access  public
     * @param   Closure $callback   Callback to
     * @param   array   $parameters Streaming API parameters
     * @throws  FloodgateException
     * @return  void
     */
    public function firehose(Closure $callback, array $parameters = [])
    {
        $this->loop('firehose.json', $callback, $parameters);
    }
}
