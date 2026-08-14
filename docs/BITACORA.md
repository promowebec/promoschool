# Bitácora del proyecto · Sistema Educativo Integral

Registro de todo lo construido en el plugin, desde el scaffolding hasta la versión actual.
Documento vivo: agregar una entrada nueva por cada fase o cambio relevante.

- **Plugin:** Sistema Educativo Integral
- **Versión actual:** 1.9.0 (`EDU_VERSION`); esquema en 1.0.9 (`EDU_DB_VERSION`)
- **Stack:** WordPress 6.x · PHP 8.2+ · MySQL 8 / MariaDB 10.6+ · mPDF · sin dependencias JS de build
- **Última actualización de esta bitácora:** 14 de agosto de 2026

---

## 1. Resumen ejecutivo

| Métrica | Valor |
|---|---|
| Tablas propias en base de datos | **30** (prefijo `wp_edu_`) |
| Pantallas del backend WordPress | **25** ítems de menú + 1 subvista (`tareas-detalle`) |
| Portales frontend (shortcodes) | **4** portales (CONGELADOS) + **2** shortcodes sueltos |
| App propia (SPA) | `[edu_app]` · Vue 3 sin build · **los 4 portales** · 23 módulos JS |
| Pestañas dentro de los portales | **30** |
| Roles personalizados | **4** (`edu_rector`, `edu_docente`, `edu_estudiante`, `edu_padre`) |
| Capabilities propias | **17** |
| Controllers (capa de escritura) | **21** clases |
| Servicios (lógica sin HTTP) | **15** clases (`includes/services/`) |
| Clases de la API REST `edu/v1` | **10** (`includes/api/`) · **59** rutas (índice de `/wp-json/edu/v1`) |
| Módulos de dominio | **6** (calificaciones, boletines, pagos, reportes, whatsapp, PWA) |
| Módulos activables/desactivables | **10** desde Ajustes |
| Handlers `admin_post_*` registrados | **60+** |
| Archivos PHP del plugin (sin vendor) | ~80 |

---

## 2. Línea de tiempo por fases

### Fase 0 — Scaffolding, roles y base de datos ✅
Archivo principal `sistema-educativo.php` con constantes (`EDU_VERSION`, `EDU_DB_VERSION`, `EDU_PLUGIN_DIR`, `EDU_PLUGIN_URL`), carga de dependencias y `edu_bootstrap()` colgado de `plugins_loaded`.

Creado:
- `includes/class-edu-loader.php` — registro centralizado de hooks.
- `includes/class-edu-activator.php` — crea las tablas con `dbDelta()`, precarga el catálogo Mineduc, versiona el esquema en `wp_options.edu_db_version` y ejecuta migraciones incrementales.
- `includes/class-edu-deactivator.php`, `includes/class-edu-i18n.php`.
- `includes/class-edu-roles.php` — 4 roles + 17 capabilities, con `ROLES_VERSION` y `maybe_sync_roles()` para re-sincronizar cuando cambian las caps.
- `includes/class-edu-context.php` — institución activa, `Edu_Context::can()`, `is_superadmin_editorial()`.
- `includes/class-edu-audit.php` — escritura en `wp_edu_audit`.
- `uninstall.php` — solo borra tablas si se define `EDU_DROP_TABLES_ON_UNINSTALL`.

Decisiones tomadas:
- `dbDelta()` ignora FOREIGN KEY → la integridad referencial se aplica desde PHP.
- El esquema canónico vive en `docs/sistema_educativo_schema.sql`; el activator lo replica.
- Prefijo `wp_edu_` para tablas, `edu_` para funciones/opciones/hooks, `Edu_` para clases.

### Fase 1 — ABM institucional ✅
Pantallas y controllers para Institución, Períodos lectivos (+ trimestres), Grados y paralelos, Materias.

- `Edu_Institution_Controller`, `Edu_Period_Controller`, `Edu_Grade_Controller`, `Edu_Subject_Controller`.
- Catálogo Mineduc (`wp_edu_subjects_catalog`) con 18 materias oficiales precargadas; cada institución "adopta" materias del catálogo o crea propias (`is_custom = 1`).
- Selector de institución activa (`admin/views/_institution-switcher.php`) para el perfil Superadmin Editorial.
- Importación CSV con plantilla descargable para grados, docentes, estudiantes y representantes (`Edu_Import_Helper`).

### Fase 2 — Calificaciones ✅
El corazón del sistema.

- `Pensum` (`wp_edu_grade_subjects`): qué materias se dictan en cada grado y con cuántas horas/semana.
- `Componentes evaluables` (`wp_edu_grade_components`): filas nombre + peso por (materia, trimestre, parcial).
- `Calificaciones`: matriz estudiantes × componentes; cada celda genera una fila en `wp_edu_grades_log`.
- `Examen final`: captura del examen y del proyecto por (grado, materia, trimestre).
- `Cierres`: cierre de parcial y de trimestre con bloqueo de escritura.
- `Resumen anual`: T1/T2/T3, promedio, estado y captura de supletorio/remedial/gracia.
- `modules/calificaciones/class-edu-grade-calculator.php` — servicio de cálculo puro.

Cadena de recálculo por hooks:

```
edu_grade_logged      → Edu_Grade_Calculator::on_grade_logged      → recalculate_parcial
edu_partial_closed    → Edu_Grade_Calculator::on_partial_closed    → recalculate_trimester
edu_trimester_closed  → Edu_Grade_Calculator::on_trimester_closed  → recalculate_year
```

Regla de cálculo del parcial: `Σ(nota_componente × peso) ÷ Σ(pesos con nota)`.
Los componentes sin nota **se excluyen** (no cuentan como cero) y la suma de pesos se **renormaliza**, por lo que no es obligatorio que los pesos sumen 1.00.

### Fase 3 — Tareas y entregas ✅
- `Edu_Assignment_Task_Controller` (CRUD de tareas + adjuntos) y `Edu_Submission_Controller` (entregas y calificación).
- Estados de tarea: `draft` → `published` → `closed`.
- Archivos en `wp-content/uploads/edu-privado/`, protegidos con `.htaccess`, servidos solo a través del controller con nonce (máx. 10 MB; pdf, doc(x), ppt(x), xls(x), jpg, png, zip).
- **Vínculo tarea → componente:** `wp_edu_assignments.component_id`. Al calificar una entrega, la nota se escribe en `wp_edu_grades_log` bajo ese componente y dispara el recálculo. Esto es lo que hace que una tarea "cuente" para el parcial.
- Sistema de **mejora/recuperación** de tareas (`allow_recovery`, `recovery_due_date`, campos `recovery_*` en submissions).

### Fase 4 — Comunicados ✅
- `Edu_Announcement_Controller` + tablas `announcements`, `announcement_recipients`, `announcement_templates`.
- Acuse de recibo por destinatario (padre/estudiante), con handler `nopriv` para acuse desde email.
- Shortcode suelto `[edu_mis_comunicados]`.

### Fase 5 — Asistencia y dashboard ✅
- `Edu_Attendance_Controller` + tabla `attendance` (diaria y por materia).
- Dashboard de Inicio diferenciado: rector ve métricas institucionales y alertas de asistencia baja; docente ve la asistencia del día y accesos rápidos.
- Portal del rector con Rendimiento, Alertas y carga de notas por docente.

### Fase 6 — PWA ✅
- `includes/class-edu-pwa.php` — manifest dinámico + service worker, inyectados solo en las páginas de portal declaradas en Ajustes.
- Atajos de acceso directo por rol desde el ícono de la app.

### Fase 7 — Integraciones ✅
Especificación en `docs/superpowers/specs/2026-06-12-fase7-integraciones-design.md`.

- **7A · Cuentas** — `Edu_Account_Controller`: suspender/activar usuarios con cascada padre → hijos. Bloqueo de login por `edu_account_status` vía filtro `authenticate` (prioridad 30) + compatibilidad con Ultimate Member (`um_custom_authenticate_error_codes`).
- **7B · Pagos Payphone** — `modules/pagos/`: `Edu_Payphone` (cliente API), `Edu_Payment_Manager` (generación mensual, morosidad, links de pago públicos por token), `Edu_Payment_Controller` (UI + REST webhook + cron diario `edu_payment_daily_cron`). Tablas `payments` y `payment_config`.
- **7C · WhatsApp** — `modules/whatsapp/`: `Edu_Whatsapp` (Twilio o Meta Cloud API) y `Edu_Whatsapp_Notifier` (comunicados, notas de trimestre, pagos vencidos, faltas). Plantillas aprobadas configurables por tipo de mensaje.
- **7D · Exportes Mineduc** — `modules/reportes/`: `Edu_Mineduc_Exporter` (acta consolidada, nómina, distributivo docente, asistencia acumulada) y `Edu_Xlsx_Writer`, escritor .xlsx propio sin dependencias (solo requiere `ext-zip`).

> Decisión de proyecto: **no se agregó PhpSpreadsheet**. Se escribió `Edu_Xlsx_Writer` a mano para no sumar dependencias de Composer.

### Extras posteriores a la Fase 7 ✅

**Fórmula sumativa Media/Superior/Bachillerato.**
Columnas `final_exam_score` y `proyecto_score` en `trimester_scores`.

