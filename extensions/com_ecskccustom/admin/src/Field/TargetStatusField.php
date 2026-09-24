<?php

/**
 * @package     YakShaver\Component\ECSKCCustomisation
 * @subpackage  Administrator
 * @author      Yak Shaver <me@kayakshaver.com>
 * @copyright   (C) 2026 Yak Shaver https://www.kayakshaver.com
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace YakShaver\Component\ECSKCCustomisation\Administrator\Field;

use Joomla\CMS\Form\Field\ListField;
use Joomla\CMS\HTML\HTMLHelper;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Walk\TargetStatus;

\defined('_JEXEC') or die;

/**
 * The seven statuses as filter options, labelled with the runner's own words
 * (plan decision 48). The empty "select" option comes from the form XML.
 */
class TargetStatusField extends ListField
{
    /**
     * @var string
     */
    protected $type = 'TargetStatus';

    /**
     * @return array<int, object>
     */
    protected function getOptions()
    {
        $options = parent::getOptions();

        foreach (TargetStatus::ALL as $status) {
            $options[] = HTMLHelper::_('select.option', $status, $status);
        }

        return $options;
    }
}
