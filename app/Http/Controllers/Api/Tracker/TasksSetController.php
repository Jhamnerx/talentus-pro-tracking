<?php

namespace App\Http\Controllers\Api\Tracker;

use App\Exceptions\ResourseNotFoundException;
use App\Transformers\ApiV1\TasksSetLocationTransformer;
use App\Transformers\Task\TasksSetTransformer;
use FractalTransformer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Tobuli\Entities\TaskSet;
use Tobuli\Entities\TaskSetLocation;
use Validator;

class TasksSetController extends ApiController
{
    /**
     * @param int $id
     * @return JsonResponse
     */
    public function index(int $id): JsonResponse
    {
        $taskSet = TaskSet::whereHas('tasks', function (Builder $builder) {
            $builder->where('device_id', $this->deviceInstance->id);
        })->find($id);

        if (!$taskSet) throw new ResourseNotFoundException('Tasks set');

        $data = FractalTransformer::item($taskSet, new TasksSetTransformer)->toArray();
        return response()->json($data)->setStatusCode(200);
    }

    /**
     * @param int $id
     * @return JsonResponse
     */
    public function taskSetLocations(int $id): JsonResponse
    {
        $taskSet = TaskSet::whereHas('tasks', function (Builder $builder) {
            $builder->where('device_id', $this->deviceInstance->id);
        })->find($id);
        if (!$taskSet) throw new ResourseNotFoundException('Tasks set');
        $locations = $taskSet->locations()->orderBy('order')->paginate();
        $data = FractalTransformer::paginate($locations, new TasksSetLocationTransformer)->toArray();
        return response()->json($data)->setStatusCode(200);
    }

    /**
     * @param int $id
     * @param int $location
     * @return JsonResponse
     */
    public function locationTasks(int $id, int $location): JsonResponse
    {
        $location = TaskSetLocation::whereHas('tasks', function (Builder $builder) {
            $builder->where('device_id', $this->deviceInstance->id);
        })->where('task_set_id', $id)->find($location);
        if (!$location) throw new ResourseNotFoundException('Task set location');
        $data = FractalTransformer::item($location, TasksSetLocationTransformer::class)->toArray();
        return response()->json($data)->setStatusCode(200);
    }
}