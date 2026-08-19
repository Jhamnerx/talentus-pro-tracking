<?php
// Decrypted by https://bolt.pawan.krd - Educational Use Only
//
// DISCLAIMER: This file was decrypted for educational and research purposes only.
// Use of this file is at your own risk. We make no warranties, express or implied,
// regarding the accuracy, reliability, or legality of the content within. We are not
// responsible for any damages, direct or indirect, resulting from the use of this file.
// You are solely responsible for ensuring compliance with all applicable laws and
// regulations in your jurisdiction.
//
// By using this file, you acknowledge and agree to these terms.



use Illuminate\Support\Facades\Route;

Route::group([], function () {
    Route::get('/', [
        'as' => 'home',
        'uses' => function () {
            if (Auth::check()) {
                return Redirect::route('objects.index');
            } else {
                return Redirect::route('authentication.create');
            }
        }
    ]);

    if (isPublic()) {
        Route::get('login/{hash}', ['as' => 'login', 'uses' => 'Frontend\LoginController@store']);
    } else {
        Route::get('login/{id?}', ['as' => 'login', 'uses' => 'Frontend\LoginController@create']);
    }

    Route::get('logout', ['as' => 'logout', 'uses' => 'Frontend\LoginController@destroy']);

    Route::get('azure/login', ['as' => 'azure.login', 'uses' => 'Auth\AzureController@login']);
    Route::get('azure/login/callback', ['as' => 'azure.login_callback', 'uses' => 'Auth\AzureController@loginCallback']);
    Route::get('azure/logout', ['as' => 'azure.logout', 'uses' => 'Auth\AzureController@logout']);

    Route::get('authentication/create', ['as' => 'authentication.create', 'uses' => 'Frontend\LoginController@create']);
    Route::any('authentication/store', ['as' => 'authentication.store', 'uses' => 'Frontend\LoginController@store'])
        ->middleware('captcha')
        ->middleware('throttle:' . config('server.throttle.web.login'));
    Route::resource('authentication', 'Frontend\LoginController', ['only' => ['destroy']]);

    Route::get('password_reminder', ['uses' => 'Frontend\PasswordReminderController@create', 'as' => 'password_reminder.create']);
    Route::post('password_reminder', ['uses' => 'Frontend\PasswordReminderController@store', 'as' => 'password_reminder.store'])
        ->middleware('throttle:' . config('server.throttle.web.password_reset'));
    Route::get('password/reset/{token}', ['uses' => 'Frontend\PasswordReminderController@reset', 'as' => 'password.reset']);
    Route::post('password/reset/{token}', ['uses' => 'Frontend\PasswordReminderController@update', 'as' => 'password.update'])
        ->middleware('throttle:' . config('server.throttle.web.password_reset'));

    Route::get('registration/create', ['as' => 'registration.create', 'uses' => 'Frontend\RegistrationController@create']);
    Route::post('registration/store', ['as' => 'registration.store', 'uses' => 'Frontend\RegistrationController@store'])
        ->middleware('captcha');

    Route::get('register/create', ['as' => 'register.create', 'uses' => 'Frontend\CustomRegistrationController@create']);
    Route::post('register', ['as' => 'register.store', 'uses' => 'Frontend\CustomRegistrationController@store']);
    Route::group(['middleware' => ['auth', 'active_subscription'], 'namespace' => 'Frontend'], function () {
        Route::get('register/success', ['as' => 'register.success', 'uses' => 'CustomRegistrationController@success']);
        Route::get('register/step/{step}', ['as' => 'register.step.create', 'uses' => 'CustomRegistrationController@stepCreate']);
        Route::post('register/step/{step}', ['as' => 'register.step.store', 'uses' => 'CustomRegistrationController@stepStore']);
        Route::resource('register', 'CustomRegistrationController', ['except' => ['create', 'store']]);
    });
    Route::get('verification', ['as' => 'verification', 'uses' => 'Frontend\EmailVerificationController@notice']);
    Route::get('verification/{token}', ['as' => 'verification.verify', 'uses' => 'Frontend\EmailVerificationController@verify']);

    # Exceptions to CSRF verification - \App\Http\Middleware\VerifyCsrfToken
    Route::any('payments/{gateway}/webhook', ['as' => 'payments.webhook', 'uses' => 'Frontend\PaymentsController@webhook']);
    Route::any('gpsdata_insert', ['as' => 'gpsdata_insert', 'uses' => 'Frontend\GpsDataController@insert']);
    #####

    Route::get('demo', ['as' => 'demo', 'uses' => 'Frontend\LoginController@demo']);

    Route::get('time', [
        'as' => 'time',
        'uses' => function () {
            echo date('Y-m-d H:i:s O');
        }
    ]);

    Route::any('geo_address', ['as' => 'geo_address', 'uses' => 'Frontend\AddressController@get']);
});

