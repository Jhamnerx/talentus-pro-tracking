<?php

namespace App\Transformers\Task;

use App\Transformers\BaseTransformer;
use Tobuli\Entities\Task;
use Tobuli\Entities\TaskStatus;

class TaskTransformer extends BaseTransformer
{
    /**
     * @return array
     */
    protected static function requireLoads(): array
    {
        return ['lastStatus', 'taskSet'];
    }

    /**
     * @param Task $entity
     * @return array
     */
    public function transform(Task $entity): array
    {
        return [
            'id'    => $entity->id,
            'title' => $entity->title,
            'status' => $entity->lastStatus ? trans(TaskStatus::$statuses[$entity->lastStatus->status]) : null,
            'task_set_id' => $entity->task_set_id,
            'task_set' => $entity->task_set_id ? [
                'id' => $entity->taskSet->id,
                'title' => $entity->taskSet->title,
            ] : null,
        ];
    }
}