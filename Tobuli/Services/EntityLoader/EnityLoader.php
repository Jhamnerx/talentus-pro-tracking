<?php


namespace Tobuli\Services\EntityLoader;


use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Tobuli\Services\EntityLoader\Filters\Filter;
use Tobuli\Services\EntityLoader\Filters\FilterValuesSequence;
use Tobuli\Services\EntityLoader\Filters\FilterValue;

abstract class EnityLoader
{
    const KEY_SEARCH = 's';
    const LIMIT = 100;

    protected $request_key = null;

    protected $orderStored = false;

    /**
     * @var Filter[]
     */
    protected $filters = [];

    /**
     * @var Builder
     */
    protected $queryItems;

    /**
     * @var Builder
     */
    protected $queryStored;

    abstract protected function transform($item);

    /**
     * @param string $key
     */
    public function setRequestKey(string $key)
    {
        $this->request_key = 'selected_' . $key;
    }

    /**
     * @return bool
     */
    public function hasSelect()
    {
        return $this->hasAttach() || $this->hasDetach();
    }

    public function hasSelectAll(): bool
    {
        $sequence = $this->parseSequence();

        if (!$sequence->hasSelectAll()) {
            return false;
        }

        return $this->getQueryItems()->count() === $this->getSelectedSubquery()->count();
    }

    /**
     * @return LengthAwarePaginator
     */
    public function get()
    {
        $items = $this->getItems();

        $items->appends([
            self::KEY_SEARCH => request()->get(self::KEY_SEARCH)
        ]);

        return $items;
    }

    protected function scopeOrderDefault($query)
    {
        return $query;
    }

    protected function scopeAppendSelected($query)
    {
        $table = $this->getQueryItems()->getModel()->getTable();

        $sequence = $this->parseSequence();

        if ($query instanceof Relation) {
            if (is_null($query->getQuery()->getQuery()->columns)) {
                $query->select($query->getModel()->qualifyColumn('*'));
            }
        }

        if ($query instanceof Builder) {
            if (is_null($query->getQuery()->columns)) {
                $query->select($query->getModel()->qualifyColumn('*'));
            }
        }

        if ($selected = $this->getSelectedSubQuery()) {
            if ($sequence->hasSelectAll() && !$sequence->hasDetaches()) {
                $asSelected = 'TRUE';
            } else {

                $query->leftjoin(DB::raw('(' .$selected->toRaw() . ') AS selected_table'), function ($join) use ($table) {
                    $join->on('selected_table.id', '=', $table.'.id');
                });

                $asSelected = '(CASE WHEN selected_table.id IS NULL THEN FALSE ELSE TRUE END)';
            }
        } else {
            $asSelected = 'FALSE';
        }

        if (!empty($asSelected)) {
            $query->addSelect(
                DB::raw("$asSelected AS selected")
            );
        }

        return $query;
    }

    protected function scopeOrderSelected($query, $order = 'desc')
    {
        return $query->orderBy('selected', $order);
    }

    /**
     * @param bool $orderStored
     */
    public function setOrderStored(bool $orderStored)
    {
        $this->orderStored = $orderStored;
    }

    /**
     * @param $query
     */
    public function setQueryItems($query)
    {
        $this->queryItems = $query;
    }

    /**
     * @return Builder|null
     */
    public function getQueryItems()
    {
        return $this->queryItems ? clone $this->queryItems : null;
    }

    /**
     * @param $query
     */
    public function setQueryStored($query)
    {
        $this->queryStored = $query;
    }

    /**
     * @return Builder|null
     */
    public function getQueryStored()
    {
        return $this->queryStored ? clone $this->queryStored : $this->queryStored;
    }

    /**

     * @return LengthAwarePaginator
     */
    protected function getItems()
    {
        $query = $this->getQueryItems();
        $query->search(request()->get('s'));

        if ($id = request('selected_id')) {
            $sequence = $this->parseSequence();
            $sequence->add(new FilterValue('id', true, $id));
            $this->orderStored = true;
        }

        $this->parseSequence();

        $query = $this->scopeAppendSelected($query);

        if ($this->orderStored) {
            $query = $this->scopeOrderSelected($query);
        }

        $query = $this->scopeOrderDefault($query);

        $items = $query->paginate($this->getPageLimit());

        $items->setCollection($items->getCollection()->transform(function ($item) {
            $transformed = $this->transform($item);
            $transformed->selected = $item->selected ?? null;
            return $transformed;
        }));

        return $items;
    }