```
inicial / preparatoria / elemental:
  Nota_Trimestre = ((P1 + P2) / 2) × 0.70 + Examen × 0.30

media / superior / bg / bt (Instructivo 2025):
  Nota_Trimestre = ((P1 + P2) / 2) × 0.70 + ((Examen + Proyecto) / 2) × 0.30
```
Detección: `in_array($sub_level, ['media','superior','bg','bt'])`.

```
Nota_Anual = (T1 + T2 + T3) / 3
≥ 7 → aprobado · 5–6.99 → supletorio · reprueba supletorio → remedial
→ reprueba remedial → gracia · reprueba gracia → reprobado
```

**Equivalencia cualitativa (Instructivo 2025).**
`includes/helpers/class-edu-qualitativa-helper.php`: redondea al entero más cercano (1–10) y devuelve código (A+ … E-) y color. Métodos `::codigo()`, `::color()`, `::badge()`. Aplicada en todas las vistas de nota.

**Componentes propios del docente.**
`grade_components.created_by`: `0` = institucional (definido por rectorado, solo lectura para el docente); `>0` = user_id del docente creador, que puede editarlo y borrarlo.

**Panel de docentes.**
Vista de supervisión para el rector: por asignación académica muestra componentes definidos, tareas creadas, notas registradas, % de avance y última actividad. Con detalle expandible por componente.

**Módulos activables.**
`includes/helpers/class-edu-modules-helper.php`: 10 módulos que se encienden/apagan desde Ajustes. Un módulo apagado desaparece del menú admin, de los tabs de los portales y no registra handlers, hooks ni cron. **Los datos nunca se borran.** Filtro `edu_module_active` para que plugins externos fuercen el estado.

**Hardening (revisión integral, jul 2026 · v1.0.9).**
- Payphone: el pago solo se marca como pagado vía `confirm_and_mark_paid()`.
- `edu-privado` protegido (en Nginx la regla debe agregarse manualmente).
- Gate `edu_db_version`: hay que subir `EDU_DB_VERSION` cada vez que cambia el esquema.

---

## 2.bis · Entradas de bitácora

### 2026-08-14 — Desglose de las notas de cada componente, y el bug que destapó

**Qué se hizo.** La celda de un componente es el **promedio** de sus filas de `grades_log`, y
no había forma de ver de qué estaba hecha: cuando un representante reclamaba una nota, nadie
podía responder sin entrar a la base de datos.

- `Edu_Gradebook_Service::component_breakdown()` — por componente del parcial devuelve su
  promedio, cuántas notas lo forman y el detalle de cada una. Las que vienen de una tarea
  traen su título; las escritas a mano se marcan `manual`. Solo lectura.
- `GET /students/{id}/component-breakdown` — `edu/v1` pasa de 59 a **60 rutas**.
- `/gradebook` agrega `score_counts` junto a `scores`, con un `COUNT(*)` en la misma consulta.
- Interfaz en las **tres** pantallas: notas del estudiante y representante (celdas de P1 y P2),
  grilla del docente ("N notas" bajo cada celda) y tabla de cierres del rector.

**El bug que se me escapó y cómo.** La primera versión usaba `can_view_grade_subject()`, que
exige asignación docente: **al estudiante y al representante los rechazaba siempre** con "Este
recurso está fuera de tu alcance", en todas sus materias. Pasó porque la primera prueba corrió
**solo como administrador**. Ahora hay una matriz de permisos con un usuario real de cada
perfil; administrador, estudiante, representante y docente de la materia pasan, y el docente
ajeno y el representante ajeno reciben `out_of_scope`.

> Lección: en este proyecto el nivel 3 es donde han aparecido casi todos los agujeros. Probar
> un endpoint con un solo perfil no es probarlo.

**El bug de datos que destapó la función.** Con datos reales aparecieron **50 filas
duplicadas** en `grades_log` y **una celda donde una corrección había quedado promediada con
la nota equivocada** (tres valores entre 6.00 y 8.00, promedio 7.00).

Causa: la grilla tiene **un input por componente**, pero `Edu_Score_Service` hacía `INSERT` en
cada guardado, nunca reemplazaba. Guardar dos veces duplicaba; corregir un 6.00 a 8.00 dejaba
al estudiante con 7.00. El camino de las tareas ya lo resolvía bien.

Corregido: la grilla borra las notas **manuales** previas de esa celda antes de insertar. Las
notas de tareas **no se tocan** —varias tareas en un mismo componente se siguen promediando,
que es el modelo—. La sustitución se audita (`notas_sustituidas`) y se devuelve en `replaced`.

**Archivos tocados.** `includes/services/class-edu-gradebook-service.php`,
`includes/services/class-edu-score-service.php`,
`includes/api/routes/class-edu-api-gradebook-routes.php`, `public/spa/js/views/notas.js`,
`public/spa/js/views/docente/calificaciones.js`, `public/spa/js/views/rector/cierres.js`,
`public/spa/css/app.css`, `tools/limpiar-notas-duplicadas.php` (nuevo).

**Cambios de esquema.** Ninguno.

**Riesgos / notas de despliegue.** `tools/limpiar-notas-duplicadas.php` **simula por defecto**;
hay que pasar `--aplicar`. Conserva la nota más reciente de cada celda, recalcula los parciales
afectados y deja constancia en `wp_edu_audit`. Avisa en pantalla de cada celda donde la nota
vigente cambia. **Correr primero la simulación en producción y revisar esas líneas**, porque
son notas de estudiantes reales.

**Documentación corregida.** Dos cosas que decían lo contrario del código:
- `CLAUDE.md` afirmaba que `grades_log` es append-only y que nunca se borran filas. Hay dos
  reemplazos deliberados y acotados; queda descrito.
- `INTEGRATION-FLIPBOOK.md` documentaba 7 funciones públicas y 7 hooks como obligatorios.
  **Ninguno de los 14 existe.** Se le antepuso un aviso: es el diseño previsto, no el estado
  actual.

### 2026-08-14 — Entrega de tareas en la app del estudiante

**Qué se hizo.** La vista de tareas del estudiante en la SPA era **solo de lectura**: listaba
tareas, mostraba el estado y descargaba el material del docente, pero **no tenía forma de
entregar**. No era un bug: la interfaz nunca se construyó. El endpoint
`POST /assignments/{id}/submissions` existía y funcionaba desde la Fase 1, sin nadie que lo
llamara.

Se añadió el bloque "Mi entrega" dentro de la fila desplegada de cada tarea:
- Muestra la entrega existente —estado, fecha, comentario y adjuntos descargables— o avisa de
  que todavía no hay ninguna.
- Formulario con comentario y archivos múltiples, que envía por `eduApi.postForm()`.
- Reenviar reemplaza la entrega anterior; el botón cambia a "Reemplazar entrega".
- Si la entrega ya fue calificada, el formulario desaparece y se explica por qué (el servicio
  devuelve `already_graded` 409).
- Si la tarea está cerrada, tampoco se ofrece.
- Entregar fuera de plazo **sí se permite**, avisando que quedará marcada como atrasada: es el
  servidor quien decide el estado `late`, y es lo que el docente espera ver.
- El **representante no entrega por su hijo**: la vista es compartida entre estudiante y
  padre, y `edu_submit_assignment` es capability solo del estudiante. Se oculta en la interfaz
  además de estar cerrado en el servidor.

**Archivos tocados.** `public/spa/js/views/tareas.js`, `public/spa/css/app.css`.

**Cambios de esquema.** Ninguno.

**Verificación.** Se escribió un arnés que compila **las 17 plantillas** de la SPA con el mismo
`vue.global.prod.js` que se sirve en producción: 17 compilan, 0 errores. Vale la pena anotar
cómo, porque costó tres intentos: el build declara `var Vue` dentro de un IIFE (hay que
evaluarlo y exponerlo en el global, porque el código de render generado resuelve `Vue` ahí), y
Vue decodifica entidades HTML de los atributos usando el DOM real — se dispara con cualquier
`&`, y las plantillas usan `&&` en las expresiones constantemente, así que el stub de
`document` tiene que devolver valores de verdad.

**Riesgos / notas de despliegue.** Solo dos archivos, ambos estáticos. Se suben sobrescribiendo
y el navegador los recoge en la siguiente carga (el import map versiona por `EDU_VERSION`, así
que conviene subir la versión si se quiere forzar el refresco).

### 2026-08-13 — Desplegado en producción y publicado (v1.9.0)

**Qué se hizo.** Se subió la v1.9.0 a producción (`online.giro22.com.ec`) y se publicó el
repositorio: PR #1 mergeado a `main`, tag `v1.9.0` y GitHub Release con el ZIP de distribución
(47.359.273 bytes) como asset.

**La sorpresa: producción venía de la 1.0.0, no de la 1.4.0.** El repositorio no tiene ese
código, así que **no se pudo calcular un parche por diff** y hubo que subir el árbol completo.

**El actualizador de wp-admin no sirve en este servidor.** `Plugins → Subir plugin → Reemplazar`
falla con "algunos archivos no se han podido copiar", listando los `*.pack` de las carpetas
`.git` que hay dentro de `vendor/` —la misma herencia de `composer install --prefer-source` que
había en el repo—. Git los deja en solo lectura y el usuario del servidor web no puede ni
hacerles `chmod`. El fallo es **inocuo**: la comprobación de escritura de
`WP_Upgrader::clear_destination()` corre *antes* de borrar nada, así que el sitio quedó intacto.

