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

interface FloodgateInterface
{
    /**
     * Apply a back off strategy?
     *
     * @static
     * @access  public
     * @param   array  $backOff Reconnection back off strategy values
     * @return  Closure
     */
    public static function applyBackOffStrategy(array $backOff);

    /**
     * Get the back off strategy delay
     *
     * @static
     * @access  public
     * @param   array  $backOff Reconnection back off strategy values
     * @return  Closure
     */
    public static function backOffStrategyDelay(array $backOff);

    /**
     * Public Stream - Sample endpoint consumer
     *
     * @access  public
     * @param   Closure $handler   Data handler
     * @param   Closure $generator API endpoint parameter generator
     * @throws  FloodgateException
     * @return  void
     */
    public function sample(Closure $handler, Closure $generator);

    /**
     * Public Stream - Filter endpoint consumer
     *
     * @access  public
     * @param   Closure $handler   Data handler
     * @param   Closure $generator API endpoint parameter generator
     * @throws  FloodgateException
     * @return  void
     */
    public function filter(Closure $handler, Closure $generator);

    /**
     * Public Stream - Firehose endpoint consumer
     *
     * @access  public
     * @param   Closure $handler   Data handler
     * @param   Closure $generator API endpoint parameter generator
     * @throws  FloodgateException
     * @return  void
     */
    public function firehose(Closure $handler, Closure $generator);

    /**
     * User Stream - User endpoint consumer
     *
     * @access  public
     * @param   Closure $handler   Data handler
     * @param   Closure $generator API endpoint parameter generator
     * @throws  FloodgateException
     * @return  void
     */
    public function user(Closure $handler, Closure $generator);

    /**
     * Site Stream - Site endpoint consumer
     *
     * @access  public
     * @param   Closure $handler   Data handler
     * @param   Closure $generator API endpoint parameter generator
     * @throws  FloodgateException
     * @return  void
     */
    public function site(Closure $handler, Closure $generator);
}
