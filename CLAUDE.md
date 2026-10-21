# CLAUDE.md — PromoSchool (Sistema Educativo Integral)

Guía permanente para Claude Code. Léela al inicio de cada sesión.

> **Este documento describe el proyecto tal como es, no como nos gustaría que fuera.**
> Si algo aquí no coincide con el código, gana el código: avísalo y corrige el documento.

## Propósito

Plugin de WordPress para la gestión académica de unidades educativas en Ecuador: institución,
períodos, grados, materias, calificaciones (3 trimestres × 2 parciales 70% + sumativa 30%),
tareas y entregas, asistencia, comunicados a representantes, boletines PDF, pagos y exportes
Mineduc. Multi-institución y multi-rol.

Desde agosto de 2026 el plugin es además el **backend único** de una plataforma propia: expone
una API REST `edu/v1` que consumirá un frontend propio. Ver `docs/API_CONTRATO_V1.md`.

## Stack real

- **PHP 8.2+** · **WordPress 6.x** · **MySQL 8 / MariaDB 10.6+**, `utf8mb4`.
- Acceso a datos con **`$wpdb` directo** y `$wpdb->prepare()`. No hay ORM ni repositorios.
- **Sin namespaces, sin `declare(strict_types=1)`, sin PSR-4, sin autoloader.** Todo se carga
  con `require_once` explícito desde `sistema-educativo.php`.
- **JavaScript vanilla, sin build.** No hay Vite, ni React, ni `@wordpress/element`. Los
  portales son plantillas PHP renderizadas por shortcodes.
- **PDF:** mPDF vía Composer (única dependencia de peso).
- **XLSX:** `Edu_Xlsx_Writer`, escritor propio sin dependencias (requiere `ext-zip`).
- **Emails:** `wp_mail`. **WhatsApp:** Twilio, Meta Cloud API o WAHA, el servidor propio de la agencia que comparte con el CRM, con una sesión por colegio (módulo desactivable).
- **Estándar:** WordPress Coding Standards. Código y comentarios **en español**.

> **Regla de dependencias:** no se agregan paquetes de Composer sin preguntar. Ya se rechazó
> PhpSpreadsheet y se escribió `Edu_Xlsx_Writer` a mano. Preferir implementaciones ligeras
> propias.

## Convenciones de nombres (las que usa el código)

| Elemento | Convención | Ejemplo real |
|---|---|---|
| Tablas | `{prefix}edu_*` | `wp_edu_students`, `wp_edu_trimester_scores` |
| Clases | `Edu_Con_Guiones_Bajos` | `Edu_Score_Service`, `Edu_Api_Auth` |
| Constantes | `EDU_*` | `EDU_VERSION`, `EDU_DB_VERSION`, `EDU_PLUGIN_DIR` |
| Funciones y hooks | `edu_*` (snake_case) | `edu_grade_logged`, `edu_partial_closed` |
| Opciones | `edu_*` | `edu_db_version`, `edu_api_secret`, `edu_modules` |
| Capabilities | `edu_*` | `edu_view_all`, `edu_grade_students` |
| Roles | en español | `edu_rector`, `edu_docente`, `edu_estudiante`, `edu_padre` |
| Endpoints REST | `/wp-json/edu/v1/{recurso}` | `/wp-json/edu/v1/gradebook` |
| CSS | `edu-*` (kebab-case) | `.edu-portal`, `.edu-gradebook` |
| Text domain | `sistema-educativo` | `__( 'Guardar', 'sistema-educativo' )` |

Archivos: `class-edu-<nombre>.php` en minúsculas con guiones.

## Arquitectura: servicios + dos adaptadores

Desde la Fase 1 de la plataforma propia, la lógica de negocio vive en `includes/services/` y
la consumen dos adaptadores.

```
  wp-admin / portales  ──▶  Edu_*_Controller  ──┐
  (formulario + nonce)      nonce · $_POST      │
                            redirect            ├──▶  Edu_*_Service
                                                │     lógica pura
  app propia           ──▶  Edu_Api_*_Routes  ──┘     devuelve array | WP_Error
  (JSON + Bearer)           token · JSON
```

