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

_(La auditoría de Claude Code rellena esta sección con lo que aún no tiene tag.)_

---

## [X.Y.Z] — YYYY-MM-DD (retroactiva, por rellenar)

_(La auditoría reverse-engineer generará una entrada por cada tag Git existente.
 Si no hay tags, mover todo el estado actual a una entrada [Unreleased].)_

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