**Método que funcionó:** FileZilla en modo **binario** (el modo Auto corrompe los `.ttf` de
mPDF), subiendo todo menos `sistema-educativo.php`, y ese archivo **de último** porque contiene
los `require_once` de los archivos nuevos. **Solo sobrescribir, nunca borrar.** `vendor/` no se
subió: su contenido no cambia entre versiones y se validó generando un boletín.

**Trampa de Windows al preparar el paquete:** descomprimir el ZIP con el Explorador en una ruta
profunda **pierde archivos en silencio** — en una prueba se perdieron 549 de 1241, medio mPDF
incluido, por el límite de 260 caracteres. El ZIP se genera con `git archive` y se extrae en
ruta corta, verificando el conteo.

**Cambios de esquema.** Ninguno que aplicar a mano: `Edu_Activator::maybe_migrate()` corre en
cada carga y se auto-controla por `edu_db_version`, así que el salto 1.0.0 → 1.9.0 aplicó
`dbDelta()` y las migraciones incrementales solo. **No hizo falta desactivar y reactivar.**

**Verificado en producción:** `readme.txt` sirve `Stable tag: 1.9.0`; `public/spa/js/app.js` y
`vue.global.prod.js` responden 200; `/wp-json/edu/v1` responde 200. Confirmado por el usuario:
**pagos, tareas y boletines funcionan**.

**Pendientes que deja abiertos.**
1. **Borrar las 8 carpetas `.git` de `vendor/` en el servidor** (~106 MB). Mientras sigan ahí,
   toda actualización desde wp-admin va a fallar igual.
2. Los 21 escenarios de adaptadores siguen sin correrse como suite. Lo que sí se comprobó:
   los 72 handlers `admin_post_*` resuelven a métodos existentes y los 102 PHP pasan `php -l`.
3. No hay auto-updater: WordPress no ofrece la actualización solo.

### 2026-08-13 — Repositorio publicable y preparación del despliegue (v1.9.0)

**Qué se hizo.** Hasta hoy todo el trabajo de las fases 1 y 2 vivía sin commitear: el
repositorio `promowebec/promoschool` tenía un único commit (`0182f25`, snapshot inicial) con
la versión 1.4.0. Se llevó el árbol a un estado publicable y se dejó listo el despliegue.

**El bug que bloqueaba todo.** El snapshot inicial había commiteado los 8 paquetes de Composer
como **gitlinks (submódulos) sin `.gitmodules`**, porque se instalaron con `--prefer-source` y
cada uno arrastraba su propio `.git`. Efecto real: quien clonara el repositorio recibía
`vendor/mpdf/mpdf` **vacío** y los boletines PDF reventaban con class-not-found. Se eliminaron
los `.git` internos y se versionó el árbol de archivos tal cual corre hoy.

No se reinstaló con Composer a propósito: `composer.lock` está desfasado de `composer.json` y
el PHP del PATH no trae `ext-gd`, así que reinstalar arriesgaba mover la versión de mPDF sin
ninguna necesidad. `vendor/` pasó de 237 MB a 132 MB — los 106 MB de diferencia eran los `.git`
internos.

**Decisión: `vendor/` se versiona.** El plugin se distribuye como ZIP para wp-admin y los
servidores de las instituciones no tienen Composer, así que mPDF tiene que viajar dentro.

**Archivos tocados.**
- `.gitignore` (nuevo) — excluye `.claude/`, la caché `tmp/` de mPDF y `demo-seed.php`.
- `.gitattributes` (nuevo) — marca `.ttf`/`.otf` como binarios; sin esto la normalización de
  finales de línea corrompería las fuentes de mPDF.
- `sistema-educativo.php`, `readme.txt` — versión 1.4.0 → 1.9.0.
- `CHANGELOG.md` — solo tenía la plantilla; se abre el registro formal en la 1.9.0.
- `readme.txt` — el `== Changelog ==` llegaba a 1.0.0 con `Stable tag: 1.9.0`; se agrega la
  entrada 1.9.0 y una sección `== Upgrade Notice ==`.
- `docs/BITACORA.md` §5 — el inventario decía 8 servicios y 36 rutas cuando el §1 ya decía 15 y
  59. Corregido contra el código.

**Cambios de esquema.** Ninguno. `EDU_DB_VERSION` sigue en 1.0.9 y la BD ya está en 1.0.9: no
hay migración pendiente.

**Verificación ejecutada.**
- `vendor/autoload.php` carga; `Mpdf\Mpdf` y el `PdfParser` de fpdi resuelven; una generación
  de PDF de prueba devuelve 28 KB con cabecera `%PDF-`.
- Los 16 archivos PHP tocados pasan `php -l`.
- Cargando WordPress 7.0.4 completo: el plugin arranca **sin fatales**, `rest_get_server()`
  devuelve 60 patrones bajo `/edu/v1` (el índice del namespace + las 59 rutas), los 7
  shortcodes se registran —`edu_app` incluido— y `Edu_Spa`, `Edu_Submission_Service`,
  `Edu_Payment_Service`, `Edu_Report_Service`, `Edu_Attendance_Service`, `Edu_Api_Write_Routes`
  y `Edu_Api_Report_Routes` existen.

**Riesgos / notas de despliegue.**
- **La actualización es aditiva.** Sin migración, y la SPA solo aparece si se crea una página
  con `[edu_app]`. Los cuatro portales shortcode siguen registrados sirviendo sus mismas URLs:
  actualizar el plugin no cambia nada de lo que ven hoy docentes ni familias.
- **Lo que sí toca código vivo** son los refactors de `Edu_Submission_Controller` (−734 líneas)
  y `Edu_Payment_Controller`. Los handlers públicos conservan nombre y firma, pero **falta
  correr los 21 escenarios de adaptadores de wp-admin** comparando URLs de redirección antes de
  subir a producción. Es el pendiente que separa "el código está" de "sé que no rompí las
  entregas ni los pagos".
- **No hay auto-updater.** WordPress no va a ofrecer la actualización solo; hay que subir el
  ZIP por wp-admin o hacer `git pull` en el servidor. Un update checker contra GitHub Releases
  queda como trabajo posterior.
- Recordar la regla de Nginx para `uploads/edu-privado/`, que el `.htaccess` no cubre.

### 2026-08-12 — Fase 2 completa: portal del rector y adjuntos en la app (v1.9.0)

**Qué se hizo.** La app propia cubre ya los **cuatro portales**. Se sumó el del rector
—panel institucional, avance de docentes, cierres, comunicados, pagos y reportes— y se cerró
el circuito de archivos, que era el único trozo del negocio que la API no sabía servir.
El namespace REST suma una ruta (`GET /files/{id}/link`) y queda en **59**, contadas sobre el
índice de `/wp-json/edu/v1`: el "59" de la entrada anterior salía de otro conteo que incluía
la raíz del namespace.

**El rector, en seis pantallas.** Panel con KPIs y rendimiento por grado con su equivalencia
cualitativa; avance por asignación de cada docente; cierres de parcial y trimestre;
comunicados con alcance institucional; pensiones con registro manual, exoneración y enlace de
cobro; y reportes (boletines PDF y los cuatro exportes Mineduc).

En **Cierres**, cada botón pide una segunda confirmación en la propia fila en vez de abrir un
`confirm()` del navegador, porque el cierre no se deshace desde la app. El botón de trimestre
queda deshabilitado mientras los dos parciales sigan abiertos.

**Tres fallos reales que salieron al probar, no al leer.**

1. **Ninguna descarga funcionaba en el navegador.** El enlace firmado se abre con
   `window.open`, y una navegación normal no puede poner la cabecera `X-WP-Nonce`. Sin nonce,
   WordPress descarta la cookie en REST (`rest_cookie_check_errors` hace
   `wp_set_current_user( 0 )`), así que la comprobación de identidad del token fallaba y las
   cinco descargas —boletín y los cuatro exportes— respondían **401**. Se arregla incluyendo
   el nonce dentro de la URL firmada. Se descartó la alternativa de recuperar la identidad
   desde el token: habría convertido el enlace en una credencial portátil, y la garantía de
   que el enlace es personal importa tratándose de datos de menores. Con el nonce dentro, un
   tercero sin la cookie sigue recibiendo 401.

2. **`PUT` con adjuntos llegaba vacío.** PHP solo parsea `multipart/form-data` en POST: editar
   una tarea adjuntando un archivo perdía todos los campos y el servidor respondía "falta el
   título". `eduApi.postForm()` ahora envía siempre POST y pide el método real con
   `X-HTTP-Method-Override`, que WP REST ya interpreta.

3. **Borrar una tarea dejaba filas huérfanas.** El código confiaba en el `ON DELETE CASCADE`
   del esquema, pero `dbDelta()` descarta las FOREIGN KEY: en la base real **no existe ni una**
   (verificado contra `information_schema`). Se borran a mano los hijos en tres sitios:
   tareas (adjuntos, entregas, archivos de entrega, rúbricas), comunicados (destinatarios) y
   grados (pensum). Se limpiaron además **47 filas huérfanas** ya acumuladas: 1 adjunto,
   44 destinatarios de comunicados borrados y 2 filas de pensum de un grado inexistente.

**Descarga de adjuntos, que no existía.** El comentario del servicio decía "las descargas van
por URL firmada (§10)", pero el tipo `attachment` nunca se implementó: la app mostraba el
nombre del archivo y nada más. Ahora `GET /files/{id}/link?type=assignment|submission` emite el
enlace y `Edu_File_Service::locate_attachment()` decide el permiso a partir del padre —la tarea
o la entrega—, no del archivo suelto, y lo revalida al descargar. Las entregas del alumno
incluyen sus archivos (una sola consulta para todas), así que el docente por fin puede abrir lo
que le entregaron.

