<?php


namespace Tobuli\Services\EntityLoader;


use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Tobuli\Services\EntityLoader\Filters\Filter;

abstract class EnityLoader
{
    const KEY_SEARCH = 's';
    const LIMIT = 100;

    protected $request_key = null;

    protected bool $orderStoredSelectAll = false;
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
     * @return bool
     */
    public function hasSelect()
    {
        return !empty($this->parseSelectedRequest());
    }

    /**
     * @return LengthAwarePaginator
     */
    public function get()
    {
        $items = $this->getItems();
        $this->applySelected($items);

        $items->appends([
            self::KEY_SEARCH => request()->get(self::KEY_SEARCH)
        ]);

        return $items;
    }

    /**
     * @param string $key
     */
    public function setRequestKey(string $key)
    {
        $this->request_key = 'selected_' . $key;
    }

    /**
     * @return Builder
     */
    public function getQuery()
    {
        $where = $this->parseWhere();

        $query = $this->getQueryItems();

        $query = $this->scopeSelect($query, $where[true] ?? null, $this->getQueryStored());
        $query = $this->scopeDeselect($query, $where[false] ?? null);

        return $query;
    }

    public function getSeleted()
    {
        $where = $this->parseWhere();

        if (empty($where[true]))
            return null;

        $query = $this->getQueryItems();
        $query = $this->scopeSelect($query, $where[true] ?? null);

        return $query;
    }

    public function getDeseleted()
    {
        $where = $this->parseWhere();

        if (empty($where[false]))
            return null;

        $query = $this->getQueryItems();
        $query = $this->scopeSelect($query, $where[false] ?? null);

        return $query;
    }

    public function getDetach()
    {
        $where = $this->parseWhere();

        if (empty($where[false]))
            return null;

        $query = $this->getQueryItems();
        $query = $this->scopeSelect($query, $where[false] ?? null);

        if (!empty($where[true]))
            $query = $this->scopeDeselect($query, $where[true] ?? null);

        return $query;
    }

    public function getAttach()
    {
        $where = $this->parseWhere();

        if (empty($where[true]))
            return null;

        $query = $this->getQueryItems();
        $query = $this->scopeSelect($query, $where[true] ?? null);

        return $query;
    }

    protected function scopeInclude($query, $include)
    {
        $table = $include->getModel()->getTable();
        $sql = $include->select("$table.id")->toRaw();

        $query->orWhereRaw("`$table`.`id` IN ($sql)");
    }

    protected function scopeExclude($query, $exclude)
    {
        $table = $exclude->getModel()->getTable();
        $sql = $exclude->select("$table.id")->toRaw();

        $query->orWhereRaw("`$table`.`id` NOT IN ($sql)");
    }

    protected function scopeDeselect($query, $where, $exclude = null)
    {
        $query->where(function($q) use ($where, $exclude){
            if ($exclude) {
                $this->scopeExclude($q, $exclude);
            }

            foreach ($this->filters as $filter) {
                if (empty($where[$filter->key()]))
                    continue;

                $filter->queryDeselect($q, $where[$filter->key()]);
            }
        });

        return $query;
    }

    protected function scopeSelect($query, $where, $include = null)
    {
        $query->where(function($q) use ($where, $include){
            if ($include) {
                $this->scopeInclude($q, $include);
            }

            foreach ($this->filters as $filter) {
                if (empty($where[$filter->key()]))
                    continue;

                $filter->querySelect($q, $where[$filter->key()]);
            }
        });

        return $query;
    }

    protected function scopeOrderDefault($query)
    {
        return $query;
    }

    protected function scopeOrderStored($query)
    {
        if ($stored = $this->getQueryStored()) {
            $table = $stored->getModel()->getTable();
            $sql = $stored->select("$table.id")->toRaw();

            $this->orderStoredSelectAll // fixme: create solution to preserve relation fields
                ? $query->select("*")
                : $query->select("$table.*");

            $query->addSelect(
                DB::raw("(SELECT `$table`.`id` IN ($sql)) AS selected")
            );

            $query->orderBy('selected', 'desc');
        }

        return $query;
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

        if ($id = request('selected_id')) {
            $query->where($this->getMainTableID(), $id);
        } else {
            $query->search(request()->get('s'));
        }

        if ($this->orderStored)
            $query = $this->scopeOrderStored($query);

        $query = $this->scopeOrderDefault($query);

        $items = $query->paginate($this->getPageLimit());

        $items->setCollection($items->getCollection()->transform(function ($item) {
            return $this->transform($item);
        }));

        return $items;
    }

    /**
     * @param LengthAwarePaginator $items
     */
    protected function applySelected(LengthAwarePaginator $items)
    {
        $this->applySelectedStored($items);
        $this->applySelectedRequest($items);
    }

    /**
     * @param LengthAwarePaginator $items
     * @return LengthAwarePaginator|void
     */
    protected function applySelectedStored(LengthAwarePaginator $items) {
        if(!$this->getQueryStored())
            return;

        $selected = $this->getQueryStored()
            ->whereIn($this->getMainTableID(), $items->pluck('id')->all())
            ->get()
            ->pluck('id');

        $items->setCollection($items->getCollection()->transform(function ($item) use ($selected){
            if (false !== $selected->search($item->id))
                $item->selected = true;

            return $item;
        }));

        return $items;
    }

    /**
     * @param LengthAwarePaginator $items
     */
    protected function applySelectedRequest(LengthAwarePaginator $items) {
        $selects = $this->parseSelectedRequest();

        foreach ($selects as $select) {
            list($field, $status, $value) = array_values($select);

            $items->setCollection($items->getCollection()->transform(function ($item) use ($field, $status, $value) {
                foreach ($this->filters as $filter) {
                    if ($filter->key() != $field)
                        continue;

                    if (!$filter->isSelectedRequest($item, $value))
                        continue;

                    $item->selected = $status;
                }

                return $item;
            }));
        }
    }

    /**
     * @return array
     */
    protected function parseSelectedRequest()
    {
        $selected = [];

        foreach (request($this->request_key, []) as $select) {
            list($field, $status, $value) = explode(';', $select, 3);

            $status = filter_var($status, FILTER_VALIDATE_BOOLEAN);

            $selected = array_filter($selected, function($item) use ($field, $status, $value) {
                if($item['field'] == $field && $item['value'] == $value && $item['status'] != $status)
                    return false;

                return true;
            });

            $selected[] = [
                'field' => $field,
                'status' => $status,
                'value' => $value,
            ];
        }

        return $selected;
    }

    protected function parseWhere()
    {
        $selects = $this->parseSelectedRequest();

        $where = [];

        foreach ($selects as $select) {
            list($field, $status, $value) = array_values($select);

            if (empty($where[$status][$field]))
                $where[$status][$field] = [];

            $where[$status][$field][] = $value;
        }

        return $where;
    }

    protected function getPageLimit()
    {
        return config('server.entity_loader_page_limit', self::LIMIT);
    }

    protected function getMainTableID()
    {
        return $this->queryItems->getModel()->getTable() . '.id';
    }

    public function setOrderStoredSelectAll(bool $orderStoredSelectAll): self
    {
        $this->orderStoredSelectAll = $orderStoredSelectAll;

        return $this;
    }
}