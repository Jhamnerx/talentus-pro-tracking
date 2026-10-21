<div class="table_error"></div>

<div class="table-responsive">
    <table class="table table-list" data-toggle="multiCheckbox">
        <thead>
        <tr>
            {!! tableHeaderCheckall(['delete_url' => trans('admin.delete_selected')]) !!}
            {!! tableHeaderSort($items->sorting, 'active') !!}
            {!! tableHeaderSort($items->sorting, 'name', 'validation.attributes.name') !!}
            {!! tableHeaderSort($items->sorting, 'query_command', 'admin.command_rules_query') !!}
            {!! tableHeader('admin.command_rules_conditions') !!}
            {!! tableHeader('validation.attributes.devices') !!}
            {!! tableHeaderSort($items->sorting, 'interval_minutes', 'admin.command_rules_interval') !!}
            {!! tableHeaderSort($items->sorting, 'last_run_at', 'admin.command_rules_last_run') !!}
            {!! tableHeader('admin.actions', 'style="text-align: right;"') !!}
        </tr>
        </thead>
        <tbody>
        @forelse ($items->getCollection() as $item)
            <tr>
                <td>
                    <div class="checkbox">
                        <input type="checkbox" value="{!! $item->id !!}">
                        <label></label>
                    </div>
                </td>
                <td>
                    <span class="label label-sm label-{!! $item->active ? 'success' : 'danger' !!}">
                        {!! trans('validation.attributes.active') !!}
                    </span>
                </td>
                <td>
                    {{ $item->name }}
                    @if ($item->description)
                        <br><small class="text-muted">{{ $item->description }}</small>
                    @endif
                </td>
                <td><code>{{ $item->query_command }}</code></td>
                <td>{{ $item->conditions_count }}</td>
                <td>{{ $item->devices_count }}</td>
                <td>{{ $item->interval_minutes }} min</td>
                <td>{{ $item->last_run_at ? \Formatter::time()->human($item->last_run_at) : '-' }}</td>
                <td class="actions">
                    <div class="btn-group dropdown droparrow" data-position="fixed">
                        <i class="btn icon edit" data-toggle="dropdown" aria-haspopup="true" aria-expanded="true"></i>
                        <ul class="dropdown-menu">
                            <li>
                                <a href="javascript:" data-modal="command_rules_edit" data-url="{{ route('admin.command_rules.edit', $item->id) }}">
                                    {{ trans('global.edit') }}
                                </a>
                            </li>
                            <li>
                                <a href="javascript:" data-modal="command_rules_logs" data-url="{{ route('admin.command_rules.logs', $item->id) }}">
                                    {{ trans('admin.command_rules_logs') }}
                                </a>
                            </li>
                            <li>
                                <a href="javascript:" data-command-rule-run="{{ route('admin.command_rules.run', $item->id) }}">
                                    {{ trans('admin.command_rules_run_now') }}
                                </a>
                            </li>
                        </ul>
                    </div>
                </td>
            </tr>
        @empty
            <tr class="">
                <td class="no-data" colspan="9">
                    {!! trans('admin.no_data') !!}
                </td>
            </tr>
        @endforelse
        </tbody>
    </table>
</div>

@include("Admin.Layouts.partials.pagination")
