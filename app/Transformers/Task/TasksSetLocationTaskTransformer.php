<?php

namespace App\Transformers\Task;

use App\Transformers\BaseTransformer;
use Tobuli\Entities\Task;
use Tobuli\Entities\TaskStatus;

class TasksSetLocationTaskTransformer extends BaseTransformer
{
    /**
     * @param Task $task
     * @return array
     */
    public function transform(Task $task): array
    {
        return [
            'id' => $task->id,
            'title' => $task->title,
            'order' => $task->order,
            'status' => $task->lastStatus ? trans(TaskStatus::$statuses[$task->lastStatus->status]) : null,
        ];
    }
}