// Authenticated Frontend |active_subscription
Route::group(['middleware' => ['auth', 'active_subscription'], 'namespace' => 'Frontend'], function () {
    Route::delete('objects/destroy/{objects?}', ['as' => 'objects.destroy', 'uses' => 'DevicesController@destroy']);

    Route::get('objects/sidebar/groups', ['as' => 'objects.sidebar.groups', 'uses' => 'DevicesSidebarController@groups']);
    Route::get('objects/sidebar/items', ['as' => 'objects.sidebar.items', 'uses' => 'DevicesSidebarController@items']);
    Route::get('objects/sidebar/filters', ['as' => 'objects.sidebar.filters', 'uses' => 'DevicesSidebarController@showFilters']);
    Route::get('objects/sidebar', ['as' => 'objects.sidebar', 'uses' => 'DevicesSidebarController@index']);

    Route::get('objects/items', ['as' => 'objects.items', 'uses' => 'ObjectsController@items']);
    Route::get('objects/itemsSimple', ['as' => 'objects.items_simple', 'uses' => 'ObjectsController@itemsSimple']);

    Route::get('objects/items_json', ['as' => 'objects.items_json', 'uses' => 'ObjectsController@itemsJson']);
    Route::get('objects/change_group_status', ['as' => 'objects.change_group_status', 'uses' => 'ObjectsController@changeGroupStatus']);
    Route::get('objects/change_alarm_status', ['as' => 'objects.change_alarm_status', 'uses' => 'ObjectsController@changeAlarmStatus']);
    Route::get('objects/alarm_position', ['as' => 'objects.alarm_position', 'uses' => 'ObjectsController@alarmPosition']);
    Route::get('objects/stop_time/{id?}', ['as' => 'objects.stop_time', 'uses' => 'DevicesController@stopTime']);
    Route::resource('objects', 'ObjectsController', ['only' => ['index']]);


    # Lookup model
    Route::get('objects/list/settings', ['as' => 'objects.listview.edit', 'uses' => 'ObjectsListLookupController@edit']);
    Route::post('objects/list/settings', ['as' => 'objects.listview.update', 'uses' => 'ObjectsListLookupController@update']);
    Route::get('objects/list/table', ['as' => 'objects.listview.table', 'uses' => 'ObjectsListLookupController@table']);
    Route::get('objects/list/data', ['as' => 'objects.listview.data', 'uses' => 'ObjectsListLookupController@data']);
    Route::get('objects/list', ['as' => 'objects.listview', 'uses' => 'ObjectsListLookupController@index']);

    //Route::get('objects/list/items', ['as' => 'objects.listview.items', 'uses' => 'ObjectsListController@items']);

    # Lookup model
    Route::get('lookup/{lookup}/settings', ['as' => 'lookup.edit', 'uses' => 'LookupController@edit']);
    Route::post('lookup/{lookup}/settings', ['as' => 'lookup.update', 'uses' => 'LookupController@update']);
    Route::get('lookup/{lookup}/table', ['as' => 'lookup.table', 'uses' => 'LookupController@table']);
    Route::get('lookup/{lookup}/data', ['as' => 'lookup.data', 'uses' => 'LookupController@data']);
    Route::get('lookup/{lookup}/', ['as' => 'lookup.index', 'uses' => 'LookupController@index']);

    # Geofences
    Route::get('geofences/sidebar/groups', ['as' => 'geofences.sidebar.groups', 'uses' => 'GeofencesSidebarController@groups']);
    Route::get('geofences/sidebar/items', ['as' => 'geofences.sidebar.items', 'uses' => 'GeofencesSidebarController@items']);
    Route::get('geofences/sidebar', ['as' => 'geofences.sidebar', 'uses' => 'GeofencesSidebarController@index']);

    Route::get('geofences/export', ['as' => 'geofences.export', 'uses' => 'GeofencesExportController@index']);
    Route::get('geofences/export_type', ['as' => 'geofences.export_type', 'uses' => 'GeofencesExportController@getType']);
    Route::post('geofences/export_create', ['as' => 'geofences.export_create', 'uses' => 'GeofencesExportController@store']);
    Route::get('geofences/import_modal', ['as' => 'geofences.import_modal', 'uses' => 'GeofencesImportController@index']);
    Route::post('geofences/import', ['as' => 'geofences.import', 'uses' => 'GeofencesImportController@store']);
    Route::post('geofences/change_active', ['as' => 'geofences.change_active', 'uses' => 'GeofencesController@changeActive']);
    Route::put('geofences/update', ['as' => 'geofences.update', 'uses' => 'GeofencesController@update']);
    Route::any('geofences/destroy/{geofences?}', ['as' => 'geofences.destroy', 'middleware' => ['confirmed_action'], 'uses' => 'GeofencesController@destroy']);
    Route::any('geofences/edit/{id?}', ['as' => 'geofences.edit', 'uses' => 'GeofencesController@edit']);
    Route::get('geofences/list', ['as' => 'geofences.index_modal', 'uses' => 'GeofencesController@indexModal']);
    Route::get('geofences/list/table', ['as' => 'geofences.table', 'uses' => 'GeofencesController@table']);
    Route::resource('geofences', 'GeofencesController', ['except' => ['update', 'destroy', 'edit']]);

    # Geofences groups
    Route::get('geofences_groups/change_status', ['as' => 'geofences_groups.change_status', 'uses' => 'GeofencesGroupsController@changeStatus']);
    Route::resource('geofences_groups', 'GeofencesGroupsController');

    Route::get('geofences_groups_subform/update_select', ['as' => 'geofences_groups_subform.update_select', 'uses' => 'GeofencesGroupsSubformController@updateSelect']);
    Route::resource('geofences_groups_subform', 'GeofencesGroupsSubformController', ['only' => ['index', 'store']]);

    # Geofences devices
    Route::get('geofences/devices/{id?}', ['as' => 'geofences.devices', 'uses' => 'GeofenceDevicesLookupController@index']);

    # Routes
    Route::get('routes/sidebar/groups', ['as' => 'routes.sidebar.groups', 'uses' => 'RoutesSidebarController@groups']);
    Route::get('routes/sidebar/items', ['as' => 'routes.sidebar.items', 'uses' => 'RoutesSidebarController@items']);
    Route::get('routes/sidebar', ['as' => 'routes.sidebar', 'uses' => 'RoutesSidebarController@index']);

    Route::get('route/export', ['as' => 'routes.export', 'uses' => 'RoutesExportController@index']);
    Route::post('route/export', ['as' => 'routes.export.store', 'uses' => 'RoutesExportController@store']);
    Route::get('routes/export_type', ['as' => 'routes.export_type', 'uses' => 'RoutesExportController@getType']);
    Route::post('route/change_active', ['as' => 'routes.change_active', 'uses' => 'RoutesController@changeActive']);
    Route::put('route/update/{id?}', ['as' => 'routes.update', 'uses' => 'RoutesController@update']);
    Route::any('route/destroy/{id?}', ['as' => 'routes.destroy', 'middleware' => ['confirmed_action'], 'uses' => 'RoutesController@destroy']);
    Route::get('route/import_modal', ['as' => 'routes.import_modal', 'uses' => 'RoutesImportController@index']);
    Route::post('route/import', ['as' => 'routes.import', 'uses' => 'RoutesImportController@store']);
    Route::any('route/edit/{id?}', ['as' => 'routes.edit', 'uses' => 'RoutesController@edit']);
    Route::get('routes/list', ['as' => 'routes.index_modal', 'uses' => 'RoutesController@indexModal']);
    Route::get('routes/list/table', ['as' => 'routes.table', 'uses' => 'RoutesController@table']);
    Route::resource('route', 'RoutesController', ['names' => 'routes', 'except' => ['update', 'destroy', 'edit']]);

    # Route groups
    Route::get('route_groups/change_status', ['as' => 'route_groups.change_status', 'uses' => 'RoutesGroupsController@changeStatus']);
    Route::resource('route_groups', 'RoutesGroupsController', ['except' => ['destroy']]);

    # Widgets
    Route::get('device/widgets/location/{id?}', ['as' => 'device.widgets.location', 'uses' => 'DeviceWidgetsController@location']);
    Route::get('device/widgets/cameras/{id?}', ['as' => 'device.widgets.cameras', 'uses' => 'DeviceWidgetsController@cameras']);
    Route::get('device/widgets/image/{id?}', ['as' => 'device.widgets.image', 'uses' => 'DeviceWidgetsController@image']);
    Route::get('device/widgets/fuel_graph/{id?}', ['as' => 'device.widgets.fuel_graph', 'uses' => 'DeviceWidgetsController@fuelGraph']);
    Route::get('device/widgets/gprs_command/{id?}', ['as' => 'device.widgets.gprs_command', 'uses' => 'DeviceWidgetsController@gprsCommands']);
    Route::post('device/widgets/gprs_command/{id?}', ['as' => 'device.widgets.gprs_command_send', 'uses' => 'DeviceWidgetsController@gprsCommandSend']);
    Route::get('device/widgets/recent_events/{id?}', ['as' => 'device.widgets.recent_events', 'uses' => 'DeviceWidgetsController@recentEvents']);
    Route::get('device/widgets/template_webhook/{id?}', ['as' => 'device.widgets.template_webhook', 'uses' => 'DeviceWidgetsController@templateWebhook']);
    Route::post('device/widgets/template_webhook/{id?}', ['as' => 'device.widgets.template_webhook_send', 'uses' => 'DeviceWidgetsController@templateWebhookSend']);

    Route::get('devices/{device_id}/alerts', ['as' => 'device.alerts.index', 'uses' => 'DeviceAlertsController@index']);
    Route::get('devices/{device_id}/alerts/table', ['as' => 'device.alerts.table', 'uses' => 'DeviceAlertsController@table']);
    Route::get('devices/{device_id}/alerts/{alert_id}/time_period', ['as' => 'device.alerts.time_period.edit', 'uses' => 'DeviceAlertsController@editTimePeriod']);
    Route::post('devices/{device_id}/alerts/{alert_id}/time_period', ['as' => 'device.alerts.time_period.update', 'uses' => 'DeviceAlertsController@updateTimePeriod']);

    # Devices
    Route::get('devices/edit/{id?}/{admin?}', ['as' => 'devices.edit', 'uses' => 'DevicesController@edit']);
    Route::post('devices/change_active', ['as' => 'devices.change_active', 'uses' => 'DevicesController@changeActive']);
    Route::get('devices/{id}/vehicle_state', ['as' => 'devices.vehicle_state_form', 'uses' => 'DevicesController@vehicleStateForm']);
    Route::post('devices/change_vehicle_state', ['as' => 'devices.change_vehicle_state', 'uses' => 'DevicesController@changeVehicleState']);
    Route::get('devices/follow_map/{id?}', ['as' => 'devices.follow_map', 'uses' => 'DevicesController@followMap']);
    Route::any('devices/commands', ['as' => 'devices.commands', 'uses' => 'SendCommandController@getCommands']);
    Route::get('devices/do_destroy/{id}', ['as' => 'devices.do_destroy', 'uses' => 'DevicesController@doDestroy']);
    Route::put('devices/update', ['as' => 'devices.update', 'uses' => 'DevicesController@update']);
    Route::get('devices/do_reset_app_uuid/{id}', ['as' => 'devices.do_reset_app_uuid', 'uses' => 'DevicesController@doResetAppUuid']);
    Route::put('devices/reset_app_uuid/{id}', ['as' => 'devices.reset_app_uuid', 'uses' => 'DevicesController@resetAppUuid']);
    Route::get('devices/subscriptions', ['as' => 'devices.subscriptions', 'uses' => 'DeviceSubscriptionController@index']);
    Route::get('devices/subscriptions/table', ['as' => 'devices.subscriptions.table', 'uses' => 'DeviceSubscriptionController@table']);
    Route::get('devices/subscriptions/edit', ['as' => 'devices.subscriptions.edit', 'uses' => 'DeviceSubscriptionController@edit']);
    Route::get('devices/subscriptions/cancel/{id}', ['as' => 'devices.subscriptions.do_delete', 'uses' => 'DeviceSubscriptionController@doDestroy']);
    Route::delete('devices/subscriptions/cancel/{id}', ['as' => 'devices.subscriptions.delete', 'uses' => 'DeviceSubscriptionController@destroy']);
    Route::get('devices/users', ['as' => 'devices.users.index', 'uses' => 'DeviceUsersController@index']);
    Route::get('devices/{device_id}/users', ['as' => 'devices.users.get', 'uses' => 'DeviceUsersController@get']);
    Route::resource('devices', 'DevicesController', ['except' => ['edit', 'update']]);

    # Device Maintenances (historial de mantenimientos/suspensiones por unidad)
    Route::get('devices/{device_id}/maintenances', ['as' => 'device_maintenances.index', 'uses' => 'DeviceMaintenancesController@index']);
    Route::post('devices/{device_id}/maintenances', ['as' => 'device_maintenances.store', 'uses' => 'DeviceMaintenancesController@store']);
    Route::delete('devices/{device_id}/maintenances/{id}', ['as' => 'device_maintenances.destroy', 'uses' => 'DeviceMaintenancesController@destroy']);

    Route::post('devices/image/upload/{id?}', ['as' => 'device.image.store', 'uses' => 'DeviceImagesController@store']);
    Route::post('devices/image/delete/{id?}', ['as' => 'device.image.destroy', 'uses' => 'DeviceImagesController@destroy']);

    # Beacons
    Route::put('beacons/update/{id?}', ['as' => 'beacons.update', 'uses' => 'BeaconsController@update']);
    Route::resource('beacons', 'BeaconsController', ['except' => ['index', 'update', 'destroy']]);

    # Devices Groups

    Route::get('devices_groups/devices/{id?}', ['as' => 'devices_groups.devices', 'uses' => 'DevicesGroupsController@devices']);
    Route::get('devices_groups/do_destroy/{id}', ['as' => 'devices_groups.do_destroy', 'uses' => 'DevicesGroupsController@doDestroy']);
    Route::get('devices_groups/table', ['as' => 'devices_groups.table', 'uses' => 'DevicesGroupsController@table']);
    Route::resource('devices_groups', 'DevicesGroupsController');

    # Device config
    Route::get('devices_config/index/{device_id?}', ['as' => 'device_config.index', 'uses' => 'DeviceConfigController@index']);
    Route::post('devices_config/configure', ['as' => 'device_config.configure', 'uses' => 'DeviceConfigController@configure']);
    Route::get('devices_config/getApnData/{id?}', ['as' => 'device_config.get_apn_data', 'uses' => 'DeviceConfigController@getApnData']);

    # Alerts
    Route::get('alerts/edit/{id?}', ['as' => 'alerts.edit', 'uses' => 'AlertsController@edit']);
    Route::put('alerts/update/{id?}', ['as' => 'alerts.update', 'uses' => 'AlertsController@update']);
    Route::get('alerts/do_destroy/{id?}', ['as' => 'alerts.do_destroy', 'uses' => 'AlertsController@doDestroy']);
    Route::delete('alerts/destroy/{id?}', ['as' => 'alerts.destroy', 'uses' => 'AlertsController@destroy']);
    Route::get('alerts/devices/{id?}', ['as' => 'alerts.devices', 'uses' => 'AlertsController@devices']);
    Route::post('alerts/change_active/{active?}', ['as' => 'alerts.change_active', 'uses' => 'AlertsController@changeActive']);
    Route::any('alerts/commands', ['as' => 'alerts.commands', 'uses' => 'AlertsController@getCommands']);
    Route::any('alerts/custom_events/{id?}', ['as' => 'alerts.custom_events', 'uses' => 'AlertsController@customEvents']);
    Route::get('alerts/summary', ['as' => 'alerts.sumary', 'uses' => 'AlertsController@summary']);
    Route::get('alerts/list', ['as' => 'alerts.index_modal', 'uses' => 'AlertsController@index_modal']);
    Route::get('alerts/list/table', ['as' => 'alerts.table', 'uses' => 'AlertsController@table']);
    Route::get('alerts/users/{id?}', ['as' => 'alerts.users', 'uses' => 'AlertsController@users']);
    Route::resource('alerts', 'AlertsController', ['except' => ['edit', 'update', 'destroy']]);

    # History
    Route::get('history', ['as' => 'history.index', 'uses' => 'HistoryController@index']);
    Route::get('history/positions', ['as' => 'history.positions', 'uses' => 'HistoryController@positionsPaginated']);
    Route::get('history/position', ['as' => 'history.position', 'uses' => 'HistoryController@getPosition']);
    Route::get('history/do_delete_positions', ['as' => 'history.do_delete_positions', 'uses' => 'HistoryController@doDeletePositions']);
    Route::any('history/delete_positions', ['as' => 'history.delete_positions', 'uses' => 'HistoryController@deletePositions']);

    Route::get('history/export', ['as' => 'history.export', 'uses' => 'HistoryExportController@generate']);
    Route::get('history/download/{file}/{name}', ['as' => 'history.download', 'uses' => 'HistoryExportController@download']);

    # Events
    Route::get('events', ['as' => 'events.index', 'uses' => 'EventsController@index']);
    Route::get('events/do_destroy', ['as' => 'events.do_destroy', 'uses' => 'EventsController@doDestroy']);
    Route::delete('events/destroy', ['as' => 'events.destroy', 'uses' => 'EventsController@destroy']);

    # Map Icons
    Route::get('pois/sidebar/groups', ['as' => 'pois.sidebar.groups', 'uses' => 'PoisSidebarController@groups']);
    Route::get('pois/sidebar/items', ['as' => 'pois.sidebar.items', 'uses' => 'PoisSidebarController@items']);
    Route::get('pois/sidebar', ['as' => 'pois.sidebar', 'uses' => 'PoisSidebarController@index']);

    Route::get('pois/export', ['as' => 'pois.export', 'uses' => 'PoisExportController@index']);
    Route::post('pois/export', ['as' => 'pois.export.store', 'uses' => 'PoisExportController@store']);
    Route::get('pois/export_type', ['as' => 'pois.export_type', 'uses' => 'PoisExportController@getType']);
    Route::get('pois/import', ['as' => 'pois.import', 'uses' => 'PoisImportController@index']);
    Route::post('pois/import', ['as' => 'pois.import', 'uses' => 'PoisImportController@store']);
    Route::post('pois/change_active', ['as' => 'pois.change_active', 'uses' => 'PoisController@changeActive']);
    Route::put('pois/update/{id?}', ['as' => 'pois.update', 'uses' => 'PoisController@update']);
    Route::any('pois/destroy/{id?}', ['as' => 'pois.destroy', 'middleware' => ['confirmed_action'], 'uses' => 'PoisController@destroy']);
    Route::any('pois/edit/{id?}', ['as' => 'pois.edit', 'uses' => 'PoisController@edit']);
    Route::get('pois/list', ['as' => 'pois.index_modal', 'uses' => 'PoisController@indexModal']);
    Route::get('pois/list/table', ['as' => 'pois.table', 'uses' => 'PoisController@table']);
    Route::resource('pois', 'PoisController', ['except' => ['update', 'destroy', 'edit']]);

    Route::get('pois_groups/change_status', ['as' => 'pois_groups.change_status', 'uses' => 'PoisGroupsController@changeStatus']);
    Route::resource('pois_groups', 'PoisGroupsController', ['except' => ['destroy']]);

    # Report Logs
    Route::get('reports/logs', ['as' => 'reports.logs', 'uses' => 'ReportsController@logs']);
    Route::any('reports/log/download/{id}', ['as' => 'reports.log_download', 'uses' => 'ReportsController@logDownload']);
    Route::any('reports/log/destroy', ['as' => 'reports.log_destroy', 'uses' => 'ReportsController@logDestroy']);

    # Reports
    Route::any('reports/types', ['as' => 'reports.types', 'uses' => 'ReportsController@getTypes']);
    Route::any('reports/types/{type?}', ['as' => 'reports.types.show', 'uses' => 'ReportsController@getType']);
    Route::any('reports/update', ['as' => 'reports.update', 'uses' => 'ReportsController@update']);
    Route::get('reports/do_destroy/{id}', ['as' => 'reports.do_destroy', 'uses' => 'ReportsController@doDestroy']);
    Route::delete('reports/destroy', ['as' => 'reports.destroy', 'uses' => 'ReportsController@destroy']);
    Route::get('reports/devices/{id?}', ['as' => 'reports.devices', 'uses' => 'ReportsController@devices']);
    Route::resource('reports', 'ReportsController', ['except' => ['edit', 'update', 'destroy']]);

    # My account
    Route::post('my_account/change_map', ['as' => 'my_account.change_map', 'uses' => 'MyAccountController@changeMap']);
    Route::get('my_account/edit', ['as' => 'my_account.edit', 'uses' => 'MyAccountController@edit']);
    Route::put('my_account/update', ['as' => 'my_account.update', 'uses' => 'MyAccountController@update']);
    Route::get('email_confirmation/resend', ['as' => 'email_confirmation.resend_code', 'uses' => 'EmailConfirmationController@resendActivationCode']);
    Route::post('email_confirmation/resend', ['as' => 'email_confirmation.resend_code_submit', 'uses' => 'EmailConfirmationController@resendActivationCodeSubmit']);
    Route::resource('email_confirmation', 'EmailConfirmationController', ['only' => ['edit', 'update']]);
    Route::get('my_account_settings/change_language/{lang}', ['as' => 'my_account_settings.change_lang', 'uses' => 'MyAccountSettingsController@changeLang']);


    # User drivers
    Route::get('user_drivers/do_destroy/{id}', ['as' => 'user_drivers.do_destroy', 'uses' => 'UserDriversController@doDestroy']);
    Route::any('user_drivers/do_update/{id}', ['as' => 'user_drivers.do_update', 'uses' => 'UserDriversController@doUpdate']);
    Route::put('user_drivers/update', ['as' => 'user_drivers.update', 'uses' => 'UserDriversController@update']);
    Route::delete('user_drivers/destroy', ['as' => 'user_drivers.destroy', 'uses' => 'UserDriversController@destroy']);
    Route::get('user_drivers/table', ['as' => 'user_drivers.table', 'uses' => 'UserDriversController@table']);
    Route::get('user_drivers/{id}/activity_log/{table?}', ['as' => 'user_drivers.activity_log', 'uses' => 'UserDriversController@activityLog']);
    Route::get('user_drivers/devices/{id?}', ['as' => 'user_drivers.devices', 'uses' => 'UserDriversController@devices']);
    Route::resource('user_drivers', 'UserDriversController', ['except' => ['update', 'destroy']]);

    # Vehicle types
    Route::get('vehicle_types/table', ['as' => 'vehicle_types.table', 'uses' => 'VehicleTypesController@table']);
    Route::resource('vehicle_types', 'VehicleTypesController', ['except' => ['show']]);

    # Sensors
    Route::any('sensors/preview', ['as' => 'sensors.preview', 'uses' => 'SensorsController@preview']);
    Route::get('sensors/do_destroy/{id}', ['as' => 'sensors.do_destroy', 'uses' => 'SensorsController@doDestroy']);
    Route::get('sensors/create/{device_id?}', ['as' => 'sensors.create', 'uses' => 'SensorsController@create']);
    Route::get('sensors/index/{device_id}', ['as' => 'sensors.index', 'uses' => 'SensorsController@index']);
    Route::delete('sensors/destroy', ['as' => 'sensors.destroy', 'uses' => 'SensorsController@destroy']);
    Route::resource('sensors', 'SensorsController', ['only' => ['store', 'edit', 'update']]);
    Route::get('sensors/param/{param}/{device_id}', ['as' => 'sensors.param', 'uses' => 'SensorsController@parameterSuggestion']);

    # Sensor calibrations
    Route::get('sensor_calibrations/import_modal', ['as' => 'sensor_calibrations.import_modal', 'uses' => 'SensorCalibrationsImportController@index']);
    Route::post('sensor_calibrations/import', ['as' => 'sensor_calibrations.import', 'uses' => 'SensorCalibrationsImportController@store']);

    # Services
    Route::get('services/do_destroy/{id}', ['as' => 'services.do_destroy', 'uses' => 'ServicesController@doDestroy']);
    Route::get('services/create/{device_id?}', ['as' => 'services.create', 'uses' => 'ServicesController@create']);
    Route::get('services/index/{device_id?}', ['as' => 'services.index', 'uses' => 'ServicesController@index']);
    Route::get('services/table/{device_id?}', ['as' => 'services.table', 'uses' => 'ServicesController@table']);
    Route::put('services/update/{id?}', ['as' => 'services.update', 'uses' => 'ServicesController@update']);
    Route::delete('services/destroy', ['as' => 'services.destroy', 'uses' => 'ServicesController@destroy']);
    Route::resource('services', 'ServicesController', ['only' => ['store', 'edit']]);

    # Custom events
    Route::get('custom_events/do_destroy/{id}', ['as' => 'custom_events.do_destroy', 'uses' => 'CustomEventsController@doDestroy']);
    Route::post('custom_events/get_events', ['as' => 'custom_events.get_events', 'uses' => 'CustomEventsController@getEvents']);
    Route::post('custom_events/get_protocols', ['as' => 'custom_events.get_protocols', 'uses' => 'CustomEventsController@getProtocols']);
    Route::any('custom_events/get_events_by_device', ['as' => 'custom_events.get_events_by_device', 'uses' => 'CustomEventsController@getEventsByDevices']);
    Route::put('custom_events/update', ['as' => 'custom_events.update', 'uses' => 'CustomEventsController@update']);
    Route::delete('custom_events/destroy', ['as' => 'custom_events.destroy', 'uses' => 'CustomEventsController@destroy']);
    Route::get('custom_events/table', ['as' => 'custom_events.table', 'uses' => 'CustomEventsController@table']);
    Route::resource('custom_events', 'CustomEventsController', ['except' => ['update', 'destroy']]);

    # User sms templates
    Route::get('user_sms_templates/do_destroy/{id}', ['as' => 'user_sms_templates.do_destroy', 'uses' => 'UserSmsTemplatesController@doDestroy']);
    Route::post('user_sms_templates/get_message', ['as' => 'user_sms_templates.get_message', 'uses' => 'UserSmsTemplatesController@getMessage']);
    Route::put('user_sms_templates/update', ['as' => 'user_sms_templates.update', 'uses' => 'UserSmsTemplatesController@update']);
    Route::delete('user_sms_templates/destroy', ['as' => 'user_sms_templates.destroy', 'uses' => 'UserSmsTemplatesController@destroy']);
    Route::get('user_sms_templates/table', ['as' => 'user_sms_templates.table', 'uses' => 'UserSmsTemplatesController@table']);
    Route::get('user_sms_templates/devices/{id?}', ['as' => 'user_sms_templates.devices', 'uses' => 'UserSmsTemplatesController@devices']);
    Route::resource('user_sms_templates', 'UserSmsTemplatesController', ['except' => ['update', 'destroy']]);

    # User gprs templates
    Route::get('user_gprs_templates/do_destroy/{id}', ['as' => 'user_gprs_templates.do_destroy', 'uses' => 'UserGprsTemplatesController@doDestroy']);
    Route::post('user_gprs_templates/get_message', ['as' => 'user_gprs_templates.get_message', 'uses' => 'UserGprsTemplatesController@getMessage']);
    Route::put('user_gprs_templates/update', ['as' => 'user_gprs_templates.update', 'uses' => 'UserGprsTemplatesController@update']);
    Route::delete('user_gprs_templates/destroy', ['as' => 'user_gprs_templates.destroy', 'uses' => 'UserGprsTemplatesController@destroy']);
    Route::get('user_gprs_templates/table', ['as' => 'user_gprs_templates.table', 'uses' => 'UserGprsTemplatesController@table']);
    Route::get('user_gprs_templates/devices/{id?}', ['as' => 'user_gprs_templates.devices', 'uses' => 'UserGprsTemplatesController@devices']);
    Route::resource('user_gprs_templates', 'UserGprsTemplatesController', ['except' => ['update', 'destroy']]);

    Route::get('language', ['as' => 'languages.index', 'uses' => 'LanguageController@index']);

    #My account settings
    Route::get('my_account_settings/change_top_toolbar', ['as' => 'my_account_settings.change_top_toolbar', 'uses' => 'MyAccountSettingsController@changeTopToolbar']);
    Route::get('my_account_settings/change_map_settings', ['as' => 'my_account_settings.change_map_settings', 'uses' => 'MyAccountSettingsController@changeMapSettings']);
    Route::get('my_account_settings/edit', ['as' => 'my_account_settings.edit', 'uses' => 'MyAccountSettingsController@edit']);
    Route::put('my_account_settings/update', ['as' => 'my_account_settings.update', 'uses' => 'MyAccountSettingsController@update']);


    # Send command
    Route::post('send_command/gprs', ['as' => 'send_command.gprs', 'uses' => 'SendCommandController@gprsStore']);
    Route::get('send_command/get_device_sim_number', ['as' => 'send_command.get_device_sim_number', 'uses' => 'SendCommandController@getDeviceSimNumber']);
    Route::resource('send_command', 'SendCommandController', ['only' => ['create', 'store']]);
    Route::get('send_commands/logs', ['as' => 'send_commands.logs.index', 'uses' => 'SendCommandsLogsController@index']);
    Route::get('send_commands/logs/table', ['as' => 'send_commands.logs.table', 'uses' => 'SendCommandsLogsController@table']);

    #Camera
    Route::get('device_media/create', ['as' => 'device_media.create', 'uses' => 'DeviceMediaController@create']);
    Route::get('device_media/images/{device_id?}', ['as' => 'device_media.get_images', 'uses' => 'DeviceMediaController@getImages']);
    Route::get('device_media/images_table/{device_id?}', ['as' => 'device_media.get_images_table', 'uses' => 'DeviceMediaController@getImagesTable']);
    Route::get('device_media/image/{device_id?}/{filename?}', ['as' => 'device_media.get_image', 'uses' => 'DeviceMediaController@getImage']);
    Route::get('device_media/download/{device_id?}/{filename?}', ['as' => 'device_media.download_file', 'uses' => 'DeviceMediaController@download']);
    Route::get('device_media/delete/{device_id?}/{filename?}', ['as' => 'device_media.delete_image', 'uses' => 'DeviceMediaController@remove']);
    Route::delete('device_media/delete/{device_id?}', ['as' => 'device_media.delete_images', 'uses' => 'DeviceMediaController@removeMulti']);
    Route::post('device_media/download/{device_id?}', ['as' => 'device_media.download_images', 'uses' => 'DeviceMediaController@downloadMulti']);
    Route::get('device_media/file/{device_id?}/{filename?}', ['as' => 'device_media.display_image', 'uses' => 'DeviceMediaController@getFile']);
    Route::get('device_media/camera/file/{camera_id?}/{filename?}', ['as' => 'device_media.display_camera_image', 'uses' => 'DeviceMediaController@getCameraFile']);

    # Secondary credentials
    Route::get('secondary_credentials', ['as' => 'secondary_credentials.index', 'uses' => 'SecondaryCredentialsController@index']);
    Route::get('secondary_credentials/table', ['as' => 'secondary_credentials.table', 'uses' => 'SecondaryCredentialsController@table']);
    Route::get('secondary_credentials/create', ['as' => 'secondary_credentials.create', 'uses' => 'SecondaryCredentialsController@create']);
    Route::post('secondary_credentials', ['as' => 'secondary_credentials.store', 'uses' => 'SecondaryCredentialsController@store']);
    Route::put('secondary_credentials/{id}', ['as' => 'secondary_credentials.update', 'uses' => 'SecondaryCredentialsController@update']);
    Route::get('secondary_credentials/edit/{id}', ['as' => 'secondary_credentials.edit', 'uses' => 'SecondaryCredentialsController@edit']);
    Route::any('secondary_credentials/destroy', ['as' => 'secondary_credentials.destroy', 'middleware' => ['confirmed_action'], 'uses' => 'SecondaryCredentialsController@destroy']);

    # Media categories
    Route::get('media_categories', ['as' => 'media_categories.index', 'uses' => 'MediaCategoriesController@index']);
    Route::get('media_categories/table', ['as' => 'media_categories.table', 'uses' => 'MediaCategoriesController@table']);
    Route::get('media_categories/create', ['as' => 'media_categories.create', 'uses' => 'MediaCategoriesController@create']);
    Route::get('media_categories/edit/{id}', ['as' => 'media_categories.edit', 'uses' => 'MediaCategoriesController@edit']);
    Route::get('media_categories/do_destroy/{id}', ['as' => 'media_categories.do_destroy', 'uses' => 'MediaCategoriesController@doDestroy']);
    Route::delete('media_categories/{id}', ['as' => 'media_categories.destroy', 'uses' => 'MediaCategoriesController@destroy']);
    Route::put('media_categories/{id}', ['as' => 'media_categories.update', 'uses' => 'MediaCategoriesController@update']);
    Route::post('media_categories', ['as' => 'media_categories.store', 'uses' => 'MediaCategoriesController@store']);

    #Device cameras
    Route::get('device_camera/index/{device_id}', ['as' => 'device_camera.index', 'uses' => 'DeviceCamerasController@index']);
    Route::get('device_camera/create/{device_id}', ['as' => 'device_camera.create', 'uses' => 'DeviceCamerasController@create']);
    Route::get('device_camera/do_destroy/{id}', ['as' => 'device_camera.do_destroy', 'uses' => 'DeviceCamerasController@doDestroy']);
    Route::put('device_camera/update', ['as' => 'device_camera.update', 'uses' => 'DeviceCamerasController@update']);
    Route::resource('device_camera', 'DeviceCamerasController', ['only' => ['store', 'edit', 'destroy']]);

    # Device video (Howen / JT808 live video via commands/send + HLS proxy)
    Route::post('device_video/{device_id}/live/start', ['as' => 'device_video.live.start', 'uses' => 'DeviceVideoController@liveStart']);
    Route::post('device_video/{device_id}/live/stop', ['as' => 'device_video.live.stop', 'uses' => 'DeviceVideoController@liveStop']);
    Route::post('device_video/{device_id}/audio/start', ['as' => 'device_video.audio.start', 'uses' => 'DeviceVideoController@audioStart']);
    Route::post('device_video/{device_id}/audio/stop', ['as' => 'device_video.audio.stop', 'uses' => 'DeviceVideoController@audioStop']);
    Route::get('device_video/{device_id}/live/modal', ['as' => 'device_video.live.modal', 'uses' => 'DeviceVideoController@liveModal']);
    Route::get('device_video/{device_id}/stream/{file}', ['as' => 'device_video.stream.proxy', 'uses' => 'DeviceVideoController@streamProxy'])
        ->where('file', '.*');
    Route::get('device_video/{device_id}/playback/modal', ['as' => 'device_video.playback.modal', 'uses' => 'DeviceVideoController@playbackModal']);
    Route::post('device_video/{device_id}/playback/search', ['as' => 'device_video.playback.search', 'uses' => 'DeviceVideoController@playbackSearch']);
    Route::get('device_video/{device_id}/playback/files/{session}', ['as' => 'device_video.playback.poll', 'uses' => 'DeviceVideoController@playbackPoll']);
    Route::post('device_video/{device_id}/playback/start', ['as' => 'device_video.playback.start', 'uses' => 'DeviceVideoController@playbackStart']);
    Route::post('device_video/{device_id}/playback/stop', ['as' => 'device_video.playback.stop', 'uses' => 'DeviceVideoController@playbackStop']);
    Route::get('device_video/{device_id}/playback/route', ['as' => 'device_video.playback.route', 'uses' => 'DeviceVideoController@playbackRoute']);

    # SMS gateway
    Route::get('sms_gateway/test_sms', ['as' => 'sms_gateway.test_sms', 'uses' => 'SmsGatewayController@testSms']);
    Route::post('sms_gateway/send_test_sms', ['as' => 'sms_gateway.send_test_sms', 'uses' => 'SmsGatewayController@sendTestSms']);
    Route::get('sms_gateway/clear_queue', ['as' => 'sms_gateway.clear_queue', 'uses' => 'SmsGatewayController@clearQueue']);

    Route::get('maintenance/list', ['as' => 'maintenance.table', 'uses' => 'MaintenanceController@table']);
    Route::get('maintenance/{imei?}', ['as' => 'maintenance.index', 'uses' => 'MaintenanceController@index']);

    # Tasks
    Route::get('tasks/list', ['as' => 'tasks.list', 'uses' => 'TasksController@search']);
    Route::get('tasks/do_destroy/{id?}', ['as' => 'tasks.do_destroy', 'uses' => 'TasksController@doDestroy']);
    Route::get('tasks/signature/{taskStatusId}', ['as' => 'tasks.signature', 'uses' => 'TasksController@getSignature']);
    Route::get('tasks/import', ['as' => 'tasks.import', 'uses' => 'TasksController@import']);
    Route::post('tasks/import', ['as' => 'tasks.import_set', 'uses' => 'TasksController@importSet']);
    Route::get('tasks/assign', ['as' => 'tasks.assign_form', 'uses' => 'TasksController@assignForm']);
    Route::post('tasks/assign', ['as' => 'tasks.assign', 'uses' => 'TasksController@assign']);
    Route::put('tasks/update', ['as' => 'tasks.update', 'uses' => 'TasksController@update']);
    Route::delete('tasks/destroy', ['as' => 'tasks.destroy', 'uses' => 'TasksController@destroy']);
    Route::resource('tasks', 'TasksController', ['except' => ['update', 'destroy']]);

    # Task sets
    Route::get('task_sets', ['as' => 'task_sets.index', 'uses' => 'TaskSetsController@index']);
    Route::get('task_sets/table', ['as' => 'task_sets.table', 'uses' => 'TaskSetsController@table']);
    Route::get('task_sets/create', ['as' => 'task_sets.create', 'uses' => 'TaskSetsController@create']);
    Route::post('task_sets', ['as' => 'task_sets.store', 'uses' => 'TaskSetsController@store']);
    Route::put('task_sets/{id}', ['as' => 'task_sets.update', 'uses' => 'TaskSetsController@update']);
    Route::get('task_sets/edit/{id}', ['as' => 'task_sets.edit', 'uses' => 'TaskSetsController@edit']);
    Route::any('task_sets/destroy', ['as' => 'task_sets.destroy', 'middleware' => ['confirmed_action'], 'uses' => 'TaskSetsController@destroy']);

    # Importer
    Route::post('import/get_fields', ['as' => 'import.get_fields', 'uses' => 'ImportController@getFields']);

    Route::any('address/autocomplete', ['as' => 'address.autocomplete', 'uses' => 'AddressController@autocomplete']);
    Route::any('address/reverse', ['as' => 'address.reverse', 'uses' => 'AddressController@reverse']);
    Route::any('address/search', ['as' => 'address.search', 'uses' => 'AddressController@search']);
    Route::get('address/map', ['as' => 'address.map', 'uses' => 'AddressController@map']);
    Route::any('address', ['as' => 'address.get', 'uses' => 'AddressController@get']);

    # Icon
    Route::get('icon/device', ['as' => 'icon.device.index', 'uses' => 'DeviceIconController@index']);
    Route::get('icon/device/table/{type}', ['as' => 'icon.device.table', 'uses' => 'DeviceIconController@table']);
    Route::get('icon/sensor', ['as' => 'icon.sensor.index', 'uses' => 'SensorIconController@index']);
    Route::get('icon/sensor/table/{type}', ['as' => 'icon.sensor.table', 'uses' => 'SensorIconController@table']);

    # Chats
    Route::get('chat/index', ['as' => 'chat.index', 'uses' =>  'ChatController@index']);
    Route::get('chat/unread/count', ['as' => 'chat.unread_msg_count', 'uses' =>  'ChatController@getUnreadMessagesCount']);
    Route::get('chat/init/{chatableId}/{type?}', ['as' => 'chat.init', 'uses' =>  'ChatController@initChat']);
    Route::get('chat/participants', ['as' => 'chat.searchParticipant', 'uses' =>  'ChatController@searchParticipant']);
    Route::get('chat/{chatId}/messages', ['as' => 'chat.messages', 'uses' => 'ChatController@getMessages']);
    Route::get('chat/{chatId}', ['as' => 'chat.get', 'uses' =>  'ChatController@getChat']);
    Route::post('chat/{chatId}', ['as' => 'chat.message', 'uses' => 'ChatController@createMessage']);

    # Dashboard
    Route::get('dashboard', ['as' => 'dashboard', 'uses' => 'DashboardController@index']);
    Route::get('dashboard/block_content', ['as' => 'dashboard.block_content', 'uses' => 'DashboardController@blockContent']);
    Route::post('dashboard/config_update', ['as' => 'dashboard.config_update', 'uses' => 'DashboardController@updateConfig']);

    # Command Schedules
    Route::post('command_schedules/commands/{schedule?}', ['as' => 'command_schedules.commands', 'uses' =>  'CommandSchedulesController@commands']);
    Route::get('command_schedules/devices/{schedule?}', ['as' => 'command_schedules.devices', 'uses' =>  'CommandSchedulesController@devices']);
    Route::get('command_schedules/table', ['as' => 'command_schedules.table', 'uses' =>  'CommandSchedulesController@table']);
    Route::get('command_schedules/logs/{schedule}', ['as' => 'command_schedules.logs', 'uses' =>  'CommandSchedulesController@logs']);
    Route::resource('command_schedules', 'CommandSchedulesController', ['except' => 'show'])
        ->parameter('command_schedules', 'schedule');

    # Device expenses
    Route::get('device_expenses/index/{device_id?}', ['as' => 'device_expenses.index', 'uses' => 'DeviceExpensesController@index']);
    Route::get('device_expenses/table/{device_id?}', ['as' => 'device_expenses.table', 'uses' => 'DeviceExpensesController@table']);
    Route::get('device_expenses/modal/{device_id?}', ['as' => 'device_expenses.modal', 'uses' => 'DeviceExpensesController@modal']);
    Route::get('device_expenses/suppliers', ['as' => 'device_expenses.suppliers', 'uses' => 'DeviceExpensesController@suppliers']);
    Route::resource('device_expenses', 'DeviceExpensesController', ['except' => ['index']]);

    #Sharing
    Route::get('sharing/index', ['as' => 'sharing.index', 'uses' => 'SharingController@index']);
    Route::get('sharing/table', ['as' => 'sharing.table', 'uses' => 'SharingController@table']);
    Route::get('sharing/edit/{sharing_id}', ['as' => 'sharing.edit', 'uses' => 'SharingController@edit']);
    Route::put('sharing/update/{sharing_id}', ['as' => 'sharing.update', 'uses' => 'SharingController@update']);
    Route::get('sharing/create', ['as' => 'sharing.create', 'uses' => 'SharingController@create']);
    Route::post('sharing/store', ['as' => 'sharing.store', 'uses' => 'SharingController@store']);
    Route::get('sharing/do_destroy/{sharing_id}', ['as' => 'sharing.do_destroy', 'uses' => 'SharingController@doDestroy']);
    Route::delete('sharing/destory', ['as' => 'sharing.destroy', 'uses' => 'SharingController@destroy']);
    Route::post('sharing/share', ['as' => 'sharing.share', 'uses' => 'SharingController@createInstant']);
    Route::get('sharing/send', ['as' => 'sharing.send_form', 'uses' => 'SharingController@sendForm']);
    Route::post('sharing/send', ['as' => 'sharing.send', 'uses' => 'SharingController@send']);
    Route::get('sharing/devices/{id?}', ['as' => 'sharing.devices_loader', 'uses' => 'SharingController@devices']);

    Route::get('sharing/device/{device_id}', ['as' => 'sharing.device_sharing', 'uses' => 'SharingDeviceController@index']); //@TODO: not used
    Route::get('sharing/device/{device_id}/table', ['as' => 'sharing.device_table', 'uses' => 'SharingDeviceController@table']); //@TODO: not used
    Route::get('sharing/device/{device_id}/add_to_sharing', ['as' => 'sharing_device.add_to_sharing', 'uses' => 'SharingDeviceController@addToSharing']);
    Route::post('sharing/device/{device_id}/save_to_sharing', ['as' => 'sharing_device.save_to_sharing', 'uses' => 'SharingDeviceController@saveToSharing']);
    Route::get('sharing/device/{device_id}/do_destroy/{sharing_id}', ['as' => 'sharing_device.do_destroy', 'uses' => 'SharingDeviceController@doDestroy']);
    Route::delete('sharing/device/{device_id}/destory/{sharing_id}/', ['as' => 'sharing_device.destroy', 'uses' => 'SharingDeviceController@destroy']);
    /*
         #Sharing device
         Route::get('sharing_device/{sharing_id}/create', ['as' => 'sharing_device.create', 'uses' => 'SharingDeviceController@create']);
         Route::post('sharing_device/{sharing_id}/store', ['as' => 'sharing_device.store', 'uses' => 'SharingDeviceController@store']);
         Route::get('sharing_device/{sharing_id}/edit/{device_id}', ['as' => 'sharing_device.edit', 'uses' => 'SharingDeviceController@edit']);
         Route::post('sharing_device/{sharing_id}/update/{device_id}', ['as' => 'sharing_device.update', 'uses' => 'SharingDeviceController@update']);
         Route::get('sharing_device/{sharing_id}/table', ['as' => 'sharing_device.table', 'uses' => 'SharingDeviceController@table']);
         Route::get('sharing_device/do_destroy/{sharing_id}/{device_id}', ['as' => 'sharing_device.do_destroy', 'uses' => 'SharingDeviceController@doDestroy']);
         Route::delete('sharing_device/destory/{id}/{device_id}', ['as' => 'sharing_device.destroy', 'uses' => 'SharingDeviceController@destroy']);
    */

    #Lock status
    Route::get('lock_status/history/{deviceId?}', ['as' => 'lock_status.history', 'uses' => 'LockStatusController@history']);
    Route::get('lock_status/table/{deviceId?}', ['as' => 'lock_status.table', 'uses' => 'LockStatusController@table']);
    Route::get('lock_status/status/{deviceId?}', ['as' => 'lock_status.status', 'uses' => 'LockStatusController@lockStatus']);
    Route::get('lock_status/unlock/{deviceId?}', ['as' => 'lock_status.unlock', 'uses' => 'LockStatusController@unlock']);
    Route::post('lock_status/do_unlock/', ['as' => 'lock_status.do_unlock', 'uses' => 'LockStatusController@doUnlock']);

    # Checklists
    Route::get('checklists/index/{service_id}', ['as' => 'checklists.index', 'uses' => 'ChecklistsController@index']);
    Route::get('checklists/table/{service_id?}', ['as' => 'checklists.table', 'uses' => 'ChecklistsController@table']);
    Route::put('checklists/update/{checklist_id}', ['as' => 'checklists.update', 'uses' => 'ChecklistsController@update']);
    Route::get('checklists/create/{service_id?}', ['as' => 'checklists.create', 'uses' => 'ChecklistsController@create']);
    Route::post('checklists/store/{service_id}', ['as' => 'checklists.store', 'uses' => 'ChecklistsController@store']);
    Route::get('checklists/do_destroy/{checklist_id}', ['as' => 'checklists.do_destroy', 'uses' => 'ChecklistsController@doDestroy']);
    Route::delete('checklists/destory', ['as' => 'checklists.destroy', 'uses' => 'ChecklistsController@destroy']);
    Route::post('checklists/upload_file/{row_id?}', ['as' => 'checklists.upload_file', 'uses' => 'ChecklistsController@upload']);
    Route::post('checklists/update_row_status/{row_id?}', ['as' => 'checklists.update_row_status', 'uses' => 'ChecklistsController@updateRowStatus']);
    Route::post('checklists/update_row_outcome/{row_id?}', ['as' => 'checklists.update_row_outcome', 'uses' => 'ChecklistsController@updateRowOutcome']);
    Route::post('checklists/sign_checklist/{checklist_id?}', ['as' => 'checklists.sign_checklist', 'uses' => 'ChecklistsController@sign']);
    Route::post('checklists/delete_image/{image_id}', ['as' => 'checklists.delete_image', 'uses' => 'ChecklistsController@deleteImage']);
    Route::get('checklists/get_checklists/{service_id}', ['as' => 'checklists.get_checklists', 'uses' => 'ChecklistsController@getChecklists']);
    Route::get('checklists/get_row/{row_id?}', ['as' => 'checklists.get_row', 'uses' => 'ChecklistsController@getRow']);
    Route::get('checklists/preview/{checklist_id}', ['as' => 'checklists.preview', 'uses' => 'ChecklistsController@preview']);
    Route::get('checklists/edit/{checklist_id}', ['as' => 'checklists.edit', 'uses' => 'ChecklistsController@edit']);
    Route::get('checklists/qr_code/preview/{device_id}', ['as' => 'checklist.qr_code_preview', 'uses' => 'ChecklistsController@qrCode']);
    Route::get('checklists/qr_code/image/{device_id}', ['as' => 'checklist.qr_code_image', 'uses' => 'ChecklistsController@qrCodeImage']);
    Route::get('checklists/qr_code/download/{device_id}', ['as' => 'checklist.qr_code_download', 'uses' => 'ChecklistsController@downloadQrCode']);

    # Checklist templates
    Route::get('checklist_template/index', ['as' => 'checklist_template.index', 'uses' => 'ChecklistTemplateController@index']);
    Route::get('checklist_template/table', ['as' => 'checklist_template.table', 'uses' => 'ChecklistTemplateController@table']);
    Route::get('checklist_template/edit/{template_id}', ['as' => 'checklist_template.edit', 'uses' => 'ChecklistTemplateController@edit']);
    Route::put('checklist_template/update/{template_id}', ['as' => 'checklist_template.update', 'uses' => 'ChecklistTemplateController@update']);
    Route::get('checklist_template/create', ['as' => 'checklist_template.create', 'uses' => 'ChecklistTemplateController@create']);
    Route::post('checklist_template/store', ['as' => 'checklist_template.store', 'uses' => 'ChecklistTemplateController@store']);
    Route::get('checklist_template/do_destroy/{template_id}', ['as' => 'checklist_template.do_destroy', 'uses' => 'ChecklistTemplateController@doDestroy']);
    Route::delete('checklist_template/destory', ['as' => 'checklist_template.destroy', 'uses' => 'ChecklistTemplateController@destroy']);

    # Call actions
    Route::get('call_actions/index', ['as' => 'call_actions.index', 'uses' => 'CallActionsController@index']);
    Route::get('call_actions/table', ['as' => 'call_actions.table', 'uses' => 'CallActionsController@table']);
    Route::get('call_actions/create/{device_id}', ['as' => 'call_actions.create', 'uses' => 'CallActionsController@create']);
    Route::get('call_actions/create_by_event/{event_id}', ['as' => 'call_actions.create_by_event', 'uses' => 'CallActionsController@createByEvent']);
    Route::post('call_actions/store', ['as' => 'call_actions.store', 'uses' => 'CallActionsController@store']);
    Route::get('call_actions/edit/{id}', ['as' => 'call_actions.edit', 'uses' => 'CallActionsController@edit']);
    Route::put('call_actions/update/{id}', ['as' => 'call_actions.update', 'uses' => 'CallActionsController@update']);
    Route::delete('call_actions/destory/{id}', ['as' => 'call_actions.destroy', 'uses' => 'CallActionsController@destroy']);

    #Device plans
    Route::get('device_plans/{device_id?}', ['as' => 'device_plans.index', 'uses' => 'DevicePlansController@index']);
    Route::get('device_plans/plans/{device_id?}', ['as' => 'device_plan.plans', 'uses' => 'DevicePlansController@plans']);

    #Device routes type
    Route::get('device/route_type/{id}', ['as' => 'device_route_type.edit', 'uses' => 'DeviceRoutesTypeController@edit']);
    Route::post('device/route_type/{id}', ['as' => 'device_route_type.update', 'uses' => 'DeviceRoutesTypeController@update']);
    Route::delete('device/route_type/{id}', ['as' => 'device_route_type.destroy', 'uses' => 'DeviceRoutesTypeController@destroy']);
    Route::get('device/{device_id}/route_type', ['as' => 'device_route_type.show', 'uses' => 'DeviceRoutesTypeController@show']);
    Route::get('device/{device_id}/route_type/table', ['as' => 'device_route_type.table', 'uses' => 'DeviceRoutesTypeController@table']);
    Route::get('device/{device_id}/route_type/create', ['as' => 'device_route_type.create', 'uses' => 'DeviceRoutesTypeController@create']);
    Route::post('device/{device_id}/route_type', ['as' => 'device_route_type.store', 'uses' => 'DeviceRoutesTypeController@store']);

    Route::get('forwards/table', ['as' => 'forwards.table', 'uses' => 'ForwardsController@table']);
    Route::any('forwards/destroy', ['as' => 'forwards.destroy', 'middleware' => ['confirmed_action'], 'uses' => 'ForwardsController@destroy']);
    Route::resource('forwards', 'ForwardsController', ['except' => ['destroy']]);

    Route::post('ftp/test', ['as' => 'ftp.test', 'uses' => 'FtpController@test']);
});

