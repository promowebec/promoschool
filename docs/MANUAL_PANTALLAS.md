# Manual de pantallas · Sistema Educativo Integral

Documento de referencia pantalla por pantalla. Sirve como manual de usuario, guion de
demostración comercial y checklist para capturas de pantalla (dípticos, propuestas).

- **Versión del plugin:** 1.1.0
- **Fecha:** 29 de julio de 2026
- **Total:** 25 pantallas de backend + 1 subvista + 4 portales frontend (30 pestañas) + 2 shortcodes sueltos + 1 pantalla pública

**Cómo leer cada ficha**

| Campo | Significado |
|---|---|
| Ruta | Dónde está la pantalla |
| Quién entra | Rol y capability requerida |
| Para qué sirve | Propósito en una frase |
| Qué se ve | Bloques, filtros, columnas |
| Acciones | Botones y qué hacen |
| Tablas | Tablas de base de datos que lee/escribe |
| Captura | Sugerencia para material comercial |

---

# PARTE A · Backend WordPress

Menú lateral **Sistema Educativo** (icono de birrete, posición 30). Entran solo administrador WP, `edu_rector` y `edu_docente`. WordPress oculta automáticamente los submenús cuya capability el usuario no tiene, así que **cada rol ve un menú distinto**.

Los ítems de módulos apagados desaparecen del menú (ver A.25 Ajustes).

---

## A.1 · Inicio

- **Ruta:** `Sistema Educativo → Inicio` (`admin.php?page=edu-inicio`) · `admin/views/inicio.php`
- **Quién entra:** todos los que acceden al plugin (cap `read`)
- **Para qué sirve:** dashboard de entrada, distinto según el rol.

**Qué se ve — perfil rector / administrador**
- Tarjetas de métricas institucionales: estudiantes activos, docentes, grados, materias del pensum, período lectivo vigente.
- Bloque de alertas de asistencia baja del mes.
- Accesos rápidos a las pantallas más usadas.

**Qué se ve — perfil docente**
- Asistencia del día pendiente de tomar.
- Accesos rápidos a Calificaciones, Tareas y Asistencia.

- **Tablas:** lectura agregada sobre `students`, `teachers`, `grades`, `subjects`, `attendance`, `periods`
- **Captura:** sí — es la primera impresión del sistema.

---

## A.2 · Institución

- **Ruta:** `page=edu-institucion` · `admin/views/institucion.php`
- **Quién entra:** `edu_view_all` (rector, admin)
- **Para qué sirve:** datos de la unidad educativa.

**Qué se ve**
- **Superadmin Editorial:** listado de todas las instituciones con crear / editar / eliminar / activar.
- **Rector:** formulario directo sobre su institución, sin selector.
- Campos: nombre, RUC, dirección, teléfono, email, URL del logo, régimen (`sierra` / `costa`).

**Acciones:** Guardar · Eliminar · Activar (fija la institución de trabajo en `user_meta.edu_current_institution_id`)

- **Tablas:** `institutions`
- **Captura:** sí — muestra que es multi-institución.

---

## A.3 · Períodos lectivos

- **Ruta:** `page=edu-periodos` · `admin/views/periodos.php`
- **Quién entra:** `edu_manage_grades`
- **Para qué sirve:** definir el año lectivo y sus trimestres.

**Qué se ve**
- Tabla de períodos: nombre (ej. 2026-2027), régimen, fecha inicio, fecha fin, días laborables (200 por defecto), número de trimestres (3), activo sí/no.
- Al crear un período se generan automáticamente sus trimestres.

**Acciones:** Guardar · Eliminar · **Activar período** (define el período de trabajo del sistema)

- **Tablas:** `periods`, `trimesters`
- **Captura:** sí — evidencia el calendario Mineduc sierra/costa.

---

## A.4 · Grados y paralelos

- **Ruta:** `page=edu-grados` · `admin/views/grados.php`
- **Quién entra:** `edu_manage_grades`
- **Para qué sirve:** estructura académica de la institución.

**Qué se ve**
- Tabla de grados: subnivel (`inicial`, `preparatoria`, `elemental`, `media`, `superior`, `bg`, `bt`), nombre, paralelo (A/B/C/D), especialidad (solo para `bt`), tutor.
- Bloque de **importación CSV** con plantilla descargable.

