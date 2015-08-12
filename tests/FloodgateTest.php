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

use GuzzleHttp\Message\Response;
use GuzzleHttp\Subscriber\Mock;
use PHPUnit_Framework_TestCase;

class FloodgateTest extends PHPUnit_Framework_TestCase
{
    /**
     * Mock a Floodgate
     *
     * @access  private
     * @param   array
     * @return  Floodgate
     */
    private function floodmock(array $responses)
    {
        $floodgate = Floodgate::create([
            'mock'  => new Mock($responses),
            'retry' => [
                'backOff' => [
                    420 => 0,
                    503 => 0,
                ],
            ],
        ]);

        $this->assertInstanceOf('\Impensavel\Floodgate\Floodgate', $floodgate);

        return $floodgate;
    }

    /**
     * Test data handler Closure to PASS
     *
     * @access  public
     * @return  Closure
     */
    public function testHandlerPass()
    {
        $handler = function ($message)
        {
            // handle data
        };

        $this->assertTrue($handler instanceof Closure);

        return $handler;
    }

    /**
     * Test parameter generator Closure to PASS
     *
     * @access  public
     * @return  Closure
     */
    public function testGeneratorPass()
    {
        $generator = function ()
        {
            return [
                // parameters
            ];
        };

        $this->assertTrue($generator instanceof Closure);

        return $generator;
    }

    /**
     * Test filter() method to FAIL (Unauthorized)
     *
     * @expectedException         \Impensavel\Floodgate\FloodgateException
     * @expectedExceptionMessage  Unauthorized
     * @expectedExceptionCode     401
     *
     * @depends testHandlerPass
     * @depends testGeneratorPass
     * @param   Closure   $handler
     * @param   Closure   $generator
     * @return  void
     */
    public function testFilterFail401(Closure $handler, Closure $generator)
    {
        $floodgate = $this->floodmock([
            new Response(401),
        ]);

        $floodgate->filter($handler, $generator);
    }

    /**
     * Test filter() method to FAIL (Enhance Your Calm)
     *
     * @expectedException         \Impensavel\Floodgate\FloodgateException
     * @expectedExceptionMessage  Enhance Your Calm
     * @expectedExceptionCode     420
     *
     * @depends testHandlerPass
     * @depends testGeneratorPass
     * @param   Closure   $handler
     * @param   Closure   $generator
     * @return  void
     */
    public function testFilterFail420(Closure $handler, Closure $generator)
    {
        $floodgate = $this->floodmock([
            new Response(420),
            new Response(420),
            new Response(420),
            new Response(420),
            new Response(420),
            new Response(420),
            new Response(420),
        ]);

        $floodgate->filter($handler, $generator);
    }

    /**
     * Test filter() method to FAIL (Service Unavailable)
     *
     * @expectedException         \Impensavel\Floodgate\FloodgateException
     * @expectedExceptionMessage  Service Unavailable
     * @expectedExceptionCode     503
     *
     * @depends testHandlerPass
     * @depends testGeneratorPass
     * @param   Closure   $handler
     * @param   Closure   $generator
     * @return  void
     */
    public function testFilterFail503(Closure $handler, Closure $generator)
    {
        $floodgate = $this->floodmock([
            new Response(503),
            new Response(503),
            new Response(503),
            new Response(503),
            new Response(503),
            new Response(503),
            new Response(503),
        ]);

        $floodgate->filter($handler, $generator);
    }
}
