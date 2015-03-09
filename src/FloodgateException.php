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

use RuntimeException;

class FloodgateException extends RuntimeException
{
    /**
     * Get the previous Exception message
     *
     * @access  public
     * @return  string|null
     */
    public function getPreviousMessage()
    {
        $previous = $this->getPrevious();

        return $previous ? null : $previous->getMessage();
    }
}
