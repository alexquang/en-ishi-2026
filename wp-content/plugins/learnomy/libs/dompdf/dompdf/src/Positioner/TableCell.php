<?php

/**
 * @package dompdf
 * @link    https://github.com/dompdf/dompdf
 * @license http://www.gnu.org/copyleft/lesser.html GNU Lesser General Public License
 */
namespace Learnomy\Vendor\Dompdf\Positioner;

use Learnomy\Vendor\Dompdf\FrameDecorator\AbstractFrameDecorator;
use Learnomy\Vendor\Dompdf\FrameDecorator\Table;
/**
 * Positions table cells
 *
 * @package dompdf
 */
class TableCell extends AbstractPositioner
{
    /**
     * @param AbstractFrameDecorator $frame
     */
    function position(AbstractFrameDecorator $frame): void
    {
        $table = Table::find_parent_table($frame);
        $cellmap = $table->get_cellmap();
        $frame->set_position($cellmap->get_frame_position($frame));
    }
}
