<?php

/**
 * @package     YakShaver\Component\ECSKCCustomisation
 * @subpackage  Administrator
 * @author      Yak Shaver <me@kayakshaver.com>
 * @copyright   (C) 2026 Yak Shaver https://www.kayakshaver.com
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

\defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Registry\EntryStatus;

/**
 * Two rows per registry entry on this page: its cells, then a closed Details section with
 * the package, description and repository. Every value is text the resolver decided,
 * escaped here; the Type, Status, Update and Update server cells are its language keys.
 *
 * @var \YakShaver\Component\ECSKCCustomisation\Administrator\View\Plugins\HtmlView $this
 */

// Trusted HTML: the five sortable headings are language keys that Joomla's
// `joomla.searchtools.grid.sort` layout translates and prints without escaping, as it
// does on every core list. Their text comes from this component's language file or an
// override written by a user with language permissions. Every other label below is
// escaped here.
$listOrder = $this->escape($this->listOrder);
$listDirn  = $this->escape($this->listDirn);

$badges = [
    EntryStatus::Enabled->value         => 'bg-success',
    EntryStatus::Disabled->value        => 'bg-danger',
    EntryStatus::ManifestMissing->value => 'bg-warning text-dark',
    EntryStatus::NotInstalled->value    => 'bg-secondary',
];
?>
<?php if ($this->items === []) : ?>
    <div class="alert alert-info" id="ecskc-no-plugins">
        <span class="icon-info-circle" aria-hidden="true"></span>
        <?php echo $this->escape(Text::_($this->registered === 0 ? 'COM_ECSKCCUSTOM_PLUGINS_NONE' : 'COM_ECSKCCUSTOM_PLUGINS_NO_MATCH')); ?>
    </div>
<?php else : ?>
    <table class="table" id="ecskc-plugins">
        <caption class="visually-hidden">
            <?php echo $this->escape(Text::_('COM_ECSKCCUSTOM_PLUGINS_CAPTION')); ?>,
            <span id="orderedBy"><?php echo $this->escape(Text::_('JGLOBAL_SORTED_BY')); ?> </span>,
            <span id="filteredBy"><?php echo $this->escape(Text::_('JGLOBAL_FILTERED_BY')); ?></span>
        </caption>
        <thead>
            <tr>
                <th scope="col"><?php echo HTMLHelper::_('searchtools.sort', 'COM_ECSKCCUSTOM_PLUGINS_HEADING_NAME', 'name', $listDirn, $listOrder); ?></th>
                <th scope="col"><?php echo HTMLHelper::_('searchtools.sort', 'COM_ECSKCCUSTOM_PLUGINS_HEADING_TYPE', 'type', $listDirn, $listOrder); ?></th>
                <th scope="col"><?php echo HTMLHelper::_('searchtools.sort', 'COM_ECSKCCUSTOM_PLUGINS_HEADING_VERSION', 'version', $listDirn, $listOrder); ?></th>
                <th scope="col"><?php echo HTMLHelper::_('searchtools.sort', 'COM_ECSKCCUSTOM_PLUGINS_HEADING_STATUS', 'status', $listDirn, $listOrder); ?></th>
                <th scope="col"><?php echo HTMLHelper::_('searchtools.sort', 'COM_ECSKCCUSTOM_PLUGINS_HEADING_UPDATE', 'update', $listDirn, $listOrder); ?></th>
                <th scope="col"><?php echo $this->escape(Text::_('COM_ECSKCCUSTOM_PLUGINS_HEADING_UPDATE_SERVER')); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($this->items as $row) : ?>
                <?php $url = $this->nameUrl($row); ?>
                <tr>
                    <th scope="row" class="border-bottom-0">
                        <?php if ($url === null) : ?>
                            <?php echo $this->escape($row->name); ?>
                        <?php else : ?>
                            <a href="<?php echo $url; ?>"><?php echo $this->escape($row->name); ?></a>
                        <?php endif; ?>
                    </th>
                    <td class="border-bottom-0"><?php echo $this->escape(Text::_($row->typeKey)); ?></td>
                    <td class="border-bottom-0"><?php echo $this->escape($row->version); ?></td>
                    <td class="border-bottom-0"><span class="badge <?php echo $badges[$row->status->value]; ?>"><?php echo $this->escape(Text::_($row->status->languageKey())); ?></span></td>
                    <td class="border-bottom-0"><?php echo $this->escape($row->updateVersion === null ? Text::_($row->update->languageKey()) : Text::sprintf($row->update->languageKey(), $row->updateVersion)); ?></td>
                    <td class="border-bottom-0"><?php echo $this->escape(Text::_($row->updateServer->languageKey())); ?></td>
                </tr>
                <tr>
                    <td colspan="6" class="pt-0">
                        <details class="mb-0">
                            <summary>
                                <span aria-hidden="true"><?php echo $this->escape(Text::_('COM_ECSKCCUSTOM_DETAILS')); ?></span>
                                <span class="visually-hidden"><?php echo $this->escape(Text::sprintf('COM_ECSKCCUSTOM_PLUGINS_DETAILS_FOR', $row->name)); ?></span>
                            </summary>
                            <dl class="row small mt-2 mb-0">
                                <?php if ($row->package !== '') : ?>
                                    <dt class="col-sm-2"><?php echo $this->escape(Text::_('COM_ECSKCCUSTOM_PLUGINS_HEADING_PACKAGE')); ?></dt>
                                    <dd class="col-sm-10"><?php echo $this->escape($row->package); ?></dd>
                                <?php endif; ?>
                                <?php if ($row->description !== '') : ?>
                                    <dt class="col-sm-2"><?php echo $this->escape(Text::_('COM_ECSKCCUSTOM_PLUGINS_HEADING_DESCRIPTION')); ?></dt>
                                    <dd class="col-sm-10"><?php echo $this->escape($row->description); ?></dd>
                                <?php endif; ?>
                                <dt class="col-sm-2"><?php echo $this->escape(Text::_('COM_ECSKCCUSTOM_PLUGINS_HEADING_REPOSITORY')); ?></dt>
                                <dd class="col-sm-10 mb-0"><a href="<?php echo $this->escape($row->repositoryUrl()); ?>" target="_blank" rel="noopener noreferrer"><?php echo $this->escape($row->repositoryLabel()); ?></a></dd>
                            </dl>
                        </details>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php echo $this->pagination?->getListFooter(); ?>
<?php endif; ?>
