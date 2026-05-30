<?php

/**
 * @package dompdf
 * @link    https://github.com/dompdf/dompdf
 * @license http://www.gnu.org/copyleft/lesser.html GNU Lesser General Public License
 */
namespace WCPOS\Vendor\Dompdf\Positioner;

use WCPOS\Vendor\Dompdf\FrameDecorator\AbstractFrameDecorator;
/**
 * Dummy positioner
 *
 * @package dompdf
 */
class NullPositioner extends AbstractPositioner
{
    /**
     * @param AbstractFrameDecorator $frame
     */
    function position(AbstractFrameDecorator $frame) : void
    {
        return;
    }
}