**Un cambio que se probó y se deshizo.** Se endureció el cierre de trimestre para exigir dos
parciales cerrados por estudiante. La suite de servicios lo rechazó: cerrar un parcial sin
notas es una vía válida y deliberada, y estaba cubierta por una prueba. Se revirtió.

**Archivos nuevos.** `public/spa/js/views/rector/` con `inicio.js`, `docentes.js`,
`cierres.js`, `pagos.js`, `reportes.js` y `comunicados.js`.

**Archivos modificados.** `includes/services/class-edu-file-service.php` (nonce en la URL
firmada, `locate_attachment()`, `attachment_link()`),
`includes/api/routes/class-edu-api-report-routes.php` (ruta del enlace y caso `attachment`),
`includes/services/class-edu-activity-service.php` (archivos de cada entrega),
`includes/services/class-edu-assignment-service.php` y
`includes/services/class-edu-announcement-service.php` (borrado explícito de hijos),
`includes/controllers/class-edu-grade-controller.php` (ídem para el pensum),
`public/spa/js/api.js` (`abrirAdjunto`, override de método), `public/spa/js/app.js`
(rutas del rector), `public/spa/js/views/tareas.js` y `views/docente/tareas.js` (adjuntos),
`public/spa/css/app.css`.

**Cambios de esquema.** Ninguno. `EDU_DB_VERSION` sigue en 1.0.9.

**Verificación.** 261 pruebas automatizadas en verde (JWT 14, API 39, servicios 41,
lecturas 60, escrituras 46, reportes 40, más los 21 escenarios de adaptadores con las mismas
URLs de redirección de wp-admin) y 23 módulos JS sin errores. En Chrome, con un rector y un
docente reales: las seis pantallas del rector renderizan con datos; las cinco descargas
devuelven binarios válidos (`PK` en los .xlsx, `%PDF` en el boletín) y siguen rechazando el
acceso sin cookie (401), con nonce alterado (403) y con firma alterada (403); un adjunto se
sube, se descarga con su contenido exacto, se reemplaza al editar y desaparece al borrar la
tarea, sin dejar filas ni archivos sueltos. La base queda en 14 filas ↔ 14 archivos y **cero
huérfanas** en las nueve comprobaciones de integridad.

**Pendiente.** Retirar los portales shortcode cuando se confirme la paridad en producción.


### 2026-08-12 — Fase 2: portal del docente en la app (v1.8.0)

**Qué se hizo.** La app propia cubre ahora el portal del docente, el más complejo de los
cuatro: inicio, grilla de calificaciones, tareas con calificación de entregas, toma de
asistencia y comunicados. El namespace REST pasa de 56 a **59 rutas**.

**Hueco de la API que salió a la luz.** Al construir la grilla apareció que **las escrituras
de calificaciones nunca se habían expuesto**. Los servicios existían desde la etapa 1b, pero
la 1d expuso solo tareas, asistencia y comunicados, y la 1e pagos y reportes: las de notas se
quedaron sin endpoint. Se añadieron los siete que faltaban:

```
POST /gradebook/scores            POST /components        PUT /components
PUT  /trimester-scores            POST /trimester-scores/close-parcial
POST /trimester-scores/close-trimester                    PUT /grades/{id}/pensum
```

Con esto la API sí cubre los seis dominios completos, lectura y escritura.

**La grilla de calificaciones** respeta las reglas del negocio al pie de la letra: una celda
vacía es *sin calificar*, no cero; solo se envían las celdas que el docente tocó; una celda
inválida no tumba el guardado —el servidor guarda el resto y devuelve el detalle—; y la fila de
un estudiante con el parcial cerrado queda bloqueada. Incluye crear un componente evaluable al
vuelo sin salir de la pantalla.

**Los selectores no cuestan llamadas.** Grado, materia y trimestre salen de `GET /me`, que ya
trae las asignaciones del docente y los trimestres del período activo.

**Cache de módulos, resuelto.** Los módulos ES se importan por URL y esas URLs no llevaban
versión: al actualizar un archivo, el navegador seguía sirviendo el anterior de su caché. Ahora
`Edu_Spa::print_import_map()` genera un *import map* con la fecha de modificación de cada
archivo, y los imports usan especificadores `@edu/...`. Es el equivalente sin compilación al
hash de un bundler. Va en `wp_footer` con prioridad 5, porque `wp_head` ya se imprimió cuando
el shortcode se renderiza.

**Dos correcciones de la primera entrega de la Fase 2.**
1. `GET /me` devuelve el tipo de perfil en inglés (`teacher`), y el mapeo a portal solo
   traducía `student` y `parent`: al docente le salía "esta parte todavía no está en la app".
2. Un enlace directo a una ruta del docente caía en Inicio, porque el hash se leía antes de
   saber qué portal era. Ahora se relee al cargar el perfil.

**Archivos nuevos.** `public/spa/js/views/docente/` con `selector.js`, `inicio.js`,
`calificaciones.js`, `tareas.js`, `asistencia.js` y `comunicados.js`.

**Archivos modificados.** `includes/class-edu-spa.php` (import map),
`includes/api/routes/class-edu-api-write-routes.php` (7 endpoints),
`public/spa/js/app.js` (rutas por portal), `public/spa/js/store.js` (mapeo de perfil),
`public/spa/css/app.css`, `readme.txt`. Todos los módulos JS pasaron a importar con `@edu/`.

**Cambios de esquema.** Ninguno. `EDU_DB_VERSION` sigue en 1.0.9.

**Verificación.** Probado en Chrome con un docente real: se creó un componente evaluable desde
la grilla, se cargó una nota escrita con coma ("8,5") y el servidor devolvió el parcial
calculado 8.50 con su cualitativa A-; se marcó un atraso y la asistencia quedó registrada. Se
comprobó además el rechazo de una nota fuera de rango: el campo se marca en rojo, el servidor
responde "Se guardaron 0 nota(s). 1 celda(s) no se pudieron guardar" y **la nota anterior se
conserva**. 226 pruebas automatizadas en verde y 17 módulos JS sin errores de sintaxis.

**Pendiente.** El portal del rector. Y en la SPA del docente faltan los adjuntos de tarea
(subida de archivos) y los cierres de parcial y trimestre, que ya tienen endpoint.


### 2026-08-12 — Fase 2 arranca: app propia para estudiante y representante (v1.7.0)

**Qué se hizo.** Primera entrega de la aplicación propia, que consume la API `edu/v1`.
Cubre los portales de **estudiante** y **representante**, los dos más simples y los que casi
solo consultan datos.

**Decisiones de arranque.**
- **Vue 3 sin compilación.** Se empaqueta `vue.global.prod.js` (154 KB) en el plugin y los
  componentes son objetos JS planos con la plantilla en una cadena. **No hay npm, ni Vite, ni
  paso de build**: desplegar es copiar archivos, igual que el resto del plugin. Los módulos ES
  nativos (`type="module"`) dan `import`/`export` sin compilar nada.
- **Mismo dominio que WordPress**, así que la app se autentica con la cookie de sesión más
  `X-WP-Nonce`. Sin CORS y sin guardar tokens en el navegador. El token Bearer de la API sigue
  disponible para una app instalada o integraciones.
- **Se reutiliza `public/css/portales.css` tal cual**: mismo lenguaje visual que los portales
  que va a reemplazar. `spa/css/app.css` solo añade lo que faltaba (estados de carga, chips,
  badges, listas).

**Cómo se monta.** Shortcode `[edu_app]` en cualquier página. `Edu_Spa` encola los assets,
pasa los datos mínimos de arranque y renderiza `<div id="edu-app">`. Si el visitante no tiene
sesión, muestra un aviso con enlace al login del sitio (respeta Ultimate Member).

**Estructura.**

```
public/spa/
├── vendor/vue.global.prod.js
├── css/app.css
└── js/
    ├── api.js          cliente REST con nonce y errores normalizados
    ├── store.js        estado compartido y formateadores
    ├── components.js   piezas reutilizables (tarjeta, métrica, nota, badge)
    ├── app.js          layout, menú, router por hash y arranque
    └── views/          inicio · notas · tareas · asistencia · comunicados · pagos · boletines
```

Las vistas de notas, tareas y asistencia **sirven a los dos portales**: lo único que cambia es
qué `student_id` hay en el store. El representante con más de un hijo ve un selector que
recarga todas las vistas al cambiar.

**Portales congelados.** Los 4 shortcodes pasan a mantenimiento correctivo: se corrigen
errores, no se agregan funciones. Llevan un aviso en la cabecera del archivo. Motivo: mantener
dos interfaces vivas obliga a construir y depurar cada pantalla dos veces, y mientras se les
sigan añadiendo funciones la paridad nunca llega. **No afecta a wp-admin.**

**Cambios de esquema.** Ninguno. `EDU_DB_VERSION` sigue en 1.0.9.

**Verificación.** Probada en Chrome contra el sitio real, con una institución de demostración
y dos sesiones (estudiante y representante):
- El promedio, la asistencia y las cualitativas coinciden con los datos cargados (8.68 · 90.91%
  · A-/B+), y la fórmula sumativa se detecta por el subnivel del grado.