**Acciones:** Guardar · Eliminar · Descargar plantilla CSV · Importar CSV

- **Tablas:** `grades`
- **Captura:** sí — el subnivel es el que decide qué fórmula de nota se aplica.

---

## A.5 · Materias

- **Ruta:** `page=edu-materias` · `admin/views/materias.php`
- **Quién entra:** `edu_manage_subjects`
- **Para qué sirve:** elegir qué materias maneja la institución.

**Qué se ve**
- Catálogo oficial Mineduc agrupado por subnivel (18 materias precargadas), cada una con botón **Adoptar**.
- Bloque separado de **materias propias** de la institución (`is_custom = 1`) — aquí entran optativas, club, refuerzo, catequesis, etc.

**Acciones:** Adoptar materia del catálogo · Crear materia propia · Editar · Eliminar · Repoblar catálogo

- **Tablas:** `subjects_catalog` (global), `subjects` (por institución)
- **Captura:** sí — demuestra cumplimiento curricular + flexibilidad para materias propias.

---

## A.6 · Docentes

- **Ruta:** `page=edu-docentes` · `admin/views/docentes.php`
- **Quién entra:** `edu_manage_teachers`
- **Para qué sirve:** registro del personal docente.

**Qué se ve:** tabla con nombre (desde `wp_users`), cédula, título, especialidad, teléfono, email, estado. Bloque de importación CSV.

**Acciones:** Guardar (crea el usuario WP con rol `edu_docente` si no existe) · Eliminar · Plantilla CSV · Importar CSV

- **Tablas:** `teachers` + `wp_users` / `wp_usermeta`
- **Nota importante:** los nombres de las personas viven en `wp_usermeta`, no en las tablas `edu_`.

---

## A.7 · Estudiantes

- **Ruta:** `page=edu-estudiantes` · `admin/views/estudiantes.php`
- **Quién entra:** `edu_manage_students`
- **Para qué sirve:** nómina estudiantil.

**Qué se ve:** tabla con nombre, cédula, grado y paralelo, fecha de nacimiento, sexo, dirección, estado (activo / retirado). Filtros por grado. Importación CSV.

**Acciones:** Guardar (crea usuario `edu_estudiante`) · Eliminar · Plantilla CSV · Importar CSV

- **Tablas:** `students` + `wp_users`
- **Captura:** sí — la importación CSV es un argumento de venta fuerte (matrícula masiva).

---

## A.8 · Representantes

- **Ruta:** `page=edu-padres` · `admin/views/padres.php`
- **Quién entra:** `edu_manage_parents`
- **Para qué sirve:** representantes legales y su vínculo con estudiantes.

**Qué se ve:** tabla de representantes (nombre, cédula, teléfono, WhatsApp, email, parentesco) y la lista de estudiantes vinculados a cada uno. Un representante puede tener varios hijos y un estudiante varios representantes.

**Acciones:** Guardar (crea usuario `edu_padre`) · Vincular / desvincular estudiante · Eliminar · Plantilla CSV · Importar CSV

- **Tablas:** `parents`, `parent_student`
- **Nota:** el campo WhatsApp es el que usan las notificaciones del módulo WhatsApp.

---

## A.9 · Asignaciones académicas

- **Ruta:** `page=edu-asignaciones` · `admin/views/asignaciones.php`
- **Quién entra:** `edu_manage_assignments`
- **Para qué sirve:** definir **qué docente dicta qué materia en qué grado** durante qué período. Es la pantalla que habilita todo lo demás para el docente.

**Qué se ve:** tabla docente ↔ grado ↔ materia ↔ período, con estado activo/inactivo.

**Acciones:** Guardar · Desactivar · Eliminar

- **Tablas:** `teacher_assignments`
- **Por qué importa:** el docente **solo ve** los grados, materias y estudiantes de sus asignaciones activas. Toda la seguridad del portal docente cuelga de esta tabla.
- **Captura:** sí.

---

## A.10 · Comunicados

- **Ruta:** `page=edu-comunicados` · `admin/views/comunicados.php` — módulo `comunicados`
- **Quién entra:** `edu_send_grade_announcement` (docente) o `edu_view_all` (rector)
- **Para qué sirve:** enviar avisos a representantes y estudiantes con constancia de lectura.

