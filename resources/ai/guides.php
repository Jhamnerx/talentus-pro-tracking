<?php

/*
|--------------------------------------------------------------------------
| Guias de uso de la plataforma para Talentus IA CHAT (UI v2)
|--------------------------------------------------------------------------
| Manual que el asistente usa al explicar como usar TalentusPro en la nueva
| interfaz (UI v2, rutas /ui/v2/*): menu lateral de iconos (rail), barra
| superior (buscador Ctrl+K, herramientas, ajustes, notificaciones, usuario)
| y paginas por modulo.
| Cada guia: titulo, palabras clave (minusculas, sin tildes), pasos, errores
| comunes y pantalla que abre el boton "Ir a..." (clave de AbrirPantallaTool
| o null). Revisar y ajustar con soporte cuando cambie la interfaz.
*/

return [

    'ubicacion' => [
        'titulo'   => 'Ver la ubicacion de una unidad en el mapa',
        'palabras' => 'ubicacion donde esta mapa localizar seguir vehiculo unidad buscar placa en vivo',
        'pasos'    => [
            'Abre Vehiculos en el menu lateral (icono de flecha de navegacion): veras el mapa en vivo con la lista de unidades a la izquierda.',
            'Escribe el nombre, placa o IMEI en el buscador "Buscar nombre, placa, IMEI" de la lista. Tambien puedes usar el buscador de la barra superior (Ctrl+K) desde cualquier pantalla.',
            'Haz clic en la unidad: el mapa se centra en ella y se abre a la derecha su ficha con estado, velocidad, ultima conexion y widgets.',
            'Para que el mapa la siga en vivo, pulsa el boton de tres puntos de la unidad y elige Seguir. El mapa se refresca solo cada pocos segundos.',
            'Con los filtros rapidos de la lista (Todos, Movimiento, Ralenti, Detenido, Desconectado) puedes ver solo las unidades en ese estado.',
        ],
        'errores'  => [
            'Si la unidad no aparece en el mapa, revisa que su casilla en la lista este marcada (la casilla muestra u oculta la unidad en el mapa).',
            'Si aparece como Desconectado, Expirado o Inactivo, preguntame "por que esta offline".',
        ],
        'pantalla' => 'unidades',
    ],

    'mapa_controles' => [
        'titulo'   => 'Controles del mapa en vivo (capas, agrupar, nombres, trafico)',
        'palabras' => 'mapa capa satelite google osm agrupar cluster nombres cola trafico pantalla completa zoom capas mostrar ocultar hitos',
        'pasos'    => [
            'En Vivo (Vehiculos), usa los botones sobre el mapa: Pantalla completa, Cambiar capa de mapa (satelite, calles, etc.), Acercar y Alejar.',
            'Seguimiento automatico de la flota ajusta el mapa para que se vean todas tus unidades; Mi ubicacion centra el mapa en tu posicion.',
            'Con los botones de capas puedes Agrupar vehiculos cercanos, Mostrar vehiculos, Mostrar geocercas, Mostrar rutas, Mostrar POIs, Mostrar hitos KM, Mostrar nombres, Mostrar cola de recorrido y Mostrar trafico en vivo.',
            'El boton Filtro de la lista permite filtrar por estado y por ultima conexion, en la lista o en el mapa.',
            'El boton Agrupar por grupo ordena la lista por grupos de vehiculos.',
        ],
        'errores'  => [
            'Algunas capas de mapa o el trafico solo aparecen si tu cuenta tiene ese proveedor de mapas habilitado.',
        ],
        'pantalla' => 'unidades',
    ],

    'acciones_unidad' => [
        'titulo'   => 'Acciones rapidas de una unidad (menu de tres puntos)',
        'palabras' => 'acciones menu tres puntos editar seguir historial comando compartir reporte de rutas alertas tipos de ruta historial de cambios',
        'pasos'    => [
            'En la lista de Vehiculos, pulsa el boton de tres puntos de la unidad.',
            'Desde ahi puedes: Mostrar historial (ultima hora, hoy o ayer), Reporte de rutas, Enviar comando, Editar, Seguir, Alertas, Tipos de ruta, Compartir e Historial de cambios.',
            'Si la unidad tiene camara veras tambien Video en vivo, Grabaciones de video e Imagenes.',
            'En la ficha de la derecha (al hacer clic en la unidad) tienes Centrar en mapa y atajos de historial: Ultima hora, Hoy y Ayer.',
        ],
        'errores'  => [
            'Las opciones que ves dependen de tus permisos y de lo que soporte el equipo GPS: si falta una, consulta a tu administrador.',
        ],
        'pantalla' => 'unidades',
    ],

    'flota' => [
        'titulo'   => 'Ver y exportar la tabla de flota',
        'palabras' => 'flota tabla lista vehiculos exportar excel columnas filtrar estado grupo modelo protocolo inventario',
        'pasos'    => [
            'Abre la pagina de flota (tambien se llega desde el Tablero con "Ver flota").',
            'Busca por nombre o placa y filtra por Estado, Grupo, Modelo del dispositivo o Protocolo.',
            'Con Columnas eliges que datos ver (placa, modelo, horas de motor, ultimo movimiento, etc.).',
            'Pulsa Exportar para descargar la tabla.',
            'En cada fila puedes ver la unidad en el mapa, abrir su historial de hoy o editarla. Con Agregar das de alta un vehiculo nuevo (si tienes permiso).',
        ],
        'errores'  => [],
        'pantalla' => 'flota',
    ],

    'editar_vehiculo' => [
        'titulo'   => 'Editar los datos de un vehiculo (nombre, icono, sensores, servicios)',
        'palabras' => 'editar vehiculo unidad nombre placa icono sensores configuracion cola exactitud campos personalizados renombrar',
        'pasos'    => [
            'En Vivo (Vehiculos), pulsa el boton de tres puntos de la unidad y elige Editar.',
            'Se abre una ventana con pestanas: Principal (nombre, placa, etc.), Iconos, Avanzado, Sensores, Servicios, Exactitud, Cola y otras segun tus permisos (Usuarios, Video, Gastos, Camaras, Campos personalizados, Historial).',
            'Haz los cambios y pulsa Guardar.',
            'Para dar de alta una unidad nueva usa el boton Nuevo vehiculo de la lista (si tienes permiso).',
        ],
        'errores'  => [
            'Tambien puedo renombrar una unidad por ti: dime cual y el nombre nuevo, y te pedire confirmacion.',
        ],
        'pantalla' => 'unidades',
    ],

    'tipos_vehiculo' => [
        'titulo'   => 'Tipos y estados de vehiculo (cargado, vacio) y limites por geocerca',
        'palabras' => 'tipo de vehiculo estado cargado vacio cisterna clasificacion limite velocidad geocerca cambiar estado',
        'pasos'    => [
            'Crea tus tipos y sus estados en Ajustes de cuenta (icono de engranaje arriba) > pestana Tipos de vehiculo.',
            'Asigna el tipo a cada unidad en Editar > pestana Avanzado.',
            'Para cambiar el estado de una unidad (por ejemplo de cargado a vacio), abre su ficha en Vivo y en Estado del vehiculo pulsa Cambiar.',
            'En el formulario de cada geocerca puedes poner un Limite por tipo de vehiculo (km/h) distinto para cada tipo y estado.',
        ],
        'errores'  => [
            'El tipo y estado de un vehiculo son de tu cuenta: otro usuario que vea el mismo vehiculo puede tenerlo clasificado distinto.',
            'Si un tipo no tiene limite en la geocerca se usa el Limite de velocidad general de la geocerca.',
        ],
        'pantalla' => 'unidades',
    ],

    'historial' => [
        'titulo'   => 'Ver el recorrido (historial) de una unidad',
        'palabras' => 'historial recorrido ruta trayecto donde estuvo reproducir replay paradas descargar kml gpx csv grafico velocidad',
        'pasos'    => [
            'Abre Historial en el menu lateral (o desde la unidad: menu de tres puntos > Mostrar historial).',
            'Elige el Dispositivo y la Fecha: hay atajos (Hoy, Ayer, Esta semana, El mes pasado...) o puedes fijar Desde y Hasta con hora.',
            'En Avanzado puedes ajustar las Paradas minimas, Ajustar a la carretera y Mostrar coordenadas no validas.',
            'Pulsa Mostrar historial: veras el recorrido en el mapa, el resumen (distancia, duracion, velocidad maxima y media) y la linea de tiempo con viajes, paradas y eventos.',
            'Usa Reproducir, Pausa, Detener y Velocidad de reproduccion para verlo paso a paso. Abajo tienes las pestanas Grafico y Registro de datos.',
            'Con el boton Descargar exportas el recorrido en GSR, KML, GPX o CSV.',
        ],
        'errores'  => [
            'Si no aparece nada, revisa el rango de fechas y que la unidad haya reportado ese periodo.',
            'Tambien puedo darte el boton para descargar el recorrido o abrirlo en el mapa: dime la unidad y el periodo.',
        ],
        'pantalla' => 'historial',
    ],

    'reportes' => [
        'titulo'   => 'Generar o programar un reporte',
        'palabras' => 'reporte informe exportar pdf excel xls generar descargar programar enviar correo email diario semanal mensual',
        'pasos'    => [
            'Abre Reportes en el menu lateral: se abre directamente la ventana para crear un reporte.',
            'Elige el Tipo de reporte, el Formato y el Periodo (o las fechas desde y hasta).',
            'Selecciona los Dispositivos y, si el reporte lo pide, Geocercas, Limite de velocidad, Paradas u otros parametros.',
            'Pulsa Generar para obtenerlo ahora.',
            'Para recibirlo por correo, agrega el email en Enviar a email, marca Diario, Semanal o Mensual con su hora y pulsa Guardar.',
            'En la pagina tienes las pestanas Reportes generados (los guardados) y Logs de programados (los enviados).',
        ],
        'errores'  => [
            'Periodos muy largos con muchas unidades tardan mas: divide el periodo si es necesario.',
            'Guardar solo deja el reporte guardado o programado; para descargarlo en el momento usa Generar.',
            'Puedo programarte un reporte: dime cual, para que unidades y cada cuanto, y te pedire confirmacion.',
        ],
        'pantalla' => 'reportes',
    ],

    'alertas' => [
        'titulo'   => 'Crear o editar una alerta',
        'palabras' => 'alerta notificacion aviso avisar crear configurar regla exceso velocidad geocerca sos ralenti motor combustible offline',
        'pasos'    => [
            'Abre Alertas en el menu lateral.',
            'Pulsa Nueva regla. Arriba escribe el Nombre y deja marcada la casilla Activa.',
            'El formulario tiene pestanas: Vehiculos, Tipo, Geocercas, Horario, Notificaciones y Comando (esta ultima solo si tienes permiso para enviar comandos).',
            'En Vehiculos marca las unidades. En Tipo elige el tipo de alerta (exceso de velocidad, exceso en geocerca, entrada o salida de zona, ralenti prolongado, detenido prolongado, offline prolongado, motor encendido/apagado, SOS, combustible, conductor, etc.) y completa sus parametros.',
            'Si el tipo usa zonas, elige las geocercas en la pestana Geocercas. En Horario puedes limitar los dias y horas en que la alerta avisa.',
            'En Notificaciones activa los canales: Notificacion de sonido, Notificacion "pop up" (con opcion de auto-ocultar), Notificacion push de la aplicacion, Notificacion de correo electronico, Notificacion SMS, Notificacion webhook, Reporte por correo, las notificaciones por correo o SMS con enlace para compartir, Color (pinta la unidad en el mapa) e Ignorar notificaciones si se repiten en minutos (anti-repeticion). Solo aparecen los canales habilitados en tu cuenta.',
            'En Comando puedes hacer que se envie un comando GPRS a la unidad cuando la alerta se dispara.',
            'Pulsa Guardar. Desde el menu de cada regla puedes Editar, Activar o Desactivar y Eliminar.',
        ],
        'errores'  => [
            'Si no llegan avisos, revisa que la regla este activa, que incluya la unidad y el horario, y que el canal de notificacion este activado en la pestana Notificaciones.',
            'Para el correo o el SMS, escribe la direccion o el numero en el campo del canal; varios se separan con punto y coma.',
            'Puedo crear una alerta por ti: dime el tipo, las unidades y el limite, y te pedire confirmacion.',
        ],
        'pantalla' => 'alertas',
    ],

    'eventos' => [
        'titulo'   => 'Consultar eventos y alertas recibidas',
        'palabras' => 'eventos alertas recibidas consultar ver lista historial de alertas notificaciones campana mapa de riesgo atender',
        'pasos'    => [
            'Abre Eventos en el menu lateral.',
            'Filtra por Rango de fechas (ultima hora, ultimas 24 horas, ultimos 7 dias o personalizado), Tipo y Dispositivo, o usa Buscar evento.',
            'Haz clic en un evento para verlo en el mapa con su ubicacion.',
            'Puedes Exportar la lista o activar el Mapa de riesgo para ver donde se concentran los eventos.',
            'Los avisos que llegan en el momento aparecen en la campana de Notificaciones de la barra superior.',
        ],
        'errores'  => [
            'Si no aparecen eventos, verifica que haya alertas activas configuradas para esas unidades y que el rango de fechas sea correcto.',
            'Borrar todo elimina todos tus eventos y no se puede deshacer.',
        ],
        'pantalla' => 'eventos',
    ],

    'geocercas' => [
        'titulo'   => 'Crear una geocerca',
        'palabras' => 'geocerca zona perimetro area crear dibujar poligono circulo corredor limite velocidad importar exportar kml',
        'pasos'    => [
            'Abre Geocercas en el menu lateral: se abre el mapa en vivo con el panel de geocercas.',
            'Pulsa Nueva geocerca y elige la forma: Poligono, Circulo o Corredor.',
            'Dibuja en el mapa: en poligono haz clic en cada vertice y cierra en el primer punto (o doble clic / Terminar); en circulo haz clic y arrastra; en corredor marca la linea y termina en el ultimo punto.',
            'Completa Nombre, Color, Grupo y Categoria; opcionalmente el Limite de velocidad (km/h) y el Limite por tipo de vehiculo. Pulsa Guardar.',
            'Para recibir avisos de entrada, salida o exceso de velocidad en la zona, crea una alerta en Alertas.',
            'Con Exportar e Importar del panel puedes mover geocercas en KML, GeoJSON, JSON o GEXP.',
        ],
        'errores'  => [
            'Si la geocerca no detecta entradas, revisa que este incluida en una alerta y con las unidades correctas.',
            'Para cambiar la forma usa Editar forma en el menu de la geocerca y luego Guardar.',
        ],
        'pantalla' => 'geocercas',
    ],

    'rutas' => [
        'titulo'   => 'Crear una ruta',
        'palabras' => 'ruta camino trayecto planificado linea dibujar desvio corredor',
        'pasos'    => [
            'Abre Rutas en el menu lateral: se abre el mapa en vivo con el panel de rutas.',
            'Pulsa Anadir ruta y dibuja la ruta en el mapa: clic para anadir puntos, doble clic para terminar.',
            'Escribe el Titulo, elige el grupo si quieres y guarda.',
            'Para controlar horarios y puntos de paso de la ruta, crea una Programacion de ruta.',
        ],
        'errores'  => [
            'Para que te avise si un vehiculo se sale de un camino, crea una geocerca tipo Corredor sobre el camino y una alerta de Salida de zona.',
        ],
        'pantalla' => 'rutas',
    ],

    'programacion_rutas' => [
        'titulo'   => 'Programar rutas y revisar su cumplimiento',
        'palabras' => 'programacion de ruta horario salida ida vuelta puntos de control cumplimiento retraso adelanto tolerancia vueltas incidencias',
        'pasos'    => [
            'Abre Programaciones de ruta en el menu lateral.',
            'En la pestana Programaciones de ruta pulsa Nueva programacion: escribe el Nombre, elige la Ruta, los Dias, la Salida de ida, la Salida de vuelta (vacia si es solo de ida), la Tolerancia de adelanto y la Tolerancia de retraso en minutos y los Vehiculos. Pulsa Guardar.',
            'Para los puntos de control, en la fila de la programacion pulsa Puntos. Se abre Puntos de control con una seccion Ida y otra Vuelta.',
            'En cada seccion pulsa + Anadir punto, elige la geocerca y escribe Min. (minutos desde la salida en que debe pasar). Antes y Despues son la tolerancia de ese punto; si los dejas en blanco se usa la de la programacion. El vehiculo debe pasar por los puntos en el orden de la lista. Pulsa Guardar.',
            'Los puntos de control son de la ruta: todas las programaciones de esa ruta los comparten.',
            'En la pestana Cumplimiento de programacion de rutas elige el rango de fechas, la programacion y el objeto, y pulsa Generar para ver cada vuelta y su estado.',
            'Marca Solo vueltas con incidencias para ver solo las que llegaron tarde, temprano o se saltaron puntos.',
        ],
        'errores'  => [
            'Antes de programar necesitas la ruta creada y las geocercas de los puntos de control.',
        ],
        'pantalla' => 'programacion_rutas',
    ],

    'pois' => [
        'titulo'   => 'Crear puntos de interes (POI)',
        'palabras' => 'poi punto de interes marcador cliente lugar icono importar exportar',
        'pasos'    => [
            'Abre POI en el menu lateral: se abre el mapa en vivo con el panel de POIs.',
            'Pulsa Nuevo POI y haz clic en el mapa donde va el punto.',
            'Ponle nombre, grupo, elige un icono y guarda.',
            'Con Exportar e Importar del panel puedes mover POIs en CSV, GEXP o KML.',
        ],
        'errores'  => [
            'El POI necesita un icono para poder guardarse.',
        ],
        'pantalla' => 'pois',
    ],

    'comandos' => [
        'titulo'   => 'Enviar un comando a la unidad (cortar motor, reiniciar...)',
        'palabras' => 'comando cortar motor apagar bloquear desbloquear reiniciar enviar gprs sms programar plantilla cola registro',
        'pasos'    => [
            'Abre Enviar comando en el menu lateral (o desde la unidad: menu de tres puntos > Enviar comando).',
            'Pulsa el boton Enviar comando.',
            'Elige la Conexion (GPRS o SMS), los Dispositivos y el Tipo de comando, con sus parametros.',
            'Pulsa Enviar comando y confirma. El resultado aparece en la misma ventana y queda en la pestana Registros.',
            'Si la unidad esta desconectada, marca Enviar automaticamente al conectarse (GPRS): quedara En cola hasta que vuelva a conectarse.',
            'En la pagina tambien tienes Horario (comandos programados), Registros, En cola, Plantillas de GPRS y Plantillas de SMS.',
        ],
        'errores'  => [
            'Corta el motor solo con el vehiculo detenido o a baja velocidad.',
            'Por SMS se necesita tener configurado el SMS gateway y que la unidad tenga numero de SIM.',
            'Puedo preparar el envio de un comando por ti; siempre te pedire confirmacion antes de enviarlo.',
        ],
        'pantalla' => 'enviar_comando',
    ],

    'compartir' => [
        'titulo'   => 'Compartir la ubicacion de una unidad con un enlace',
        'palabras' => 'compartir enlace link ubicacion en vivo cliente tercero publico expira',
        'pasos'    => [
            'Abre Compartir en el menu lateral (o desde la unidad: menu de tres puntos > Compartir).',
            'Pulsa Nuevo enlace.',
            'Ponle un nombre, elige los Vehiculos y en Expira por indica Ninguna, una Duracion o una Fecha. Puedes marcar Eliminar al expirar.',
            'Opcionalmente envia el enlace por correo (o SMS si esta habilitado). Guarda.',
            'El enlace aparece en la tabla: copialo y envialo. Quien lo abra vera la ubicacion sin necesitar usuario.',
        ],
        'errores'  => [
            'Desactiva o elimina el enlace cuando ya no lo necesites: quien lo tenga dejara de ver los vehiculos.',
        ],
        'pantalla' => 'compartir',
    ],

    'medir' => [
        'titulo'   => 'Medir distancias, ver unas coordenadas o buscar una direccion',
        'palabras' => 'regla medir distancia buscar direccion coordenadas punto herramientas',
        'pasos'    => [
            'En el mapa en Vivo pulsa el icono de llave (Herramientas) de la barra superior.',
            'Regla: haz clic en los puntos del mapa para medir la distancia.',
            'Mostrar punto: escribe unas coordenadas para ubicarlas en el mapa.',
            'Buscar Direccion: escribe una direccion para encontrarla en el mapa.',
            'En Historial tambien hay un boton Regla sobre el mapa.',
        ],
        'errores'  => [
            'El menu Herramientas solo aparece en el mapa en Vivo y sus paneles (geocercas, POI).',
        ],
        'pantalla' => 'unidades',
    ],

    'monitor' => [
        'titulo'   => 'Monitorear varias unidades a la vez (monitoreo de grupo)',
        'palabras' => 'monitoreo de grupo monitor cuadricula varios mapas pantallas vigilar varias unidades',
        'pasos'    => [
            'Abre Monitoreo de grupo en el menu lateral.',
            'Elige el Tamano de la cuadricula (cuantos mapas pequenos quieres ver).',
            'En cada celda elige el vehiculo que quieres seguir: se mueve en vivo.',
            'Puedes activar o desactivar Geocercas, POI, Rutas y Cola en los mapas.',
        ],
        'errores'  => [
            'La cuadricula y los vehiculos elegidos se recuerdan en este navegador.',
        ],
        'pantalla' => 'monitor',
    ],

    'dashboard' => [
        'titulo'   => 'Tablero (indicadores de la flota)',
        'palabras' => 'tablero dashboard panel de control indicadores kpi resumen ranking conduccion puntuacion kilometraje',
        'pasos'    => [
            'Abre Tablero en el menu lateral.',
            'Elige el periodo: Hoy, Ayer, Semana o un rango Desde / Hasta, y pulsa Aplicar.',
            'Veras indicadores clave (flota total, en movimiento, detenidos, inactivos, utilizacion), estado de dispositivos, conduccion y comportamiento, alertas por tipo, ranking de conduccion y unidades con mayor kilometraje.',
            'Usa los enlaces Ver flota, Ver tareas y Ver mapa para ir al detalle.',
        ],
        'errores'  => [
            'La puntuacion va de 0 a 100 segun los eventos de riesgo del periodo: mas alto es mejor.',
        ],
        'pantalla' => 'dashboard',
    ],

    'mantenimiento' => [
        'titulo'   => 'Programar mantenimientos (servicios)',
        'palabras' => 'mantenimiento servicio cambio de aceite revision kilometraje horas de motor recordatorio vencido',
        'pasos'    => [
            'Para agregar un servicio, selecciona la unidad en el mapa en vivo. En el widget Servicios de su ficha pulsa Anadir servicio; si ya tiene servicios, pulsa el icono de la cabecera del widget para abrir la lista y alli + Anadir servicio. Tambien se puede desde Editar, en la pestana Servicios.',
            'Completa el formulario: Nombre, Expira por (Odometro, Horas del motor o Dias), Intervalo (cada cuanto se repite), Ultimo servicio (el valor del ultimo mantenimiento) y Generar evento cuando falten (aviso previo). El formulario muestra el Odometro actual o las Horas actuales del motor como referencia.',
            'Marca Renovar servicio al expirar para que se reprograme solo. Opcional: Descripcion y Email para el aviso (varios correos separados por punto y coma). Pulsa Guardar.',
            'Abre Mantenimiento en el menu lateral para ver todos los servicios con lo Restante en distancia, horas de motor y dias.',
            'Desde el menu de cada servicio puedes Editarlo o Eliminarlo.',
        ],
        'errores'  => [
            'Puedo decirte que servicios estan proximos o vencidos: preguntame por el mantenimiento de tu flota.',
        ],
        'pantalla' => 'mantenimiento',
    ],

    'conductores' => [
        'titulo'   => 'Registrar y asignar conductores',
        'palabras' => 'conductor chofer driver asignar rfid ibutton tarjeta dispositivo actual',
        'pasos'    => [
            'Abre Conductores en el menu lateral.',
            'Pulsa Nuevo conductor.',
            'Completa Nombre, Dispositivos asignados y, si quieres, Establecer como actual para fijarlo en un vehiculo. Agrega RFID, Email, Telefono y Descripcion.',
            'Guarda. Puedes filtrar Con dispositivos o Sin dispositivos y usar Editar seleccionados para cambiar varios a la vez.',
        ],
        'errores'  => [
            'Para que el conductor se identifique solo, su RFID debe coincidir con la tarjeta que lee el equipo GPS.',
        ],
        'pantalla' => 'conductores',
    ],

    'gastos' => [
        'titulo'   => 'Registrar gastos de la flota',
        'palabras' => 'gastos combustible peaje repuestos costo registrar factura gasto vehiculo',
        'pasos'    => [
            'Abre Gastos en el menu lateral.',
            'Pulsa Nuevo gasto.',
            'Elige el Vehiculo y completa Nombre, Tipo, Cantidad, Costo unitario y Fecha. El Total se calcula solo.',
            'Guarda. Filtra la tabla por vehiculo o por rango de fechas para revisar los gastos.',
        ],
        'errores'  => [],
        'pantalla' => 'gastos',
    ],

    'tareas' => [
        'titulo'   => 'Crear tareas de recogida y entrega',
        'palabras' => 'tareas recogida entrega pedido delivery factura prioridad firma conjunto de tareas importar',
        'pasos'    => [
            'Abre Tareas en el menu lateral.',
            'Pulsa Nueva tarea.',
            'Elige el Vehiculo, escribe el Titulo, el Numero de factura y la Prioridad.',
            'En Recogida y en Entrega marca la direccion (buscador o clic en el mapa) y el rango de horas Desde / Hasta. Agrega un comentario si quieres y guarda.',
            'Filtra por vehiculo o estado. Tambien puedes Importar tareas o agruparlas en Conjuntos de tareas.',
        ],
        'errores'  => [
            'Recogida y Entrega necesitan direccion y rango de horas: sin ellos la tarea no se guarda.',
        ],
        'pantalla' => 'tareas',
    ],

    'operaciones' => [
        'titulo'   => 'Operaciones: control de vueltas y reporte de turno',
        'palabras' => 'operaciones vueltas ciclos turno cargado vacio carga descarga puntos de operacion paradas motivos horometro abastecimiento',
        'pasos'    => [
            'Abre Operaciones en el menu lateral (solo si tu cuenta tiene el modulo).',
            'En la pestana Operaciones pulsa Nueva operacion: define los puntos (carga, descarga, taller...), los turnos y descansos, y el tipo de vehiculo con sus estados cargado y vacio.',
            'En la pestana En vivo veras el estado de cada vehiculo y sus vueltas del dia.',
            'En la pestana Vueltas buscas por rango de fechas y ves cada vuelta con sus tramos y paradas; puedes Exportar.',
            'En la pestana Turno ves el reporte del turno (graficas por hora, horas y horometro, kilometraje y abastecimientos).',
            'Con Motivos de parada defines las justificaciones y con Recalcular vueltas rehaces el calculo de un periodo.',
        ],
        'errores'  => [
            'Si no aparecen vueltas, revisa que los vehiculos tengan el tipo de vehiculo de la operacion y que pasen por los puntos definidos.',
        ],
        'pantalla' => 'operaciones',
    ],

    'seguridad' => [
        'titulo'   => 'Seguridad: control de flota (velocidad, estados, excesos)',
        'palabras' => 'seguridad control de flota sobrevelocidad excesos turno dia noche stand by resumen diario excel',
        'pasos'    => [
            'Abre Seguridad en el menu lateral.',
            'Filtra por Flota (tipo de vehiculo), Turno (Ambos, Dia o Noche), rango Desde / Hasta y Dispositivo; ajusta la Vigencia (min) y el Limite manual (km/h) si hace falta. Pulsa Buscar.',
            'Veras tarjetas de resumen, el estado actual por unidad, el resumen diario, graficos de velocidad y sobrevelocidad, y el detalle por unidad.',
            'Pulsa Exportar a Excel para descargar el tablero.',
        ],
        'errores'  => [
            'El Limite manual solo aplica fuera de geocercas: dentro manda el limite de la geocerca.',
        ],
        'pantalla' => 'seguridad',
    ],

    'camara' => [
        'titulo'   => 'Ver fotos y videos de las camaras',
        'palabras' => 'camara fotos imagenes medios galeria video en vivo grabaciones dashcam mdvr',
        'pasos'    => [
            'Para fotos e imagenes: pulsa Camara / Medios en el menu lateral, elige el vehiculo y se abre su galeria. Tambien desde el menu de tres puntos de la unidad > Imagenes.',
            'Para video en vivo: en la lista de Vehiculos, menu de tres puntos de la unidad > Video en vivo.',
            'Para videos grabados: menu de tres puntos > Grabaciones de video.',
        ],
        'errores'  => [
            'Las opciones de video solo aparecen si la unidad tiene camara configurada y tienes permiso de video.',
            'El video en vivo necesita que la unidad este conectada.',
        ],
        'pantalla' => null,
    ],

    'chat' => [
        'titulo'   => 'Chatear con un vehiculo',
        'palabras' => 'chat mensaje conductor escribir enviar mensaje vehiculo',
        'pasos'    => [
            'Abre Chat en el menu lateral.',
            'Elige el vehiculo en la lista de la izquierda (puedes buscarlo).',
            'Escribe el mensaje y pulsa Enviar.',
        ],
        'errores'  => [
            'Solo aparecen los vehiculos que admiten chat.',
        ],
        'pantalla' => null,
    ],

    'cuenta' => [
        'titulo'   => 'Cambiar contrasena, idioma, unidades de medida o zona horaria',
        'palabras' => 'contrasena password idioma zona horaria unidades km millas cuenta perfil configuracion ajustes tema oscuro version anterior cerrar sesion',
        'pasos'    => [
            'Pulsa tu avatar (iniciales) arriba a la derecha para abrir el menu de usuario.',
            'Cambiar contrasena cambia tu contrasena; Idioma cambia el idioma de la plataforma.',
            'Ajustes de cuenta (tambien el icono de engranaje) abre pestanas como Principal (unidades de distancia, capacidad y altitud, formatos de fecha y hora, zona horaria), Grupos de vehiculos, Tipos de vehiculo, Widgets, Tablero y Mapa.',
            'El boton de luna/sol de la barra superior alterna el tema claro u oscuro.',
            'Volver a UI clasica (o Version anterior en el menu lateral) abre la interfaz anterior; Cerrar sesion sale de la cuenta.',
        ],
        'errores'  => [],
        'pantalla' => null,
    ],

    'offline' => [
        'titulo'   => 'Mi unidad aparece desconectada o no reporta',
        'palabras' => 'offline no reporta sin senal desconectado no aparece no actualiza gris sin transmitir expirado inactivo',
        'pasos'    => [
            'En Vivo, usa el filtro Desconectado de la lista para ver que unidades no reportan.',
            'En Herramientas (icono de llave de la barra superior) > Unidades sin transmitir veras la lista de unidades que dejaron de reportar.',
            'Revisa si la unidad esta Expirada o Inactiva (con el boton Filtro de la lista, por estado).',
            'Verifica que el vehiculo tenga energia y que el chip tenga saldo y senal.',
            'Si sigue sin reportar, contacta a soporte con el nombre o IMEI de la unidad.',
        ],
        'errores'  => [
            'Puedo diagnosticarla por ti: dime el nombre o la placa de la unidad.',
        ],
        'pantalla' => 'unidades',
    ],

    'asistente' => [
        'titulo'   => 'Que puede hacer el asistente',
        'palabras' => 'ayuda que puedes hacer funciones asistente talentus ia chat ejemplos',
        'pasos'    => [
            'Responder sobre tu flota: excesos de velocidad, paradas, km recorridos, horas de motor, ralenti, combustible, conductores, eventos y alertas en un periodo, y comparar periodos.',
            'Decirte donde esta una unidad, como esta tu flota ahora, que mantenimientos o vencimientos estan proximos y por que una unidad esta desconectada.',
            'Darte botones para ver una unidad en el mapa, descargar su recorrido o un reporte, y llevarte a la pantalla correcta.',
            'Preparar acciones como crear una alerta, programar un reporte, enviar un comando o renombrar una unidad: nunca las ejecuto sin que pulses Confirmar.',
            'Abrirte directamente un formulario o panel: editar un vehiculo, video en vivo y grabaciones, fotos de la camara, compartir la ubicacion, servicios, configurar el equipo, alertas de una unidad, medir distancias, buscar una direccion, unidades sin transmitir, ajustes de cuenta o cambiar la contrasena.',
            'Abrir el chat desde el boton flotante de abajo a la derecha, el icono de la barra superior o Ctrl+I. Con el boton de minimizar queda en el boton flotante y la conversacion sigue al cambiar de pantalla.',
            'Explicarte como usar cada parte de la plataforma.',
            'Ejemplos: "Excesos de 80 km/h de la semana pasada", "Que unidad paro mas ayer", "Donde esta ABC-123".',
        ],
        'errores'  => [],
        'pantalla' => null,
    ],
];
