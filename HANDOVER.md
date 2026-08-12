# HANDOVER — Estado actual de PromoSchool

> **Léelo PRIMERO al retomar el proyecto.**
> Se actualiza al final de cada sesión.

## Última actualización

- **Fecha:** _(pendiente: se rellena en la primera sesión con auditoría)_
- **Sesión #:** 0 (bitácora recién instalada; hay historia previa en Git)
- **Rama activa:** `main` (confirmar en primera sesión)
- **Último commit:** _(pendiente `git log -1`)_

## Estado del proyecto en una frase

Plugin PromoSchool en producción / avanzado. Bitácora acaba de instalarse; primera sesión de Claude Code debe hacer auditoría reverse-engineer para poblar HANDOVER, PROGRESS, DECISIONS y CHANGELOG con la realidad actual.

## Fase actual

_(Se completa tras auditoría.)_

Fases del roadmap original (del Plan Sistema Educativo v1):

- [ ] Fase 1 — Base institucional (períodos, grados, materias, catálogo Mineduc)
- [ ] Fase 2 — Personas y matrícula (docentes, estudiantes, padres, asignaciones)
- [ ] Fase 3 — Académico (tareas, entregas, rúbricas)
- [ ] Fase 4 — Calificaciones (componentes, cálculo parcial/trimestre/año, recuperaciones)
- [ ] Fase 5 — Asistencia
- [ ] Fase 6 — Comunicados (plantillas, envío, acuse, WhatsApp)
- [ ] Fase 7 — Boletines PDF
- [ ] Fase 8 — Dashboard rector + auditoría
- [ ] Fase 9 — Portal estudiante y portal padre
- [ ] Fase 10 — App PWA + pagos en línea (opcional)

_(La auditoría marca cuáles están done/parcial/pendiente.)_

## Próximo paso concreto

**Primera sesión:** correr el prompt de auditoría (ver sección "Primer prompt Claude Code" al final).

## Bloqueos actuales

_(Se rellena tras auditoría.)_

## Decisiones pendientes

- ¿Qué feature nueva arrancamos primero? (Actividades interactivas / boletín / WhatsApp / otro)
- ¿PromoSchool vive en repo propio o en el mismo monorepo que Flipbook?

## Contexto crítico para no olvidar

- Prefijo tablas: `wp_edu_*` (28 tablas conocidas del schema base).
- Modelo académico oficial Mineduc: NO tocar sin ADR + validación (afecta boletines legales).
- Roles slug en español: `edu_docente`, `edu_estudiante`, `edu_padre`, `edu_rector`.
- Text domain: `sistema-educativo`.
- Datos de menores: fixtures anonimizadas siempre.
- Auditoría obligatoria en toda mutación de nota / asistencia / entrega.
- Cierre de parcial es irreversible sin `edu_manage_all`.
- Envíos masivos siempre asíncronos (Action Scheduler).
- Integración con Flipbook: PromoSchool NO conoce Flipbook. Ver `INTEGRATION-FLIPBOOK.md`.

## Cómo verificar que todo sigue funcionando

```
composer test      # PHPUnit
npm test           # Jest / Vitest si aplica
npm run lint       # ESLint + PHPCS
```

## Enlaces rápidos

- Repositorio Git: _(pendiente rellenar)_
- Sitio local Flywheel: `C:\Users\Usuario\Local Sites\sistema-educativo\`
- URL local: _(pendiente)_
- CI/CD: _(pendiente)_

---

## Primer prompt Claude Code (copia-y-pega)

```
Lee CLAUDE.md, HANDOVER.md, INTEGRATION-FLIPBOOK.md y (si existen) docs/database-schema.sql
y docs/rest-api-contract.md.

Tareas en orden:

1. AUDITORÍA. Recorre el plugin y hazme un mapa real:
   - Qué archivos PHP existen y su estado (completo / parcial / stub).
   - Qué tablas wp_edu_* existen realmente en el activator/schema vs las 28 del
     schema base documentado. Marca las que faltan y las nuevas.
   - Qué endpoints REST están implementados vs el contrato.
   - Estado de React admin (qué pantallas existen).
   - Tests: cuántos hay, cuántos pasan, cobertura aproximada.
   - Últimos 10 commits de git para inferir en qué se estaba trabajando.

2. RECONCILIACIÓN. Detecta desviaciones código ↔ documentación y decide:
   - Código mejor: actualiza el doc.
   - Doc mejor: TODO refactor.
   - Ambos válidos: ADR nuevo explicando por qué el código eligió otra ruta.

3. RETRO-FILL de bitácora:
   - HANDOVER.md: fase real, próximo paso concreto, bloqueos, checkboxes.
   - PROGRESS.md: entrada "Sesión 0 — Estado heredado" resumiendo lo ya construido.
   - DECISIONS.md: mínimo 5 ADRs retroactivos extraídos del código (Opción A vs B,
     tablas wp_edu_*, cálculo Mineduc, cola async, colación heredada, etc.).
   - CHANGELOG.md: entrada por cada tag Git si hay, o un [Unreleased] con el estado.

4. VERIFICA integración con Flipbook plugin:
   - ¿Existen columnas wp_edu_students.grade_id (usada por Flipbook v_flipbook_books_for_student)?
   - ¿Existe wp_edu_grades_log con las columnas que Flipbook escribe?
   - ¿Existe wp_edu_assignments para hotspot edu_task?
   Si algo cambió respecto al schema base, actualiza INTEGRATION-FLIPBOOK.md y avisa.

5. RESUMEN en 15 líneas: fase real, features completas, features parciales,
   gaps críticos, próximos pasos lógicos (top 3).

No modifiques código en esta pasada, solo documentación.
```

## Formato para actualizar (copia esto al final de cada sesión)

```
### Sesión N — YYYY-MM-DD · <titulo>
- Trabajé en: <archivos>
- Completé: <checkboxes>
- Empecé pero no terminé: <archivo:línea>
- Pruebas: <verde/rojo>
- Próximo paso concreto: <acción>
- Bloqueos nuevos: <si hay>
```
