<div class="action-block">
    <a href="javascript:" class="btn btn-action" data-url="{!! route('vehicle_types.create') !!}" data-modal="vehicle_types_create" type="button">
        <i class="icon add"></i> {{ trans('front.add_vehicle_type') }}
    </a>
</div>
<div data-table>
    @include('Frontend.VehicleTypes.table')
</div>

<script>
    tables.set_config('setup-form-vehicle-types', {
        url:'{{ route('vehicle_types.table') }}',
    });

    function vehicle_types_create_modal_callback() {
        tables.get('setup-form-vehicle-types');
    }
    function vehicle_types_edit_modal_callback() {
        tables.get('setup-form-vehicle-types');
    }
</script>
