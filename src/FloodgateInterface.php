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
     * @return  Closure
     */
    public static function applyBackOffStrategy();

    /**
     * Get the back off strategy delay
     *
     * @static
     * @access  public
     * @return  Closure
     */
    public static function backOffStrategyDelay();

    /**
     * Get the Streaming API endpoint parameters
     *
     * @access  public
     * @return  array
     */
    public function getParameters();

    /**
     * Consume Streaming API Sample endpoint
     *
     * @access  public
     * @param   Closure $callback Data handler callback
     * @throws  FloodgateException
     * @return  void
     */
    public function sample(Closure $callback);

    /**
     * Consume Streaming API Filter endpoint
     *
     * @access  public
     * @param   Closure $callback Data handler callback
     * @throws  FloodgateException
     * @return  void
     */
    public function filter(Closure $callback);

    /**
     * Consume Streaming API Firehose endpoint
     *
     * @access  public
     * @param   Closure $callback Data handler callback
     * @throws  FloodgateException
     * @return  void
     */
    public function firehose(Closure $callback);
}
