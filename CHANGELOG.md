# Changelog — PromoSchool

Formato: [Keep a Changelog](https://keepachangelog.com/es-ES/1.1.0/) · Versionado: [SemVer](https://semver.org/lang/es/)

## Convención de tipos

- `Added` — funcionalidad nueva
- `Changed` — cambio en funcionalidad existente
- `Deprecated` — funcionalidad marcada para eliminación
- `Removed` — funcionalidad eliminada
- `Fixed` — bugs corregidos
- `Security` — parche de seguridad
- `DB` — cambio en esquema (indica versión de migración)
- `Compat` — cambio de compatibilidad Flipbook o hooks públicos

---

## [Unreleased]

_(Sin cambios pendientes de tag.)_

---

## [1.11.0] — 2026-08-14

Reglas de integridad sobre entregas y calificaciones. **Cambia comportamiento**:
lo que antes se podía repetir, ahora no.

Principio de fondo: **una nota con respaldo no se sustituye por una sin respaldo.**
La nota que sale de calificar una entrega se apoya en el archivo que subió el
estudiante; una tecleada en la grilla no se apoya en nada.

### Changed
- **El estudiante entrega una sola vez.** Antes podía reenviar mientras la tarea
  no estuviera calificada, y al docente le constaban varias entregas del mismo
  estudiante. Una entrega en estado `returned` **sí** admite reenvío: esa segunda
  entrega la pidió el docente.
- **El docente califica una sola vez.** Recalificar en silencio borraba el vínculo
  con la entrega sin dejar constancia de por qué cambió la nota.
- **La grilla no deja editar celdas con respaldo.** Si la nota de un componente
  salió de una entrega calificada, el campo queda bloqueado con un candado. Las
  celdas vacías y las de notas manuales se siguen editando con normalidad.

### Added
- **Devolver el trabajo** (`PUT /submissions/{id}/return`, `edu/v1` pasa a
  **61 rutas**): única forma de deshacer una calificación. La entrega vuelve a
  `returned`, la nota se borra de `grades_log` para que deje de contar en el
  promedio, el parcial se recalcula y queda auditado. El estudiante puede reenviar.
- `GET /gradebook` devuelve `score_locked` junto a `scores` y `score_counts`.

### Security
- Las tres reglas se aplican **en el servicio**, no solo en la interfaz.

---

## [1.10.1] — 2026-08-14

### Fixed
- **`tools/limpiar-notas-duplicadas.php` habría borrado notas reales.** Conservaba
  "la fila más reciente" de cada celda. En un entorno de prueba todos los duplicados
  eran idénticos y parecía inofensivo, pero en producción apareció el patrón
  «12 filas, 6 valores distintos»: no es un docente corrigiendo doce veces, son seis
  notas legítimas duplicadas. Quedarse con la última habría llevado a un estudiante
  de **6.75 a 0.00**, y a otro de 7.80 a 3.00.

  Ahora borra **solo copias exactas** —una fila de cada valor— y **únicamente cuando
  el promedio de la celda no cambia**. Colapsar `(6,6,7,7)` a `(6,7)` conserva el 6.5;
  a `(7)` no. Toda celda cuyo promedio se movería se omite y se reporta para revisarla
  a mano con el desglose de la app.
- Nuevo `--detalle`: vuelca fila a fila las celdas omitidas, que es lo que permite
  distinguir varias notas legítimas duplicadas de una nota corregida.

---

## [1.10.0] — 2026-08-14

Sin migración de base de datos: `EDU_DB_VERSION` sigue en 1.0.9.

### Added
- **Entrega de tareas en la app del estudiante.** La vista era solo de lectura: el
  endpoint `POST /assignments/{id}/submissions` existía desde la Fase 1 sin que nadie
  lo llamara. Ahora hay bloque "Mi entrega" con comentario y archivos múltiples.
  Reenviar reemplaza la entrega; una entrega ya calificada no admite cambios; entregar
  fuera de plazo se permite avisando que quedará como atrasada. El representante no
  entrega por su hijo.
- **Desglose de las notas que forman cada componente.** La celda de un componente es
  el *promedio* de sus notas y no había forma de ver de qué estaba hecha. Nuevo
  `GET /students/{id}/component-breakdown` (`edu/v1` pasa a **60 rutas**), disponible
  en las tres pantallas: notas del estudiante y representante, grilla del docente y
  tabla de cierres del rector. Las notas que vienen de una tarea muestran su título.
- `GET /gradebook` devuelve `score_counts` junto a `scores`.
- `tools/limpiar-notas-duplicadas.php` — simula por defecto; `--aplicar` para ejecutar.

### Fixed
- **La grilla de calificaciones acumulaba en vez de reemplazar.** Tiene un input por
  componente, pero cada guardado hacía `INSERT`: guardar dos veces duplicaba la nota y
  corregir un 6.00 a 8.00 dejaba al estudiante con **7.00**, el promedio de ambas.
  Ahora se borran las notas manuales previas de la celda antes de insertar. Las notas
  de tareas no se tocan: varias tareas en un componente se siguen promediando.
- El desglose rechazaba al estudiante y al representante con "fuera de tu alcance" en
  todas sus materias, por usar un helper que exige asignación docente.

### Security
- La sustitución de notas manuales se audita (`notas_sustituidas`), por ser una
  eliminación de calificaciones.

### Docs
- `CLAUDE.md` afirmaba que `grades_log` es append-only y que nunca se borran filas. Hay
  dos reemplazos deliberados y acotados; queda descrito.
- `INTEGRATION-FLIPBOOK.md` documentaba 7 funciones públicas y 7 hooks como
  obligatorios. **Ninguno de los 14 existe**; lleva ahora un aviso al inicio.

---

## [1.9.0] — 2026-08-13

Primera versión que incorpora la plataforma propia: la API `edu/v1` completa
sus rutas de escritura y aparece la app propia (SPA) que la consume.

**Despliegue aditivo.** El esquema no cambia (`EDU_DB_VERSION` sigue en 1.0.9,
no hay migración) y la SPA solo se ve si se crea una página con `[edu_app]`.
Los cuatro portales shortcode siguen registrados sirviendo sus mismas URLs, así
que actualizar el plugin no altera nada de lo que ven hoy docentes ni familias.

### Added
- **Fase 2 — App propia.** Shortcode `[edu_app]` (`Edu_Spa`) que monta una SPA
  de Vue 3 **sin paso de build**: 23 módulos JS en `public/spa/`, hoja de
  estilos propia y Vue vendorizado. Cubre los cuatro portales.
  - Familia: inicio, notas, tareas, asistencia, comunicados, pagos, boletines.
  - Docente: inicio, calificaciones, tareas, asistencia, comunicados.
  - Rector: inicio, docentes, cierres, comunicados, pagos, reportes.
  - Se sirve desde el mismo dominio de WordPress y se autentica con cookie +
    `X-WP-Nonce` en vez de Bearer: evita CORS y el problema de la cabecera
    `Authorization` en Apache.
  - El menú respeta `Edu_Modules`: la sección de un módulo apagado no se pinta.
- **Fase 1 — Escritura por API.** 17 rutas REST de mutación
  (`Edu_Api_Write_Routes`) y 11 de reportes (`Edu_Api_Report_Routes`). El
  namespace `edu/v1` queda en **59 rutas**.
- Servicios nuevos: `Edu_Payment_Service`, `Edu_Report_Service`,
  `Edu_Submission_Service`. La capa de servicios llega a **15 clases**.
- `Edu_Attendance_Service` queda enganchada en el bootstrap: existía en el
  árbol pero nunca se había requerido.

### Changed
- `Edu_Submission_Controller` y `Edu_Payment_Controller` pasan a ser
  adaptadores delgados: su lógica se mueve al servicio y quedan como capa de
  nonce + `$_POST` + redirect. **Los handlers públicos no cambian de nombre ni
  de firma**, así que wp-admin y los portales siguen llamando lo mismo.
- Los cuatro portales de `public/shortcodes/` quedan marcados como
  **CONGELADOS** en su cabecera: mantenimiento correctivo, sin funciones
  nuevas. Todo lo nuevo va a la SPA.

### DB
- **Sin migración.** `EDU_DB_VERSION` permanece en 1.0.9.

### Compat
- Ningún hook público cambia. La integración con Flipbook sigue igual: el tab
  "Mis textos" de los portales de docente y estudiante ejecuta
  `do_shortcode('[mis_textos]')` y depende de `shortcode_exists('mis_textos')`.
- **Brecha de paridad conocida:** la SPA todavía **no** cubre el tab "Mis
  textos" ni el tab "materias" del docente. Hay que resolverlo antes de
  retirar el portal del docente.

### Build
- `vendor/` se versiona como archivos reales. El snapshot inicial lo había
  commiteado como 8 gitlinks sin `.gitmodules` (efecto de instalar con
  `--prefer-source`), así que un clon del repo recibía `vendor/mpdf/mpdf`
  vacío y los boletines PDF reventaban con class-not-found.
- Añadidos `.gitignore` (excluye `.claude/`, la caché `tmp/` de mPDF y
  `demo-seed.php`) y `.gitattributes` (protege `.ttf`/`.otf` de la
  normalización de finales de línea, que corrompería las fuentes de mPDF).

---

## [1.4.0] y anteriores — hasta 2026-07

Fases 0 a 7 del plugin, más la revisión de seguridad de julio de 2026. El
detalle histórico por fase está en `docs/BITACORA.md`; este changelog arranca
su registro formal en la 1.9.0.

---

## Cómo añadir entrada al terminar una sesión

En `[Unreleased]`:

```markdown
### Added
- Nueva feature X (#issue).

### Fixed
- Bug donde al hacer A pasaba B (#issue).

### DB
- Migración 004: añadida columna `announcements.priority` (VARCHAR 10).

### Compat
- Añadido helper público `edu_grades_log_insert()` que Flipbook usa. Ver INTEGRATION-FLIPBOOK.md.
```

Al hacer release: mover contenido de `[Unreleased]` a `[X.Y.Z] — fecha` y taggear.