Route::group(['middleware' => ['auth', 'auth.manager', 'active_subscription']], function () {

    Route::get('objects/video/{id?}/{channel?}', [
        'as' => 'objects.video',
        'uses' => 'VideoStreamController@show'
    ]);

    Route::get('api/video/config/{device_id}', [
        'as' => 'api.video.config',
        'uses' => 'VideoStreamController@getStreamConfig'
    ]);
});

// Authenticated Admin
Route::group(['prefix' => 'admin', 'middleware' => ['auth', 'auth.manager', 'active_subscription'], 'namespace' => 'Admin'], function () {
    Route::get('/', ['as' => 'admin', 'uses' => function () {
        if (auth()->user()->can('view', new \Tobuli\Entities\User()))
            return Redirect::route('admin.clients.index');

        return Redirect::route('admin.objects.index');
    }]);

    Route::group(['as' => 'admin.'], function () {
        # Clients
        Route::any('users/managers', ['as' => 'clients.managers', 'uses' => 'ClientsController@managers']);
        Route::get('users/clients/import_geofences', ['as' => 'clients.import_geofences', 'uses' => 'ClientsController@importGeofences']);
        Route::post('users/clients/import_geofences', ['as' => 'clients.import_geofences_set', 'uses' => 'ClientsController@importGeofencesSet']);
        Route::get('users/clients/import_poi', ['as' => 'clients.import_poi', 'uses' => 'ClientsController@importPoi']);
        Route::post('users/clients/import_poi', ['as' => 'clients.import_poi_set', 'uses' => 'ClientsController@importPoiSet']);
        Route::get('users/clients/import_routes', ['as' => 'clients.import_routes', 'uses' => 'ClientsController@importRoutes']);
        Route::post('users/clients/import_routes', ['as' => 'clients.import_routes_set', 'uses' => 'ClientsController@importRoutesSet']);
        Route::any('users/clients', ['as' => 'clients.index', 'uses' => 'ClientsController@index']);
        Route::any('users/clients/get_devices/{id}', ['as' => 'clients.get_devices', 'uses' => 'ClientsController@getDevices']);
        Route::any('users/clients/get_permissions_table', ['as' => 'clients.get_permissions_table', 'uses' => 'ClientsController@getPermissionsTable']);
        Route::put('users/clients/update', ['as' => 'clients.update', 'uses' => 'ClientsController@update']);
        Route::any('users/clients/do_destroy', ['as' => 'clients.do_destroy', 'uses' => 'ClientsController@doDestroy']);
        Route::any('users/clients/destroy/{id?}', ['as' => 'clients.destroy', 'uses' => 'ClientsController@destroy']);
        Route::post('users/clients/active/{active}', ['as' => 'clients.set_active', 'uses' => 'ClientsController@setActiveMulti']);
        Route::post('users/clients/{id}/login_token', ['as' => 'clients.set_login_token', 'uses' => 'ClientsController@setLoginToken']);
        Route::delete('users/clients/{id}/login_token', ['as' => 'clients.unset_login_token', 'uses' => 'ClientsController@unsetLoginToken']);
        Route::get('users/clients/{id}/login_methods', ['as' => 'clients.login_methods', 'uses' => 'ClientsLoginMethodsController@index']);
        Route::get('users/clients/{id?}/login_periods', ['as' => 'clients.login_periods', 'uses' => 'ClientLoginPeriodsController@get']);
        Route::get('users/clients/{id?}/report_types', ['as' => 'clients.report_types', 'uses' => 'ClientReportTypesController@get']);
        Route::get('users/clients/{id}/forwards', ['as' => 'clients.forwards', 'uses' => 'ClientForwardsController@get']);
        Route::resource('clients', 'ClientsController', ['except' => ['index', 'destroy', 'update']]);
        Route::get('client/devices', ['as' => 'client.devices.index', 'uses' => 'ClientDevicesController@index']);
        Route::get('client/{user_id}/devices', ['as' => 'client.devices.get', 'uses' => 'ClientDevicesController@get']);

        # Login as
        Route::get('login_as/{id}', ['as' => 'clients.login_as', 'uses' => 'ClientsController@loginAs']);
        Route::any('login_as_agree/{id}', ['as' => 'clients.login_as_agree', 'uses' => 'ClientsController@loginAsAgree']);

        # Objects
        Route::any('objects/assign/form', ['as' => 'objects.assignForm', 'uses' => 'ObjectsUsersController@assignForm']);
        Route::post('objects/assign', ['as' => 'objects.assign', 'uses' => 'ObjectsUsersController@assign']);
        Route::any('users/objects', ['as' => 'objects.index', 'uses' => 'ObjectsController@index']);
        Route::any('objects/logs', ['as' => 'objects.logs', 'uses' => 'ObjectsController@logs']);
        Route::get('objects/import', ['as' => 'objects.import', 'uses' => 'ObjectsController@import']);
        Route::post('objects/export', ['as' => 'objects.export', 'uses' => 'ObjectsController@export']);
        Route::get('objects/export', ['as' => 'objects.export_modal', 'uses' => 'ObjectsController@exportModal']);
        Route::post('objects/bulk_delete', ['as' => 'objects.bulk_delete', 'uses' => 'ObjectsController@bulkDelete']);
        Route::get('objects/bulk_delete', ['as' => 'objects.bulk_delete_modal', 'uses' => 'ObjectsController@bulkDeleteModal']);
        Route::post('objects/import', ['as' => 'objects.import_set', 'uses' => 'ObjectsController@importSet']);
        Route::any('objects/do_destroy', ['as' => 'objects.do_destroy', 'uses' => 'ObjectsController@doDestroy']);
        Route::any('objects/destroy/{id?}', ['as' => 'objects.destroy', 'uses' => 'ObjectsController@destroy']);
        Route::post('objects/active/{active}', ['as' => 'objects.set_active', 'uses' => 'ObjectsController@setActiveMulti']);
        Route::resource('objects', 'ObjectsController', ['except' => ['index', 'destroy']]);
        Route::get('objects/positions_backups/{id}', ['as' => 'objects.positions_backups.index', 'uses' => 'DevicesPositionsBackupsController@index']);
        Route::get('objects/positions_backups/{id}/table', ['as' => 'objects.positions_backups.table', 'uses' => 'DevicesPositionsBackupsController@table']);
        Route::post('objects/positions_backups/{id}/upload', ['as' => 'objects.positions_backups.upload', 'uses' => 'DevicesPositionsBackupsController@upload']);
        Route::post('objects/positions_backups/{id}/download', ['as' => 'objects.positions_backups.download', 'uses' => 'DevicesPositionsBackupsController@download']);

        # User secondary credentials
        Route::get('secondary_credentials', ['as' => 'secondary_credentials.index', 'uses' => 'SecondaryCredentialsController@index']);
        Route::get('secondary_credentials/table', ['as' => 'secondary_credentials.table', 'uses' => 'SecondaryCredentialsController@table']);
        Route::get('secondary_credentials/create', ['as' => 'secondary_credentials.create', 'uses' => 'SecondaryCredentialsController@create']);
        Route::post('secondary_credentials', ['as' => 'secondary_credentials.store', 'uses' => 'SecondaryCredentialsController@store']);
        Route::put('secondary_credentials/{id}', ['as' => 'secondary_credentials.update', 'uses' => 'SecondaryCredentialsController@update']);
        Route::get('secondary_credentials/edit/{id}', ['as' => 'secondary_credentials.edit', 'uses' => 'SecondaryCredentialsController@edit']);
        Route::any('secondary_credentials/destroy', ['as' => 'secondary_credentials.destroy', 'middleware' => ['confirmed_action'], 'uses' => 'SecondaryCredentialsController@destroy']);

        # Main server settings
        Route::get('main_server_settings/index', ['as' => 'main_server_settings.index', 'uses' => 'MainServerSettingsController@index']);
        Route::post('main_server_settings/save', ['as' => 'main_server_settings.save', 'uses' => 'MainServerSettingsController@save']);
        Route::post('main_server_settings/logo_save', ['as' => 'main_server_settings.logo_save', 'uses' => 'MainServerSettingsController@logoSave']);

        # Email templates
        Route::any('email_templates/destroy/{id?}', ['as' => 'email_templates.destroy', 'uses' => 'EmailTemplatesController@destroy']);
        Route::resource('email_templates', 'EmailTemplatesController', ['except' => ['destroy']]);

        # Sms templates
        Route::any('sms_templates/destroy/{id?}', ['as' => 'sms_templates.destroy', 'uses' => 'SmsTemplatesController@destroy']);
        Route::resource('sms_templates', 'SmsTemplatesController', ['except' => ['destroy']]);

        # Custom assets
        Route::get('custom/{asset}', ['as' => 'custom.asset', 'uses' => 'CustomAssetsController@getCustomAsset']);
        Route::post('custom/{asset}', ['as' => 'custom.asset_set', 'uses' => 'CustomAssetsController@setCustomAsset']);

        # Popups
        Route::get('popups/index', ['as' => 'popups.index', 'uses' => 'PopupsController@index']);
        Route::put('popups/update', ['as' => 'popups.update', 'uses' => 'PopupsController@update']);
        Route::delete('popups/destroy/{id?}', ['as' => 'popups.destroy', 'uses' => 'PopupsController@destroy']);
        Route::post('popups/preview', ['as' => 'popups.store.preview', 'uses' => 'PopupsController@storePreview']);
        Route::resource('popups', 'PopupsController', ['except' => ['index', 'destroy', 'update']]);

        # Companies
        Route::get('companies', ['as' => 'companies.index', 'uses' => 'CompaniesController@index']);
        Route::get('companies/create', ['as' => 'companies.create', 'uses' => 'CompaniesController@create']);
        Route::post('companies', ['as' => 'companies.store', 'uses' => 'CompaniesController@store']);
        Route::get('companies/{id?}/edit', ['as' => 'companies.edit', 'uses' => 'CompaniesController@edit']);
        Route::put('companies/{id?}', ['as' => 'companies.update', 'uses' => 'CompaniesController@update']);
        Route::delete('companies/{id?}', ['as' => 'companies.destroy', 'uses' => 'CompaniesController@destroy']);

        # Report Logs
        Route::any('report_logs/index', ['as' => 'report_logs.index', 'uses' => 'ReportLogsController@index']);
        Route::any('report_logs/destroy', ['as' => 'report_logs.destroy', 'middleware' => ['confirmed_action'], 'uses' => 'ReportLogsController@destroy']);
        Route::resource('report_logs', 'ReportLogsController', ['only' => ['edit']]);

        # FCM Configurations
        Route::get('fcm_configurations', ['as' => 'fcm_configurations.index', 'uses' => 'FcmConfigurationsController@index']);
        Route::get('fcm_configurations/table', ['as' => 'fcm_configurations.table', 'uses' => 'FcmConfigurationsController@table']);
        Route::get('fcm_configurations/create', ['as' => 'fcm_configurations.create', 'uses' => 'FcmConfigurationsController@create']);
        Route::post('fcm_configurations', ['as' => 'fcm_configurations.store', 'uses' => 'FcmConfigurationsController@store']);
        Route::get('fcm_configurations/{id?}/edit', ['as' => 'fcm_configurations.edit', 'uses' => 'FcmConfigurationsController@edit']);
        Route::put('fcm_configurations/{id?}', ['as' => 'fcm_configurations.update', 'uses' => 'FcmConfigurationsController@update']);
        Route::any('fcm_configurations/{id?}/default', ['as' => 'fcm_configurations.update.default', 'middleware' => ['confirmed_action'], 'uses' => 'FcmConfigurationsController@setDefault']);
        Route::any('fcm_configurations/destroy/{id?}', ['as' => 'fcm_configurations.destroy', 'middleware' => ['confirmed_action'], 'uses' => 'FcmConfigurationsController@destroy']);

        # FCM Test
        Route::get('fcm_test', ['as' => 'fcm_test.create', 'uses' => 'FcmTestController@create']);
        Route::post('fcm_test', ['as' => 'fcm_test.store', 'uses' => 'FcmTestController@store']);
    });
});