**Tres vistas por parámetro `action`**
- `list` — tabla de comunicados enviados con % de lectura.
- `new` — formulario: destino (institucional / por grado / por estudiante), asunto, cuerpo, exigir acuse sí/no.
- `detail` — lista de destinatarios con estado leído / no leído y fecha del acuse.

**Acciones:** Enviar · Eliminar · Ver detalle

- **Tablas:** `announcements`, `announcement_recipients`, `announcement_templates`
- **Dispara:** `edu_announcement_sent` → cola de emails y, si el módulo está activo, WhatsApp.
- **Captura:** sí — la pantalla de detalle con el acuse de recibo es muy vendedora.

---

## A.11 · Pensum

- **Ruta:** `page=edu-pensum` · `admin/views/pensum.php`
- **Quién entra:** `edu_manage_curriculum` (rector)
- **Para qué sirve:** decidir **qué materias se dictan en cada grado** y con cuántas horas.

**Qué se ve:** selector de grado → lista de todas las materias de la institución, cada una con checkbox *Incluir en pensum* y campo *horas/semana*. Guardado en bloque.

- **Tablas:** `grade_subjects`
- **Clave para instituciones con pocas materias:** aquí se deja marcadas solo las materias que el colegio realmente dicta. Lo que no se marca no aparece en calificaciones, boletines ni portales para ese grado.
- **Captura:** sí.

---

## A.12 · Componentes evaluables

- **Ruta:** `page=edu-componentes` · `admin/views/componentes.php`
- **Quién entra:** `edu_manage_curriculum` (rector, gestiona todo) o `edu_grade_students` (docente, solo sus materias)
- **Para qué sirve:** definir **de qué se compone la nota de cada parcial**.

**Qué se ve**
- Tres selectores: Materia → Trimestre → Parcial (1 o 2).
- Tabla editable con columnas: **Componente** · **Peso (0.00 – 1.00)** · **Origen** · **Notas** · acción Quitar.
- Pie con la suma de pesos en verde si es 1.00, en ámbar si no.
- Botón **+ Agregar componente**.

**Reglas de negocio**
- **Origen institucional** (`created_by = 0`): lo define el rectorado; el docente lo ve en gris, solo lectura.
- **Origen docente** (`created_by = user_id`): el docente lo crea, edita y borra libremente.
- Un componente con notas registradas **no se puede eliminar** (candado 🔒 con el número de notas).
- **Los pesos no tienen que sumar 1.00.** El cálculo renormaliza dividiendo por la suma de los pesos que sí tienen nota, y los componentes sin nota se excluyen (no cuentan como cero).
- **Solo importa la proporción entre los pesos.** Dejarlos todos en 1.00 equivale a dejarlos todos en 0.33: todos pesan igual. El peso por defecto es 1.00.
- **Esta pantalla es opcional para el docente.** Desde v1.1.0 puede crear el componente directamente al crear la tarea (A.13). Aquí se revisa el set completo del parcial y se ajustan pesos.

- **Tablas:** `grade_components` (lee `grades_log` para el conteo)
- **Captura:** sí — es la pantalla que explica el modelo de evaluación.

---

## A.13 · Tareas y actividades

- **Ruta:** `page=edu-tareas` · `admin/views/tareas.php` — módulo `tareas`
- **Quién entra:** `edu_create_assignment` (docente) o `edu_view_all`
- **Para qué sirve:** crear tareas, lecciones, trabajos, deberes, exámenes y correcciones, con archivos adjuntos y recepción de entregas.

**Cuatro vistas por parámetro `action`**

**`list`** — tabla filtrable por grado / materia / trimestre: título, tipo, parcial, fecha de entrega, estado (borrador / publicada / cerrada), número de entregas recibidas.

**`new` / `edit`** — formulario con estos campos, en este orden:
1. Grado
2. Materia
3. Trimestre
4. Parcial (1 o 2)
5. **Se evalúa como** — un solo campo con tres modos:
   - un componente ya existente de esa materia/trimestre/parcial,
   - **`➕ Crear componente nuevo…`** (opción por defecto) → aparece un campo de nombre y el componente se crea al guardar la tarea, con el mismo peso que los demás,
   - *Sin vincular (no cuenta para la nota)*.
6. Título, descripción
7. Fecha de entrega, nota máxima (10.00 por defecto)
8. Notificar a representantes
9. Adjuntos (máx. 10 MB: pdf, doc/docx, ppt/pptx, xls/xlsx, jpg, png, zip)
10. Configuración de mejora/recuperación (permitir, fecha límite)