**Regla de oro: ninguna lógica de negocio nueva se escribe en un controller ni en una vista.**
Va al servicio; los dos adaptadores la consumen. Así una regla se implementa una sola vez y
vale para el wp-admin y para la app.

**Contrato de un servicio:** métodos `public static`, reciben un array asociativo, devuelven un
array de resultado o un `WP_Error`. Nunca `echo`, `wp_die()`, `exit`, `wp_redirect()`, ni leen
`$_POST`/`$_GET`. Los códigos de error van **sin prefijo** (`invalid_scope`, `no_components`)
porque las vistas de admin los leen así; la capa REST les antepone `edu_`.

### Estructura

```
sistema-educativo/
├── sistema-educativo.php        # constantes, requires, edu_bootstrap()
├── includes/
│   ├── class-edu-context.php    # institución activa + Edu_Context::can()
│   ├── class-edu-audit.php      # escritura en wp_edu_audit
│   ├── class-edu-roles.php      # 4 roles + 17 capabilities
│   ├── class-edu-activator.php  # dbDelta + migraciones incrementales
│   ├── services/                # 16 clases — LA LÓGICA VIVE AQUÍ
│   ├── controllers/             # 21 clases — adaptadores de formulario
│   ├── api/                     # 10 clases — API REST edu/v1 (61 rutas)
│   └── helpers/
├── admin/views/                 # 26 pantallas de wp-admin
├── public/shortcodes/           # 4 portales + 2 shortcodes sueltos
├── modules/                     # calificaciones, boletines, pagos, reportes, whatsapp
├── docs/
└── vendor/                      # mPDF
```

## Modelo académico (Mineduc Ecuador)

### Fórmulas por subnivel

**Inicial / Preparatoria / Elemental:**
```
Nota_Trimestre = ((Parcial_1 + Parcial_2) / 2) × 0.70 + Examen_Final × 0.30
```

**Media / Superior / Bachillerato** (Instructivo 2025):
```
Nota_Trimestre = ((Parcial_1 + Parcial_2) / 2) × 0.70 + ((Examen_Final + Proyecto) / 2) × 0.30
```

Detección: `in_array( $sub_level, array( 'media', 'superior', 'bg', 'bt' ), true )`.
Helper: `Edu_Service::uses_sumativa()` / `Edu_Service::formula()`.

```
Nota_Anual = (Trim_1 + Trim_2 + Trim_3) / 3
≥ 7 → aprobado · 5–6.99 → supletorio · reprueba supletorio → remedial
→ reprueba remedial → gracia · reprueba gracia → reprobado
```

### Modo «solo trimestre» (opcional por institución, desde la 1.16.0)

Opción `edu_modo_notas_{id}`. Sin parciales: el 70% es el **promedio simple de todas las notas
del trimestre**; la sumativa sigue por subnivel. Todo se guarda en `parcial_num = 1`.
La fórmula vive **solo** en `Edu_Service::trimester_score()`; el modo se lee con
`Edu_Service::grading_mode()` y no se puede cambiar con notas en el período activo.

### Cálculo del parcial — la regla que más se malinterpreta

```
Nota_Parcial = promedio simple de TODAS las notas del parcial      (desde la 1.17.0)
```

**Cada nota vale igual:** no hay pesos ni promedio previo por componente. Tareas 10, 8, 9 y
una prueba 6 dan (10+8+9+6)/4 = 8.25. La columna `grade_components.weight` sigue en la tabla
y en la API por compatibilidad, pero **ningún cálculo la usa** (decisión del usuario, 16 oct
2026). Hasta la 1.16.0 era `Σ(nota_componente × peso) ÷ Σ(pesos con nota)`.

Un componente **sin calificar no cuenta como cero**: simplemente no aporta notas. Un
estudiante con una sola nota de 7 saca 7.00, no una fracción.

Al actualizar a la 1.17.0, `Edu_Grade_Calculator::maybe_recalcular_sin_pesos()` recalcula una
vez (en `admin_init`, por tandas) los parciales abiertos; los cerrados y los de trimestres
cerrados no se tocan. Opción `edu_recalculo_sin_pesos`; cada nota cambiada queda auditada
(`recalculo_sin_pesos`, con el valor anterior) más una entrada de resumen.

### Equivalencia cualitativa (Instructivo 2025)