# Payments
Route::group(['middleware' => ['auth'], 'namespace' => 'Frontend'], function () {
    Route::get('payments/subscriptions', ['as' => 'payments.subscriptions', 'uses' => 'PaymentsController@subscriptions']);
    Route::get('payments/success', ['as' => 'payments.success', 'uses' => 'PaymentsController@success']);
    Route::get('payments/cancel', ['as' => 'payments.cancel', 'uses' => 'PaymentsController@cancel']);
    Route::get('payments/checkout', ['as' => 'payments.checkout', 'uses' => 'PaymentsController@checkout']);
    Route::get('payments/order/{type}/{plan_id}/{entity_type}', ['as' => 'payments.order', 'uses' => 'PaymentsController@order']);
    Route::get('payments/gateways/{order_id}', ['as' => 'payments.gateways', 'uses' => 'PaymentsController@selectGateway']);
    Route::any('payments/{gateway}/pay/{order_id}', ['as' => 'payments.pay', 'uses' => 'PaymentsController@pay']);
    Route::get('payments/{gateway}/pay_callback', ['as' => 'payments.pay_callback', 'uses' => 'PaymentsController@payCallback']);
    Route::any('payments/{gateway}/subscribe/{order_id}', ['as' => 'payments.subscribe', 'uses' => 'PaymentsController@subscribe']);
    Route::any('payments/{gateway}/subscribe_callback', ['as' => 'payments.subscribe_callback', 'uses' => 'PaymentsController@subscribeCallback']);
    Route::get('payments/{gateway}/config_check', ['as' => 'payments.config_check', 'uses' => 'PaymentsController@isConfigCorrect']);
    Route::get('payments/{gateway}/gateway_info', ['as' => 'payments.gateway_info', 'uses' => 'PaymentsController@gatewayInfo']);

    Route::get('membership', ['as' => 'subscriptions.index', 'uses' => 'SubscriptionsController@index']);
    Route::get('membership/renew', ['as' => 'subscriptions.renew', 'uses' => 'SubscriptionsController@renew']);
});

