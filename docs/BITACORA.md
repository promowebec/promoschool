# Bitácora del proyecto · Sistema Educativo Integral

Registro de todo lo construido en el plugin, desde el scaffolding hasta la versión actual.
Documento vivo: agregar una entrada nueva por cada fase o cambio relevante.

- **Plugin:** Sistema Educativo Integral
- **Versión actual:** 1.4.0 (`EDU_VERSION`); esquema en 1.0.9 (`EDU_DB_VERSION`)
- **Stack:** WordPress 6.x · PHP 8.2+ · MySQL 8 / MariaDB 10.6+ · mPDF · sin dependencias JS de build
- **Última actualización de esta bitácora:** 11 de agosto de 2026

---

## 1. Resumen ejecutivo

| Métrica | Valor |
|---|---|
| Tablas propias en base de datos | **30** (prefijo `wp_edu_`) |
| Pantallas del backend WordPress | **25** ítems de menú + 1 subvista (`tareas-detalle`) |
| Portales frontend (shortcodes) | **4** portales + **2** shortcodes sueltos |
| Pestañas dentro de los portales | **30** |
| Roles personalizados | **4** (`edu_rector`, `edu_docente`, `edu_estudiante`, `edu_padre`) |
| Capabilities propias | **17** |
| Controllers (capa de escritura) | **21** clases |
| Servicios (lógica sin HTTP) | **8** clases (`includes/services/`) |
| Clases de la API REST `edu/v1` | **8** (`includes/api/`) · **36** rutas |
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

### Servicios — `includes/services/` (8)
Escritura: `Edu_Service` (base), `Edu_Score_Service`, `Edu_Trimester_Score_Service`, `Edu_Curriculum_Service`.
Lectura: `Edu_Catalog_Service`, `Edu_People_Service`, `Edu_Gradebook_Service`, `Edu_Activity_Service`.
Lógica de negocio sin HTTP, compartida por los controllers y la API REST.

### API REST — `includes/api/` (8)
`Edu_Api_Jwt`, `Edu_Api_Auth`, `Edu_Api` + rutas `auth`, `me`, `catalog`, `gradebook` y `activity`. Namespace `edu/v1`, 36 rutas.

### Controllers — `includes/controllers/` (21)
`institution`, `period`, `grade`, `subject`, `teacher`, `student`, `parent`, `curriculum` (pensum + componentes), `assignment`, `assignment-task`, `submission`, `score`, `trimester-score`, `year-score`, `attendance`, `announcement`, `boletin`, `account`, `payment`, `settings`, más el helper de import.

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
| 3 | Panel de docentes en portal | Existe solo como pantalla del wp-admin; el portal del rector no lo tiene como tab. |
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