**Acciones:** Guardar · Publicar · Cerrar · Eliminar · Descargar adjunto (URL con nonce)

- **Tablas:** `assignments`, `assignment_files`; puede insertar en `grade_components`
- **Punto crítico:** este campo es lo que convierte la tarea en nota. Si queda *Sin vincular*, la tarea se califica pero **no alimenta el parcial**.
- **Ya no se pide "Tipo".** La columna `type` se rellena sola deduciéndola del nombre del componente (prueba/quiz → examen, proyecto → trabajo, mejora → corrección…). Sigue existiendo para los filtros del listado y los exportes Mineduc.
- **Nombre repetido = mismo componente.** Si se escribe un nombre que ya existe en ese parcial, no se duplica: se reutiliza y las notas de ambas actividades se promedian dentro de ese componente.
- **Captura:** sí, el formulario completo.

---

## A.14 · Tareas → Detalle de entregas *(subvista)*

- **Ruta:** `page=edu-tareas&action=detail&id=N` · `admin/views/tareas-detalle.php`
- **Para qué sirve:** ver y calificar las entregas de una tarea.

**Qué se ve:** por estudiante — estado (pendiente / entregada / calificada / devuelta / atrasada), fecha de entrega, archivos subidos, comentario del estudiante, input de nota, campo de retroalimentación. Bloque separado de mejoras/recuperaciones.

**Acciones:** Calificar · Devolver con observaciones · Descargar archivo del estudiante · Calificar mejora

- **Tablas:** `submissions`, `submission_files`
- **Efecto en cadena:** al guardar la nota, si la tarea tiene `component_id`, se escribe una fila en `grades_log` y se dispara `edu_grade_logged` → recálculo del parcial.
- **Captura:** sí — muestra el ciclo completo tarea → entrega → nota.

---

## A.15 · Calificaciones

- **Ruta:** `page=edu-calificaciones` · `admin/views/calificaciones.php`
- **Quién entra:** `edu_grade_students` o `edu_view_all`
- **Para qué sirve:** captura masiva de notas.

**Qué se ve**
- Filtros encadenados: Grado → Materia → Trimestre → Parcial. El docente solo ve lo de sus asignaciones activas.
- **Matriz estudiantes (filas) × componentes (columnas)**. Cada celda es un input 0–10 que muestra el promedio actual registrado en ese componente. Dejar la celda vacía = no modificar.
- Columna final con la nota del parcial calculada y su equivalencia cualitativa (A+ … E-) con color.

**Acciones:** Guardar notas (batch)

- **Tablas:** escribe `grades_log`; actualiza `parcial_scores` por el recálculo
- **Captura:** sí — **la pantalla más importante de la demo**.

---

## A.16 · Examen final

- **Ruta:** `page=edu-examen-final` · `admin/views/examen-final.php`
- **Quién entra:** `edu_grade_students` o `edu_view_all`
- **Para qué sirve:** capturar el 30% sumativo del trimestre.

**Qué se ve:** filtros Grado → Materia → Trimestre. Tabla por estudiante con: Parcial 1, Parcial 2 (solo lectura), input **Examen final**, input **Proyecto** (solo si el subnivel es `media`, `superior`, `bg` o `bt`), y nota del trimestre calculada con su equivalencia cualitativa.

**Fórmula aplicada según subnivel**
```
inicial / preparatoria / elemental:  ((P1+P2)/2) × 0.70 + Examen × 0.30
media / superior / bg / bt:          ((P1+P2)/2) × 0.70 + ((Examen+Proyecto)/2) × 0.30
```

- **Tablas:** `trimester_scores` (campos `final_exam_score`, `proyecto_score`, `computed_score`)
- **Captura:** sí — demuestra cumplimiento del Instructivo 2025.

---

## A.17 · Asistencia

- **Ruta:** `page=edu-asistencia` · `admin/views/asistencia.php` — módulo `asistencia`
- **Quién entra:** `edu_take_attendance` o `edu_view_all`
- **Para qué sirve:** tomar lista.

**Qué se ve**
- Selector de grado + fecha → tabla de estudiantes con radio buttons: presente / ausente / atraso / justificado.
- Bloque de historial del mes en curso filtrado por grado, con % de asistencia.