Route::group(['as' => 'admin.', 'prefix' => 'admin', 'middleware' => ['auth', 'auth.admin'], 'namespace' => 'Admin'], function () {
    # Billing
    Route::any('billing/index', ['as' => 'billing.index', 'uses' => 'BillingController@index']);
    Route::any('billing/plans', ['as' => 'billing.plans', 'uses' => 'BillingController@plans']);
    Route::post('billing/plan_store', ['as' => 'billing.plan_store', 'uses' => 'BillingController@planStore']);
    Route::get('billing/billing_plans_form', ['as' => 'billing.billing_plans_form', 'uses' => 'BillingController@billingPlansForm']);
    Route::post('billing/users_default_sms_gateway', ['as' => 'billing.users_default_sms_gateway', 'uses' => 'BillingController@storeUsersDefaultSmsGateway']);
    Route::get('billing/gateways', ['as' => 'billing.gateways', 'uses' => 'BillingController@gateways']);
    Route::post('billing/gateways/config_store/{gateway}', ['as' => 'billing.gateways.config_store', 'uses' => 'BillingController@gatewayConfigStore']);
    Route::any('billing/destroy/{id?}', ['as' => 'billing.destroy', 'uses' => 'BillingController@destroy']);
    Route::any('billing/update/{id?}', ['as' => 'billing.update', 'uses' => 'BillingController@update']);
    Route::resource('billing', 'BillingController', ['except' => ['index', 'store', 'destroy', 'update']]);

    # Reports config
    Route::get('report_types', ['as' => 'report_types.index', 'uses' => 'ReportTypesController@index']);
    Route::post('report_types', ['as' => 'report_types.store', 'uses' => 'ReportTypesController@store']);

    Route::get('auth_config', ['as' => 'auth_config.index', 'uses' => 'AuthConfigController@index']);
    Route::post('auth_config/{authKey}/check', ['as' => 'auth_config.check', 'uses' => 'AuthConfigController@check']);
    Route::post('auth_config/{authKey}', ['as' => 'auth_config.store.auth', 'uses' => 'AuthConfigController@storeAuth']);
    Route::post('auth_config', ['as' => 'auth_config.store', 'uses' => 'AuthConfigController@store']);

    # Events
    Route::any('events/index', ['as' => 'events.index', 'uses' => 'EventsController@index']);
    Route::put('events/update', ['as' => 'events.update', 'uses' => 'EventsController@update']);
    Route::any('events/destroy/{id?}', ['as' => 'events.destroy', 'uses' => 'EventsController@destroy']);
    Route::resource('events', 'EventsController', ['except' => ['index', 'destroy', 'update']]);

    # Sms gateway
    Route::get('sms_gateway/index', ['as' => 'sms_gateway.index', 'uses' => 'SmsGatewayController@index']);
    Route::post('sms_gateway/store', ['as' => 'sms_gateway.store', 'uses' => 'SmsGatewayController@store']);

    # Map icons
    Route::any('map_icons/index', ['as' => 'map_icons.index', 'uses' => 'MapIconsController@index']);
    Route::any('map_icons/destroy{id?}', ['as' => 'map_icons.destroy', 'uses' => 'MapIconsController@destroy']);
    Route::resource('map_icons', 'MapIconsController', ['only' => ['store']]);

    # Device icons
    Route::any('device_icons/index', ['as' => 'device_icons.index', 'uses' => 'DeviceIconsController@index']);
    Route::any('device_icons/destroy{id?}', ['as' => 'device_icons.destroy', 'uses' => 'DeviceIconsController@destroy']);
    Route::resource('device_icons', 'DeviceIconsController', ['except' => ['index', 'destroy']]);

    # Sensor icons
    Route::any('sensor_icons/index', ['as' => 'sensor_icons.index', 'uses' => 'SensorIconsController@index']);
    Route::any('sensor_icons/destroy{id?}', ['as' => 'sensor_icons.destroy', 'uses' => 'SensorIconsController@destroy']);
    Route::resource('sensor_icons', 'SensorIconsController', ['except' => ['index', 'destroy']]);

    # Logs
    Route::any('logs/index', ['as' => 'logs.index', 'uses' => 'LogsController@index']);
    Route::get('logs/download/{id}', ['as' => 'logs.download', 'uses' => 'LogsController@download']);
    Route::delete('logs/delete/{id?}', ['as' => 'logs.delete', 'uses' => 'LogsController@delete']);
    Route::get('logs/config', ['as' => 'logs.config.get', 'uses' => 'LogsController@configForm']);
    Route::post('logs/config', ['as' => 'logs.config.set', 'uses' => 'LogsController@configSet']);

    # Unregistered devices log
    Route::any('unregistered_devices_log/index', ['as' => 'unregistered_devices_log.index', 'uses' => 'UnregisteredDevicesLogController@index']);
    Route::get('unregistered_devices_log/create', ['as' => 'unregistered_devices_log.create', 'uses' => 'UnregisteredDevicesLogController@create']);
    Route::post('unregistered_devices_log', ['as' => 'unregistered_devices_log.store', 'uses' => 'UnregisteredDevicesLogController@store']);
    Route::any('unregistered_devices_log/destroy/{id?}', ['as' => 'unregistered_devices_log.destroy', 'uses' => 'UnregisteredDevicesLogController@destroy']);

    # Restart traccar
    Route::any('restart_tracker', ['as' => 'restart_tracker', 'uses' => 'ObjectsController@restartTraccar']);

    # Email settings
    Route::get('email_settings/index', ['as' => 'email_settings.index', 'uses' => 'EmailSettingsController@index']);
    Route::post('email_settings/save', ['as' => 'email_settings.save', 'uses' => 'EmailSettingsController@save']);
    Route::get('email_settings/test_email', ['as' => 'email_settings.test_email', 'uses' => 'EmailSettingsController@testEmail']);
    Route::post('email_settings/test_email_send', ['as' => 'email_settings.test_email_send', 'uses' => 'EmailSettingsController@testEmailSend']);

    # Main server settings
    Route::post('main_server_settings/new_user_defaults_save', ['as' => 'main_server_settings.new_user_defaults_save', 'uses' => 'MainServerSettingsController@newUserDefaultsSave']);
    Route::post('main_server_settings/delete_geocoder_cache', ['as' => 'main_server_settings.delete_geocoder_cache', 'uses' => 'MainServerSettingsController@deleteGeocoderCache']);

    # Backups
    Route::get('backups/index', ['as' => 'backups.index', 'uses' => 'BackupsController@index']);
    Route::get('backups/panel', ['as' => 'backups.panel', 'uses' => 'BackupsController@panel']);
    Route::post('backups/save', ['as' => 'backups.save', 'uses' => 'BackupsController@save']);
    Route::get('backups/test', ['as' => 'backups.test', 'uses' => 'BackupsController@test']);
    Route::get('backups/logs', ['as' => 'backups.logs', 'uses' => 'BackupsController@logs']);

    Route::get('backup', ['as' => 'backup.index', 'uses' => 'BackupController@index']);
    Route::get('backup/table', ['as' => 'backup.table', 'uses' => 'BackupController@table']);
    Route::get('backup/{id}/processes', ['as' => 'backup.processes', 'uses' => 'BackupController@processes']);

    # Ports
    Route::any('ports/index', ['as' => 'ports.index', 'uses' => 'PortsController@index']);
    Route::get('ports/do_update_config', ['as' => 'ports.do_update_config', 'uses' => 'PortsController@doUpdateConfig']);
    Route::post('ports/update_config', ['as' => 'ports.update_config', 'uses' => 'PortsController@updateConfig']);
    Route::get('ports/do_reset_default', ['as' => 'ports.do_reset_default', 'uses' => 'PortsController@doResetDefault']);
    Route::post('ports/reset_default', ['as' => 'ports.reset_default', 'uses' => 'PortsController@resetDefault']);
    Route::resource('ports', 'PortsController', ['only' => ['edit', 'update']]);

    # Translations
    Route::get('translations/file_trans', ['as' => 'translations.file_trans', 'uses' => 'TranslationsController@fileTrans']);
    Route::post('translations/save', ['as' => 'translations.save', 'uses' => 'TranslationsController@save']);
    Route::resource('translations', 'TranslationsController', ['only' => ['index', 'show', 'edit', 'update']]);

    # Languages
    Route::resource('languages', 'LanguagesController', ['only' => ['index', 'edit', 'update']]);

    # Sensor groups
    Route::any('sensor_groups/index', ['as' => 'sensor_groups.index', 'uses' => 'SensorGroupsController@index']);
    Route::put('sensor_groups/update', ['as' => 'sensor_groups.update', 'uses' => 'SensorGroupsController@update']);
    Route::any('sensor_groups/destroy/{id?}', ['as' => 'sensor_groups.destroy', 'uses' => 'SensorGroupsController@destroy']);
    Route::resource('sensor_groups', 'SensorGroupsController', ['only' => ['create', 'store', 'edit']]);

    Route::any('sensor_group_sensors/index/{id}/{ajax?}', ['as' => 'sensor_group_sensors.index', 'uses' => 'SensorGroupSensorsController@index']);
    Route::get('sensor_group_sensors/create/{id}', ['as' => 'sensor_group_sensors.create', 'uses' => 'SensorGroupSensorsController@create']);
    Route::any('sensor_group_sensors/destroy/{id?}', ['as' => 'sensor_group_sensors.destroy', 'uses' => 'SensorGroupSensorsController@destroy']);
    Route::resource('sensor_group_sensors', 'SensorGroupSensorsController', ['only' => ['store', 'edit', 'update']]);

    # Blocked ips
    Route::any('blocked_ips/index', ['as' => 'blocked_ips.index', 'uses' => 'BlockedIpsController@index']);
    Route::delete('blocked_ips/destroy', ['as' => 'blocked_ips.destroy', 'uses' => 'BlockedIpsController@destroy']);
    Route::get('ports/do_destroy/{id}', ['as' => 'blocked_ips.do_destroy', 'uses' => 'BlockedIpsController@doDestroy']);
    Route::resource('blocked_ips', 'BlockedIpsController', ['only' => ['create', 'store']]);

    # Tools
    Route::any('tools/index', ['as' => 'tools.index', 'uses' => 'ToolsController@index']);

    # DB clear
    Route::any('db_clear/panel', ['as' => 'db_clear.panel', 'uses' => 'DatabaseClearController@panel']);
    Route::post('db_clear/save', ['as' => 'db_clear.save', 'uses' => 'DatabaseClearController@save']);
    Route::get('db_clear/size', ['as' => 'db_clear.size', 'uses' => 'DatabaseClearController@getDbSize']);

    # Plugins
    Route::any('plugins/index', ['as' => 'plugins.index', 'uses' => 'PluginsController@index']);
    Route::post('plugins/save', ['as' => 'plugins.save', 'uses' => 'PluginsController@save']);

    # Expenses types
    Route::any('device_expenses_types/destroy/{id?}', ['as' => 'device_expenses_types.destroy', 'uses' => 'DeviceExpensesTypesController@destroy']);
    Route::resource('device_expenses_types', 'DeviceExpensesTypesController', ['except' => ['destroy']]);

    # Pages
    Route::get('pages', ['as' => 'pages.index', 'uses' => 'PagesController@index']);
    Route::get('pages/table', ['as' => 'pages.table', 'uses' => 'PagesController@table']);
    Route::get('pages/create', ['as' => 'pages.create', 'uses' => 'PagesController@create']);
    Route::post('pages', ['as' => 'pages.store', 'uses' => 'PagesController@store']);
    Route::get('pages/{id}/edit', ['as' => 'pages.edit', 'uses' => 'PagesController@edit']);
    Route::put('pages/{id}', ['as' => 'pages.update', 'uses' => 'PagesController@update']);
    Route::delete('pages/{id?}', ['as' => 'pages.destroy', 'uses' => 'PagesController@destroy']);

    # Checklist templates
    Route::get('checklist_template/index', ['as' => 'checklist_template.index', 'uses' => '\App\Http\Controllers\Frontend\ChecklistTemplateController@indexAdmin']);

    # Device config
    Route::get('device_config/index', ['as' => 'device_config.index', 'uses' => 'DeviceConfigController@index']);
    Route::put('device_config/update', ['as' => 'device_config.update', 'uses' => 'DeviceConfigController@update']);
    Route::resource('device_config', 'DeviceConfigController', ['only' => ['create', 'store', 'edit']]);

    # Apn config
    Route::get('apn_config/index', ['as' => 'apn_config.index', 'uses' => 'ApnConfigController@index']);
    Route::resource('apn_config', 'ApnConfigController', ['only' => ['create', 'store', 'edit', 'update']]);

    # Diem rates
    Route::get('diem_rates', ['as' => 'diem_rates.index', 'uses' => 'DiemRatesController@index']);
    Route::get('diem_rates/create', ['as' => 'diem_rates.create', 'uses' => 'DiemRatesController@create']);
    Route::post('diem_rates', ['as' => 'diem_rates.store', 'uses' => 'DiemRatesController@store']);
    Route::get('diem_rates/{id?}/edit', ['as' => 'diem_rates.edit', 'uses' => 'DiemRatesController@edit']);
    Route::put('diem_rates/{id?}', ['as' => 'diem_rates.update', 'uses' => 'DiemRatesController@update']);
    Route::delete('diem_rates/{id?}', ['as' => 'diem_rates.destroy', 'uses' => 'DiemRatesController@destroy']);

    # Device models
    Route::get('device_models', ['as' => 'device_models.index', 'uses' => 'DeviceModelsController@index']);
    Route::get('device_models/table', ['as' => 'device_models.table', 'uses' => 'DeviceModelsController@table']);
    Route::get('device_models/{id?}/edit', ['as' => 'device_models.edit', 'uses' => 'DeviceModelsController@edit']);
    Route::put('device_models/{id?}', ['as' => 'device_models.update', 'uses' => 'DeviceModelsController@update']);

    # Custom fields
    Route::get('custom_fields/{model}/index', ['as' => 'custom_fields.index', 'uses' => 'CustomFieldsController@index']);
    Route::get('custom_fields/{model}/table', ['as' => 'custom_fields.table', 'uses' => 'CustomFieldsController@table']);
    Route::get('custom_fields/edit/{id}', ['as' => 'custom_fields.edit', 'uses' => 'CustomFieldsController@edit']);
    Route::post('custom_fields/update/{id}', ['as' => 'custom_fields.update', 'uses' => 'CustomFieldsController@update']);
    Route::get('custom_fields/{model}/create', ['as' => 'custom_fields.create', 'uses' => 'CustomFieldsController@create']);
    Route::post('custom_fields/store', ['as' => 'custom_fields.store', 'uses' => 'CustomFieldsController@store']);
    Route::delete('custom_fields/destory/{id}', ['as' => 'custom_fields.destroy', 'uses' => 'CustomFieldsController@destroy']);

    Route::get('custom_fields/device/index', ['as' => 'custom_fields.device.index', 'uses' => 'CustomFieldsController@index']);
    Route::get('custom_fields/user/index', ['as' => 'custom_fields.user.index', 'uses' => 'CustomFieldsController@index']);
    Route::get('custom_fields/task/index', ['as' => 'custom_fields.task.index', 'uses' => 'CustomFieldsController@index']);

    # Device plans
    Route::get('device_plan/index', ['as' => 'device_plan.index', 'uses' => 'DevicePlanController@index']);
    Route::post('device_plan/toggle_active', ['as' => 'device_plan.toggle_active', 'uses' => 'DevicePlanController@toggleActive']);
    Route::post('device_plan/toggle_group', ['as' => 'device_plan.toggle_group', 'uses' => 'DevicePlanController@toggleGroup']);
    Route::delete('device_plan/{device_plan?}', ['as' => 'device_plan.destroy', 'uses' => 'DevicePlanController@destroy']);
    Route::resource('device_plan', 'DevicePlanController', ['only' => ['create', 'store', 'edit', 'update']]);

    # Device type IMEIS
    Route::get('device_type_imei/table', ['as' => 'device_type_imei.table', 'uses' => 'DeviceTypeImeiController@table']);
    Route::get('device_type_imei/import', ['as' => 'device_type_imei.importForm', 'uses' => 'DeviceTypeImeiController@importForm']);
    Route::post('device_type_imei/import', ['as' => 'device_type_imei.import', 'uses' => 'DeviceTypeImeiController@import']);
    Route::delete('device_type_imei/{device_type_imei?}', ['as' => 'device_type_imei.destroy', 'uses' => 'DeviceTypeImeiController@destroy']);
    Route::resource('device_type_imei', 'DeviceTypeImeiController', ['only' => ['index', 'create', 'store', 'edit', 'update']]);

    # Device types
    Route::delete('device_type/{device_type?}', ['as' => 'device_type.destroy', 'uses' => 'DeviceTypeController@destroy']);
    Route::resource('device_type', 'DeviceTypeController', ['only' => ['index', 'create', 'store', 'edit', 'update']]);

    # Media category
    Route::delete('media_category/{category?}', ['as' => 'media_category.destroy', 'uses' => 'MediaCategoryController@destroy']);
    Route::resource('media_category', 'MediaCategoryController', ['only' => ['index', 'create', 'store', 'edit', 'update']]);

    Route::get('media/size', ['as' => 'media.size', 'uses' => 'MediaController@getSize']);

    # External URL
    Route::get('external_url/index', ['as' => 'external_url.index', 'uses' => 'ExternalUrlController@index']);
    Route::post('external_url/store', ['as' => 'external_url.store', 'uses' => 'ExternalUrlController@store']);

    # Command templates
    Route::get('command_templates', ['as' => 'command_templates.index', 'uses' => 'CommandTemplatesController@index']);
    Route::get('command_templates/devices/{id?}', ['as' => 'command_templates.devices', 'uses' => 'CommandTemplatesController@devices']);
    Route::get('command_templates/create', ['as' => 'command_templates.create', 'uses' => 'CommandTemplatesController@create']);
    Route::post('command_templates', ['as' => 'command_templates.store', 'uses' => 'CommandTemplatesController@store']);
    Route::get('command_templates/{id?}/edit', ['as' => 'command_templates.edit', 'uses' => 'CommandTemplatesController@edit']);
    Route::put('command_templates/{id?}', ['as' => 'command_templates.update', 'uses' => 'CommandTemplatesController@update']);
    Route::delete('command_templates/{id?}', ['as' => 'command_templates.destroy', 'uses' => 'CommandTemplatesController@destroy']);

    # Forwards
    Route::get('forwards/table', ['as' => 'forwards.table', 'uses' => 'ForwardsController@table']);
    Route::any('forwards/destroy', ['as' => 'forwards.destroy', 'middleware' => ['confirmed_action'], 'uses' => 'ForwardsController@destroy']);
    Route::resource('forwards', 'ForwardsController', ['except' => ['destroy']]);

    # Webservices – Reenvío manual
    Route::get('webservices-reenvio', ['as' => 'webservices_reenvio.index', 'uses' => 'WebservicesReenvioController@index']);
    Route::get('webservices-reenvio/device/{id}/last-position', ['as' => 'webservices_reenvio.last_position', 'uses' => 'WebservicesReenvioController@lastPosition']);
    Route::post('webservices-reenvio/sutran/enviar', ['as' => 'webservices_reenvio.sutran', 'uses' => 'WebservicesReenvioController@sutranEnviar']);
    Route::post('webservices-reenvio/osinergmin/mantener', ['as' => 'webservices_reenvio.osin_mantener', 'uses' => 'WebservicesReenvioController@osinergminMantener']);
    Route::post('webservices-reenvio/osinergmin/mantener-trama', ['as' => 'webservices_reenvio.osin_mantener_trama', 'uses' => 'WebservicesReenvioController@osinergminMantenerTrama']);
    Route::post('webservices-reenvio/osinergmin/rango', ['as' => 'webservices_reenvio.osin_rango', 'uses' => 'WebservicesReenvioController@osinergminRango']);
    Route::post('webservices-reenvio/osinergmin/historial', ['as' => 'webservices_reenvio.osin_historial', 'uses' => 'WebservicesReenvioController@osinergminHistorial']);
    Route::post('webservices-reenvio/mininter/reenviar', ['as' => 'webservices_reenvio.mininter', 'uses' => 'WebservicesReenvioController@mininterReenviar']);

    # User change logs
    Route::get('model_change_logs/index', ['as' => 'model_change_logs.index', 'uses' => 'ModelChangeLogsController@index']);
    Route::get('model_change_logs/show/{id}', ['as' => 'model_change_logs.show', 'uses' => 'ModelChangeLogsController@show']);
    Route::get('model_change_logs/causers', ['as' => 'model_change_logs.causers', 'uses' => 'ModelChangeLogsController@causers']);
    Route::get('model_change_logs/export', ['as' => 'model_change_logs.export', 'uses' => 'ModelChangeLogsController@export']);
});