- El acuse de recibo de un comunicado funciona de punta a punta: el contador baja y queda
  registrada la fecha de confirmación.
- El selector de hijo recarga todas las vistas (al pasar a un hijo con 6.30 aparece C+ naranja
  y la asistencia queda vacía, que es lo correcto).
- Los menús de Pagos y Boletines solo aparecen para el representante; las secciones de módulos
  apagados no se muestran.
- Sin errores en la consola del navegador. Sintaxis de los 11 módulos JS verificada con Node.

**Pendiente conocido.** El ancho de la app lo limita la plantilla del tema; conviene usar una
plantilla a ancho completo en la página que la aloje. Faltan los portales de docente y rector.


### 2026-08-12 — API `edu/v1` etapa 1e: pagos, reportes y dashboards (v1.6.0) · **Fase 1 completa**

**Qué se hizo.** Los últimos 12 endpoints. El namespace queda en **56 rutas** y la API cubre
los seis dominios: calificaciones, tareas, asistencia, comunicados, pagos y boletines/reportes.
Con esto el frontend propio (Fase 2) puede construirse entero sin ninguna pantalla de WordPress.

| Servicio nuevo | Contenido |
|---|---|
| `Edu_Payment_Service` | configuración, generación de cuotas, pago manual, exoneración, links de pago y suspensión de morosos |
| `Edu_Report_Service` | dashboard del rector, del docente, panel de docentes y autorización de binarios |

```
GET/PUT /payment-config          POST /payments/generate-monthly
POST    /payments/{id}/manual    POST /payments/{id}/waive
POST    /payments/{id}/link      POST /payments/suspend-overdue
GET     /reports/boletin         GET  /reports/mineduc/{tipo}
GET     /dashboard/rector        GET  /dashboard/docente
GET     /dashboard/teacher-panel GET  /files/download
```

`GET /dashboard/teacher-panel` cierra la **deuda técnica #3**: el panel de docentes existía
solo en wp-admin y ahora la app lo tiene igual.

**Descargas firmadas.** Los reportes no devuelven el binario: validan permiso y responden
`{url, expires_at}` con un token HMAC de 5 minutos atado al usuario. El navegador abre esa URL
—donde no puede mandar la cabecera `Authorization`— y `/files/download` verifica firma,
vencimiento y titular, **revalida el permiso** y recién entonces sirve el PDF o el .xlsx con
los generadores de siempre (mPDF y `Edu_Xlsx_Writer`). Compartir el enlace no sirve: emitido
para otra cuenta responde 403.

**Lo que NO se tocó, a propósito.** El circuito de Payphone (inicio, retorno del navegador y
webhook) sigue en el controller: son redirecciones y llamadas de la pasarela. La invariante del
hardening v1.0.9 sigue intacta: **un pago solo se marca pagado desde
`confirm_and_mark_paid()`**, con confirmación server-side.

**Hardening.** `waive`, `register_manual` y `generate_link` solo miraban la capability: con el
ID bastaba para exonerar o marcar pagada una cuota **de otra institución**. Ahora todas validan
pertenencia. `suspend_overdue` tampoco validaba el período ni acotaba por institución: podía
suspender cuentas ajenas. Corregido. Y se añadió auditoría a exonerar, registrar pago manual y
suspender morosos, que antes no dejaban rastro — algo llamativo tratándose de dinero.

**Cambios de esquema.** Ninguno. `EDU_DB_VERSION` sigue en 1.0.9 desde hace seis versiones.

**Verificación.** 40 pruebas por HTTP real: los tres dashboards con cifras comprobadas
(promedio 8.40 con su cualitativa B+, alerta de asistencia al 25%), el ciclo completo de pagos
y las URLs firmadas, incluido el rechazo de un token emitido para otra cuenta y de uno
manipulado. Total acumulado de la Fase 1: **253 pruebas en verde** (14 JWT + 39 API + 41
servicios + 60 lecturas + 46 escrituras + 40 reportes + 13 adaptadores de wp-admin).


### 2026-08-12 — API `edu/v1` etapa 1d: escrituras de tareas, asistencia y comunicados (v1.5.0)

**Qué se hizo.** Los cuatro dominios que quedaban salieron de sus controllers, y con ellos
llegaron **15 endpoints de escritura**. El namespace pasa de 36 a **44 rutas**. Con esto la
app puede operar el día a día del docente y del estudiante, no solo consultarlo.

| Servicio nuevo | Contenido |
|---|---|
| `Edu_File_Service` | Almacenamiento privado compartido por tareas y entregas: tamaño y extensiones permitidas, `ensure_protected_dir()`, `store_uploads()`, `url_to_path()`, `delete_physical()`, `download_url()`, `stream()` |
| `Edu_Assignment_Service` | `save()`, `publish()`, `close()`, `delete()`, adjuntos, `derive_type()`, `load_for_manage()`, `can_access()` |
| `Edu_Submission_Service` | `submit()`, `grade()`, `save_recovery_settings()`, `submit_recovery()`, `grade_recovery()`, `can_download()` |
| `Edu_Attendance_Service` | `save()`, `flatten_matrix()` |
| `Edu_Announcement_Service` | `send()`, `acknowledge()`, `delete()` |

Los cuatro controllers correspondientes quedaron como adaptadores. Se conservan como
envoltorios delegantes `Edu_Assignment_Task_Controller::ensure_protected_dir()`,
`::get_download_url()`, `::derive_type()`, `::handle_file_uploads()` y sus constantes, porque
los usan el activator, las vistas y los portales.

**Endpoints nuevos.**

```
POST   /assignments                      PUT/PATCH /assignments/{id}
DELETE /assignments/{id}                 POST      /assignments/{id}/publish
POST   /assignments/{id}/close           PUT       /assignments/{id}/recovery-settings
POST   /assignments/{id}/submissions     POST      /assignments/{id}/recovery
PUT    /submissions/{id}/grade           PUT       /submissions/{id}/recovery-grade
PUT    /attendance
POST   /announcements                    DELETE    /announcements/{id}
POST   /announcements/{id}/acknowledge
```

**Hardening.** Tres huecos que la extraccion dejo a la vista:

1. **Borrado de comunicados sin dueno.** `handle_delete()` solo miraba la capability:
   cualquiera con `edu_grade_students` podia borrar el comunicado de otro sabiendo el ID.
   Ahora quien no ve toda la institucion solo borra lo que el envio, y el rector solo dentro
   de su institucion.
2. **Acuse de recibo sin verificar destinatario.** Se podia marcar como leido un comunicado
   ajeno. Ahora se exige ser destinatario.
3. **Calificacion de entregas ajenas.** Calificar y configurar la mejora no comprobaban la
   materia: un docente podia calificar entregas de una materia que no dicta. Ahora pasan por
   `can_view_grade_subject()`.

**Correccion menor.** `handle_save_recovery_settings()` pasaba `'NULL'` como formato de
`$wpdb->update()` (los validos son `%s`, `%d`, `%f`). Ahora usa `%s`.

**Cambios de esquema.** Ninguno. `EDU_DB_VERSION` sigue en 1.0.9.

**Verificacion.** 46 pruebas por HTTP real que recorren el circuito completo: crear tarea con
componente al vuelo (el tipo se deduce solo: "Lecciones" da `leccion`), publicar, entregar,
calificar 16/20 con normalizacion a 8.00, comprobar que la nota aparece en el gradebook,
recalificar sin duplicar filas, cerrar, habilitar la mejora, entregarla y calificarla
conservando la MEJOR de las dos notas. Mas asistencia (incluido que guardar el dia sin
novedades marca presente), comunicados con destinatarios, bandeja y acuse de recibo, y todos
los accesos cruzados que deben fallar. Y 13 pruebas de adaptadores que confirman que las nueve
pantallas de wp-admin siguen redirigiendo exactamente igual. Las suites anteriores siguen en
verde: 14 del JWT, 39 de la 1a, 41 de la 1b y 60 de la 1c.


### 2026-08-11 — API `edu/v1` etapa 1c: endpoints de lectura (v1.4.0)

**Qué se hizo.** 27 endpoints `GET` que cubren los seis dominios. El namespace `edu/v1` pasa
de 9 a **36 rutas**. Con esto la app de la Fase 2 se puede construir entera sin exponer
todavía ninguna escritura.

| Servicio de lectura nuevo | Cubre |
|---|---|
| `Edu_Catalog_Service` | instituciones, períodos, trimestres, grados, catálogo Mineduc, materias, pensum |
| `Edu_People_Service` | docentes, estudiantes (paginado), representantes, asignaciones |
| `Edu_Gradebook_Service` | componentes, gradebook, notas de trimestre, resumen anual, boleta |
| `Edu_Activity_Service` | tareas, entregas, asistencia, comunicados, bandeja propia, pagos, auditoría |

Rutas nuevas en `includes/api/routes/`: `catalog`, `gradebook`, `activity`.

**Alcance personal en todas las lecturas.** Helpers nuevos en `Edu_Service`: `identity()`,
`own_children_ids()`, `own_grade_ids()`, `teacher_has_assignment()`, `can_view_student()`,
`can_view_grade_subject()`. El docente solo ve sus grados, materias, estudiantes y
asignaciones; el representante solo a sus hijos; el estudiante solo a sí mismo. Cada regla
tiene una prueba que intenta el acceso cruzado y exige un 403.

