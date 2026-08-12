# DECISIONS — Registro de decisiones arquitectónicas (ADR)

> Cada decisión importante queda aquí con contexto, alternativas, decisión y consecuencias.
> Nunca borrar entradas. Si una cambia, añadir ADR nueva que la reemplace.

## Índice

- [ADR-001](#adr-001-plugin-nativo-de-wordpress-en-vez-de-app-separada)
- [ADR-002](#adr-002-tablas-propias-wp_edu_-en-vez-de-cpt-postmeta)
- [ADR-003](#adr-003-modelo-mineduc-3-trimestres-x-2-parciales-7030)
- [ADR-004](#adr-004-catalogo-de-materias-precargado-vs-libre)
- [ADR-005](#adr-005-collation-heredada-de-wordpress)
- [ADR-006](#adr-006-envios-masivos-asincronos-con-action-scheduler)
- [ADR-007](#adr-007-integracion-con-flipbook-por-lectura-de-tablas-wp_edu_)

_(Los ADR de las nuevas decisiones se añaden con numeración continua ADR-008, ADR-009, etc.)_

---

## Formato ADR (usar este para cada nueva)

```markdown
## ADR-XXX: Título corto y decisivo

- **Fecha:** YYYY-MM-DD
- **Estado:** Propuesta | Aceptada | Deprecada | Reemplazada por ADR-YYY
- **Deciden:** promowebec + Claude Code

### Contexto
### Alternativas consideradas
### Decisión
### Consecuencias
- Positivas
- Negativas / trade-offs
- Riesgos
```

---

## ADR-001: Plugin nativo de WordPress en vez de app separada

- **Fecha:** 2026-05 (retroactiva)
- **Estado:** Aceptada

### Contexto
Se necesita un sistema educativo integral para colegios ecuatorianos con roles, autenticación, gestión de usuarios, tareas, calificaciones. WP ya resuelve mucho de eso.

### Alternativas
1. **Plugin WP nativo** (Opción A del plan original).
2. App separada Laravel/Node + WP vitrina (Opción B).
3. Híbrido plugin + microservicios (Opción C).

### Decisión
Opción A para MVP y v1 completo. Evolución a C solo si se supera 1.000 estudiantes activos o >5.000 emails diarios.

### Consecuencias
- ✅ Un solo login, un solo hosting, un solo mantenimiento.
- ✅ Reutiliza roles, media, cron, i18n de WP.
- ⚠️ Techos de rendimiento de WP con muchos usuarios simultáneos → mitigable con cache de objetos y colas.

---

## ADR-002: Tablas propias `wp_edu_*` en vez de CPT + postmeta

- **Fecha:** 2026-05 (retroactiva)
- **Estado:** Aceptada

### Contexto
Millones de filas potenciales en calificaciones, asistencia y comunicados. `wp_posts + wp_postmeta` sería EAV, degradaría todo el sitio y complicaría consultas.

### Alternativas
1. CPT + postmeta.
2. Tablas propias normalizadas.
3. Mixto.

### Decisión
28 tablas propias con prefijo `wp_edu_*`, InnoDB, índices compuestos.

### Consecuencias
- ✅ Escala predecible.
- ✅ Consultas SQL limpias con JOIN estándar.
- ✅ No contamina `wp_posts`.
- ⚠️ Perdemos revisiones/autoguardado nativos → reimplementados donde importa (auditoría propia en `wp_edu_audit`).

---

## ADR-003: Modelo Mineduc 3 trimestres x 2 parciales 70/30

- **Fecha:** 2026-05 (retroactiva)
- **Estado:** Aceptada

### Contexto
Ecuador tiene un modelo oficial de evaluación: 3 trimestres, cada uno con 2 parciales que ponderan 70% y un examen final que pondera 30%. Es normativa Mineduc; cambiarla invalida los boletines oficiales.

### Alternativas
1. Modelo configurable por institución.
2. Modelo fijo Mineduc.
3. Ambos (Mineduc + custom por institución).

### Decisión
Modelo Mineduc **fijo** en `Score_Calculator_Service`. Custom no soportado en v1.

### Consecuencias
- ✅ Boletines legalmente válidos.
- ✅ Cálculos deterministas y testeables.
- ⚠️ Colegios internacionales no ecuatorianos no pueden usar el plugin sin fork.
- 🔒 Cualquier cambio requiere ADR nuevo + validación con Mineduc.

---

## ADR-004: Catálogo de materias precargado vs libre

- **Fecha:** 2026-05 (retroactiva)
- **Estado:** Aceptada

### Contexto
Cada colegio necesita crear materias, pero el Mineduc define un pensum oficial por subnivel. Dejar libre invita a inconsistencia; forzar pensum impide flexibilidad de BT (Bachillerato Técnico).

### Alternativas
1. Todo libre.
2. Todo del catálogo oficial.
3. **Catálogo precargado + opción `is_custom=TRUE` para propias.**

### Decisión
Opción 3. `wp_edu_subjects_catalog` con 18 materias oficiales precargadas en la activación; `wp_edu_subjects` referencia con `catalog_id` (NULL si es propia).

### Consecuencias
- ✅ Reportes al Mineduc consistentes.
- ✅ Flexibilidad para materias técnicas locales.
- ⚠️ Sync manual si Mineduc actualiza el catálogo → tarea de mantenimiento anual.

---

## ADR-005: Collation heredada de WordPress

- **Fecha:** 2026-05 (retroactiva)
- **Estado:** Aceptada

### Contexto
WP core usa `utf8mb4_unicode_520_ci` en la mayoría de instalaciones. Forzar `utf8mb4_unicode_ci` en `wp_edu_*` rompe JOINs con `wp_users`.

### Decisión
Cada `CREATE TABLE` termina con `{$wpdb->get_charset_collate()}`.

### Consecuencias
- ✅ Consistencia automática.
- ✅ JOINs con `wp_users`, `wp_usermeta` sin castear.
- ⚠️ Tests corren contra misma collation que producción.

---

## ADR-006: Envíos masivos asíncronos con Action Scheduler

- **Fecha:** 2026-05 (retroactiva)
- **Estado:** Aceptada

### Contexto
Un comunicado a un grado de 30 alumnos son 30-60 emails + eventualmente 30-60 WhatsApps. Un comunicado institucional a 1000 alumnos son 2000+ envíos. Enviar síncrono agota timeout PHP.

### Alternativas
1. Envío directo síncrono.
2. WP-Cron nativo.
3. **Action Scheduler** (librería de WooCommerce, escala mucho mejor).

### Decisión
Action Scheduler. Cada comunicado se descompone en 1 job por destinatario, throttled a N/min según proveedor.

### Consecuencias
- ✅ Envío garantizado incluso con miles de destinatarios.
- ✅ Reintentos automáticos con backoff.
- ✅ UI de monitoreo integrada.
- ⚠️ Añade dependencia Composer.
- ⚠️ Necesita cron real del servidor (no WP-Cron por request) para volúmenes altos.

---

## ADR-007: Integración con Flipbook por lectura de tablas `wp_edu_*`

- **Fecha:** 2026-05 (retroactiva)
- **Estado:** Aceptada

### Contexto
El plugin Flipbook necesita saber qué materia/grado tiene un libro, qué estudiantes pertenecen a un grado, y escribir notas cuando el alumno completa un libro. Podría haber SDK propio, hooks, o lectura directa de tablas.

### Alternativas
1. SDK PHP `Edu_SDK` que Flipbook incluye.
2. Hooks públicos con `do_action` / `apply_filters`.
3. **Lectura directa de tablas `wp_edu_*` desde Flipbook, con detección runtime.**

### Decisión
Opción 3. Flipbook lee `wp_edu_students`, `wp_edu_subjects`, `wp_edu_grades` directamente. Escribe en `wp_edu_submissions` y `wp_edu_grades_log`. PromoSchool **no conoce** a Flipbook.

### Consecuencias
- ✅ Sin acoplamiento en PromoSchool (puede evolucionar sin coordinar).
- ✅ Flipbook standalone: funciona sin PromoSchool con FK NULL.
- ⚠️ Contrato implícito: cambios en el esquema `wp_edu_*` pueden romper Flipbook.
  → Mitigación: `INTEGRATION-FLIPBOOK.md` documenta el contrato y toda migración debe revisarlo.
- ⚠️ Escritura desde Flipbook sin pasar por servicios de PromoSchool salta lógica (auditoría, cálculo).
  → Mitigación: Flipbook escribe usando `edu_grades_log_insert()` (helper público) que sí dispara los hooks correctos.

---

_(Añadir aquí ADR-008 en adelante conforme salgan decisiones nuevas.)_
