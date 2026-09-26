<?php

/**
 * @package     YakShaver\Component\ECSKCCustomisation
 * @subpackage  Administrator
 * @author      Yak Shaver <me@kayakshaver.com>
 * @copyright   (C) 2026 Yak Shaver https://www.kayakshaver.com
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;

/**
 * The run's faults, the interruption and the counts, which both pages show above
 * their rows. Report text is the runner's, escaped (plan decision 48).
 *
 * The page that loads this sets `counts`: the faults, the interruption or null,
 * the count per status, and the total.
 *
 * @var \YakShaver\Component\ECSKCCustomisation\Administrator\View\Targets\HtmlView $this
 */

$counts = $this->counts;
?>
<?php if ($counts['faults'] !== []) : ?>
    <div class="alert alert-danger" id="ecskc-faults">
        <h2 class="alert-heading h4"><?php echo Text::_('COM_ECSKCCUSTOM_FAULTS_HEADING'); ?></h2>
        <ul class="mb-0">
            <?php foreach ($counts['faults'] as $fault) : ?>
                <li><?php echo $this->escape($fault); ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>
<?php if ($counts['interruption'] !== null) : ?>
    <div class="alert alert-warning" id="ecskc-interruption">
        <h2 class="alert-heading h4"><?php echo Text::_('COM_ECSKCCUSTOM_INTERRUPTION_HEADING'); ?></h2>
        <p class="mb-0"><?php echo $this->escape($counts['interruption']); ?></p>
    </div>
<?php endif; ?>
<table class="table table-sm w-auto" id="ecskc-counts">
    <caption><?php echo Text::_('COM_ECSKCCUSTOM_COUNTS_CAPTION'); ?></caption>
    <thead>
        <tr>
            <?php foreach (\array_keys($counts['byStatus']) as $status) : ?>
                <th scope="col"><?php echo $this->escape($status); ?></th>
            <?php endforeach; ?>
            <th scope="col"><?php echo Text::_('COM_ECSKCCUSTOM_COUNTS_TOTAL'); ?></th>
        </tr>
    </thead>
    <tbody>
        <tr>
            <?php foreach ($counts['byStatus'] as $count) : ?>
                <td><?php echo (int) $count; ?></td>
            <?php endforeach; ?>
            <td><?php echo (int) $counts['total']; ?></td>
        </tr>
    </tbody>
</table>
