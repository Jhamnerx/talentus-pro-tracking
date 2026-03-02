<?php

namespace Tobuli\Helpers\RelationTransfer\Sync;

use App\Jobs\Job;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Arr;

abstract class AbstractRelationSync extends Job implements ShouldQueue
{
    use InteractsWithQueue, SerializesModels;

    protected Model $parent;
    protected $data;
    protected string $method;
    protected bool $insertIgnores = true;

    public function __construct(Model $parent, string $method, $data = null)
    {
        $this->parent = $parent;
        $this->method = $method;
        $this->data = $data;
    }

    abstract protected function _attach($only): void;
    abstract protected function _detach($only): void;

    public function handle()
    {
        $this->{$this->method}($this->data);
    }

    public function applyChanges(array $changes): void
    {
        $this->detach($changes['detached']);
        $this->attach($changes['attached']);
    }

    public function sync($only = null): void
    {
        $this->detach($only);
        $this->attach($only);
    }

    public function attach($only = null): void
    {
        $only = $this->parseItems($only);

        if ($only !== null && !$only) {
            return;
        }

        $this->_attach($only);
    }

    public function detach($only = null): void
    {
        $only = $this->parseItems($only);

        if ($only !== null && !$only) {
            return;
        }

        $this->_detach($only);
    }

    protected function parseItems($items)
    {
        if ($items === null) {
            return null;
        }

        if (is_scalar($items) || $items instanceof Model) {
            $items = [$items];
        }

        if (\Arr::first($items) instanceof Model) {
            $items = Arr::pluck($items, 'id');
        }

        return $items;
    }
}