**`GET /gradebook`** cumple el §8.1 del contrato: `scores` como mapa `component_id → nota`,
`null` para "sin calificar" (que no es cero), promedio y no última nota, `computed_score` y
`cualitativa` resueltos en el servidor, y `context.formula` derivada del subnivel.

**Corrección de seguridad hallada probando.** Sin token, `/gradebook` respondía **400** en vez
de 401: WordPress valida los parámetros obligatorios **antes** del `permission_callback`, así
que la petición anónima moría en la validación y revelaba qué parámetros espera la ruta. Se
añadió un corte en `rest_authentication_errors` que exige sesión en las rutas privadas de
`edu/v1` antes del dispatch, exceptuando `/auth/token`, `/auth/refresh`, `/payphone/webhook`,
el índice del namespace y el preflight `OPTIONS`.

**Dos bugs preexistentes más del `institution_id` inexistente**, en
`public/shortcodes/class-edu-shortcode-rector.php` (el portal del rector, no el wp-admin):

1. El KPI "Docentes" usaba `wp_edu_teachers WHERE institution_id` — columna que no existe.
   Mostraba 0 y escribía un error de base de datos en cada carga. Es el mismo bug corregido
   en `admin/views/inicio.php` en la v1.2.0.
2. Peor: la consulta de **comunicados** tenía la misma referencia dentro de un `EXISTS`, lo que
   hacía fallar **toda** la consulta. El listado de comunicados del portal del rector salía
   siempre vacío. El "fix con condición triple" registrado en mayo nunca llegó a funcionar por
   esto. Ahora el vínculo docente–institución se resuelve por sus asignaciones y por
   `usermeta.edu_institution_id`.

**Archivos nuevos.** `includes/services/class-edu-catalog-service.php`,
`class-edu-people-service.php`, `class-edu-gradebook-service.php`,
`class-edu-activity-service.php`; `includes/api/routes/class-edu-api-catalog-routes.php`,
`class-edu-api-gradebook-routes.php`, `class-edu-api-activity-routes.php`.

**Archivos modificados.** `includes/services/class-edu-service.php` (helpers de alcance),
`includes/api/class-edu-api.php` (`from_service()`, `from_service_collection()`, registro de
rutas), `includes/api/class-edu-api-auth.php` (corte de 401),
`public/shortcodes/class-edu-shortcode-rector.php` (los dos bugs), `sistema-educativo.php`
(requires + v1.4.0), `readme.txt`.

**Cambios de esquema.** Ninguno. `EDU_DB_VERSION` sigue en 1.0.9.

**Verificación.** 60 pruebas por HTTP real con cuatro sesiones simultáneas (rector, docente,
representante y estudiante) sobre una institución de prueba con dos grados, dos materias, tres
estudiantes, notas cargadas y un docente asignado a una sola materia. Cubren el contenido del
gradebook, la paginación con `X-WP-Total`, el gate de módulos encendido y apagado, la
validación de fechas, el 401 sin sesión y los accesos cruzados que deben fallar. Las suites
anteriores siguen en verde: 39 de la 1a, 41 de la 1b, 14 del JWT y los 10 adaptadores de
wp-admin con sus URLs intactas.

### 2026-08-11 — API `edu/v1` etapa 1b: capa de servicios de calificaciones (v1.3.0)

**Qué se hizo.** El dominio de calificaciones dejó de vivir dentro de los controllers. Ahora
la lógica está en `includes/services/` y los controllers son adaptadores de transporte: nonce,
traducción de `$_POST` y redirección. Es el patrón que permite que la app y el wp-admin
compartan una sola implementación de cada regla.

| Servicio | Métodos |
|---|---|
| `Edu_Service` (base) | `error()`, `require_cap()`, `require_institution()`, `validate_parcial()`, `check_scope()`, `active_student_ids()`, `uses_sumativa()`, `formula()`, `parse_score()` |
| `Edu_Score_Service` | `save_batch()`, `flatten_matrix()` |
| `Edu_Trimester_Score_Service` | `save_exam()`, `close_parcial()`, `close_trimester()` |
| `Edu_Curriculum_Service` | `save_pensum()`, `save_components()`, `resolve_or_create_component()`, `puede_crear_componente()` |

Un servicio nunca lee `$_POST`, no imprime, no redirige y no llama a `wp_die()`: recibe un
array ya saneado y devuelve un array o un `WP_Error`. Los códigos de error son los mismos
strings que ya usaban las vistas (`invalid_scope`, `no_components`, `invalid_parcial`,
`invalid_subject`, `invalid_trimester`, `no_students`, `parcials_open`, `invalid_grade`), así
que ningún mensaje de admin cambió. La capa REST les antepondrá `edu_`.

**Hardening que salió a la luz al extraer.** Dos huecos previos, ninguno con efecto en el uso
normal de las pantallas:

1. **Notas en un parcial cerrado.** La pantalla deshabilita las casillas de los estudiantes con
   el parcial cerrado, pero el servidor no lo comprobaba: un POST fabricado insertaba filas en
   `grades_log`. No alteraban la nota (el calculador se niega a recalcular un parcial cerrado),
   pero ensuciaban el log y habrían contado si el parcial se reabría. Ahora se rechazan y se
   reportan como `partial_closed`.
2. **Cierres sin validar institución.** `close_parcial()` y `close_trimester()` solo miraban la
   capability: un rector podía cerrar un parcial de otra institución con un POST fabricado.
   Ahora pasan por `check_scope()`. Se agregó el mensaje de `invalid_scope` a la vista Cierres.

**Corrección a la especificación.** El contrato decía que un parcial cerrado devolvería 409
para todo el lote. Es incorrecto: `parcial_scores.is_closed` es **por estudiante**. El servicio
salta a los cerrados y guarda los demás. Corregido el §8.2 del contrato.

**Archivos nuevos.** `includes/services/class-edu-service.php`,
`class-edu-score-service.php`, `class-edu-trimester-score-service.php`,
`class-edu-curriculum-service.php`.

**Archivos modificados.** Los tres controllers (`score`, `trimester-score`, `curriculum`)
reescritos como adaptadores; `sistema-educativo.php` (requires + v1.3.0);
`admin/views/cierres.php` (mensaje de `invalid_scope`); `readme.txt`.
`Edu_Curriculum_Controller::resolve_or_create_component()` queda como envoltorio delegante:
lo llama `Edu_Assignment_Task_Controller` y lo llamarán las integraciones externas.

**Cambios de esquema.** Ninguno. `EDU_DB_VERSION` sigue en 1.0.9.

**Verificación.** 41 pruebas funcionales de los servicios sobre una institución de prueba
creada y borrada por la propia suite: renormalización de pesos (un estudiante con un solo
componente calificado saca 7.00, no 4.67), promedio de varias notas en el mismo componente,
fórmula sumativa con proyecto, conservación del examen al enviar solo el proyecto, los dos
cierres y su orden, protección de componentes con notas, aislamiento entre instituciones y
permisos por rol. Más 10 pruebas de los adaptadores que ejecutan cada handler con nonce y
`$_POST` reales y comparan la URL de redirección con la que esperaba cada vista: las diez
coinciden exactamente con el comportamiento anterior. Las 39 pruebas de la etapa 1a siguen en
verde y no aparecieron errores nuevos en el log.

### 2026-08-11 — API `edu/v1` etapa 1a: puerta de entrada (v1.2.0)

**Qué se hizo.** Primera implementación del contrato: la infraestructura de la API y el
flujo completo de autenticación. Con esto una app externa ya puede iniciar sesión y
preguntar quién es; falta exponer los dominios (notas, tareas, etc.).

Endpoints vivos: `POST /auth/token`, `POST /auth/refresh`, `POST /auth/revoke`,
`GET /me`, `PUT /me/password`, `GET /me/sessions`.

- **JWT propio HS256** escrito a mano con `hash_hmac()` — sin dependencias de Composer.
  Access de 15 min; rechaza `alg=none`, firma alterada, emisor ajeno y token vencido.
- **Refresh rotatorio** de 30 días, guardado **hasheado** (SHA-256) en
  `usermeta.edu_refresh_tokens` con etiqueta de dispositivo; máximo 10 dispositivos. Al
  refrescar se invalida el anterior: reutilizarlo devuelve 401.
- **Revocación inmediata** vía `usermeta.edu_token_version`, que va dentro del token. Se
  sube al suspender la cuenta, al cambiar la contraseña y con `revoke?all=true`.
- **Rate limit** de 5 intentos fallidos por (usuario + IP) cada 15 min → 429 con `Retry-After`.
- **`GET /me`** devuelve en una sola llamada: perfil, roles, capabilities, institución,
  período activo con sus trimestres, mapa de módulos activos y el bloque que corresponda
  (asignaciones del docente, grado del estudiante, hijos del representante).
- El login pasa por `wp_authenticate()`, así que sigue corriendo el bloqueo de cuentas
  suspendidas y la compatibilidad con Ultimate Member.

**Hallazgo de seguridad durante las pruebas.** `rest_send_cors_headers()` del core de
WordPress refleja `Access-Control-Allow-Origin` para **cualquier** origen, y con
`Allow-Credentials: true`. Añadir las cabeceras propias no basta: hay que **retirar** las del
core con `header_remove()` cuando el origen no está en la lista blanca. Sin eso, la lista
blanca no servía de nada. Corregido y cubierto con una prueba de origen hostil.