**Acciones:** Guardar asistencia

- **Tablas:** `attendance`
- **Efecto:** una ausencia puede disparar `edu_attendance_absence` → notificación WhatsApp al representante.
- **Captura:** sí — junto con Calificaciones es lo mínimo que pide cualquier colegio.

---

## A.18 · Panel de docentes

- **Ruta:** `page=edu-panel-docentes` · `admin/views/panel-docentes.php`
- **Quién entra:** solo `edu_view_all` (rector, admin)
- **Para qué sirve:** supervisión del trabajo docente. Responde "¿quién está cargando notas y quién no?".

**Qué se ve**
- Filtros por grado y por docente.
- Una fila por asignación académica (docente + grado + materia) con: número de componentes del parcial (institucionales y propios), tareas creadas y cuántas alimentan notas, notas registradas, **% de avance de calificación** sobre el total de estudiantes, última actividad.
- Con `?edu_pd_detail=<id>` se expande el detalle por componente: peso, origen, notas registradas, promedio y tareas vinculadas.

- **Tablas:** lectura sobre `teacher_assignments`, `grade_components`, `grades_log`, `assignments`, `students`
- **Captura:** sí — **es el argumento de venta para el rector**: control real de la carga de notas.

---

## A.19 · Cierres

- **Ruta:** `page=edu-cierres` · `admin/views/cierres.php`
- **Quién entra:** `edu_close_partial` (rector)
- **Para qué sirve:** congelar notas.

**Qué se ve:** filtros Grado → Materia → Trimestre. Tabla con el estado de cada parcial (abierto / cerrado, cuántos estudiantes ya tienen nota) y botones de cierre. El botón de **cierre de trimestre** solo se habilita cuando ambos parciales están cerrados.

**Acciones:** Cerrar parcial 1 · Cerrar parcial 2 · Cerrar trimestre

- **Tablas:** `parcial_scores.is_closed`, `trimester_scores.is_closed`
- **Dispara:** `edu_partial_closed` → recálculo de trimestre · `edu_trimester_closed` → recálculo anual + notificación WhatsApp de notas
- **Efecto:** un parcial cerrado ya no se recalcula ni admite escritura.
- **Captura:** sí.

---

## A.20 · Resumen anual

- **Ruta:** `page=edu-resumen-anual` · `admin/views/resumen-anual.php`
- **Quién entra:** `edu_generate_reports` / `edu_grade_students`
- **Para qué sirve:** consolidado del año y gestión de recuperaciones.

**Qué se ve:** filtros Grado → Materia → Período. Tabla por estudiante con T1, T2, T3, promedio anual, equivalencia cualitativa y **estado**: aprobado / supletorio / remedial / gracia / reprobado. Para los estudiantes en recuperación aparece un input inline para la nota del examen que corresponde a su estado actual.

```
Nota_Anual = (T1 + T2 + T3) / 3
≥ 7 → aprobado · 5 a 6.99 → supletorio
reprueba supletorio → remedial · reprueba remedial → gracia · reprueba gracia → reprobado
```

**Acciones:** Guardar notas de recuperación (batch)

- **Tablas:** `year_scores`
- **Captura:** sí.

---

## A.21 · Boletines PDF

- **Ruta:** `page=edu-boletines` · `admin/views/boletines.php` — módulo `boletines`
- **Quién entra:** `edu_generate_reports` o `edu_view_all`
- **Para qué sirve:** emitir libretas oficiales.

**Qué se ve:** selector de período + grado → lista de estudiantes con enlace de descarga individual y botón de **descarga masiva en ZIP** de todo el grado.

**Contenido del PDF** (`Edu_Boletin_Generator`, mPDF): encabezado con datos y logo de la institución, datos del estudiante, tabla de materias con P1/P2/Examen/Proyecto/nota de trimestre por cada trimestre, promedio anual, equivalencia cualitativa, estado y observaciones.

- **Tablas:** lectura de `trimester_scores`, `year_scores`, `students`, `subjects`, `institutions`
- **Captura:** sí — **el PDF impreso es lo que más convence a un colegio.**

---

## A.22 · Exportes Mineduc

