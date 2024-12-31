<?php

namespace App\Transformers\Task;

use App\Transformers\BaseTransformer;
use FractalTransformer;
use League\Fractal\Pagination\IlluminatePaginatorAdapter;
use Tobuli\Entities\TaskSetLocation;

class TasksSetLocationTransformer extends BaseTransformer
{

    /**
     * @return string[]
     */
    protected static function requireLoads()
    {
        return ['tasks'];
    }

    /**
     * @var string[]
     */
    protected $availableIncludes = [
        'tasks'
    ];

    /**
     * @var string[]
     */
    protected $defaultIncludes = [
        'tasks'
    ];
    /**
     * @param TaskSetLocation $location
     * @return array
     */
    public function transform(TaskSetLocation $location): array
    {
        $task = $location->tasks->first();
        $key = $task->pivot->address_key;
        return [
            'id' => $location->id,
            'lat' => $task->{$key . '_address_lat'},
            'lng' => $task->{$key . '_address_lng'},
        ];
    }

    /**
     * @param TaskSetLocation $location
     * @return \League\Fractal\Resource\Collection
     */
    public function includeTasks(TaskSetLocation $location)
    {
        $tasks = $location->tasks()->orderBy('task_set_location_tasks_pivot.task_order')
            ->paginate()
            ->setPageName('tp');
        return $this->collection($tasks, new \App\Transformers\ApiV1\TasksSetLocationTaskTransformer)
            ->setPaginator(new IlluminatePaginatorAdapter($tasks));
    }
}