**Archivos nuevos.** `includes/api/class-edu-api-jwt.php`, `includes/api/class-edu-api-auth.php`,
`includes/api/class-edu-api.php`, `includes/api/routes/class-edu-api-auth-routes.php`,
`includes/api/routes/class-edu-api-me-routes.php`.

**Archivos modificados.** `sistema-educativo.php` (requires + `Edu_Api::register()`, v1.2.0),
`includes/class-edu-context.php` (nuevo `override_institution_id()`, solo en memoria),
`includes/class-edu-activator.php` (genera `edu_api_secret`),
`includes/controllers/class-edu-account-controller.php` (nuevo hook `edu_account_suspended`),
`includes/controllers/class-edu-settings-controller.php` y `admin/views/ajustes.php`
(sección "API REST": dominios autorizados y casilla para cerrar todas las sesiones),
`readme.txt`.

**Bug preexistente corregido de paso.** `admin/views/inicio.php` contaba docentes con
`SELECT COUNT(*) FROM wp_edu_teachers WHERE institution_id = %d`, pero esa tabla **no tiene**
`institution_id`: el KPI "Docentes" del dashboard del rector mostraba 0 y llenaba el log de
errores de base de datos. Ahora hace JOIN con `usermeta.edu_institution_id`, el mismo criterio
que ya usaba la lista de `admin/views/docentes.php`. Verificado: cuenta 2 de 2, sin error.

**Cambios de esquema.** Ninguno. `EDU_DB_VERSION` sigue en 1.0.9; `EDU_VERSION` sube a 1.2.0.
Lo nuevo vive en opciones (`edu_api_secret`, `edu_api_allowed_origins`) y usermeta
(`edu_token_version`, `edu_refresh_tokens`).

**Verificación.** 39 pruebas end-to-end por HTTP contra el sitio de Local (con usuario
temporal, creado y borrado por la propia prueba) y 14 unitarias del JWT. Todas en verde.
wp-admin y los portales sin regresión: el filtro `determine_current_user` solo actúa si llega
una cabecera `Authorization: Bearer`, de modo que la autenticación por cookie queda intacta.

**Notas de despliegue.** En Apache hace falta `CGIPassAuth On` (o la `RewriteRule` que copia
la cabecera) para que llegue el `Authorization`. En producción el login exige HTTPS; en
entornos `local`/`development` se permite HTTP para poder desarrollar con Local. Si la app
vivirá en otro dominio, hay que cargarlo en **Ajustes → API REST → Dominios autorizados**.

### 2026-08-11 — Contrato de la API `edu/v1` (Fase 1 de plataforma propia)

**Qué se hizo.** Documento de diseño `docs/API_CONTRATO_V1.md`: el contrato completo de la
API REST que convierte al plugin en backend único de la plataforma propia (Opción A). Es
diseño, **no hay código nuevo**.

Contenido: convenciones (URL, formatos, paginación, errores), autenticación, autorización en
tres niveles, gate de módulos, catálogo de ~90 endpoints en 9 dominios, detalle fino de los
dos endpoints críticos (`GET /gradebook` y `POST /gradebook/scores`), formas canónicas de los
recursos, manejo de archivos, catálogo de códigos de error, invariantes de seguridad y plan de
implementación en 5 etapas.

**Hallazgo que condiciona todo el diseño.** Los controllers **no son reutilizables desde REST**:
`check_admin_referer()`, lectura directa de `$_POST`, `wp_die()` y `wp_safe_redirect() + exit`.
El contrato define por eso una **capa de servicios** (`includes/services/`) con métodos puros
que devuelven `array|WP_Error`, y deja controllers y endpoints REST como dos adaptadores
delgados sobre ella. Sin esa extracción, la promesa de la Opción A —*un cambio aplica a plugin
y app*— no se cumple.

**Decisiones fijadas.**
- Auth por **JWT propio HMAC-SHA256** (access 15 min + refresh rotatorio 30 días), implementado
  a mano con `hash_hmac()` — sin nuevas dependencias de Composer. Revocación masiva vía
  `usermeta.edu_token_version`. Application Passwords para integraciones; cookie+nonce sigue
  válido en el mismo dominio.
- Formato de error nativo de `WP_Error`, reutilizando los códigos internos existentes
  (`invalid_scope`, `no_components`, `invalid_parcial`) con prefijo `edu_`.
- **Las fórmulas de cálculo y la equivalencia cualitativa se resuelven siempre en el servidor.**
  La API entrega `computed_score` y `cualitativa` ya calculados: nunca se duplican en JavaScript.
- Módulo apagado → **404** (no 403), y `GET /me` publica el mapa de módulos activos.
- Binarios (PDF/XLSX/ZIP) y archivos privados vía **URL firmada de 5 minutos**, porque el
  navegador no puede mandar `Authorization` en una descarga directa.

**Archivos tocados.** `docs/API_CONTRATO_V1.md` (nuevo), `docs/BITACORA.md`.

**Cambios de esquema.** Ninguno, ni ahora ni en la implementación prevista: todo lo nuevo vive
en `wp_options` y `wp_usermeta`. `EDU_DB_VERSION` sigue en 1.0.9.

**Notas de despliegue.** Apache descarta la cabecera `Authorization` salvo `CGIPassAuth On` o
una regla `RewriteRule` — mismo tipo de gotcha que la regla de Nginx para `edu-privado`.
Quedan 5 decisiones abiertas en la §14 del contrato, previas a escribir código.

### 2026-07-29 — Componente evaluable unificado y creado al vuelo (v1.1.0)

**Qué se hizo.** El formulario de tarea pedía dos campos que en la práctica decían lo
mismo (*Componente evaluable* y *Tipo*), y para poder elegir un componente había que
salir antes a la pantalla *Componentes evaluables*. Ahora es **un solo campo**,
*"Se evalúa como"*, con tres modos: elegir un componente existente, `➕ Crear componente
nuevo…` (escribiendo el nombre ahí mismo) o *Sin vincular*.

- El campo `Tipo` desapareció del formulario. La columna `assignments.type` se conserva
  (la usan el filtro del listado y los exportes Mineduc) y se rellena automáticamente
  con `Edu_Assignment_Task_Controller::derive_type()`, que deduce el tipo por palabras
  clave del nombre del componente (o del título si no hay componente).
- Componente nuevo nace con peso **1.00**, es decir pesa igual que los demás. Si se
  escribe un nombre que ya existe en el mismo parcial **no se duplica**: se reutiliza el
  existente y las notas se promedian entre sí.
- Los permisos se validan en el helper: el rector puede crear en cualquier materia de su
  institución; el docente solo en materias de sus asignaciones activas.
- En *Componentes evaluables*, un peso vacío ya no descartaba la fila en silencio: ahora
  asume 1.00.

**Archivos tocados.**
- `includes/controllers/class-edu-curriculum-controller.php` — nuevo
  `resolve_or_create_component()` + `puede_crear_componente()`; peso vacío → 1.00.
- `includes/controllers/class-edu-assignment-task-controller.php` — constantes
  `VALID_TYPES` y `TYPE_KEYWORDS`, nuevo `derive_type()`, resolución del componente en
  `handle_save()`, carga de `$existing` adelantada.
- `admin/views/tareas.php` — campo unificado + JS del toggle.
- `admin/views/componentes.php` — pesos por defecto en 1.00 y textos de ayuda.
- `public/shortcodes/class-edu-shortcode-docente.php` — mismo campo en los formularios de
  nueva tarea y edición; nuevas funciones JS `eduFillComponentes()` y
  `eduToggleNuevoComponente()`.

**Cambios de esquema.** Ninguno. `EDU_DB_VERSION` sigue en 1.0.9; `EDU_VERSION` sube a 1.1.0.

**Notas de despliegue.** Retrocompatible: el controller sigue aceptando un `type`
explícito si algún llamador externo lo envía, lo que deja el camino listo para el puente
con Flipbook/H5P. Las tareas existentes conservan su tipo.

---

## 3. Mapa de versiones y migraciones de esquema

Las migraciones incrementales viven en `Edu_Activator::run_incremental_migrations()` y se disparan desde `maybe_migrate()` cuando `edu_db_version` es distinta de `EDU_DB_VERSION`.

| Versión | Cambio de esquema | Motivo |
|---|---|---|
| 1.0.0 | 28 tablas iniciales + catálogo Mineduc | Fase 0 |
| 1.0.1 | `assignments.component_id` | Vincular tarea con componente evaluable |
| 1.0.2 – 1.0.3 | (sin cambio de esquema) | Fases 3–5, solo UI y controllers |
| 1.0.4 | `assignments.allow_recovery`, `assignments.recovery_due_date`, `submissions.recovery_*` | Mejora/recuperación de tareas |
| 1.0.5 | `trimester_scores.final_exam_score`, `trimester_scores.proyecto_score` | Fórmula sumativa Instructivo 2025 |
| 1.0.6 | Tablas `payments` y `payment_config` | Módulo de pensiones Payphone |
| 1.0.7 | `grade_components.created_by` | Componentes propios del docente |
| 1.0.8 | `students.sexo`, `students.direccion` | Nómina AMIE |
| 1.0.9 | (sin cambio de esquema) | Hardening y revisión integral |
| 1.1.0 | (sin cambio de esquema) | Componente evaluable unificado y creado al vuelo |
| 1.2.0 | (sin cambio de esquema) | API `edu/v1` etapa 1a: autenticación por token y `GET /me` |
| 1.3.0 | (sin cambio de esquema) | API `edu/v1` etapa 1b: capa de servicios de calificaciones |
| 1.4.0 | (sin cambio de esquema) | API `edu/v1` etapa 1c: 27 endpoints de lectura |
| 1.5.0 | (sin cambio de esquema) | API `edu/v1` etapa 1d: 15 endpoints de escritura |
| 1.6.0 | (sin cambio de esquema) | API `edu/v1` etapa 1e: pagos, reportes y dashboards — Fase 1 completa |
| 1.7.0 | (sin cambio de esquema) | Fase 2: app propia (Vue 3 sin build) para estudiante y representante |
| 1.8.0 | (sin cambio de esquema) | Fase 2: portal del docente + escrituras de calificaciones en la API |
| 1.9.0 | (sin cambio de esquema) | Fase 2 completa: portal del rector, descarga de adjuntos y arreglo de las descargas firmadas |