- **Ruta:** `page=edu-exportes` · `admin/views/exportes-mineduc.php` — módulo `exportes`
- **Quién entra:** `edu_generate_reports` o `edu_view_all`
- **Para qué sirve:** descargar reportes .xlsx compatibles con SIME/AMIE.

**Cuatro reportes:** acta consolidada de calificaciones · nómina de estudiantes (AMIE) · distributivo docente · asistencia acumulada.

**Qué se ve:** selector de tipo de reporte + período (+ grado cuando aplica) → botón Descargar.

- **Implementación:** `Edu_Xlsx_Writer`, escritor .xlsx propio sin dependencias (requiere `ext-zip`). **No se usa PhpSpreadsheet.**
- **Captura:** sí — ahorro de trabajo administrativo real.

---

## A.23 · Cuentas

- **Ruta:** `page=edu-cuentas` · `admin/views/cuentas.php` — módulo `cuentas`
- **Quién entra:** `edu_view_all`
- **Para qué sirve:** activar o suspender el acceso de representantes y estudiantes.

**Qué se ve:** listado de usuarios con rol, estudiante vinculado, estado (activa / suspendida) y filtros. Acciones individuales y **masivas**.

**Acciones:** Suspender · Activar · Acción masiva

- **Tablas:** `wp_usermeta.edu_account_status`
- **Reglas:** suspender a un representante **suspende en cascada a sus hijos**. Una cuenta suspendida no puede iniciar sesión (filtro `authenticate`, prioridad 30, compatible con Ultimate Member).
- **Captura:** opcional.

---

## A.24 · Pagos

- **Ruta:** `page=edu-pagos` · `admin/views/pagos.php` — módulo `pagos`
- **Quién entra:** `edu_view_all`
- **Para qué sirve:** pensiones y matrículas con cobro en línea.

**Qué se ve**
- **Dashboard:** recaudado del mes, pendiente, vencido, número de estudiantes en mora.
- **Listado de pagos:** estudiante, concepto (matrícula / pensión), mes, monto, vencimiento, estado (pending / paid / overdue / waived), método, referencia.
- **Configuración:** credenciales Payphone (con botón de prueba de conexión), valores de pensión y matrícula por grado, día de vencimiento y días de gracia.
- **Herramientas:** generar pagos del mes, registrar pago manual, generar link de pago, exonerar pago, suspender cuentas con mora.

- **Tablas:** `payments`, `payment_config`
- **Automatismos:** cron diario `edu_payment_daily_cron` (marca vencidos, dispara `edu_payment_overdue`) y webhook REST de confirmación Payphone. Un pago solo se marca pagado por `confirm_and_mark_paid()`.
- **Captura:** sí, el dashboard.

---

## A.25 · Auditoría

- **Ruta:** `page=edu-auditoria` · `admin/views/auditoria.php`
- **Quién entra:** `edu_view_audit` (rector) o admin WP
- **Para qué sirve:** trazabilidad de cambios sensibles, sobre todo notas.

**Qué se ve:** log completo con filtros por fecha, acción y usuario. Cada fila: fecha/hora, usuario, acción, tipo de entidad, ID, valor anterior → valor nuevo.

- **Tablas:** `audit`
- **Captura:** sí — es un argumento fuerte frente a reclamos de padres por notas.

---

## A.26 · Ajustes

- **Ruta:** `page=edu-ajustes` · `admin/views/ajustes.php`
- **Quién entra:** solo `manage_options` (administrador WP)
- **Para qué sirve:** configuración global del plugin.

**Secciones**

**1. Módulos del sistema** — un checkbox *Activo* por módulo:

| Módulo | Qué controla |
|---|---|
| `tareas` | Tareas, entregas con archivos y su calificación |
| `comunicados` | Comunicados con acuse de recibo |
| `asistencia` | Registro de asistencia |
| `boletines` | Boletines PDF individuales y ZIP |
| `pagos` | Pensiones, matrículas, Payphone, morosidad |
| `whatsapp` | Notificaciones por WhatsApp |
| `cuentas` | Suspensión/activación de cuentas |
| `exportes` | Reportes Excel SIME/AMIE |
| `pwa` | Manifest y service worker de app móvil |
| `textos` | Tab "Mis textos" (Flipbook) |

Un módulo apagado desaparece del menú admin y de los tabs de los portales, y detiene sus crons y notificaciones. **Los datos no se borran**: al reactivarlo todo vuelve.

