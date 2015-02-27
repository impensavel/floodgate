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
use GuzzleHttp\Message\ResponseInterface;
use GuzzleHttp\Stream\Utils;
use GuzzleHttp\Subscriber\Oauth\Oauth1;

class Floodgate
{
    /**
     * Twitter Streaming API URLs
     */
    const STREAM_URL      = 'https://stream.twitter.com/1.1/statuses/';
    const SITE_STREAM_URL = 'https://sitestream.twitter.com/1.1/';
    const USER_STREAM_URL = 'https://userstream.twitter.com/1.1/';

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
     * Response processor
     *
     * @access  private
     * @param   Closure                               $callback
     * @param   \GuzzleHttp\Message\ResponseInterface $response
     * @throws  FloodgateException
     * @return  void
     */
    private function processor(Closure $callback, ResponseInterface $response)
    {
        $status = $response->getStatusCode();

        if ($status != 200) {
            throw new FloodgateException($response->getReasonPhrase(), $status);
        }

        $stream = $response->getBody();

        // read stream continuously and pass each line to the callback
        while (! $stream->eof()) {
            $line = Utils::readline($stream);

            $data = json_decode($line);

            $callback($data);
        }
    }

    /**
     * Open the Floodgate
     *
     * @access  public
     * @param   string $endpoint   Streaming API endpoint
     * @param   array  $parameters Streaming API parameters
     * @param   string $method     HTTP method
     * @return  \GuzzleHttp\Message\ResponseInterface
     */
    public function open($endpoint, array $parameters = [], $method = 'GET')
    {
        $options = [
            'exceptions' => false,
            'stream'     => true,
            'auth'       => 'oauth',
        ];

        // set option name according to the HTTP method
        $name = (strtolower($method) == 'post') ? 'body' : 'query';

        foreach ($parameters as $field => $value) {
            $options[$name][$field] = is_array($value) ? implode(',', $value) : $value;
        }

        $response = $this->http->createRequest($method, $endpoint, $options);

        return $this->http->send($response);
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
        $response = $this->open('filter.json', $parameters);

        $this->processor($callback, $response);
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
        $response = $this->open('filter.json', $parameters, 'POST');

        $this->processor($callback, $response);
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
        $response = $this->open('firehose.json', $parameters);

        $this->processor($callback, $response);
    }
}