    protected function getPageLimit()
    {
        return config('server.entity_loader_page_limit', self::LIMIT);
    }

    protected function getMainTableID()
    {
        return $this->queryItems->getModel()->getTable() . '.id';
    }

    protected function hasFilter($key)
    {
        foreach ($this->filters as $filter) {
            if ($filter->key() == $key)
                return true;
        }

        return false;
    }

    protected function getFilter($key)
    {
        foreach ($this->filters as $filter) {
            if ($filter->key() == $key)
                return $filter;
        }

        return null;
    }

    protected function parseSequence() : FilterValuesSequence
    {
        if (empty($this->sequence)) {
            $this->sequence = new FilterValuesSequence();

            foreach (request($this->request_key, []) as $select) {
                list($field, $status, $value) = explode(';', $select, 3);

                if (!$this->hasFilter($field))
                    continue;

                $this->sequence->add(new FilterValue($field, $status, $value));
            }
        }

        return $this->sequence;
    }

    public function hasAttach()
    {
        $sequence = $this->parseSequence();

        return $sequence->hasAttaches();
    }

    public function hasDetach()
    {
        $sequence = $this->parseSequence();

        return $sequence->hasDetaches();
    }

    public function getQuerySelected()
    {
        $selectedQuery = $this->getSelectedSubquery();

        if (!$selectedQuery)
            return null;

        $query = $this->getQueryItems();

        $query->whereIn($this->getQueryItems()->getModel()->getQualifiedKeyName(), $selectedQuery);

        return $query;
    }

    public function getQueryAttach()
    {
        $selectedQuery = $this->getSelectedSubquery();

        if (!$selectedQuery)
            return null;

        $query = $this->getQueryItems();

        $query->whereIn($this->getQueryItems()->getModel()->getQualifiedKeyName(), $selectedQuery);

        return $query->select($this->getQueryItems()->getModel()->getQualifiedKeyName());
    }

    public function getQueryDetach()
    {
        $selectedQuery = $this->getSelectedSubquery();

        if (!$selectedQuery)
            return null;

        $query = $this->getQueryItems();

        $query->whereNotIn($this->getQueryItems()->getModel()->getQualifiedKeyName(), $selectedQuery);

        return $query->select($this->getQueryItems()->getModel()->getQualifiedKeyName());
    }

    protected function getSelectedSubquery()
    {
        $sequence = $this->parseSequence();

        $stored = $this->getQueryStored();

        if (config('tobuli.entityloader_store_query') && $sequence->isEmpty()) {

            return $stored
                ? $stored->select($stored->getModel()->getQualifiedKeyName())->clearOrdersBy()
                : null;
        }

        return $this->tmpTableQuery($sequence, $stored);
    }

    protected function tmpTable() : TempTable
    {
        if (empty($this->tmpTable))
            $this->tmpTable = new TempTable();

        return $this->tmpTable;
    }

    protected function tmpTableSequences($sequence)
    {
        $tmpTable = $this->tmpTable();

        foreach ($sequence->all() as $filterValue) {

            $select = $this->getQueryItems()
                ->select($this->getQueryItems()->getModel()->getQualifiedKeyName())
                ->where(function ($query) use ($filterValue) {
                    $this->getFilter($filterValue->getField())->querySelect($query, $filterValue->getValues());
                });

            if ($filterValue->getStatus()) {
                $tmpTable->append($select);
            } else {
                $tmpTable->remove($select);
            }
        }

        return $tmpTable;
    }

    protected function tmpTableStored($stored)
    {
        $this->tmpTable()->append( 
            $stored
                ->select($stored->getModel()->getQualifiedKeyName())
                ->clearOrdersBy()
        );
    }

    protected function tmpTableQuery($sequence, $stored)
    {
        if (empty($this->tmpTableQuery)) {
            if (!$sequence->hasDeselectAll() && $stored) {
                $this->tmpTableStored($stored);
            }

            $this->tmpTableSequences($sequence);

            $this->tmpTableQuery = $this->tmpTable()->toQuery();
        }

        return $this->tmpTableQuery;
    }
}