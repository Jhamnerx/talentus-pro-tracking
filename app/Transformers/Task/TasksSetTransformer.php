<?php

namespace App\Transformers\Task;

use App\Transformers\BaseTransformer;
use FractalTransformer;
use League\Fractal\Pagination\IlluminatePaginatorAdapter;
use Tobuli\Entities\TaskSet;

class TasksSetTransformer extends BaseTransformer
{
    /**
     * @var string[]
     */
    protected $availableIncludes = [
        'locations'
    ];

    /**
     * @var string[]
     */
    protected $defaultIncludes = [
        'locations'
    ];

    /**
     * @return string[]
     */
    protected static function requireLoads(): array
    {
        return ['locations'];
    }

    /**
     * @param TaskSet $entity
     * @return array
     */
    public function transform(TaskSet $entity) {
        return [
            'id' => $entity->id,
            'title' => $entity->title,
        ];
    }

    /**
     * @param TaskSet $entity
     * @return mixed
     */
    public function includeLocations(TaskSet $entity)
    {
        $locations = $entity->locations()
            ->orderBy('order')
            ->paginate(1)
            ->setPath(route('tracker.task-set.locations', $entity->id));
        return $this->collection($locations, new \App\Transformers\ApiV1\TasksSetLocationTransformer)
            ->setPaginator(new IlluminatePaginatorAdapter($locations));
    }
}