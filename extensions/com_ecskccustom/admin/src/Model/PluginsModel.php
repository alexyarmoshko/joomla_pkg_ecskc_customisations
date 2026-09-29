<?php

/**
 * @package     YakShaver\Component\ECSKCCustomisation
 * @subpackage  Administrator
 * @author      Yak Shaver <me@kayakshaver.com>
 * @copyright   (C) 2026 Yak Shaver https://www.kayakshaver.com
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace YakShaver\Component\ECSKCCustomisation\Administrator\Model;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Factory\MVCFactoryInterface;
use Joomla\CMS\MVC\Model\ListModel;
use Joomla\CMS\Pagination\Pagination;
use UnexpectedValueException;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Registry\RegistryListState;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Registry\RegistryPage;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Registry\RegistryResolution;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Registry\RegistryResolver;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Registry\RegistryRow;
use YakShaver\Component\ECSKCCustomisation\Administrator\Extension\ECSKCCustomisationComponent;

\defined('_JEXEC') or die;

/**
 * The registry's extensions as a list: one row per entry, sorted, searched and paged.
 *
 * ListModel keeps the sort, the search, the limit and the start in the user state,
 * defaults the limit to the site's global list limit and floors the start to a
 * multiple of the limit. It does not send a new search back to the first page: it
 * saves the filters without comparing them, and the footer posts the current start
 * back with the form. populateState does that here. The resolver does the rest over
 * the whole list, and the footer is built from the page it returns, because it
 * replaces a start past the end.
 */
class PluginsModel extends ListModel
{
    private ?RegistryResolution $resolution = null;

    private ?RegistryPage $page = null;

    private ?Pagination $pagination = null;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct($config = [], ?MVCFactoryInterface $factory = null)
    {
        if (empty($config['filter_fields'])) {
            $config['filter_fields'] = RegistryResolver::SORTABLE;
        }

        parent::__construct($config, $factory);
    }

    /**
     * The rows, or the page-level fault, resolved once per request.
     */
    public function getResolution(): RegistryResolution
    {
        if ($this->resolution === null) {
            $component        = $this->component();
            $this->resolution = $component->registryResolver()->resolve($component->registry());
        }

        return $this->resolution;
    }

    /**
     * The page the list state asks for. Empty when the resolution is a fault.
     */
    public function getPage(): RegistryPage
    {
        return $this->page ??= $this->component()->registryResolver()->page(
            $this->getResolution()->rows,
            new RegistryListState(
                $this->stateText('list.ordering', RegistryResolver::DEFAULT_ORDERING),
                $this->stateText('list.direction', RegistryResolver::DEFAULT_DIRECTION),
                $this->stateText('filter.search', ''),
                (int) $this->getState('list.start', 0),
                (int) $this->getState('list.limit', 0),
            ),
            static fn (string $key): string => Text::_($key)
        );
    }

    /**
     * @return list<RegistryRow>
     */
    public function getItems()
    {
        return $this->getPage()->rows;
    }

    /**
     * The number of rows the search matched, before the page is cut.
     */
    public function getTotal()
    {
        return $this->getPage()->total;
    }

    /**
     * The start the resolver used, which is the list state's unless that was past the end.
     */
    public function getStart()
    {
        return $this->getPage()->start;
    }

    /**
     * The footer, from the page: its total and its start, which the resolver may have
     * moved back from past the end.
     */
    public function getPagination()
    {
        $page = $this->getPage();

        return $this->pagination ??= new Pagination($page->total, $page->start, (int) $this->getState('list.limit', 0));
    }

    /**
     * A search that differs from the stored one starts at the first page, before
     * ListModel reads the start. Only a request that sends the filters can change it.
     *
     * @param string|null $ordering
     * @param string|null $direction
     */
    protected function populateState($ordering = RegistryResolver::DEFAULT_ORDERING, $direction = RegistryResolver::DEFAULT_DIRECTION)
    {
        $app     = Factory::getApplication();
        $input   = $app->getInput();
        $filters = $input->get('filter', null, 'array');

        if (\is_array($filters)) {
            // The user state can hold the filters as an object, so read both shapes.
            $stored = (array) $app->getUserState($this->context . '.filter', []);

            if ($this->searchText($filters) !== $this->searchText($stored)) {
                $input->set('limitstart', 0);
            }
        }

        parent::populateState($ordering, $direction);
    }

    /**
     * @param array<mixed> $filters
     */
    private function searchText(array $filters): string
    {
        $search = $filters['search'] ?? '';

        return \is_string($search) ? $search : '';
    }

    /**
     * A state value as text. The request can put an array where text belongs, and
     * the resolver treats '' as its default.
     */
    private function stateText(string $property, string $default): string
    {
        $value = $this->getState($property, $default);

        return \is_string($value) ? $value : $default;
    }

    private function component(): ECSKCCustomisationComponent
    {
        $component = Factory::getApplication()->bootComponent('com_ecskccustom');

        if (!$component instanceof ECSKCCustomisationComponent) {
            throw new UnexpectedValueException('com_ecskccustom did not boot as ECSKCCustomisationComponent.');
        }

        return $component;
    }
}
