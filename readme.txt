=== Sistema Educativo Integral ===
Contributors: cowork
Tags: education, gradebook, students, teachers, school, ecuador
Requires at least: 6.0
Tested up to: 6.5
Requires PHP: 8.2
Stable tag: 1.11.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Gestión académica integral para unidades educativas de Ecuador: calificaciones, tareas, asistencia, comunicados y boletines.

== Description ==

Sistema Educativo Integral es un plugin de WordPress diseñado para unidades educativas en Ecuador. Cubre el flujo completo de gestión académica:

* Calificaciones con el esquema oficial Mineduc: 3 trimestres × 2 parciales (70%) + examen final (30%).
* Tareas, lecciones, pruebas y entregas con archivos adjuntos.
* Comunicados padre-docente con acuse de recibo.
* Asistencia diaria y por materia.
* Boletines PDF generados con mPDF.
* Dashboard institucional para los 4 roles: rector, docente, estudiante, padre.

Soporta los subniveles oficiales del Ministerio de Educación: Inicial, EGB (Preparatoria, Elemental, Media, Superior) y Bachillerato (General y Técnico, con especialidad).

Régimen escolar configurable: Sierra (sep–jul) o Costa (may–feb), 200 días laborables.

== Installation ==

1. Sube la carpeta `sistema-educativo` a `/wp-content/plugins/`.
2. Activa el plugin desde el menú **Plugins** de WordPress.
3. En la activación se crean automáticamente:
   * 28 tablas en la base de datos (prefijo `wp_edu_`).
   * Catálogo Mineduc precargado con 18 materias oficiales.
   * 4 roles personalizados: `edu_rector`, `edu_docente`, `edu_estudiante`, `edu_padre`.

== Frequently Asked Questions ==

= ¿Borra mis datos al desinstalar? =

No por defecto. Para que el plugin elimine sus tablas durante la desinstalación, agrega esta línea en `wp-config.php` antes de desinstalar:

`define( 'EDU_DROP_TABLES_ON_UNINSTALL', true );`

= ¿Soporta régimen costa? =

Sí. Cada institución y cada período académico se configuran como `sierra` o `costa`, ajustando el calendario lectivo a los 200 días laborables correspondientes.

= ¿Qué pasa con materias propias de la institución? =

El catálogo Mineduc se precarga, pero cada institución puede registrar materias propias marcándolas como `is_custom = TRUE` en `wp_edu_subjects`.

== Changelog ==

= 1.11.0 =
* El estudiante entrega una sola vez. Antes podía reenviar y al docente le constaban varias entregas del mismo estudiante.
* El docente califica una sola vez. Para dar otra oportunidad está la recuperación; para corregir un error, devolver el trabajo.
* Nuevo botón "Devolver": la entrega vuelve al estudiante, la nota deja de contar y el parcial se recalcula. Todo queda auditado.
* La grilla de calificaciones bloquea las celdas cuya nota viene de una entrega calificada. Las vacías y las manuales se siguen editando.
* Sin cambios de esquema: no hay migración de base de datos.

= 1.10.1 =
* Corregido `tools/limpiar-notas-duplicadas.php`: conservaba la fila más reciente de cada celda y eso habría borrado notas reales. Ahora borra solo copias exactas y únicamente cuando el promedio de la celda no cambia; las demás se reportan para revisarlas a mano.
* Nueva opción `--detalle` para ver fila a fila las celdas que no se tocan.

= 1.10.0 =
* Entrega de tareas desde la app del estudiante: comentario y archivos, con reemplazo de la entrega anterior.
* Desglose de las notas que forman cada componente, en las notas del estudiante y representante, la grilla del docente y la tabla de cierres del rector.
* Corregido: la grilla de calificaciones acumulaba notas en vez de reemplazarlas. Corregir un 6.00 a 8.00 dejaba al estudiante con 7.00, el promedio de ambas.
* Corregido: el desglose rechazaba al estudiante y al representante en todas sus materias.
* Incluye `tools/limpiar-notas-duplicadas.php` para depurar las notas duplicadas ya registradas.
* Sin cambios de esquema: no hay migración de base de datos.

= 1.9.0 =
* App propia: nuevo shortcode `[edu_app]` que monta una aplicación de Vue 3 sin paso de build, cubriendo los cuatro portales (estudiante, representante, docente y rector).
* API REST `edu/v1` completa sus rutas de escritura: 17 de mutación y 11 de reportes. El namespace queda en 59 rutas.
* Tres servicios nuevos (pagos, reportes y entregas); la capa de servicios llega a 15 clases.
* Los controllers de entregas y pagos pasan a ser adaptadores delgados sobre los servicios. Sin cambios en sus handlers públicos.
* Los cuatro portales shortcode quedan en mantenimiento correctivo: lo nuevo va a la app.
* Sin cambios de esquema: no hay migración de base de datos.
* Actualización aditiva: la app solo aparece si creas una página con `[edu_app]`. Las páginas de portal existentes no cambian.

= 1.0.0 =
* Lanzamiento inicial.
* Fase 0: scaffolding del plugin — esquema de 28 tablas, catálogo Mineduc precargado, 4 roles personalizados, sistema de hooks centralizado.

== Upgrade Notice ==

= 1.11.0 =
Cambia comportamiento: el estudiante ya no puede reenviar una tarea entregada y el docente no puede recalificar una entrega. Avisa a los docentes de que para corregir una nota deben usar el botón "Devolver".

= 1.10.1 =
Corrige el script de limpieza de notas duplicadas, que en su versión anterior podía borrar calificaciones legítimas. Si ya ejecutaste `--aplicar` con la 1.10.0, revisa las notas afectadas.

= 1.10.0 =
Sin migración de base de datos. Corrige un fallo por el que corregir una nota en la grilla la promediaba con la equivocada en vez de reemplazarla. Tras actualizar, ejecuta `php tools/limpiar-notas-duplicadas.php` (simula) para ver si tu base tiene notas duplicadas de antes.

= 1.9.0 =
Actualización aditiva y sin migración de base de datos. Los portales existentes siguen funcionando igual. Para estrenar la app nueva, crea una página con el shortcode `[edu_app]`.
