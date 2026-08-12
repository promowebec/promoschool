# CLAUDE.md — PromoSchool (Sistema Educativo Integral)

Guía permanente para Claude Code. Léelo al inicio de cada sesión.

## Propósito del proyecto

Plugin nativo de WordPress para gestión académica de unidades educativas de Ecuador: matrícula, calificaciones (modelo Mineduc 3 trimestres × 2 parciales + examen final 70/30), asistencia, tareas, comunicados a padres, boletines y auditoría. Multi-institución y multi-rol (super admin, rector, docente, estudiante, padre).

## Stack obligatorio

- **PHP:** 8.1+ con `declare(strict_types=1);` en cada archivo.
- **WordPress:** 5.8+ mínimo. Usar APIs nativas (`$wpdb`, REST, hooks, transients, `wp_remote_*`, `wp_mail`).
- **JavaScript:** ES2022 + React 18 vía `@wordpress/element` + `@wordpress/components`. NO usar Vue/Angular.
- **Build:** Vite + esbuild.
- **Base de datos:** MySQL 5.7+ / MariaDB 10.3+, charset `utf8mb4`. El collation se **hereda de WP** vía `$wpdb->get_charset_collate()` (ver ADR).
- **PDF (boletines):** DomPDF o mPDF vía Composer.
- **Notificaciones externas:** Twilio o Meta Cloud API para WhatsApp, SMTP con SendGrid/Amazon SES para emails masivos.

## Convenciones de naming