`Edu_Qualitativa_Helper` redondea al entero más cercano (1–10) y devuelve código y color:
10 → A+, 9 → A-, 8 → B+, 7 → B-, 6 → C+, 5 → C-, 4 → D+, 3 → D-, 2 → E+, 1 → E-.

**Se calcula siempre en el servidor.** Nunca duplicar la escala en JavaScript: si cambia el
Instructivo, la app y el boletín PDF quedarían mostrando notas distintas.

### Subniveles y régimen

`inicial` · `basica` (`preparatoria`, `elemental`, `media`, `superior`) · `bachillerato`
(`bg`, `bt` con `specialty`). Paralelos A–D. Régimen `sierra` (sep–jul) o `costa` (may–feb),
200 días laborables.

## Roles y capabilities (las reales)

| Rol | Capabilities |
|---|---|
| `edu_rector` | `edu_view_all`, `edu_manage_grades`, `edu_manage_subjects`, `edu_manage_teachers`, `edu_manage_students`, `edu_manage_parents`, `edu_manage_assignments`, `edu_manage_curriculum`, `edu_view_audit`, `edu_generate_reports`, `edu_close_partial`, `edu_send_institutional_announcements` + todas las de docente |
| `edu_docente` | `edu_grade_students`, `edu_create_assignment`, `edu_take_attendance`, `edu_send_grade_announcement`, `edu_view_own_grades` |
| `edu_estudiante` | `edu_submit_assignment`, `edu_view_own_grades`, `edu_view_assignments` |
| `edu_padre` | `edu_view_child_grades`, `edu_view_child_attendance`, `edu_read_announcements`, `edu_acknowledge_announcement` |

El rol `administrator` de WordPress es el **Superadmin Editorial**: no tiene caps `edu_*`, pero
`Edu_Context::can()` le da paso por `manage_options` y puede cambiar de institución.

Estudiantes y representantes **no entran a wp-admin**: `Edu_Admin::redirect_non_admin_roles()`
los devuelve al home. Su acceso son los portales.

## Control de acceso en tres niveles

Todo endpoint y todo servicio aplica, en este orden:

1. **Capability** — `Edu_Service::require_cap()` / `Edu_Context::can()`.
2. **Institución** — el recurso debe ser de la institución activa (`Edu_Service::check_scope()`).
3. **Alcance personal** — docente solo sus asignaciones, representante solo sus hijos,
   estudiante solo sus datos: `can_view_student()`, `can_view_grade_subject()`,
   `own_children_ids()`, `own_grade_ids()`, `teacher_has_assignment()`.

El nivel 3 es donde han aparecido casi todos los agujeros del proyecto. **Se valida en el
servicio, nunca solo en la interfaz.**

## Seguridad — reglas no negociables

1. **Nonces** en todo formulario (`wp_nonce_field` + `check_admin_referer`). La API usa token
   Bearer y por eso no necesita nonce.
2. **Capability + institución + alcance** en cada operación, en el servicio.
3. **Sanitizar la entrada** y **escapar la salida** siempre.
4. **`$wpdb->prepare()` siempre.** Si hay que interpolar una lista de IDs, pasarlos antes por
   `intval` y dejar comentado el `phpcs:ignore`.
5. **Datos de menores:** nunca cédulas ni nombres reales en logs, pruebas o capturas.
6. **Auditoría obligatoria** en toda mutación de notas, asistencia, entregas, cuentas y dinero:
   `Edu_Audit::log()`.
7. **Cierres:** un parcial o trimestre cerrado no se recalcula ni se sobrescribe.
8. **Pagos:** un pago solo se marca pagado desde `confirm_and_mark_paid()`, con confirmación
   server-side. La única excepción es el registro manual, que exige `edu_view_all` y se audita.
9. **Archivos de estudiantes** en `uploads/edu-privado/`, servidos solo por handler con
   verificación de propiedad. En Nginx hace falta la regla manual
   `location ^~ /wp-content/uploads/edu-privado/ { deny all; }`.
10. **HTTPS obligatorio en producción.**

## Hooks públicos

