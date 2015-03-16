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
use GuzzleHttp\Message\Response;
use GuzzleHttp\Subscriber\Mock;
use GuzzleHttp\Subscriber\Oauth\Oauth1;
use GuzzleHttp\Subscriber\Retry\RetrySubscriber;
use PHPUnit_Framework_TestCase;

class FloodgateTest extends PHPUnit_Framework_TestCase
{
    /**
     * Mock a MyFloodgate
     *
     * @access  private
     * @param   array
     * @return  Floodgate
     */
    private function mockGate(array $responses)
    {
        $http = new Client([
            'base_url' => Floodgate::STREAM_URL,
            'defaults' => [
                'exceptions' => false,
                'stream'     => true,
                'auth'       => 'oauth',
                'headers'    => [
                    'User-Agent' => 'Floodgate/1.0',
                ],
            ],
        ]);

        $this->assertInstanceOf('\GuzzleHttp\Client', $http);

        $http->getEmitter()->attach(new Mock($responses));

        $stream = new MyFloodgate($http, new Oauth1([]), new RetrySubscriber([
            'filter' => MyFloodgate::applyBackOffStrategy(),
            'delay'  => MyFloodgate::backOffStrategyDelay(),
            'max'    => MyFloodgate::RECONNECTION_ATTEMPTS,
        ]));

        $this->assertInstanceOf('\Impensavel\Floodgate\MyFloodgate', $stream);

        return $stream;
    }

    /**
     * Test data handler callback to PASS
     *
     * @access  public
     * @return  Closure
     */
    public function testCallbackHandler()
    {
        $handler = function () {};

        $this->assertTrue(is_callable($handler));

        return $handler;
    }

    /**
     * Test generator callback to PASS
     *
     * @access  public
     * @return  Closure
     */
    public function testCallbackGenerator()
    {
        $generator = function () {
            return [];
        };

        $this->assertTrue(is_callable($generator));

        return $generator;
    }

    /**
     * Test filter() method to FAIL (Unauthorized)
     *
     * @expectedException         \Impensavel\Floodgate\FloodgateException
     * @expectedExceptionMessage  Unauthorized
     * @expectedExceptionCode     401
     *
     * @depends testCallbackHandler
     * @depends testCallbackGenerator
     * @param   Closure   $handler
     * @param   Closure   $generator
     * @return  void
     */
    public function testFilterFail401(Closure $handler, Closure $generator)
    {
        $stream = $this->mockGate([
            new Response(401),
        ]);

        $stream->filter($handler, $generator);
    }

    /**
     * Test filter() method to FAIL (Enhance Your Calm)
     *
     * @expectedException         \Impensavel\Floodgate\FloodgateException
     * @expectedExceptionMessage  Enhance Your Calm
     * @expectedExceptionCode     420
     *
     * @depends testCallbackHandler
     * @depends testCallbackGenerator
     * @param   Closure   $handler
     * @param   Closure   $generator
     * @return  void
     */
    public function testFilterFail420(Closure $handler, Closure $generator)
    {
        $stream = $this->mockGate([
            new Response(420),
            new Response(420),
            new Response(420),
            new Response(420),
            new Response(420),
            new Response(420),
            new Response(420),
        ]);

        $stream->filter($handler, $generator);
    }

    /**
     * Test filter() method to FAIL (Service Unavailable)
     *
     * @expectedException         \Impensavel\Floodgate\FloodgateException
     * @expectedExceptionMessage  Service Unavailable
     * @expectedExceptionCode     503
     *
     * @depends testCallbackHandler
     * @depends testCallbackGenerator
     * @param   Closure   $handler
     * @param   Closure   $generator
     * @return  void
     */
    public function testFilterFail503(Closure $handler, Closure $generator)
    {
        $stream = $this->mockGate([
            new Response(503),
            new Response(503),
            new Response(503),
            new Response(503),
            new Response(503),
            new Response(503),
            new Response(503),
        ]);

        $stream->filter($handler, $generator);
    }
}

/**
 * Floodgate test implementation
 */
class MyFloodgate extends Floodgate
{
    // override values to avoid exponential back offs
    protected static $backOff = [
        420 => 0,
        503 => 0,
    ];
}