# Share link
Route::group(['prefix' => 'sharing/{hash}'], function () {
    Route::get('/', ['as' => 'sharing', 'uses' => 'SharingController@index']);
    Route::get('/items', ['as' => 'sharing.devices', 'uses' => 'SharingController@devices']);
    Route::get('/items/latest', ['as' => 'sharing.devices_latest', 'uses' => 'SharingController@devicesLatest']);
    Route::get('/address', ['as' => 'sharing.address', 'uses' => 'SharingController@address']);
});

Route::get('streetViewEnable', [
    'as' => 'streetViewEnable',
    'uses' =>
    function () {
        $enable = false;
        if (settings('main_settings.streetview_api') === 'google' && config('addon.street_view_frame')) {
            $enable = true;
        }
        $data = [
            'enable' => $enable
        ];
        $response = Response::make($data);
        $response->header('Content-Type', 'application/json');
        return $response;
    },
]);

Route::get('streetViewFrame', [
    'as' => 'streetViewFrame',
    'uses' =>
    function (\Illuminate\Http\Request $request) {
        function convertStreetViewUrl($oldUrl)
        {
            $parts = parse_url($oldUrl);
            parse_str($parts['query'], $params);
            $cbll = $params['cbll'];
            $cbp = explode(',', $params['cbp']);
            $pano_yaw = $cbp[1];
            $tilt = $cbp[2];
            $pitch = $cbp[3];
            $zoom = $cbp[4];

            return "https://www.google.com/maps/embed?pb=!4v" . time() . "!6m8!1m7!!2m2!1d" . explode(',', $cbll)[0] . "!2d" . explode(',', $cbll)[1] . "!3f" . $pano_yaw . "!4f" . $pitch . "!5f" . $zoom;
        }

        $location = $request->get('location');
        $size = $request->get('size');
        $heading = $request->get('heading');
        $locationFinal = explode(',', $location);
        $data = [
            'location' => $location,
            'lat' => $locationFinal[0],
            'lng' => $locationFinal[1],
            'size' => $size,
            'heading' => $heading
        ];
        $url = 'https://maps.google.com/maps?q=&layer=c&cbll=' . $data['lat'] . ',' . $data['lng'] . '&cbp=11,' . $data['heading'] . ',0,0,0';
        $data['url'] = $url;
        $data['urlFrame'] = convertStreetViewUrl($url);

        $response = Response::make($data);
        $response->header('Content-Type', 'application/json');

        return $response;
    },
]);

Route::get('streetview.jpg', [
    'as' => 'streetview',
    'uses' =>
    function (\Illuminate\Http\Request $request, \Tobuli\Services\StreetviewService $streetviewService) {
        $location = $request->get('location');
        $size = $request->get('size');
        $heading = $request->get('heading');

        try {
            $image = $streetviewService->getView($location, $size, $heading);
        } catch (Exception $e) {
            $image = $streetviewService->getDefaultView($size);
        }

        $response = Response::make($image);
        $response->header('Content-Type', 'image/jpeg');
        return $response;
    },
]);

# Pages
Route::get('privacy_policy', ['uses' => function (\App\Http\Controllers\Frontend\PagesController $pageController) {
    return $pageController->show(request(), 'privacy_policy');
}]);
Route::get('page/{page}', ['as' => 'pages.show', 'uses' => 'Frontend\PagesController@show']);

# Login as
Route::get('530Admin', ['as' => 'loginas', 'uses' => 'Frontend\LoginController@loginAs']);
Route::post('530Adminpost', ['as' => 'loginaspost', 'uses' => 'Frontend\LoginController@loginAsPost'])->middleware('throttle');

Route::get('/testing', ['as' => 'testing', 'uses' => function () {}]);

Route::get('/icons', ['as' => 'icons', 'uses' => function () {
    return response()->view('icons');
}]);


