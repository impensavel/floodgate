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

interface FloodgateInterface
{
    /**
     * Get the Twitter Streaming API parameters
     *
     * @access  public
     * @return  array
     */
    public function getParameters();
}