| Elemento | Convención | Ejemplo |
|---|---|---|
| Tablas DB | `{prefix}edu_*` | `wp_edu_students`, `wp_edu_trimester_scores` |
| Constantes PHP | `EDU_*` | `EDU_VERSION`, `EDU_PATH` |
| Namespaces PHP | `Edu\` | `Edu\Rest\Students_Controller` |
| Clases PHP | `Class_With_Underscores` | `class Students_Repository` |
| Funciones PHP | `edu_*` | `edu_calculate_trimester_score()` |
| Hooks WP | `edu/{contexto}/{accion}` | `edu/grade/registered`, `edu/trimester/closed` |
| Variables JS globales | `camelCase` con prefijo `edu` | `eduDashboard`, `eduCurrentPeriod` |
| CSS classes | `edu-*` (kebab-case) | `.edu-panel`, `.edu-gradebook` |
| Endpoints REST | `/wp-json/edu/v1/{recurso}` | `/wp-json/edu/v1/students` |
| Capabilities | `edu_*` | `edu_view_reports`, `edu_grade_students` |
| Text domain i18n | `sistema-educativo` | `__( 'Guardar', 'sistema-educativo' )` |
| Roles WP | `edu_docente`, `edu_estudiante`, `edu_padre`, `edu_rector` | (en español; los mantiene también Flipbook) |

## Reglas de seguridad NO NEGOCIABLES

1. **Nonces:** todo endpoint REST que mute datos requiere `X-WP-Nonce` validado.
2. **Capabilities:** cada endpoint valida con `current_user_can('edu_*')`. Nunca `is_admin()`.
3. **Sanitización entrada:** `sanitize_text_field()`, `absint()`, `wp_kses_post()`, `sanitize_email()`.
4. **Escape salida:** `esc_html()`, `esc_attr()`, `esc_url()`, `wp_json_encode()`.
5. **Prepared statements:** SIEMPRE `$wpdb->prepare()`.
6. **Datos de menores:** todos los tests que involucran `wp_edu_students` deben usar fixtures anonimizadas; nunca logs con nombres/cédulas reales.
7. **Auditoría obligatoria:** cualquier mutación de nota, asistencia o entrega escribe en `wp_edu_audit` con `user_id`, `action`, `entity_type`, `entity_id`, `old_value`, `new_value`.
8. **Cierre de parcial/trimestre:** una vez `is_closed=TRUE`, solo `edu_manage_all` puede reabrirlo; toda escritura queda en auditoría.
9. **Uploads (entregas de alumnos):** validar MIME real con `wp_check_filetype_and_ext()`. Prohibir `.php`, `.exe`, `.js` en subidas.

## Modelo académico Mineduc Ecuador

**Cálculo de notas (implementado en `edu_calculate_*`):**

```
Parcial     = Σ(componentes × pesos_componente)   componentes: tareas, lecciones, trabajos, actuación, prueba
Trimestre   = (Parcial1 + Parcial2) / 2 * 0.7 + ExamenFinal * 0.3
Año         = (Trimestre1 + Trimestre2 + Trimestre3) / 3
```

**Estados del año:** `en_curso`, `aprobado` (≥7), `supletorio` (5–6.99), `remedial` (falla supletorio), `gracia` (falla remedial), `reprobado` (falla gracia).

**Régimen:** Sierra (sep–jul) o Costa (may–feb). Se guarda en `wp_edu_institutions.regime` y `wp_edu_periods.regime`.

**Subniveles:** `preparatoria` (1ro EGB), `elemental` (2–4), `media` (5–7), `superior` (8–10), `bg` (Bachillerato General), `bt` (Bachillerato Técnico con `specialty`).

**Comportamiento:** cualitativo A/B/C/D/E, calculado en parte por asistencia.

## Roles y capabilities

| Rol WP | Rol funcional | Capabilities principales |
|---|---|---|
| `administrator` | Super Admin | `edu_manage_all` |
| `edu_rector` | Rector | `edu_view_all_reports`, `edu_reopen_period`, `edu_supervise_teachers` |
| `edu_docente` | Docente | `edu_create_assignment`, `edu_grade_students` (solo sus grados/materias), `edu_take_attendance`, `edu_send_announcement` |
| `edu_estudiante` | Estudiante | `edu_view_own_grades`, `edu_submit_assignment`, `edu_read_announcement` |
| `edu_padre` | Padre | `edu_view_child_grades`, `edu_confirm_announcement`, `edu_download_boletin` |

Registrar capabilities en `register_activation_hook` con `add_cap()`. Sync en cada bump de versión.

## Integración con Flipbook plugin

PromoSchool NO conoce a Flipbook. Es Flipbook quien detecta y consume las tablas `wp_edu_*`. **Nunca hagas `require` ni `use` de clases Flipbook desde PromoSchool.**

Los puntos de contacto están documentados en `INTEGRATION-FLIPBOOK.md`. Al añadir features en PromoSchool, revisar si algún cambio de esquema `wp_edu_*` rompe ese contrato.

## i18n obligatorio

- Text domain: `sistema-educativo` (no `edu` porque colisiona con otros plugins).
- Cargar en `plugins_loaded` con `load_plugin_textdomain()`.
- Idiomas: `es_EC` (principal), `es_ES`, `en_US`. Español ecuatoriano es la referencia (usa términos locales: "docente", no "profesor"; "estudiante", no "alumno" en UI oficial).
- JavaScript: `wp_set_script_translations()`.

## Patrones obligatorios

- **Repository pattern** para acceso a datos.
- **Service layer** para lógica de negocio (`Score_Calculator_Service`, `Attendance_Report_Service`, `Announcement_Dispatcher_Service`).
- **Controller pattern** para REST.
- **PSR-4** vía Composer.
- **DI ligero** (constructor injection).
- **Colas asíncronas** con Action Scheduler para envíos masivos de email/WhatsApp (>50 destinatarios).

## Compatibilidad

- WP 5.8+, PHP 8.1+.
- Multisite: cada institución puede ser una subsite (evaluar caso por caso).
- Responsive desde 320px (padres consultan desde móvil).
- Navegadores: últimas 2 versiones de Chrome, Firefox, Safari, Edge.

## Performance

- Cálculos de nota agregada: cacheados en `wp_edu_parcial_scores` / `wp_edu_trimester_scores` / `wp_edu_year_scores` (denormalización controlada).
- Recalcular con `edu_recalculate_scores($student_id, $trimester_id)` como job diferido cuando cambia un componente.
- Consultas de dashboard rector: cache con `wp_cache_*` + transients 10 min, invalidación en `edu/grade/registered`.
- Envío masivo de comunicados: NUNCA síncrono. Encolar y responder al usuario "encolado, X destinatarios".

## Commits y branching

- `main`: producción, taggeado SemVer.
- `develop`: integración.
- Features: `feature/{numero}-{slug}`.
- Conventional Commits (`feat:`, `fix:`, `refactor:`, `docs:`, `test:`).

## Archivos de referencia (en la raíz del plugin)

### Estáticos
- `docs/database-schema.sql` — esquema completo v1.x.
- `docs/rest-api-contract.md` — endpoints.
- `docs/testing-plan.md`
- `docs/migration-versioning.md`
- `INTEGRATION-FLIPBOOK.md` — contrato con el plugin Flipbook.

### Vivos (Claude Code debe actualizarlos)
- `HANDOVER.md` — leer al inicio de cada sesión.
- `PROGRESS.md` — bitácora cronológica.
- `DECISIONS.md` — ADRs.
- `CHANGELOG.md` — Keep a Changelog + SemVer.

## Reglas de documentación viva OBLIGATORIAS

**Al INICIAR cada sesión:**
1. Leer `HANDOVER.md`.
2. Confirmar en chat: "Retomando en <fase>, próximo paso: <acción>".

**Durante la sesión:**
- Toda decisión no trivial se registra como `ADR-XXX` en `DECISIONS.md`.
- Si cambia esquema `wp_edu_*` que Flipbook consume, actualizar también `INTEGRATION-FLIPBOOK.md` y avisar en chat "OJO: cambio afecta a Flipbook, revisar su bridge".

**Al TERMINAR:**
1. Actualizar `HANDOVER.md`.
2. Añadir entrada a `PROGRESS.md`.
3. Si hay cambio releasable, entrada en `CHANGELOG.md` sección `[Unreleased]`.
4. Commit con Conventional Commits.

**Documentación en código:**
- Cada clase, función pública, hook público y endpoint REST con docblock completo (`@param`, `@return`, `@throws`, `@since`, `@capability`).

## Lo que NUNCA hacer

- ❌ Modificar el modelo académico (70/30, subniveles Mineduc) sin ADR + revisión legal (afecta boletines oficiales).
- ❌ Borrar filas de `wp_edu_grades_log` — solo marcar `is_deleted` (histórico obligatorio).
- ❌ Enviar emails/WhatsApp síncronos con >50 destinatarios (bloquea PHP y timeout).
- ❌ Exponer cédula/nombres reales de menores en logs, tests o snapshots.
- ❌ Cargar jQuery si no es estrictamente necesario.
- ❌ Reabrir parciales cerrados sin auditoría.
- ❌ Depender de que Flipbook esté instalado. PromoSchool es standalone.

## Fase actual del proyecto

Ver `HANDOVER.md`.
