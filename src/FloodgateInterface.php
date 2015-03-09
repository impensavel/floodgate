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
     * Get the Twitter Streaming API parameters
     *
     * @access  public
     * @return  array
     */
    public function getParameters();

    /**
     * Consume Streaming API Sample endpoint
     *
     * @access  public
     * @param   Closure $callback
     * @throws  FloodgateException
     * @return  void
     */
    public function sample(Closure $callback);

    /**
     * Consume Streaming API Filter endpoint
     *
     * @access  public
     * @param   Closure $callback
     * @throws  FloodgateException
     * @return  void
     */
    public function filter(Closure $callback);

    /**
     * Consume Streaming API Firehose endpoint
     *
     * @access  public
     * @param   Closure $callback  Callback to
     * @throws  FloodgateException
     * @return  void
     */
    public function firehose(Closure $callback);
}
