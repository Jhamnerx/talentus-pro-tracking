Plan de migración — Legacy → TalentusPro
Cobertura actual
Dominio legacy (Frontend) TalentusPro (/talentus/\*) Estado
Login/ · Registration/ · PasswordReminder/ · EmailConfirmation/ · CustomRegistration/ · Captcha/ — ❌ Solo legacy
Layouts/loged.blade.php (live map shell) live ✅ Migrado (con toggles)
Dashboard/ (Blocks + content) dashboard ✅ Migrado
Devices/ (CRUD, edit, beacon, follow_map, positions_backups) fleet (solo lista) ⚠️ Falta edición/creación/destroy
DevicesGroups/ — ❌ Falta
Geofences/ · GeofencesGroups/ zones ✅ Migrado (CRUD + import/export)
Routes · RoutesGroups/ (subsección de zones) ⚠️ Sin CRUD propio
Alerts/ · DeviceAlerts/ · Events/ · CustomEvents/ alerts ✅ Reglas y feed listos. Falta CustomEvents y DeviceAlerts vinculados a vehículo
History/ history ✅ Migrado
Reports/ · Reports/partials · Schedules/ reports ⚠️ Genera reportes, falta Schedules (programación)
MyAccount/ · MyAccountSettings/ · Languages/ — ❌ Falta
Pois/ · PoisGroups/ (toggle en live → toast info) ❌ Falta CRUD y endpoint
Chat/ — ❌ Falta
UserDrivers/ · UserGprsTemplates/ · UserSmsTemplates/ · SmsGateway/ — ❌ Falta
Sensors/ · DeviceConfig/ · LockStatus/ · CallAction/ · SendCommand/ (sólo via legacy modal) ❌ Falta UI nativa
Services/ · DeviceExpenses/ · Maintenance/ · Tasks/ · TaskSets/ · Checklist/ · ChecklistTemplates/ — ❌ Falta toda la línea de mantenimiento
DeviceMedia/ · DeviceVideo/ · Video/ · MediaCategories/ — ❌ Falta
Forwards/ · Sharing/ · SecondaryCredentials/ · Subscriptions/ · DeviceSubscription/ · DevicePlans/ · Beacons/ · Lookup/ · Addresses/ · Docs · IconsModal/ · Popups/ · Objects/ · ObjectsList/ · Widgets/ · Import/ · DeviceRoutesType/ · LoginAs/ · Forwards/ admin (deeplinks a legacy) ⚠️ Sólo enlace legacy, sin UI nativa
Olas de migración (priorizado por impacto / esfuerzo)
Ola 1 — Esenciales para que el agente nunca salga del shell

# Vista nueva Legacy referente Endpoint sugerido Esfuerzo

1.1 fleet → modal edit/create device Devices/edit.blade.php, Devices/create.blade.php talentus.api.devices.{meta,store,update,destroy} M
1.2 fleet → drawer detalle + acceso a sensores / config / iconos Devices/partials/_, Sensors/, DeviceConfig/, IconsModal/ extender api.devices con meta y sensors L
1.3 send command desde drawer de live SendCommand/, CallAction/ talentus.api.commands.{list,send} S
1.4 POIs nativos (CRUD + toggle real en mapa) Pois/, PoisGroups/ talentus.api.pois.{index,store,update,destroy} M
1.5 Routes nativos (subsección de zones o página propia) Routes, RoutesGroups/ talentus.api.routes._ M
Ola 2 — Operación diaria

# Vista nueva Legacy referente Esfuerzo

2.1 DevicesGroups (filtrable desde fleet) DevicesGroups/ S
2.2 Drivers (UserDrivers) — asignación a vehículos UserDrivers/ S
2.3 DeviceAlerts por vehículo (drawer en fleet/live) DeviceAlerts/, partials de Alerts S
2.4 CustomEvents dentro de alerts CustomEvents/ S
2.5 Schedules dentro de reports (programar envío) Schedules/ M
2.6 Sharing (links públicos por vehículo) Sharing/ M
Ola 3 — Mantenimiento y operaciones

# Página nueva Legacy Esfuerzo

3.1 /talentus/maintenance con tabs: Servicios · Gastos · Mantenimientos · Tareas · Checklists Services/, DeviceExpenses/, Maintenance/, Tasks/, TaskSets/, Checklist/, ChecklistTemplates/ L
3.2 /talentus/media (galería de fotos/videos por vehículo) DeviceMedia/, DeviceVideo/, Video/, MediaCategories/ M
3.3 /talentus/chat (mensajería interna) Chat/ M
Ola 4 — Cuenta + integraciones

# Página nueva Legacy Esfuerzo

4.1 /talentus/account (perfil + ajustes + idioma + zona horaria + plantillas SMS/GPRS personales) MyAccount/, MyAccountSettings/, UserGprsTemplates/, UserSmsTemplates/, Languages/ M
4.2 /talentus/admin/forwards nativo (no deeplink) Forwards/ S
4.3 /talentus/admin/sms-gateways nativo SmsGateway/ S
4.4 /talentus/admin/secondary-credentials nativo SecondaryCredentials/ S
4.5 Importar/Exportar masivo (vehículos, POIs, geocercas, drivers) Import/ M
Ola 5 — Suscripciones, billing y onboarding

# Página Legacy Esfuerzo

5.1 /talentus/billing (planes, suscripción, facturación) Subscriptions/, DeviceSubscription/, DevicePlans/ L
5.2 /talentus/objects (gestión catálogo de objetos rastreables / beacons) Objects/, ObjectsList/, Beacons/ M
Ola 6 — Públicas (fuera del shell)

# Página Legacy Esfuerzo

6.1 Login moderno Login/ S
6.2 Registro + custom registration multitenant Registration/, CustomRegistration/ M
6.3 Recuperar contraseña + confirmación de email PasswordReminder/, EmailConfirmation/ S
6.4 Página pública de tracking compartido Sharing/ (vista pública) M
Ola 7 — Páginas residuales / utilidades

# Tarea Legacy Esfuerzo

7.1 Selector de iconos nativo (drawer) IconsModal/ S
7.2 Lookup global de direcciones Addresses/, Lookup/ S
7.3 Documentación API en /talentus/docs Docs S
7.4 LoginAs (suplantación de usuario) LoginAs/ S
7.5 Centro de widgets embebibles Widgets/ M
7.6 Bandeja de popups del operador Popups/ S
Recomendación de orden de ejecución
Empezar por Ola 1 — quita la dependencia visual del legacy en la operación diaria (alta‑media de vehículo, comandos, POIs, rutas).
Ola 2 en paralelo a Ola 1 — completa lo que ya está parcialmente migrado (alerts, reports).
Olas 3–5 según prioridad comercial.
Olas 6–7 al final, requieren branding completo y reemplazan flujos públicos / utilitarios.
Convenciones a respetar en cada migración
Ruta nueva bajo Route::group(['prefix' => 'talentus', 'as' => 'talentus.']) en web.php:1105.
Permisos vía $this->user->perm('<dominio>', '<view|edit>') en TalentusController.php.
API JSON bajo talentus.api.<dominio>.\*; CRUD con meta|store|edit|update|destroy.
Vista en resources/views/talentus/pages/<dominio>.blade.php extendiendo talentus.layout.
CSS por página en public/assets/talentus/css/<dominio>.css, JS en public/assets/talentus/js/<dominio>.js, ambos con ?v=N para cache‑bust.
Modales/drawers vía window.tpModal; toasts vía tpModal.toast.
Reutilizar ModalHelpers/Repositories/Validators existentes — la UI nueva sólo ca
