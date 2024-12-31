<?php

namespace App\Transformers\ApiV1;

use App\Transformers\BaseTransformer;
use FractalTransformer;
use League\Fractal\Pagination\IlluminatePaginatorAdapter;
use Tobuli\Entities\TaskSetLocation;

class TasksSetLocationTransformer extends \App\Transformers\Task\TasksSetLocationTransformer
{
    /**
     * @param TaskSetLocation $location
     * @return \League\Fractal\Resource\Collection
     */
    public function includeTasks(TaskSetLocation $location)
    {
        $tasks = $location->tasks()
            ->with('lastStatus')
            ->orderBy('task_set_location_tasks_pivot.task_order')
            ->paginate(15, ['*'], 'tp')
            ->setPageName('tp')
            ->setPath(route('tracker.task-set-location.tasks', [
                'id' => $location->task_set_id,
                'location' => $location->id
            ]));
        return $this->collection($tasks, new \App\Transformers\ApiV1\TasksSetLocationTaskTransformer)
            ->setPaginator(new IlluminatePaginatorAdapter($tasks));
    }
}