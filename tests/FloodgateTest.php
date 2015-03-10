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
use PHPUnit_Framework_TestCase;

class FloodgateTest extends PHPUnit_Framework_TestCase
{
    /**
     * Test create() method to PASS
     *
     * @access  public
     * @return  void
     */
    public function testCreatePass()
    {
        $stream = MyFloodgate::create([]);

        $this->assertInstanceOf('\Impensavel\Floodgate\Floodgate', $stream);
    }

    /**
     * Test instantiation to PASS
     *
     * @access  public
     * @return  FloodgateInterface
     */
    public function testInstancePass()
    {
        $http = new Client([
            'base_url' => MyFloodgate::STREAM_URL,
        ]);

        $this->assertInstanceOf('\GuzzleHttp\Client', $http);

        $responses = new Mock([
            // testFilterFail420
            new Response(420),

            // testFilterFail503
            new Response(503),

            // testFilterFail401
            new Response(401),

            // testFilterPass
            new Response(200),
        ]);

        $http->getEmitter()->attach($responses);

        $stream = new MyFloodgate($http, new Oauth1([]));

        $this->assertInstanceOf('\Impensavel\Floodgate\Floodgate', $stream);

        return $stream;
    }

    /**
     * Test filter() method to FAIL (Too many reconnects)
     *
     * @expectedException         \Impensavel\Floodgate\FloodgateException
     * @expectedExceptionMessage  Reached maximum reconnection attempts
     * @expectedExceptionCode     420
     *
     * @depends testInstancePass
     * @param   FloodgateInterface $stream
     * @return  void
     */
    public function testFilterFail420(FloodgateInterface $stream)
    {
        $stream->filter(function ($data) {});
    }

    /**
     * Test filter() method to FAIL (Server unavailable)
     *
     * @expectedException         \Impensavel\Floodgate\FloodgateException
     * @expectedExceptionMessage  Reached maximum reconnection attempts
     * @expectedExceptionCode     503
     *
     * @depends testInstancePass
     * @param   FloodgateInterface $stream
     * @return  void
     */
    public function testFilterFail503(FloodgateInterface $stream)
    {
        $stream->filter(function ($data) {});
    }

    /**
     * Test filter() method to FAIL (Unauthorized)
     *
     * @expectedException         \Impensavel\Floodgate\FloodgateException
     * @expectedExceptionMessage  Unauthorized
     * @expectedExceptionCode     401
     *
     * @depends testInstancePass
     * @param   FloodgateInterface $stream
     * @return  void
     */
    public function testFilterFail401(FloodgateInterface $stream)
    {
        $stream->filter(function ($data) {});
    }

    /**
     * Test filter() method to PASS
     *
     * @depends testInstancePass
     * @param   FloodgateInterface $stream
     * @return  void
     */
    public function testFilterPass(FloodgateInterface $stream)
    {
        $stream->filter(function ($data) {});
    }
}

/**
 * Floodgate test implementation
 */
class MyFloodgate extends Floodgate
{
    // do not attempt reconnections
    const RECONNECTION_ATTEMPTS = 0;

    // override values to avoid exponential back offs
    protected static $backOff = [
        200 => 0,
        420 => 0,
        503 => 0,
        401 => 0, // fixes Undefined offset error
    ];

    public function getParameters()
    {
        return [];
    }

    // read once implementation
    protected function consume($endpoint, Closure $callback, $method = 'GET')
    {
        $this->parameters = $this->getParameters();

        $response = $this->open($endpoint, $method);

        if ($response) {
            $this->processor($callback, $response);
        }
    }
}
