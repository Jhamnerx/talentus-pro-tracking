<table class="table table-list">
    <tr>
        {!! tableHeader('front.folder') !!}
        {!! tableHeader('backups.history') !!}
        {!! tableHeader('backups.origin') !!}
        {!! tableHeader('admin.actions') !!}
    </tr>
    <tbody>
    @forelse ($folders as $folder)
        @php
            $folderName = $folder['name'];
            $isOlderThanCleanup = $cutoff && $folder['from'] && $folder['from'] < $cutoff;
        @endphp
        <tr>
            <td>{{ $folderName }}</td>
            <td>
                @if ($folder['from'] && $folder['to'])
                    @if ($folder['approximate'])
                        {{ trans('backups.approximate') }}
                    @endif
                    {{ substr($folder['from'], 0, 10) }} → {{ substr($folder['to'], 0, 10) }}
                @endif
            </td>
            <td>{{ strtoupper(implode(' + ', $folder['origins'])) }}</td>
            <td>
                {!! Form::open(['url' => route('admin.objects.positions_backups.download', $id), 'method' => 'POST', 'id' => $folderName, 'style' => 'display:inline']) !!}
                {!! Form::hidden('folder', $folderName) !!}
                {!! Form::submit(trans('admin.download'), ['class' => 'btn btn-sm']) !!}
                {!! Form::close() !!}

                <button class="btn btn-sm btn-primary uploaders"
                        data-folder="{{ $folderName }}"
                        @if ($isOlderThanCleanup)
                            data-warning="{{ trans('backups.restore_warning', ['date' => substr($cutoff, 0, 10)]) }}"
                        @endif
                >
                    {{ trans('front.upload') }}
                </button>
            </td>
        </tr>
    @empty
        <tr>
            <td class="no-data" colspan="4">
                {{ trans('admin.no_data') }}
            </td>
        </tr>
    @endforelse
    </tbody>
</table>

<div class="nav-pagination">
    @if ($folders->total())
        {!! $folders->render() !!}
    @endif
</div>

<script>
    $(document).ready(function() {
        $('.uploaders').unbind().click(function () {
            var warning = $(this).data('warning');

            if (warning && !confirm(warning)) {
                return;
            }

            $(this).prop('disabled', true);

            $.ajax({
                type: 'POST',
                url: '{{ route('admin.objects.positions_backups.upload', $id) }}',
                data: {
                    folder: $(this).data('folder')
                },
                success: function () {
                    toastr.success('{{ trans('front.upload_initiated') }}');
                }
            });
        });
    });
</script>