```php
do_action( 'edu_grade_logged',       $student_id, $component_id, $score );
do_action( 'edu_partial_closed',     $student_id, $subject_id, $trimester_id, $parcial_num );
do_action( 'edu_trimester_closed',   $student_id, $subject_id, $trimester_id );
do_action( 'edu_announcement_sent',  $announcement_id );
do_action( 'edu_payment_overdue',    $payment, $student );
do_action( 'edu_payment_confirmed',  $payment_id );
do_action( 'edu_attendance_absence', $student_id, $fecha, $tipo );
do_action( 'edu_account_suspended',  $user_id );
do_action( 'edu_audit', $user_id, $action, $entity_type, $entity_id, $old, $new );

apply_filters( 'edu_module_active', bool $activo, string $modulo );  // solo puede apagar
```

Cadena de recálculo: `edu_grade_logged` → parcial · `edu_partial_closed` → trimestre ·
`edu_trimester_closed` → año.

## Base de datos

**31 tablas** con prefijo `wp_edu_` (la 31 es `wa_queue`, la cola de WhatsApp, desde 1.0.10). Esquema canónico en `docs/sistema_educativo_schema.sql`
(28 tablas; `payments` y `payment_config` se crean en el activator).

- `dbDelta()` **ignora las FOREIGN KEY**: la integridad se aplica desde PHP.
- La versión vive en `wp_options.edu_db_version`. **Al cambiar el esquema hay que subir
  `EDU_DB_VERSION`**, o la migración no corre.
- `wp_edu_grades_log` guarda **una fila por nota**, y varias filas del mismo componente se
  promedian. No es del todo append-only: hay dos reemplazos deliberados, ambos acotados y
  auditados. Recalificar una entrega borra la fila de **esa misma tarea** antes de insertar
  (`Edu_Submission_Service::log_component_score()`), y guardar la grilla borra las notas
  **manuales** previas de esa celda (`Edu_Score_Service`), porque la grilla tiene un solo input
  por componente y si no, corregir un 6.00 a 8.00 dejaría al estudiante con 7.00. Las notas de
  tareas nunca se borran desde la grilla.

### Trampas conocidas del esquema

- `wp_edu_students` **no tiene** `first_name` / `last_name`. Los nombres están en `wp_usermeta`.
- `wp_edu_teachers` **no tiene** `institution_id`. El vínculo docente–institución vive en
  `usermeta.edu_institution_id`, o se deduce por `teacher_assignments` → `grades`.
- `wp_edu_announcements` **no tiene** `institution_id`. Se acota por el remitente o por el
  grado destino.
- `wp_edu_parcial_scores.is_closed` es **por estudiante**, no por grado o materia.

**Antes de escribir cualquier consulta sobre una tabla que no hayas tocado en esta sesión,
abre su `CREATE TABLE` en el SQL canónico y copia los nombres tal cual.** Estas cuatro trampas
ya causaron bugs en producción, uno de ellos dejó un listado vacío durante meses.

## Módulos activables

`Edu_Modules` gobierna 11 módulos: `tareas`, `entregas` (requiere `tareas`), `comunicados`,
`asistencia`, `boletines`, `pagos`, `whatsapp`, `cuentas`, `exportes`, `pwa`, `textos`. Un módulo
apagado desaparece del menú, de los tabs y no registra handlers ni cron. **Los datos nunca se
borran.** En la API, un módulo apagado responde **404**, no 403.

### Plan contratado (v1.14.0)

Un módulo está activo solo si **el plan lo incluye** (`Edu_Plan`) **y** Ajustes no lo apagó:
Ajustes solo puede apagar. El plan es una licencia firmada con Ed25519 que emite la agencia con
`tools/emitir-licencia.php`; el plugin solo trae la clave pública (`Edu_Plan::CLAVE_PUBLICA`).
La privada **nunca** entra al repositorio. Las ediciones (base, particular, fiscal, editorial)
son plantillas del script, no código del plugin.

- Sin licencia: una instalación **heredada** (ya tenía instituciones cuando llegó la 1.14.0,
  como giro22) conserva todos sus módulos; una **nueva** queda solo con el núcleo.
- El filtro `edu_module_active` corre antes del plan: puede apagar, no encender.
- Vencer `actualizaciones_hasta` no apaga módulos (pago único).
- Superar la capacidad (`limites.estudiantes`) solo muestra un aviso.
- Un módulo nuevo del catálogo nace apagado para quien tenga licencia, hasta que la licencia
  lo incluya. Al agregar uno, sumarlo también a `EDU_LIC_MODULOS` del script.