---

## 4. Inventario de base de datos (30 tablas)

**Core institucional (7):** `institutions`, `periods`, `trimesters`, `grades`, `subjects_catalog`, `subjects`, `grade_subjects`

**Personas (5):** `teachers`, `students`, `parents`, `parent_student`, `teacher_assignments`

**Académico (11):** `assignments`, `assignment_files`, `submissions`, `submission_files`, `rubrics`, `rubric_scores`, `grade_components`, `grades_log`, `parcial_scores`, `trimester_scores`, `year_scores`

**Asistencia (1):** `attendance`

**Comunicación (3):** `announcement_templates`, `announcements`, `announcement_recipients`

**Pagos (2):** `payments`, `payment_config`

**Auditoría (1):** `audit`

---

## 5. Inventario de código

### Servicios — `includes/services/` (15)
Base: `Edu_Service` (capabilities, `check_scope()`, alcance personal).
Escritura: `Edu_Score_Service`, `Edu_Trimester_Score_Service`, `Edu_Curriculum_Service`,
`Edu_Assignment_Service`, `Edu_Submission_Service`, `Edu_Attendance_Service`,
`Edu_Announcement_Service`, `Edu_Payment_Service`, `Edu_File_Service`.
Lectura: `Edu_Catalog_Service`, `Edu_People_Service`, `Edu_Gradebook_Service`,
`Edu_Activity_Service`, `Edu_Report_Service`.
Lógica de negocio sin HTTP, compartida por los controllers y la API REST.

### API REST — `includes/api/` (10)
`Edu_Api_Jwt`, `Edu_Api_Auth`, `Edu_Api` + rutas `auth`, `me`, `catalog`, `gradebook`,
`activity`, `write` (mutaciones) y `report` (reportes). Namespace `edu/v1`, **59 rutas**.

> Cómo se cuentan: `rest_get_server()->get_routes()` devuelve 60 patrones bajo
> `/edu/v1`, de los cuales uno es el índice del propio namespace que registra
> WordPress. Contar `register_rest_route` da menos (42) porque varias rutas
> declaran más de un método en la misma llamada.

### Controllers — `includes/controllers/` (20)
`institution`, `period`, `grade`, `subject`, `teacher`, `student`, `parent`, `curriculum` (pensum + componentes), `assignment`, `assignment-task`, `submission`, `score`, `trimester-score`, `year-score`, `attendance`, `announcement`, `boletin`, `account`, `payment`, `settings`. El helper de import vive en `includes/helpers/`.

### Helpers — `includes/helpers/` (3)
`Edu_Import_Helper` (CSV), `Edu_Qualitativa_Helper` (equivalencias A+/E-), `Edu_Modules` (módulos activables).

### Módulos de dominio — `modules/` (6 clases núcleo)
- `calificaciones/class-edu-grade-calculator.php`
- `boletines/class-edu-boletin-generator.php` (mPDF)
- `pagos/class-edu-payphone.php`, `pagos/class-edu-payment-manager.php`
- `reportes/class-edu-mineduc-exporter.php`, `reportes/class-edu-xlsx-writer.php`
- `whatsapp/class-edu-whatsapp.php`, `whatsapp/class-edu-whatsapp-notifier.php`

### Vistas admin — `admin/views/` (26 archivos)
`inicio`, `institucion`, `periodos`, `grados`, `materias`, `docentes`, `estudiantes`, `padres`, `asignaciones`, `comunicados`, `pensum`, `componentes`, `tareas`, `tareas-detalle`, `calificaciones`, `examen-final`, `asistencia`, `panel-docentes`, `cierres`, `resumen-anual`, `boletines`, `exportes-mineduc`, `cuentas`, `pagos`, `auditoria`, `ajustes` + parcial `_institution-switcher`.

### Shortcodes — `public/shortcodes/` (6)
| Shortcode | Clase | Tabs |
|---|---|---|
| `[edu_portal_rector]` | `Edu_Shortcode_Rector` | 10 |
| `[edu_portal_docente]` | `Edu_Shortcode_Docente` | 7 |
| `[edu_portal_estudiante]` | `Edu_Shortcode_Estudiante` | 6 |
| `[edu_portal_padre]` | `Edu_Shortcode_Padre` | 7 |
| `[edu_mis_tareas]` | `Edu_Shortcode_Tareas` | — |
| `[edu_mis_comunicados]` | `Edu_Shortcode_Comunicados` | — |

---

## 6. Roles y capabilities

| Rol | Capabilities |
|---|---|
| `edu_rector` | `edu_view_all`, `edu_manage_grades`, `edu_manage_subjects`, `edu_manage_teachers`, `edu_manage_students`, `edu_manage_parents`, `edu_manage_assignments`, `edu_manage_curriculum`, `edu_view_audit`, `edu_generate_reports`, `edu_close_partial`, `edu_send_institutional_announcements` + todas las de docente |
| `edu_docente` | `edu_grade_students`, `edu_create_assignment`, `edu_take_attendance`, `edu_send_grade_announcement`, `edu_view_own_grades` |
| `edu_estudiante` | `edu_submit_assignment`, `edu_view_own_grades`, `edu_view_assignments` |
| `edu_padre` | `edu_view_child_grades`, `edu_view_child_attendance`, `edu_read_announcements`, `edu_acknowledge_announcement` |

Estudiantes y representantes **no entran al wp-admin**: `Edu_Admin::redirect_non_admin_roles()` los devuelve al home. Su único acceso son los portales frontend.

---

## 7. Hooks públicos del plugin

```php
do_action( 'edu_grade_logged',     $student_id, $component_id, $score );
do_action( 'edu_partial_closed',   $student_id, $subject_id, $trimester_id, $parcial_num );
do_action( 'edu_trimester_closed', $student_id, $subject_id, $trimester_id );
do_action( 'edu_announcement_sent',$announcement_id );
do_action( 'edu_payment_overdue',  $payment, $student );
do_action( 'edu_payment_confirmed',$payment_id );
do_action( 'edu_attendance_absence', $student_id, $fecha, $tipo );
do_action( 'edu_account_suspended', $user_id );
do_action( 'edu_audit', $user_id, $action, $entity_type, $entity_id, $old, $new );

apply_filters( 'edu_module_active', bool $activo, string $modulo );
```

---

## 8. Seguridad implementada

- Nonces en todos los formularios (`wp_nonce_field` + `check_admin_referer`).
- Sanitización de entrada y escapado de salida en todas las vistas (WPCS).
- Validación de capability **en cada controller**, no solo en la UI.
- Validación de pertenencia a la institución activa antes de escribir (`invalid_scope`).
- Archivos de estudiantes fuera del árbol público (`uploads/edu-privado/` + `.htaccess`), servidos por controller con nonce.
- Auditoría de cambios sensibles en `wp_edu_audit`.
- Bloqueo de login para cuentas suspendidas.
- Componentes con notas registradas **no se pueden eliminar** (protección de integridad).
- Parciales y trimestres cerrados no se recalculan ni se sobrescriben.

---

## 9. Deuda técnica y pendientes conocidos

| # | Tema | Detalle |
|---|---|---|
| 1 | ~~Componente al vuelo~~ | ✅ Resuelto en v1.1.0. |
| 2 | Integración Flipbook | Hoy es solo visual: el tab "Mis textos" ejecuta `do_shortcode('[mis_textos]')`. No existe puente para que una asignación creada desde Flipbook genere una tarea con componente en el sistema educativo. |
| 3 | ~~Panel de docentes en portal~~ | ✅ Resuelto en v1.6.0: `GET /dashboard/teacher-panel`. |
| 4 | Perfil "solo calificaciones" | Los módulos ya se apagan uno por uno, pero no hay un preset de un clic para una institución que solo quiere notas + asistencia. |
| 5 | Reportes por componente | No hay exporte/visual analítico por componente para presentar a instituciones. |
| 6 | Nginx | La protección de `edu-privado` con `.htaccess` no aplica; requiere regla manual en el server block. |
| 7 | Tests | No hay suite PHPUnit corriendo. |
| 8 | Demo seed | `demo-seed.php` se carga si existe; debe eliminarse en producción real. |

---

## 10. Cómo mantener esta bitácora

Al cerrar cada cambio relevante, agregar una entrada con este formato:

```
### YYYY-MM-DD — Título del cambio (vX.Y.Z)
**Qué se hizo:** …
**Archivos tocados:** …
**Cambios de esquema:** … (si hubo, subir EDU_DB_VERSION)
**Riesgos / notas de despliegue:** …
```
