<style>
    .task-stats {
        display: flex;
        gap: 8px;
        margin-bottom: 12px;
    }

    .task-stat {
        flex: 1;
        background: #f7f9fa;
        border-radius: 3px;
        padding: 8px 6px;
        text-align: center;
    }

    .task-stat .ts-num {
        font-size: 20px;
        font-weight: 700;
        color: #2c3e50;
        display: block;
        line-height: 1;
    }

    .task-stat .ts-label {
        font-size: 10px;
        color: #8a9bb0;
        display: block;
        margin-top: 2px;
        text-transform: uppercase;
        letter-spacing: .04em;
    }

    .task-list {
        list-style: none;
        padding: 0;
        margin: 0;
    }

    .task-list li {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 5px 0;
        border-bottom: 1px solid #f2f4f5;
        font-size: 12px;
    }

    .task-list li:last-child {
        border-bottom: none;
    }

    .task-list .tl-name {
        color: #2c3e50;
        flex: 1;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        padding-right: 8px;
    }

    .task-list .tl-status {
        font-size: 10px;
        background: #e8ecef;
        color: #5a6878;
        padding: 2px 6px;
        border-radius: 3px;
        white-space: nowrap;
    }

    .task-sep {
        font-size: 10px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: .06em;
        color: #8a9bb0;
        margin: 10px 0 6px;
    }
</style>

<div class="task-stats">
    <div class="task-stat">
        <span class="ts-num">{{ $task_count }}</span>
        <span class="ts-label">{{ trans('front.count') }}</span>
    </div>
    @foreach ($statuses as $key => $status)
        <div class="task-stat">
            <span class="ts-num">{{ $status_counts[$key] }}</span>
            <span class="ts-label">{{ trans($status) }}</span>
        </div>
    @endforeach
</div>

@if (count($latests) > 0)
    <p class="task-sep">{{ trans('front.latest') }}</p>
    <ul class="task-list">
        @foreach ($latests as $task)
            <li>
                <span class="tl-name">{{ $task->title }}</span>
                <span class="tl-status">{{ $task->status_name }}</span>
            </li>
        @endforeach
    </ul>
@endif
