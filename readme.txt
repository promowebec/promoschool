=== Sistema Educativo Integral ===
Contributors: cowork
Tags: education, gradebook, students, teachers, school, ecuador
Requires at least: 6.0
Tested up to: 6.5
Requires PHP: 8.2
Stable tag: 1.9.0
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

= 1.9.0 =
Actualización aditiva y sin migración de base de datos. Los portales existentes siguen funcionando igual. Para estrenar la app nueva, crea una página con el shortcode `[edu_app]`.
