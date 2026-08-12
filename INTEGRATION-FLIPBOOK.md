# INTEGRATION-FLIPBOOK — Contrato con el plugin Flipbook

> Este documento define los puntos de contacto entre PromoSchool y el plugin Flipbook.
> **Cualquier cambio en las tablas o hooks listados aquí es breaking para Flipbook.**
> Al modificar `wp_edu_*` revisar este archivo y avisar en el CHANGELOG con sección `Compat`.

## Principio

**PromoSchool NO conoce a Flipbook.** Es Flipbook quien detecta la existencia de PromoSchool con `SHOW TABLES LIKE 'wp_edu_students'` y consume lo que necesita. PromoSchool debe seguir siendo standalone.

Sin embargo, mantenemos un **contrato implícito** de estabilidad sobre las estructuras y helpers que Flipbook usa. Este archivo lo documenta.

---

## 1. Tablas leídas por Flipbook (solo SELECT)

Estas tablas y sus columnas están congeladas para Flipbook. Añadir columnas nuevas es seguro; renombrar / eliminar / cambiar tipo es **breaking**.

### `wp_edu_institutions`
| Columna | Uso en Flipbook |
|---|---|
| `id` | FK opcional `wp_flipbook_books.institution_id` |
| `name` | Selector agrupado por institución en el admin |

### `wp_edu_subjects`
| Columna | Uso |
|---|---|
| `id` | FK opcional `wp_flipbook_books.subject_id` |
| `institution_id` | Filtro |
| `name` | Selector |

### `wp_edu_grades`
| Columna | Uso |
|---|---|
| `id` | Target de `wp_flipbook_assignments.target_id` cuando `target_type='edu_grade'` |
| `institution_id` | Agrupar cursos por colegio en admin |
| `name`, `paralelo` | Mostrar "5to EGB · A" |
| `sub_level` | Filtro Mineduc |

### `wp_edu_students`
| Columna | Uso |
|---|---|
| `id`, `user_id` | Resolver "libros del alumno logueado" |
| `grade_id` | Vista `v_flipbook_books_for_student` |
| `status` | Solo `status='active'` |

### `wp_edu_teachers`
| Columna | Uso |
|---|---|
| `user_id` | Resolver "docente de este grado" para cruce con anotaciones compartidas |

### `wp_edu_teacher_assignments`
| Columna | Uso |
|---|---|
| `teacher_id`, `grade_id`, `subject_id`, `period_id`, `is_active` | Alcance por grado de anotaciones docente (Flipbook V5) |

### `wp_edu_assignments`
| Columna | Uso |
|---|---|
| `id` | Target de hotspot `type='edu_task'` y (futuro) `edu_activity` |
| `title` | Mostrar en popup del hotspot |

---

## 2. Tablas escritas por Flipbook

Flipbook escribe (INSERT/UPDATE) en estas tablas. Cambiar sus columnas es **breaking**.

### `wp_edu_submissions`
Flipbook inserta una submission cuando el alumno completa un libro asociado a `edu_task`.

| Columna | Valor que Flipbook escribe |
|---|---|
| `assignment_id` | Del payload del hotspot |
| `student_id` | Del alumno logueado |
| `status` | `submitted` |
| `submitted_at` | NOW |
| `comment` | Opcional (autoguardado del progreso) |

### `wp_edu_grades_log`
Cuando el libro tiene puntuación calculada, Flipbook la registra aquí vía helper público (ver sección 3).

| Columna | Valor |
|---|---|
| `student_id`, `component_id`, `score` | Del cálculo Flipbook |
| `assignment_id` | Opcional |
| `registered_by` | ID del docente autor del libro (no del alumno) |

### `wp_edu_audit`
Cada vez que Flipbook mueve una nota, escribe entrada de auditoría con `action='flipbook_grade_written'`.

---

## 3. Helpers PHP públicos (contrato de API)

PromoSchool DEBE exponer estas funciones. Su firma no cambia sin ADR + bump de versión mayor.