## Integración con Flipbook

PromoSchool es **standalone** y no conoce a Flipbook: nunca hacer `require` ni instanciar
clases suyas. El único punto de contacto es el módulo `textos`, que ejecuta
`do_shortcode('[mis_textos]')` — en un tab de los portales, y ahora también en `GET /me/texts`
para la pestaña «Mis libros» de la SPA.

**Quién registra `[mis_textos]` cambia según el sitio, y hay que comprobarlo, no suponerlo.**
En producción (giro22) lo registra el `functions.php` del tema, con un mapa de 289 roles de
WordPress a páginas; en Local lo registra Flipbook. El plugin no reinterpreta ese criterio:
reenvía el HTML tal cual. Eso funciona porque el shortcode de producción es HTML puro; **uno
que dependa de `wp_enqueue_*` no sobreviviría a una petición REST**.

El puente real —que una asignación creada desde Flipbook genere una tarea con componente— **no
existe todavía** (deuda técnica #2 de la bitácora). Cuando se construya, el punto de entrada es
`Edu_Curriculum_Service::resolve_or_create_component()`, que ya acepta llamadas externas.

Si cambias el esquema `wp_edu_*`, avisa en el chat de que puede afectar a Flipbook.

## Documentación viva — actualizar SIEMPRE

| Archivo | Qué es |
|---|---|
| `docs/BITACORA.md` | **La fuente de verdad del estado del proyecto.** Historia, inventarios, versiones y deuda técnica. Leerlo al empezar. |
| `docs/API_CONTRATO_V1.md` | Contrato de la API `edu/v1` y registro de lo entregado por etapa. |
| `docs/MANUAL_PANTALLAS.md` | Ficha de cada pantalla. Manual de usuario y guion comercial. |
| `docs/sistema_educativo_schema.sql` | Esquema canónico. Fuente de verdad de los campos. |

**Al cerrar cada cambio relevante**, agregar una entrada a `docs/BITACORA.md` con el formato de
su §10: qué se hizo · archivos tocados · cambios de esquema · riesgos y notas de despliegue.
Si el cambio afecta a la API, actualizar también el contrato.

## Cómo trabajar en este proyecto

1. **Una fase a la vez.** Pide una fase o etapa concreta, no todo junto.
2. **Lee el esquema antes de tocar una tabla.** Ver "trampas conocidas" arriba.
3. **No inventes fórmulas.** El modelo académico es el de arriba y afecta boletines oficiales.
4. **Verifica de verdad.** Este proyecto se prueba con scripts que crean su propio banco de
   datos y lo borran al terminar. No declarar algo funcionando sin haberlo ejecutado.
5. **Sin regresiones en wp-admin.** Al refactorizar, las pantallas deben comportarse igual;
   se comprueba ejecutando cada handler y comparando la URL de redirección.
6. **Avisa de las inconsistencias** entre documentos y código. Gana el código.
7. **Idioma:** código y comentarios en español; strings de UI con i18n.

### Cómo probar en Local

- PHP CLI: `…\lightning-services\php-8.2.29+0\bin\win32\php.exe` con
  `-d extension_dir=…\ext -d extension=php_mysqli.dll -d extension=php_curl.dll -d extension=php_mbstring.dll`.
- MySQL escucha en **`127.0.0.1:10004`**: definir `DB_HOST` **antes** de requerir `wp-load.php`.
- Patrón que funciona: script que crea un fixture propio, golpea la API con `wp_remote_request()`
  y lo borra en `register_shutdown_function`.

## Portales shortcode: CONGELADOS (12 ago 2026)

Los 4 portales de `public/shortcodes/` (`rector`, `docente`, `estudiante`, `padre`) están en
**mantenimiento correctivo**: se corrigen errores, **no se agregan funciones**. Todo lo nuevo
va a la SPA de la Fase 2.

Motivo: la SPA los va a reemplazar. Mantener dos interfaces vivas obliga a construir y depurar
cada pantalla dos veces, y mientras se les sigan añadiendo funciones la paridad nunca llega.
`docs/MANUAL_PANTALLAS.md` es el checklist para saber cuándo la SPA alcanzó paridad.

**Esto no afecta a `wp-admin`:** las 26 vistas del backend siguen vivas y en uso normal.

Cuando la SPA cubra un portal, la página de WordPress que hoy tiene el shortcode pasa a
renderizar la SPA. Mismo dominio y misma URL, así que la migración es reversible portal por
portal: si algo falla, se vuelve a poner el shortcode.

## Lo que NUNCA hacer

- ❌ Cambiar el modelo académico (70/30, subniveles, escala cualitativa) sin decisión explícita:
  afecta boletines oficiales.
- ❌ Borrar filas de `wp_edu_grades_log` fuera de los dos reemplazos ya previstos (recalificar
  una entrega y guardar la grilla, ambos auditados). Nunca borrar notas de tareas desde la grilla.
- ❌ Duplicar en JavaScript el cálculo de notas o la escala cualitativa.
- ❌ Escribir lógica de negocio en un controller o en una vista.
- ❌ Enviar WhatsApp sin pasar por `Edu_Whatsapp_Queue::encolar()` (lotes por cron, tope
  diario de la licencia `limites.whatsapp_dia`).
- ❌ Enviar emails o WhatsApp masivos de forma síncrona. Encolar en cron y responder
  "encolado, N destinatarios".
- ❌ Exponer cédulas o nombres reales de menores en logs, pruebas o capturas.
- ❌ Devolver `file_url` de archivos privados en una respuesta de la API. Enlace firmado siempre.
- ❌ Reabrir parciales o trimestres cerrados sin auditoría.
- ❌ Agregar dependencias de Composer sin preguntar.
- ❌ Depender de que Flipbook esté instalado.

## Estado actual

**v1.18.0** · esquema `EDU_DB_VERSION` 1.0.12. Desde la 1.14.0 los módulos dependen del plan contratado (licencia firmada, ver «Plan contratado»). Desde la 1.16.0 cada institución elige modo de calificación «por parciales» o «solo trimestre». Desde la 1.17.0 los parciales no ponderan: cada nota vale igual. Desde la 1.18.0 una materia propia se ofrece en todos los niveles o solo en uno (`subjects.sub_levels`, `NULL` = todos).

Fases 0–7 del plugin completas (calificaciones, tareas, comunicados, asistencia, PWA, cuentas,
pagos Payphone, WhatsApp y exportes Mineduc), más la revisión de seguridad de julio de 2026.

**Fase 1 completa:** API REST `edu/v1` con **59 rutas** cubriendo los seis dominios, sobre 15
servicios.

**Fase 2 completa:** la app propia (`[edu_app]`, Vue 3 sin build, 23 módulos JS) cubre los
**cuatro portales** —estudiante, representante, docente y rector—. Se sirve **desde el mismo
dominio de WordPress** (decisión de agosto de 2026: evita CORS y el problema de la cabecera
`Authorization` en Apache) y se autentica con cookie + `X-WP-Nonce`.

Verificado con 261 pruebas automatizadas más los 21 escenarios de adaptadores de wp-admin.

**Certificado de promoción (14 oct 2026):** `Edu_Certificate_Service` + `Edu_Certificado_Generator`.
Rector/secretaría y el representante (solo sus hijos); requiere año cerrado y todo aprobado; el texto «promovido a» sale de
`grades.next_grade_name`; firmas y sello en `edu-privado/certificados/{institución}/`, nunca por URL.
Textos, cargos, columnas y color: plantilla por institución en la opción `edu_cert_plantilla_{id}`.

**Textos (19 ago 2026):** la SPA tiene la pestaña «Mis libros» en estudiante, docente y
rector, sobre `GET /me/texts`. La asignación sigue siendo la del sitio (roles de WordPress);
el plugin solo la muestra. La rama `textos-por-institucion` tiene aparcado, sin fusionar, un
modelo de asignación propio para cuando poner roles uno por uno deje de escalar.

**Siguiente:** retirar los portales shortcode cuando se confirme la paridad en producción
(checklist en `docs/MANUAL_PANTALLAS.md`).

El detalle siempre está en `docs/BITACORA.md`.