**Núcleo no desactivable:** calificaciones, personas, pensum, componentes, cierres, resumen anual, auditoría.

**2. Páginas del portal** — a qué página de WordPress corresponde `[edu_mis_tareas]` y `[edu_mis_comunicados]` (para las redirecciones tras entregar).

**3. Páginas de portales (PWA)** — página de cada uno de los 4 portales, para inyectar el manifest solo ahí y armar los atajos del ícono de la app.

**4. Email de envío** — nombre y dirección del remitente (filtros `wp_mail_from` / `wp_mail_from_name`).

**5. WhatsApp Business** — proveedor (desactivado / Twilio / Meta), credenciales, nombres de las plantillas aprobadas por tipo de mensaje (comunicado, nota, pago, asistencia), idioma, y botón de prueba de envío por AJAX.

- **Opciones:** `edu_modules`, `edu_tareas_page_id`, `edu_comunicados_page_id`, `edu_email_from_*`, `edu_wa_*`
- **Captura:** sí — **la tabla de módulos es la pantalla clave para vender por etapas.**

---

# PARTE B · Portales frontend (shortcodes)

Se publican pegando el shortcode en una página de WordPress. Todos comparten el mismo layout: barra lateral con avatar, nombre, rol y menú de navegación con badges de pendientes; contenido a la derecha. Estilos en `public/css/portales.css`. Navegación por parámetro `?edu_tab=`.

Los tabs de módulos apagados se ocultan automáticamente (`Edu_Modules::filter_sidenav()`).

---

## B.1 · `[edu_portal_rector]` — Portal del Rector