```php
// Devuelve true si PromoSchool está activo y sus tablas existen.
function edu_is_active(): bool;

// Devuelve datos del estudiante desde su user_id de WP.
function edu_get_student( int $user_id ): ?object;

// Devuelve datos del docente desde su user_id.
function edu_get_teacher( int $user_id ): ?object;

// Devuelve los grados donde ese docente enseña en el período activo.
function edu_get_teacher_grades( int $teacher_user_id ): array;

// Inserta una nota en wp_edu_grades_log disparando hooks + auditoría.
// Devuelve el ID insertado o WP_Error.
function edu_grades_log_insert( array $payload ): int|WP_Error;

// Devuelve las materias de un grado dado.
function edu_get_grade_subjects( int $grade_id ): array;

// Devuelve el período activo de una institución.
function edu_get_active_period( int $institution_id ): ?object;
```

---

## 4. Hooks públicos

PromoSchool dispara estos hooks; Flipbook (u otros) pueden engancharse.

| Hook | Cuándo | Params |
|---|---|---|
| `edu/student/enrolled` | Nueva matrícula | `int $student_id` |
| `edu/student/status_changed` | active → transferred/graduated | `int $student_id, string $old, string $new` |
| `edu/grade/registered` | Nota nueva en `wp_edu_grades_log` | `int $log_id, array $payload` |
| `edu/parcial/closed` | Parcial cierra | `int $parcial_id` |
| `edu/trimester/closed` | Trimestre cierra | `int $trimester_id` |
| `edu/attendance/registered` | Asistencia registrada | `int $attendance_id` |
| `edu/announcement/sent` | Comunicado enviado | `int $announcement_id` |

Flipbook escucha `edu/student/status_changed` para invalidar cachés de "libros del alumno".

---

## 5. Vistas SQL creadas por Flipbook sobre tablas `wp_edu_*`

Flipbook crea 2 vistas que hacen JOIN con `wp_edu_*`. Si PromoSchool renombra estas tablas, las vistas se rompen:

```sql
CREATE OR REPLACE VIEW v_flipbook_books_for_student AS
  SELECT DISTINCT b.id, b.title, ..., s.id AS student_id, s.grade_id
  FROM wp_flipbook_books b
  JOIN wp_flipbook_assignments a ON a.book_id = b.id
  JOIN wp_edu_students s ON (
    (a.target_type = 'edu_grade' AND a.target_id = s.grade_id)
    OR (a.target_type = 'user' AND a.target_id = s.user_id)
  )
  WHERE b.status = 'published' AND s.status = 'active';
```

Si `wp_edu_students` deja de tener `user_id` o `grade_id`, esta vista falla.

---

## 6. Checklist antes de cambiar esquema `wp_edu_*`

Cuando Claude Code toque el esquema:

- [ ] ¿La columna/tabla cambiada aparece en sección 1 o 2 de este archivo?
- [ ] Si sí: proponer alias/vista de compatibilidad o coordinar release con Flipbook.
- [ ] Actualizar este archivo con la nueva estructura.
- [ ] Añadir entrada en `CHANGELOG.md` sección `Compat`.
- [ ] Avisar en chat: "OJO: cambio afecta a Flipbook, revisar su `class-promoschool-bridge.php`".

---

## 7. Roadmap de integración (nuevas features)

Cuando PromoSchool añada estas features, Flipbook las consumirá:

| Feature futura | Consumo Flipbook | Cuándo |
|---|---|---|
| Módulo Actividades interactivas (`wp_edu_activities`) | Hotspot `type='edu_activity'` con `payload.activity_id` | Cuando exista el CRUD y REST |
| Boletines PDF | Botón "Ver boletín" en dashboard alumno del visor | Cuando exista `edu_get_boletin_url()` |
| Chat docente-padre | Notificación en visor del alumno | Cuando exista `wp_edu_messages` |
| Pagos en línea | Bloqueo de libros si mensualidad vencida | Cuando exista `edu_is_student_paid_up()` helper |

Cada feature nueva que Flipbook consuma debe añadirse a las secciones 1, 2 o 3 arriba **antes** de que Flipbook empiece a usarla.
