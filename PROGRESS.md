# PROGRESS — Bitácora de sesiones de trabajo

> Registro cronológico. Complementa a HANDOVER.md (que solo muestra el estado actual).

---

## Sesión 0 — YYYY-MM-DD · Bitácora instalada (por rellenar)

**Duración:** — · **Modo:** Cowork setup
**Rama Git:** —

### Hecho
- Instalados 6 archivos de bitácora en el plugin: CLAUDE.md, HANDOVER.md, DECISIONS.md, PROGRESS.md, CHANGELOG.md, INTEGRATION-FLIPBOOK.md.
- Definidas convenciones, reglas de seguridad, modelo Mineduc, roles, integración con Flipbook.

### Pendiente
- Correr auditoría reverse-engineer con Claude Code (prompt en HANDOVER.md sección "Primer prompt").
- Rellenar HANDOVER con estado real del plugin.
- Retro-fill de PROGRESS "Sesión 0.5 — Estado heredado".

### Aprendido
- El plugin lleva tiempo en desarrollo y tiene historia en Git; la bitácora se instala AHORA como línea base hacia adelante.

---

## Plantilla para nuevas sesiones (copia esto)

```markdown
## Sesión N — YYYY-MM-DD · <titulo corto>

**Duración:** Xh · **Modo:** Claude Code / manual / Cowork
**Rama Git:** feature/xxx-xxx
**Commits:** abc1234, def5678
**PR:** #N

### Hecho
- Cambio concreto 1
- Cambio concreto 2

### Archivos tocados
- `inc/services/class-score-calculator-service.php`
- `assets/src/admin/components/GradebookTable.jsx`

### Tests
- ✅ PHPUnit 42/42 · ✅ Vitest 128/128 · ✅ phpcs 0 · ✅ CI verde

### Pendiente
- Terminar validación de recuperación cuando parcial ya estaba cerrado.
- Revisar UI móvil del gradebook (padre en teléfono).

### Decisiones nuevas
- (Si aplica, mover a DECISIONS.md como ADR-XXX y aquí solo la referencia)

### Aprendido / gotchas
- `wp_edu_trimester_scores.computed_score` NO debe recalcular en cascada al escribir un componente — encolar recálculo con Action Scheduler.

### Próxima sesión
1. Acción concreta 1.
2. Acción concreta 2.
```

---

## Métricas del proyecto (actualizar mensualmente)

| Mes | Sesiones | Horas | PHP files | Tablas | Endpoints REST | Tests | Cobertura | Commits |
|---|---:|---:|---:|---:|---:|---:|---:|---:|
| — | — | — | — | — | — | — | — | — |