`public/shortcodes/class-edu-shortcode-rector.php` · color de acento indigo (#4f46e5) · **10 tabs**

| # | Tab | Contenido |
|---|---|---|
| 1 | **Inicio** | Métricas institucionales, rendimiento por grado, alertas activas, **carga de notas por docente**, mejoras activas |
| 2 | **Rendimiento** | Comparativo por grado y materia con promedios y equivalencias cualitativas |
| 3 | **Alertas** | Grados con asistencia baja del mes · estudiantes en riesgo (nota < 7) · mejoras activas por tarea |
| 4 | **Comunicados** | Redactar comunicado institucional + historial con % de lectura |
| 5 | **Cierres** | Cierre de parciales, cierre de trimestre y captura de recuperación |
| 6 | **Auditoría** | Log de cambios con filtros |
| 7 | **Boletines** | Generación y descarga de boletines PDF (individual y ZIP) |
| 8 | **Resumen anual** | Consolidado T1/T2/T3, promedio, estado |
| 9 | **Cuentas** | Activar/suspender cuentas · badge con el número de suspendidas |
| 10 | **Pagos** | Dashboard de recaudación, registrar pago, suspender morosos, credenciales Payphone, valores por grado · badge de pendientes |

**Captura:** sí — Inicio y Pagos.

---

## B.2 · `[edu_portal_docente]` — Portal del Docente

`public/shortcodes/class-edu-shortcode-docente.php` · acento verde (#059669) · **7 tabs**

| # | Tab | Contenido |
|---|---|---|
| 1 | **Inicio** | Próximas entregas · acciones rápidas · alertas de estudiantes · mis grados asignados |
| 2 | **Mis materias** | Materias y grados de sus asignaciones activas, con avance |
| 3 | **Calificaciones** | Matriz estudiantes × componentes con captura de notas, igual que A.15 pero sin entrar al wp-admin |
| 4 | **Tareas y lecciones** | Crear tarea con el campo **"Se evalúa como"** (elegir componente o crear uno nuevo escribiendo el nombre), editar, publicar, cerrar, ver y calificar entregas · badge de entregas pendientes |
| 5 | **Asistencia** | Tomar lista por grado y fecha |
| 6 | **Comunicados** | Redactar comunicado a su grado o a un estudiante + historial |
| 7 | **Mis textos** | Ejecuta `do_shortcode('[mis_textos]')` — integración visual con el plugin Flipbook |

- **Seguridad:** todo se filtra por `teacher_assignments` con `is_active = 1`. Un docente nunca ve grados ni materias ajenas.
- **Captura:** sí — Calificaciones y Tareas. **Este portal es la demo principal para el cuerpo docente.**

---

## B.3 · `[edu_portal_estudiante]` — Portal del Estudiante

`public/shortcodes/class-edu-shortcode-estudiante.php` · acento ámbar (#d97706) · **6 tabs**

| # | Tab | Contenido |
|---|---|---|
| 1 | **Inicio** | Resumen del período y tareas pendientes |
| 2 | **Mis notas** | Notas por materia, parcial, trimestre, con equivalencia cualitativa |
| 3 | **Mis tareas** | Tareas pendientes y entregadas, subida de archivos, comentario, ver retroalimentación y **entregar mejora** · badge de pendientes |
| 4 | **Asistencia** | Resumen del mes + registro detallado |
| 5 | **Comunicados** | Comunicados recibidos con acuse de recibo |
| 6 | **Mis textos** | `[mis_textos]` (Flipbook) |

**Captura:** sí — Mis notas y Mis tareas.

---

## B.4 · `[edu_portal_padre]` — Portal del Representante

`public/shortcodes/class-edu-shortcode-padre.php` · **7 tabs**

Incluye **selector de hijo** (`?edu_hijo=`) cuando el representante tiene más de un estudiante vinculado.

| # | Tab | Contenido |
|---|---|---|
| 1 | **Inicio** | Últimas notas · comunicados sin leer |
| 2 | **Notas** | Notas del hijo por materia y trimestre con equivalencia cualitativa |
| 3 | **Tareas** | Tareas del hijo y su estado de entrega · badge de pendientes |
| 4 | **Asistencia** | Resumen del mes + historial |
| 5 | **Comunicados** | Comunicados con botón de **acuse de recibo** · badge de no leídos |
| 6 | **Boletines** | Descarga del boletín PDF del hijo |
| 7 | **Pagos** | Estado de cuenta, pago en línea con Payphone y retorno de la transacción · badge de pendientes |

**Captura:** sí — Notas y Pagos. **Este portal es el que justifica la inversión frente a los padres.**

---

## B.5 · Shortcodes sueltos

| Shortcode | Módulo | Para qué |
|---|---|---|
| `[edu_mis_tareas]` | `tareas` | Página independiente de tareas del estudiante (útil si no se quiere el portal completo) |
| `[edu_mis_comunicados]` | `comunicados` | Página independiente de comunicados con acuse de recibo |

---

# PARTE C · Pantalla pública sin login

## C.1 · Link de pago

- **Ruta:** cualquier URL del sitio con `?edu_pago_token=<token>` (`Edu_Payment_Manager::render_payment_link_page()` sobre `template_redirect`)
- **Quién entra:** cualquiera con el link (representantes sin cuenta activa incluidos)
- **Qué se ve:** detalle del pago (estudiante, concepto, mes, monto, vencimiento) y botón de pago Payphone.
- **Seguridad:** token de un solo propósito; la confirmación llega por el webhook REST y solo entonces se marca pagado.

---

# PARTE D · Guion sugerido para la demo y el díptico

Orden recomendado de capturas para una propuesta a un colegio (12 pantallas):

| # | Pantalla | Mensaje que transmite |
|---|---|---|
| 1 | A.1 Inicio (rector) | "Todo el colegio en una pantalla" |
| 2 | A.4 Grados y paralelos | "Estructura oficial Mineduc, sierra o costa" |
| 3 | A.5 Materias | "Catálogo oficial + sus materias propias y optativas" |
| 4 | A.11 Pensum | "Cada grado dicta solo lo que realmente dicta" |
| 5 | A.12 Componentes evaluables | "Ustedes definen cómo se compone la nota" |
| 6 | B.2 Portal docente → Calificaciones | "El docente sube notas desde el celular" |
| 7 | A.17 Asistencia | "Tomar lista en 30 segundos" |
| 8 | A.18 Panel de docentes | "El rector sabe quién cargó notas y quién no" |
| 9 | A.16 Examen final | "Fórmula del Instructivo 2025 aplicada automáticamente" |
| 10 | A.21 Boletín PDF | "Libreta lista para imprimir y firmar" |
| 11 | B.4 Portal representante → Notas | "El padre ve las notas de su hijo sin llamar al colegio" |
| 12 | A.26 Ajustes → Módulos | "Empiecen con notas y asistencia; el resto cuando quieran" |

**Nota para las capturas:** usar datos de demostración con nombres ficticios. Nunca capturar datos reales de menores.