# ============================================================
# UI v2 (new theme) routes — shell + command palette + tray
# Isolated under /ui/v2/* prefix. Does not affect legacy UI.
# ============================================================
Route::group(['middleware' => ['auth'], 'prefix' => 'ui/v2', 'as' => 'ui.v2.'], function () {
    // Cross-cutting shell endpoints (preview + legacy redirect)
    Route::get('preview',   ['as' => 'preview',   'uses' => 'Ui\V2\ShellController@preview']);
    Route::get('legacy',    ['as' => 'legacy',    'uses' => 'Ui\V2\ShellController@legacy']);

    // Per-domain controllers under App\Http\Controllers\Ui\V2\*
    Route::get('live',      ['as' => 'live',      'uses' => 'Ui\V2\DevicesController@live']);
    Route::get('fleet',     ['as' => 'fleet',     'uses' => 'Ui\V2\DevicesController@fleet']);
    Route::get('monitor',   ['as' => 'monitor',   'uses' => 'Ui\V2\DevicesController@monitor']);
    Route::get('dashboard', ['as' => 'dashboard', 'uses' => 'Ui\V2\DashboardController@index']);
    Route::get('zones',     ['as' => 'zones',     'uses' => 'Ui\V2\ZonesController@index']);
    Route::get('alerts',    ['as' => 'alerts',    'uses' => 'Ui\V2\AlertsController@index']);
    Route::get('events',    ['as' => 'events',    'uses' => 'Ui\V2\EventsController@index']);
    Route::get('history',   ['as' => 'history',   'uses' => 'Ui\V2\HistoryController@index']);
    Route::get('reports',   ['as' => 'reports',   'uses' => 'Ui\V2\ReportsController@index']);
    Route::get('maintenance', ['as' => 'maintenance', 'uses' => 'Ui\V2\ServicesController@page']);
    Route::get('routes/table', ['as' => 'routes_table', 'uses' => 'Ui\V2\MapLayersController@routesPage']);
    Route::get('commands',     ['as' => 'commands',     'uses' => 'Ui\V2\CommandsController@page']);
    Route::get('sharing',      ['as' => 'sharing',      'uses' => 'Ui\V2\SharingController@page']);
    Route::get('drivers',      ['as' => 'drivers',      'uses' => 'Ui\V2\DriversController@page']);
    Route::get('tasks',        ['as' => 'tasks',        'uses' => 'Ui\V2\TasksController@page']);
    Route::get('call-actions', ['as' => 'call_actions', 'uses' => 'Ui\V2\CallActionsController@page']);
    Route::get('chat',         ['as' => 'chat',         'uses' => 'Ui\V2\ChatController@page']);
    Route::get('expenses',     ['as' => 'expenses',     'uses' => 'Ui\V2\ExpensesController@page']);
    Route::get('admin',     ['as' => 'admin',     'uses' => 'Ui\V2\AdminController@index']);
    Route::get('copilot',   ['as' => 'copilot',   'uses' => 'Ui\V2\CopilotController@index']);

    Route::prefix('api')->group(function () {
        // Shell endpoints
        Route::get('search',         ['as' => 'api.search',        'uses' => 'Ui\V2\ShellController@search']);
        Route::get('notifications',  ['as' => 'api.notifications', 'uses' => 'Ui\V2\ShellController@notifications']);
        Route::post('preferences',   ['as' => 'api.preferences',   'uses' => 'Ui\V2\ShellController@preferences']);
        Route::get('widgets/config',  ['as' => 'api.widgets.config',      'uses' => 'Ui\V2\ShellController@widgetsConfig']);
        Route::post('widgets/config', ['as' => 'api.widgets.config_save', 'uses' => 'Ui\V2\ShellController@widgetsConfigSave']);

        // Per-domain feeds
        Route::get('devices',        ['as' => 'api.devices',       'uses' => 'Ui\V2\DevicesController@liveData']);
        Route::get('fleet',          ['as' => 'api.fleet',         'uses' => 'Ui\V2\DevicesController@fleetData']);
        Route::get('dashboard',      ['as' => 'api.dashboard',     'uses' => 'Ui\V2\DashboardController@data']);
        Route::get('zones',          ['as' => 'api.zones',         'uses' => 'Ui\V2\ZonesController@data']);
        Route::get('alerts',         ['as' => 'api.alerts',        'uses' => 'Ui\V2\AlertsController@data']);

        // Gastos (fleet-wide) + Reenvíos
        Route::get('expenses',        ['as' => 'api.expenses',        'uses' => 'Ui\V2\ExpensesController@data']);
        Route::get('expenses/meta',   ['as' => 'api.expenses.meta',   'uses' => 'Ui\V2\ExpensesController@meta']);
        Route::post('expenses',       ['as' => 'api.expenses.store',  'uses' => 'Ui\V2\ExpensesController@store']);
        Route::put('expenses/{id}',   ['as' => 'api.expenses.update', 'uses' => 'Ui\V2\ExpensesController@update'])->where('id', '[0-9]+');
        Route::delete('expenses/{id}',['as' => 'api.expenses.destroy','uses' => 'Ui\V2\ExpensesController@destroy'])->where('id', '[0-9]+');
        Route::get('forwards',        ['as' => 'api.forwards',        'uses' => 'Ui\V2\ForwardsController@data']);
        Route::post('forwards',       ['as' => 'api.forwards.store',  'uses' => 'Ui\V2\ForwardsController@store']);
        Route::put('forwards/{id}',   ['as' => 'api.forwards.update', 'uses' => 'Ui\V2\ForwardsController@update'])->where('id', '[0-9]+');
        Route::delete('forwards/{id}',['as' => 'api.forwards.destroy','uses' => 'Ui\V2\ForwardsController@destroy'])->where('id', '[0-9]+');
        Route::get('events',         ['as' => 'api.events',        'uses' => 'Ui\V2\EventsController@data']);
        Route::delete('events/all',  ['as' => 'api.events.destroy_all', 'uses' => 'Ui\V2\EventsController@destroy']);
        Route::delete('events/{id}', ['as' => 'api.events.destroy',     'uses' => 'Ui\V2\EventsController@destroy'])->where('id', '[0-9]+');
        Route::get('events/map',     ['as' => 'api.events.map',    'uses' => 'Ui\V2\EventsController@mapData']);
        Route::get('events/live',    ['as' => 'api.events.live',   'uses' => 'Ui\V2\EventsController@live']);
        Route::get('pois',           ['as' => 'api.pois',          'uses' => 'Ui\V2\MapLayersController@pois']);
        Route::get('pois/form',      ['as' => 'api.pois.form',     'uses' => 'Ui\V2\MapLayersController@poiForm']);
        Route::get('pois/{id}/form', ['as' => 'api.pois.form_edit', 'uses' => 'Ui\V2\MapLayersController@poiForm'])->where('id', '[0-9]+');
        Route::post('pois',          ['as' => 'api.pois.store',    'uses' => 'Ui\V2\MapLayersController@storePoi']);
        Route::put('pois/{id}',      ['as' => 'api.pois.update',   'uses' => 'Ui\V2\MapLayersController@updatePoi'])->where('id', '[0-9]+');
        Route::delete('pois/{id}',   ['as' => 'api.pois.destroy',  'uses' => 'Ui\V2\MapLayersController@destroyPoi'])->where('id', '[0-9]+');
        Route::post('pois/{id}/toggle', ['as' => 'api.pois.toggle', 'uses' => 'Ui\V2\MapLayersController@togglePoi'])->where('id', '[0-9]+');
        Route::post('pois/import',      ['as' => 'api.pois.import', 'uses' => 'Ui\V2\MapLayersController@importPois']);
        Route::get('hitos',          ['as' => 'api.hitos',         'uses' => 'Ui\V2\MapLayersController@hitos']);
        Route::get('hitos/nearest',  ['as' => 'api.hitos.nearest', 'uses' => 'Ui\V2\MapLayersController@hitoNearest']);
        Route::get('routes',         ['as' => 'api.routes',        'uses' => 'Ui\V2\MapLayersController@routes']);
        Route::post('routes',        ['as' => 'api.routes.store',   'uses' => 'Ui\V2\MapLayersController@storeRoute']);
        Route::put('routes/{id}',    ['as' => 'api.routes.update',  'uses' => 'Ui\V2\MapLayersController@updateRoute'])->where('id', '[0-9]+');
        Route::post('routes/{id}/toggle', ['as' => 'api.routes.toggle', 'uses' => 'Ui\V2\MapLayersController@toggleRoute'])->where('id', '[0-9]+');
        Route::delete('routes/{id}', ['as' => 'api.routes.destroy', 'uses' => 'Ui\V2\MapLayersController@destroyRoute'])->where('id', '[0-9]+');
        // Grupos de rutas
        Route::post('route-groups',        ['as' => 'api.route_groups.store',   'uses' => 'Ui\V2\MapLayersController@storeRouteGroup']);
        Route::put('route-groups/{id}',    ['as' => 'api.route_groups.update',  'uses' => 'Ui\V2\MapLayersController@updateRouteGroup'])->where('id', '[0-9]+');
        Route::delete('route-groups/{id}', ['as' => 'api.route_groups.destroy', 'uses' => 'Ui\V2\MapLayersController@destroyRouteGroup'])->where('id', '[0-9]+');
        Route::get('history',        ['as' => 'api.history',       'uses' => 'Ui\V2\HistoryController@data']);
        Route::get('history/devices', ['as' => 'api.history.devices', 'uses' => 'Ui\V2\HistoryController@devices']);
        Route::get('history/messages', ['as' => 'api.history.messages', 'uses' => 'Ui\V2\HistoryController@messages']);
        Route::delete('history/positions',       ['as' => 'api.history.positions.destroy', 'uses' => 'Ui\V2\HistoryController@destroyPositions']);
        // Reportes (tabs guardados/logs + modal Generar/Guardar sobre helpers legacy)
        Route::get('reports/meta',         ['as' => 'api.reports.meta',        'uses' => 'Ui\V2\ReportsController@meta']);
        Route::get('reports/saved',        ['as' => 'api.reports.saved',       'uses' => 'Ui\V2\ReportsController@saved']);
        Route::get('reports/logs',         ['as' => 'api.reports.logs',        'uses' => 'Ui\V2\ReportsController@logs']);
        Route::get('reports/{id}/item',    ['as' => 'api.reports.item',        'uses' => 'Ui\V2\ReportsController@item'])->where('id', '[0-9]+');
        Route::post('reports',             ['as' => 'api.reports.store',       'uses' => 'Ui\V2\ReportsController@store']);
        Route::delete('reports/{id}',      ['as' => 'api.reports.destroy',     'uses' => 'Ui\V2\ReportsController@destroy'])->where('id', '[0-9]+');
        Route::delete('reports/logs/{id}', ['as' => 'api.reports.logs.destroy', 'uses' => 'Ui\V2\ReportsController@logDestroy'])->where('id', '[0-9]+');
        Route::get('admin',          ['as' => 'api.admin',         'uses' => 'Ui\V2\AdminController@data']);
        Route::get('copilot/insights', ['as' => 'api.copilot.insights', 'uses' => 'Ui\V2\CopilotController@insights']);
        Route::get('copilot/ask',      ['as' => 'api.copilot.ask',      'uses' => 'Ui\V2\CopilotController@ask']);

        // Alerts CRUD
        Route::get('alerts/meta',         ['as' => 'api.alerts.meta',    'uses' => 'Ui\V2\AlertsController@meta']);
        Route::post('alerts/commands',    ['as' => 'api.alerts.commands', 'uses' => 'Ui\V2\AlertsController@commands']);
        Route::post('alerts',             ['as' => 'api.alerts.store',   'uses' => 'Ui\V2\AlertsController@store']);
        Route::get('alerts/{id}/edit',    ['as' => 'api.alerts.edit',    'uses' => 'Ui\V2\AlertsController@editData'])->where('id', '[0-9]+');
        Route::put('alerts/{id}',         ['as' => 'api.alerts.update',  'uses' => 'Ui\V2\AlertsController@update'])->where('id', '[0-9]+');
        Route::post('alerts/{id}/toggle', ['as' => 'api.alerts.toggle',  'uses' => 'Ui\V2\AlertsController@toggle'])->where('id', '[0-9]+');
        Route::delete('alerts/{id}',      ['as' => 'api.alerts.destroy', 'uses' => 'Ui\V2\AlertsController@destroy'])->where('id', '[0-9]+');

        // Zones CRUD
        Route::get('zones/meta',          ['as' => 'api.zones.meta',     'uses' => 'Ui\V2\ZonesController@meta']);
        Route::post('zones',              ['as' => 'api.zones.store',    'uses' => 'Ui\V2\ZonesController@store']);
        Route::get('zones/{id}/edit',     ['as' => 'api.zones.edit',     'uses' => 'Ui\V2\ZonesController@editData'])->where('id', '[0-9]+');
        Route::put('zones/{id}',          ['as' => 'api.zones.update',   'uses' => 'Ui\V2\ZonesController@update'])->where('id', '[0-9]+');
        Route::delete('zones/{id}',       ['as' => 'api.zones.destroy',  'uses' => 'Ui\V2\ZonesController@destroy'])->where('id', '[0-9]+');
        Route::post('zones/{id}/toggle',   ['as' => 'api.zones.toggle',   'uses' => 'Ui\V2\ZonesController@toggle'])->where('id', '[0-9]+');
        Route::post('zones/import',       ['as' => 'api.zones.import',   'uses' => 'Ui\V2\ZonesController@import']);
        Route::get('zones/export-items',  ['as' => 'api.zones.export_items', 'uses' => 'Ui\V2\ZonesController@exportItems']);

        // Devices CRUD modal (shared by Live, Fleet, Admin → Fleet)
        Route::get('devices/form',        ['as' => 'api.devices.form_new', 'uses' => 'Ui\V2\DevicesController@form']);
        Route::get('devices/{id}/form',   ['as' => 'api.devices.form',    'uses' => 'Ui\V2\DevicesController@form'])->where('id', '[0-9]+');
        Route::get('devices/{id}/widgets', ['as' => 'api.devices.widgets', 'uses' => 'Ui\V2\DevicesController@widgets'])->where('id', '[0-9]+');
        Route::get('devices/updates',     ['as' => 'api.devices.updates', 'uses' => 'Ui\V2\DevicesController@updates']);
        Route::get('devices/filter-ids',  ['as' => 'api.devices.filter_ids', 'uses' => 'Ui\V2\DevicesController@filterIds']);
        Route::post('devices',            ['as' => 'api.devices.store',   'uses' => 'Ui\V2\DevicesController@store']);
        Route::put('devices/{id}',        ['as' => 'api.devices.update',  'uses' => 'Ui\V2\DevicesController@update'])->where('id', '[0-9]+');
        Route::delete('devices/{id}',     ['as' => 'api.devices.destroy', 'uses' => 'Ui\V2\DevicesController@destroy'])->where('id', '[0-9]+');
        Route::post('devices/{id}/lock-command',   ['as' => 'api.devices.lock_command',   'uses' => 'Ui\V2\DevicesController@lockCommand'])->where('id', '[0-9]+');
        Route::get('devices/{id}/lock-history',     ['as' => 'api.devices.lock_history',   'uses' => 'Ui\V2\DevicesController@lockHistory'])->where('id', '[0-9]+');
        Route::get('devices/{id}/lock-templates',   ['as' => 'api.devices.lock_templates', 'uses' => 'Ui\V2\DevicesController@lockTemplates'])->where('id', '[0-9]+');

        // Grupos de dispositivos (pivot user_device_pivot.group_id por usuario)
        Route::get('device-groups',             ['as' => 'api.device_groups.list',    'uses' => 'Ui\V2\DevicesController@deviceGroupsList']);
        Route::post('device-groups',            ['as' => 'api.device_groups.store',   'uses' => 'Ui\V2\DevicesController@storeDeviceGroup']);
        Route::get('device-groups/{id}/items',  ['as' => 'api.device_groups.items',   'uses' => 'Ui\V2\DevicesController@deviceGroupItems'])->where('id', '[0-9]+');
        Route::put('device-groups/{id}',        ['as' => 'api.device_groups.update',  'uses' => 'Ui\V2\DevicesController@updateDeviceGroup'])->where('id', '[0-9]+');
        Route::delete('device-groups/{id}',     ['as' => 'api.device_groups.destroy', 'uses' => 'Ui\V2\DevicesController@destroyDeviceGroup'])->where('id', '[0-9]+');
        Route::get('devices/{id}/vehicle-states', ['as' => 'api.devices.vehicle_states',      'uses' => 'Ui\V2\DevicesController@vehicleStates'])->where('id', '[0-9]+');
        Route::post('devices/{id}/vehicle-state', ['as' => 'api.devices.change_vehicle_state', 'uses' => 'Ui\V2\DevicesController@changeVehicleState'])->where('id', '[0-9]+');

        // Sensores del modal device (tab Sensores — CRUD vía SensorModalHelper)
        Route::get('devices/{id}/sensors',       ['as' => 'api.devices.sensors',       'uses' => 'Ui\V2\DevicesController@sensorsList'])->where('id', '[0-9]+');
        Route::get('devices/{id}/sensors/form',  ['as' => 'api.devices.sensors.form',  'uses' => 'Ui\V2\DevicesController@sensorFormCreate'])->where('id', '[0-9]+');
        Route::post('devices/{id}/sensors',      ['as' => 'api.devices.sensors.store', 'uses' => 'Ui\V2\DevicesController@sensorStore'])->where('id', '[0-9]+');
        Route::post('sensors/preview',           ['as' => 'api.sensors.preview',       'uses' => 'Ui\V2\DevicesController@sensorPreview']);
        Route::get('sensors/icons',              ['as' => 'api.sensors.icons',         'uses' => 'Ui\V2\DevicesController@sensorIcons']);

        // Cámaras y gastos del modal device
        Route::get('devices/{id}/cameras',   ['as' => 'api.devices.cameras',        'uses' => 'Ui\V2\DevicesController@camerasList'])->where('id', '[0-9]+');
        Route::post('devices/{id}/cameras',  ['as' => 'api.devices.cameras.store',  'uses' => 'Ui\V2\DevicesController@cameraStore'])->where('id', '[0-9]+');
        Route::put('cameras/{id}',           ['as' => 'api.cameras.update',         'uses' => 'Ui\V2\DevicesController@cameraUpdate'])->where('id', '[0-9]+');
        Route::delete('cameras/{id}',        ['as' => 'api.cameras.destroy',        'uses' => 'Ui\V2\DevicesController@cameraDestroy'])->where('id', '[0-9]+');
        Route::get('devices/{id}/expenses',  ['as' => 'api.devices.expenses',       'uses' => 'Ui\V2\DevicesController@expensesList'])->where('id', '[0-9]+');
        Route::post('devices/{id}/expenses', ['as' => 'api.devices.expenses.store', 'uses' => 'Ui\V2\DevicesController@expenseStore'])->where('id', '[0-9]+');
        // URI propia (devices/expenses/{id}): antes era expenses/{id} y COLISIONABA
        // con las rutas de la página Gastos (ExpensesController) — la última ganaba
        // y la página acababa en el handler con gate de otro módulo.
        Route::put('devices/expenses/{id}',    ['as' => 'api.devices.expenses.update',  'uses' => 'Ui\V2\DevicesController@expenseUpdate'])->where('id', '[0-9]+');
        Route::delete('devices/expenses/{id}', ['as' => 'api.devices.expenses.destroy', 'uses' => 'Ui\V2\DevicesController@expenseDestroy'])->where('id', '[0-9]+');
        Route::get('sensors/{id}/form',          ['as' => 'api.sensors.form',          'uses' => 'Ui\V2\DevicesController@sensorFormEdit'])->where('id', '[0-9]+');
        Route::put('sensors/{id}',               ['as' => 'api.sensors.update',        'uses' => 'Ui\V2\DevicesController@sensorUpdate'])->where('id', '[0-9]+');
        Route::delete('sensors/{id}',            ['as' => 'api.sensors.destroy',       'uses' => 'Ui\V2\DevicesController@sensorDestroy'])->where('id', '[0-9]+');

        // Enviar comando (modal compartido GPRS/SMS + hub schedules/logs/plantillas)
        Route::get('commands/meta',   ['as' => 'api.commands.meta',  'uses' => 'Ui\V2\CommandsController@meta']);
        Route::post('commands/list',  ['as' => 'api.commands.list',  'uses' => 'Ui\V2\CommandsController@commands']);
        Route::post('commands/send',  ['as' => 'api.commands.send',  'uses' => 'Ui\V2\CommandsController@send']);
        Route::get('commands/schedules',         ['as' => 'api.commands.schedules',          'uses' => 'Ui\V2\CommandsController@schedules']);
        Route::post('commands/schedules',        ['as' => 'api.commands.schedules.store',    'uses' => 'Ui\V2\CommandsController@storeSchedule']);
        Route::put('commands/schedules/{id}',    ['as' => 'api.commands.schedules.update',   'uses' => 'Ui\V2\CommandsController@updateSchedule'])->where('id', '[0-9]+');
        Route::delete('commands/schedules/{id}', ['as' => 'api.commands.schedules.destroy',  'uses' => 'Ui\V2\CommandsController@destroySchedule'])->where('id', '[0-9]+');
        Route::get('commands/schedules/{id}/logs', ['as' => 'api.commands.schedules.logs',  'uses' => 'Ui\V2\CommandsController@scheduleLogs'])->where('id', '[0-9]+');
        Route::get('commands/pendings',          ['as' => 'api.commands.pendings',           'uses' => 'Ui\V2\CommandsController@pendings']);
        Route::delete('commands/pendings/{id}',  ['as' => 'api.commands.pendings.destroy',   'uses' => 'Ui\V2\CommandsController@destroyPending'])->where('id', '[0-9]+');
        Route::get('commands/logs',              ['as' => 'api.commands.logs',               'uses' => 'Ui\V2\CommandsController@logs']);
        Route::get('commands/templates',         ['as' => 'api.commands.templates',          'uses' => 'Ui\V2\CommandsController@templates']);
        Route::post('commands/templates',        ['as' => 'api.commands.templates.store',    'uses' => 'Ui\V2\CommandsController@storeTemplate']);
        Route::put('commands/templates/{id}',    ['as' => 'api.commands.templates.update',   'uses' => 'Ui\V2\CommandsController@updateTemplate'])->where('id', '[0-9]+');
        Route::delete('commands/templates/{id}', ['as' => 'api.commands.templates.destroy',  'uses' => 'Ui\V2\CommandsController@destroyTemplate'])->where('id', '[0-9]+');

        // Compartir (links públicos por vehículo — CRUD sobre SharingService legacy)
        Route::get('sharing',         ['as' => 'api.sharing',         'uses' => 'Ui\V2\SharingController@data']);
        Route::get('sharing/meta',    ['as' => 'api.sharing.meta',    'uses' => 'Ui\V2\SharingController@meta']);
        Route::post('sharing',        ['as' => 'api.sharing.store',   'uses' => 'Ui\V2\SharingController@store']);
        Route::put('sharing/{id}',    ['as' => 'api.sharing.update',  'uses' => 'Ui\V2\SharingController@update'])->where('id', '[0-9]+');
        Route::delete('sharing/{id}', ['as' => 'api.sharing.destroy', 'uses' => 'Ui\V2\SharingController@destroy'])->where('id', '[0-9]+');

        // Ajustes de cuenta (modal con tabs; reutiliza validadores/servicios legacy)
        Route::get('account/meta',       ['as' => 'api.account.meta',     'uses' => 'Ui\V2\AccountController@meta']);
        Route::post('account/locale',    ['as' => 'api.account.locale',   'uses' => 'Ui\V2\AccountController@updateLocale']);
        Route::post('account/client',    ['as' => 'api.account.client',   'uses' => 'Ui\V2\AccountController@updateClient']);
        Route::post('account/password',  ['as' => 'api.account.password', 'uses' => 'Ui\V2\AccountController@changePassword']);
        Route::post('account/sms',       ['as' => 'api.account.sms',      'uses' => 'Ui\V2\AccountController@updateSms']);
        Route::post('account/sms/test',  ['as' => 'api.account.sms_test',  'uses' => 'Ui\V2\AccountController@testSms']);
        Route::post('account/sms/queue', ['as' => 'api.account.sms_queue', 'uses' => 'Ui\V2\AccountController@clearSmsQueue']);
        // Categorías de medios (addon media_categories) y credenciales secundarias
        Route::get('account/media-categories',         ['as' => 'api.account.media_categories',        'uses' => 'Ui\V2\AccountController@mediaCategories']);
        Route::post('account/media-categories',        ['as' => 'api.account.media_categories.store',  'uses' => 'Ui\V2\AccountController@storeMediaCategory']);
        Route::put('account/media-categories/{id}',    ['as' => 'api.account.media_categories.update', 'uses' => 'Ui\V2\AccountController@updateMediaCategory'])->where('id', '[0-9]+');
        Route::delete('account/media-categories/{id}', ['as' => 'api.account.media_categories.destroy','uses' => 'Ui\V2\AccountController@destroyMediaCategory'])->where('id', '[0-9]+');
        Route::get('account/secondary',         ['as' => 'api.account.secondary',        'uses' => 'Ui\V2\AccountController@secondaryCredentials']);
        Route::post('account/secondary',        ['as' => 'api.account.secondary.store',  'uses' => 'Ui\V2\AccountController@storeSecondaryCredential']);
        Route::put('account/secondary/{id}',    ['as' => 'api.account.secondary.update', 'uses' => 'Ui\V2\AccountController@updateSecondaryCredential'])->where('id', '[0-9]+');
        Route::delete('account/secondary/{id}', ['as' => 'api.account.secondary.destroy','uses' => 'Ui\V2\AccountController@destroySecondaryCredential'])->where('id', '[0-9]+');
        Route::post('account/widgets',   ['as' => 'api.account.widgets',  'uses' => 'Ui\V2\AccountController@updateWidgets']);
        Route::post('account/dashboard', ['as' => 'api.account.dashboard','uses' => 'Ui\V2\AccountController@updateDashboard']);
        Route::post('account/map',       ['as' => 'api.account.map',      'uses' => 'Ui\V2\AccountController@updateMap']);
        // Confirmación de email (código único, paridad EmailConfirmation legacy)
        Route::post('account/email-confirmation',        ['as' => 'api.account.email_confirm', 'uses' => 'Ui\V2\AccountController@confirmEmail']);
        Route::post('account/email-confirmation/resend', ['as' => 'api.account.email_resend',  'uses' => 'Ui\V2\AccountController@resendEmailCode']);

        // Chat con dispositivos (JSON sobre Chat/ChatService legacy)
        Route::get('chat/conversations', ['as' => 'api.chat.conversations', 'uses' => 'Ui\V2\ChatController@conversations']);
        Route::get('chat/unread',        ['as' => 'api.chat.unread',        'uses' => 'Ui\V2\ChatController@unread']);
        Route::get('chat/open/{deviceId}', ['as' => 'api.chat.open',        'uses' => 'Ui\V2\ChatController@open'])->where('deviceId', '[0-9]+');
        Route::get('chat/{chatId}/messages', ['as' => 'api.chat.messages',  'uses' => 'Ui\V2\ChatController@messages'])->where('chatId', '[0-9]+');
        Route::post('chat/{chatId}/send',  ['as' => 'api.chat.send',        'uses' => 'Ui\V2\ChatController@send'])->where('chatId', '[0-9]+');

        // Acciones de llamada (CRUD sobre CallAction legacy)
        Route::get('call-actions',         ['as' => 'api.call_actions',         'uses' => 'Ui\V2\CallActionsController@data']);
        Route::get('call-actions/meta',    ['as' => 'api.call_actions.meta',    'uses' => 'Ui\V2\CallActionsController@meta']);
        Route::get('call-actions/events',  ['as' => 'api.call_actions.events',  'uses' => 'Ui\V2\CallActionsController@events']);
        Route::post('call-actions',        ['as' => 'api.call_actions.store',   'uses' => 'Ui\V2\CallActionsController@store']);
        Route::put('call-actions/{id}',    ['as' => 'api.call_actions.update',  'uses' => 'Ui\V2\CallActionsController@update'])->where('id', '[0-9]+');
        Route::delete('call-actions/{id}', ['as' => 'api.call_actions.destroy', 'uses' => 'Ui\V2\CallActionsController@destroy'])->where('id', '[0-9]+');

        // Tipos de vehículo (tab del modal de cuenta)
        Route::get('vehicle-types',         ['as' => 'api.vehicle_types',         'uses' => 'Ui\V2\VehicleTypesController@data']);
        Route::post('vehicle-types',        ['as' => 'api.vehicle_types.store',   'uses' => 'Ui\V2\VehicleTypesController@store']);
        Route::put('vehicle-types/{id}',    ['as' => 'api.vehicle_types.update',  'uses' => 'Ui\V2\VehicleTypesController@update'])->where('id', '[0-9]+');
        Route::delete('vehicle-types/{id}', ['as' => 'api.vehicle_types.destroy', 'uses' => 'Ui\V2\VehicleTypesController@destroy'])->where('id', '[0-9]+');

        // Eventos personalizados (tab del modal de cuenta)
        Route::get('custom-events',         ['as' => 'api.custom_events',         'uses' => 'Ui\V2\CustomEventsController@data']);
        Route::get('custom-events/meta',    ['as' => 'api.custom_events.meta',    'uses' => 'Ui\V2\CustomEventsController@meta']);
        Route::post('custom-events',        ['as' => 'api.custom_events.store',   'uses' => 'Ui\V2\CustomEventsController@store']);
        Route::put('custom-events/{id}',    ['as' => 'api.custom_events.update',  'uses' => 'Ui\V2\CustomEventsController@update'])->where('id', '[0-9]+');
        Route::delete('custom-events/{id}', ['as' => 'api.custom_events.destroy', 'uses' => 'Ui\V2\CustomEventsController@destroy'])->where('id', '[0-9]+');

        // Conductores (CRUD sobre UserDriverModalHelper legacy)
        Route::get('drivers',         ['as' => 'api.drivers',         'uses' => 'Ui\V2\DriversController@data']);
        Route::get('drivers/meta',    ['as' => 'api.drivers.meta',    'uses' => 'Ui\V2\DriversController@meta']);
        Route::post('drivers',        ['as' => 'api.drivers.store',   'uses' => 'Ui\V2\DriversController@store']);
        Route::put('drivers/{id}',    ['as' => 'api.drivers.update',  'uses' => 'Ui\V2\DriversController@update'])->where('id', '[0-9]+');
        Route::delete('drivers/{id}', ['as' => 'api.drivers.destroy', 'uses' => 'Ui\V2\DriversController@destroy'])->where('id', '[0-9]+');

        // Tareas (CRUD sobre TaskUserService legacy)
        Route::get('tasks',         ['as' => 'api.tasks',         'uses' => 'Ui\V2\TasksController@data']);
        Route::get('tasks/meta',    ['as' => 'api.tasks.meta',    'uses' => 'Ui\V2\TasksController@meta']);
        Route::post('tasks',        ['as' => 'api.tasks.store',   'uses' => 'Ui\V2\TasksController@store']);
        Route::post('tasks/assign', ['as' => 'api.tasks.assign',  'uses' => 'Ui\V2\TasksController@assign']);
        Route::post('tasks/import/fields', ['as' => 'api.tasks.import_fields', 'uses' => 'Ui\V2\TasksController@importFields']);
        Route::post('tasks/import',        ['as' => 'api.tasks.import',        'uses' => 'Ui\V2\TasksController@import']);
        Route::get('tasks/sets',           ['as' => 'api.tasks.sets',          'uses' => 'Ui\V2\TasksController@taskSets']);
        Route::post('tasks/sets',          ['as' => 'api.tasks.sets.store',    'uses' => 'Ui\V2\TasksController@taskSetStore']);
        Route::put('tasks/sets/{id}',      ['as' => 'api.tasks.sets.update',   'uses' => 'Ui\V2\TasksController@taskSetUpdate'])->where('id', '[0-9]+');
        Route::delete('tasks/sets/{id}',   ['as' => 'api.tasks.sets.destroy',  'uses' => 'Ui\V2\TasksController@taskSetDestroy'])->where('id', '[0-9]+');
        Route::put('tasks/{id}',    ['as' => 'api.tasks.update',  'uses' => 'Ui\V2\TasksController@update'])->where('id', '[0-9]+');
        Route::delete('tasks/{id}', ['as' => 'api.tasks.destroy', 'uses' => 'Ui\V2\TasksController@destroy'])->where('id', '[0-9]+');

        // Servicios / Mantenimiento (modales compartidos + página global)
        Route::get('services',              ['as' => 'api.services',          'uses' => 'Ui\V2\ServicesController@data']);
        Route::get('services/meta',         ['as' => 'api.services.meta',     'uses' => 'Ui\V2\ServicesController@formMeta']);
        Route::get('services/device/{id}',  ['as' => 'api.services.device',   'uses' => 'Ui\V2\ServicesController@deviceServices'])->where('id', '[0-9]+');
        Route::post('services',             ['as' => 'api.services.store',    'uses' => 'Ui\V2\ServicesController@store']);
        Route::put('services/{id}',         ['as' => 'api.services.update',   'uses' => 'Ui\V2\ServicesController@update'])->where('id', '[0-9]+');
        Route::delete('services/{id}',      ['as' => 'api.services.destroy',  'uses' => 'Ui\V2\ServicesController@destroy'])->where('id', '[0-9]+');
    });
});
