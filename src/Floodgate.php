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
use GuzzleHttp\Subscriber\Mock;
use GuzzleHttp\Subscriber\Oauth\Oauth1;
use GuzzleHttp\Subscriber\Retry\RetrySubscriber;

class Floodgate implements FloodgateInterface
{
    /**
     * Reconnection delay (in seconds)
     *
     * @access  protected
     * @var     int
     */
    protected $reconnectionDelay = 300;

    /**
     * Stall timeout (in seconds)
     *
     * @access  protected
     * @var     int
     */
    protected $stallTimeout = 90;

    /**
     * Twitter message as associative array?
     *
     * @access  protected
     * @var     bool
     */
    protected $messageAsArray = false;

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
     * HTTP Client
     *
     * @access  protected
     * @var     \GuzzleHttp\Client
     */
    protected $http;

    /**
     * Floodgate constructor
     *
     * @access  public
     * @param   \GuzzleHttp\Client $http   HTTP client
     * @param   array              $config Floodgate configuration
     * @return  Floodgate
     */
    public function __construct(Client $http, array $config = [])
    {
        $this->http = $http;

        // configuration defaults
        $config = array_replace([
            'reconnection_delay' => 300,
            'stall_timeout'      => 90,
            'message_as_array'   => false,
        ], $config);

        $this->reconnectionDelay = $config['reconnection_delay'];
        $this->stallTimeout = $config['stall_timeout'];
        $this->messageAsArray = $config['message_as_array'];
    }

    /**
     * Create a Floodgate object
     *
     * @static
     * @access  public
     * @param   array  $config Configurations
     * @return  Floodgate
     */
    public static function create(array $config = [])
    {
        // configuration defaults
        $config = array_replace_recursive([
            'floodgate' => [],
            'http'      => [
                'defaults' => [
                    'headers' => [
                        'User-Agent' => 'Floodgate/2.0',
                    ],
                ],
            ],
            'oauth'     => [],
            'retry'     => [
                'attempts' => 6,
                'back_off' => [
                    420 => 60, // Enhance Your Calm
                    503 => 5,  // Service Unavailable
                ],
            ],
            'mock'      => null,
        ], $config, [
            'http' => [
                'defaults' => [
                    'exceptions' => false,
                    'stream'     => true,
                    'auth'       => 'oauth',
                ],
            ],
        ]);

        $http = new Client($config['http']);

        $oauth = new Oauth1($config['oauth']);

        $retry = new RetrySubscriber([
            'filter' => static::applyBackOffStrategy($config['retry']['back_off']),
            'delay'  => static::backOffStrategyDelay($config['retry']['back_off']),
            'max'    => $config['retry']['attempts'],
        ]);

        $http->getEmitter()->attach($oauth);
        $http->getEmitter()->attach($retry);

        // make testing easy
        if ($config['mock'] instanceof Mock) {
            $http->getEmitter()->attach($config['mock']);
        }

        return new static($http, $config['floodgate']);
    }

    /**
     * Register an API endpoint generator
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
        $this->cache[$endpoint] = call_user_func($generator);
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

        return call_user_func($this->generators[$endpoint]);
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
        if ((time() - $this->lastConnection) > $this->reconnectionDelay) {
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
                if ((time() - $stalled) > $this->stallTimeout) {
                    break;
                }

                continue;
            }

            // pass each line to the data handler
            $handler(json_decode($line, $this->messageAsArray));

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
    public static function applyBackOffStrategy(array $backOff)
    {
        return function ($retries, AbstractTransferEvent $event) use ($backOff)
        {
            if ($event->hasResponse()) {
                $status = $event->getResponse()->getStatusCode();

                return array_key_exists($status, $backOff);
            }

            return false;
        };
    }

    /**
     * {@inheritdoc}
     */
    public static function backOffStrategyDelay(array $backOff)
    {
        return function ($retries, AbstractTransferEvent $event) use ($backOff)
        {
            if ($event->hasResponse()) {
                $status = $event->getResponse()->getStatusCode();

                // back off exponentially
                return $backOff[$status] * pow(2, $retries);
            }

            return 0;
        };
    }

    /**
     * {@inheritdoc}
     */
    public function sample(Closure $handler, Closure $generator)
    {
        $this->consume('https://stream.twitter.com/1.1/statuses/sample.json', $handler, $generator);
    }

    /**
     * {@inheritdoc}
     */
    public function filter(Closure $handler, Closure $generator)
    {
        $this->consume('https://stream.twitter.com/1.1/statuses/filter.json', $handler, $generator, 'POST');
    }

    /**
     * {@inheritdoc}
     */
    public function firehose(Closure $handler, Closure $generator)
    {
        $this->consume('https://stream.twitter.com/1.1/statuses/firehose.json', $handler, $generator);
    }

    /**
     * {@inheritdoc}
     */
    public function user(Closure $handler, Closure $generator)
    {
        $this->consume('https://userstream.twitter.com/1.1/user.json', $handler, $generator);
    }

    /**
     * {@inheritdoc}
     */
    public function site(Closure $handler, Closure $generator)
    {
        $this->consume('https://sitestream.twitter.com/1.1/site.json', $handler, $generator);
    }
}
