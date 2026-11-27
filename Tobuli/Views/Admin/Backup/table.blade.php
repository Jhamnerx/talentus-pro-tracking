<div class="table-responsive">
    <table class="table table-list" data-toggle="multiCheckbox">
        <thead>
        <tr>
            {!! tableHeaderSort($items->sorting, 'name') !!}
            {!! tableHeaderSort($items->sorting, 'launcher') !!}
            {!! tableHeaderSort($items->sorting, 'date') !!}
            {!! tableHeader('front.progress') !!}
            {!! tableHeader('front.completed') !!}
            {!! tableHeader('backups.destinations') !!}
            {!! tableHeader('backups.database_size') !!}
            {!! tableHeader('validation.attributes.message') !!}
            {!! tableHeader('admin.actions', 'style="text-align: right;"') !!}
        </tr>
        </thead>
        <tbody>
        @php /** @var \Tobuli\Entities\Backup $item */ @endphp
        @forelse ($items->getCollection() as $item)
            <tr>
                <td>{{ $item->name }}</td>
                <td>{{ $item->launcher }}</td>
                <td>{{ Formatter::time()->human($item->created_at) }}</td>
                <td>
                    @php
                        $done = $item->progressDone();
                        $total = $item->progressTotal();
                        $percentage = $total ? (int)($done / $total * 100) : 100;
                    @endphp

                    <div class="progress">
                        <div class="progress-bar"
                             role="progressbar"
                             aria-valuenow="{{ $done }}"
                             aria-valuemin="0"
                             aria-valuemax="{{ $total }}"
                             style="width: {{ $percentage }}%;">
                            {{ $done }} / {{ $total }}
                        </div>
                    </div>
                </td>
                <td>{{ $item->isCompleted() ? trans('global.yes') : trans('global.no') }}</td>
                <td>
                    @foreach (array_keys($item->destinations ?: []) as $destinationName)
                        <div>{{ strtoupper($destinationName) }}: {{ trans('backups.status_' . $item->destinationStatus($destinationName)) }}</div>
                    @endforeach
                </td>
                <td>{{ $item->database_bytes ? round($item->database_bytes / 1048576, 1) . ' MB' : '' }}</td>
                <td>{{ $item->message }}</td>
                <td class="actions">
                    @if (settings('backups.drive_enabled') && !empty($item->destinations['drive']))
                        {!! Form::open(['route' => ['admin.backup.protect', $item->id], 'method' => 'POST', 'style' => 'display:inline']) !!}
                        {!! Form::submit(trans($item->protected ? 'backups.unprotect' : 'backups.protect'), ['class' => 'btn btn-xs btn-default']) !!}
                        {!! Form::close() !!}
                    @endif
                    <i class="btn icon ico-arrow-down"
                       type="button"
                       data-url="{{ route('admin.backup.processes', $item->id) }}"
                       data-toggle="collapse"
                       data-target="#backup-processes-{{ $item->id }}">
                    </i>
                </td>
            </tr>
            <tr class="row-table-inner">
                <td colspan="9" id="backup-processes-{{ $item->id }}" aria-expanded="false" class="collapse"></td>
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

@include('Admin.Layouts.partials.pagination')