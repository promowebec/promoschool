# Contrato de la API `edu/v1`

Especificación de diseño de la API REST del plugin **Sistema Educativo Integral**.
Es la **Fase 1** del plan de plataforma propia (Opción A: el plugin queda como backend
único; el frontend propio lo consume). Este documento se escribe **antes** del código:
define el contrato, no lo implementa.

- **Versión del documento:** 1.0 (borrador para aprobación)
- **Fecha:** 11 de agosto de 2026
- **Plugin de referencia:** v1.1.0 · esquema 1.0.9
- **Namespace REST:** `edu/v1` → base `https://<host>/wp-json/edu/v1`

> Documentos relacionados: `docs/BITACORA.md` (estado del sistema), `docs/MANUAL_PANTALLAS.md`
> (qué hace cada pantalla, base funcional de los endpoints), `docs/sistema_educativo_schema.sql`
> (esquema canónico, fuente de verdad de los campos).

---

## 1. Alcance

La API debe cubrir **todo lo que hoy hacen las 25 pantallas del backend y los 4 portales
frontend**, para que la SPA propia (Fase 2) no necesite ninguna pantalla de WordPress.

Cubre seis dominios: **calificaciones, tareas, asistencia, comunicados, pagos y boletines/reportes**,
más el ABM institucional y de personas que los alimenta.

**Fuera de alcance en v1:** administración de WordPress (plugins, temas, usuarios que no sean
`edu_*`), el módulo Flipbook/H5P (deuda técnica #2 de la bitácora, se diseñará aparte) y la
extracción del núcleo a paquete Composer (Fase 3).

---

## 2. Estado actual y el problema a resolver primero

### 2.1 Lo que ya existe

- El namespace `edu/v1` **ya está registrado**, con una sola ruta:
  `POST|GET /edu/v1/payphone/webhook` (`Edu_Payment_Controller::register_rest_routes()`),
  con `permission_callback => '__return_true'` porque la llama Payphone.
- 60+ handlers `admin_post_*` que contienen toda la lógica de escritura.
- `Edu_Context` resuelve institución activa y capabilities con bypass para el Superadmin Editorial.
- `Edu_Modules` enciende y apaga 10 módulos.
- Toda la lógica de cálculo ya está aislada en `Edu_Grade_Calculator` (esto sí es reutilizable tal cual).

### 2.2 El bloqueador: los controllers están acoplados al ciclo formulario→redirect

Los controllers **no se pueden llamar desde un endpoint REST**. Ejemplo real —
`Edu_Score_Controller::handle_save_scores()`:

```php
public static function handle_save_scores() {
    check_admin_referer( 'edu_save_scores' );      // 1. exige nonce de formulario
    self::guard();                                  // 2. termina en wp_die()
    $grade_id = (int) $_POST['grade_id'];           // 3. lee $_POST directo
    ...
    self::redirect( array( 'status' => 'updated' ) ); // 4. wp_safe_redirect() + exit
}
```

Los cuatro puntos son incompatibles con REST: el nonce de admin no aplica a un cliente con
token, `wp_die()` devuelve HTML, `$_POST` no existe en un body JSON, y el `exit` mata la
respuesta antes de serializar nada.

Envolver los controllers con buffers de salida o `$_POST` sintético es frágil y duplica la
validación. La única forma de cumplir la promesa de la Opción A —*un cambio aplica a plugin
y app*— es extraer la lógica.

### 2.3 Arquitectura propuesta: una capa de servicios y dos adaptadores

```
                    ┌──────────────────────────────┐
  wp-admin  ───────▶│ Edu_*_Controller (adaptador) │──┐
  (form POST)       │ nonce · $_POST · redirect     │  │
                    └──────────────────────────────┘  │
                                                       ├──▶ Edu_*_Service
                    ┌──────────────────────────────┐  │    (lógica pura)
  SPA / app ───────▶│ Edu_Api_* (adaptador REST)   │──┘    caps · validación · escritura
  (JSON + Bearer)   │ token · JSON · WP_REST_Resp  │       devuelve array | WP_Error
                    └──────────────────────────────┘
```

**Contrato de un servicio:**

- Vive en `includes/services/class-edu-<dominio>-service.php`.
- Métodos `public static`, reciben un **array asociativo ya sanitizado** y devuelven un
  **array de resultado** o un **`WP_Error`**. Nunca `echo`, nunca `wp_die()`, nunca `exit`,
  nunca `wp_redirect()`, nunca leen `$_POST`/`$_GET`.
- Validan capability con `Edu_Context::can()` y pertenencia a la institución, igual que hoy.
- Disparan los mismos hooks (`edu_grade_logged`, `edu_partial_closed`, …) y la misma auditoría.
- Los códigos de error son los **mismos strings que hoy** (`invalid_scope`, `no_components`,
  `invalid_parcial`, …), para no reescribir mensajes ni romper la UI de admin.

**El controller queda como adaptador delgado:**

```php
public static function handle_save_scores() {
    check_admin_referer( 'edu_save_scores' );
    $res = Edu_Score_Service::save_batch( self::read_input( $_POST ) );
    if ( is_wp_error( $res ) ) {
        self::redirect( array( 'status' => 'error', 'code' => $res->get_error_code() ) );
    }
    self::redirect( array( 'status' => 'updated', 'saved' => $res['saved'] ) );
}
```

**Regla de oro:** a partir de esta fase, **ninguna lógica de negocio nueva se escribe en un
controller ni en una vista**. Va al servicio; los dos adaptadores la consumen.

---

## 3. Convenciones generales

### 3.1 URL y versionado

- Base: `/wp-json/edu/v1`.
- Recursos en **plural y kebab-case**: `/trimester-scores`, `/teacher-assignments`.
- Identificadores numéricos: `/students/42`.
- Sub-recursos cuando la relación es de composición: `/assignments/7/submissions`.
- Acciones que no son CRUD, como sub-recurso en `POST`: `/assignments/7/publish`,
  `/trimester-scores/close-parcial`.
- **`v1` es estable.** Cambios que rompan compatibilidad abren `edu/v2`. Se permite *agregar*
  campos a una respuesta sin subir versión; el cliente debe ignorar los desconocidos.

### 3.2 Formatos

| Tipo | Formato | Ejemplo |
|---|---|---|
| Fecha | `YYYY-MM-DD` | `"2026-08-11"` |
| Fecha y hora | ISO 8601 con offset del sitio | `"2026-08-11T14:30:00-05:00"` |
| Nota | `number` con 2 decimales, escala 0–10 | `8.75` (nunca `"8,75"`) |
| Dinero | `number` con 2 decimales, USD | `45.00` |
| Booleano | `true`/`false` (nunca `1`/`0`) | `"is_closed": true` |
| Nulo | `null` (nunca `""` ni `0`) | `"recovery_score": null` |
| ID | `integer` (nunca string) | `"id": 42` |

Todo texto va en UTF-8 sin escapar HTML: el escapado es responsabilidad del cliente.
Los campos de cuerpo enriquecido (`announcements.body`) se devuelven ya pasados por
`wp_kses_post()`.

### 3.3 Paginación, filtros y orden

Convención de WP REST, para que cualquier cliente estándar funcione:

```
GET /students?grade_id=5&status=active&page=2&per_page=50&orderby=apellidos&order=asc
```

- `page` (default 1), `per_page` (default 20, máximo 100).
- Respuesta: **array plano** de recursos + cabeceras `X-WP-Total` y `X-WP-TotalPages`.
- `_fields=id,nombre` recorta la respuesta (soportado por WP core).

No se usa envoltorio `{data: [...]}`. La razón: WP REST ya define esta convención y los
clientes (incluido `@wordpress/api-fetch`) la esperan.

### 3.4 Errores

Se usa el formato nativo de `WP_Error` que WP REST serializa:

```json
{
  "code": "edu_partial_closed",
  "message": "El parcial ya está cerrado y no admite cambios.",
  "data": { "status": 409, "details": { "parcial_num": 1, "closed_at": "2026-07-02T10:11:00-05:00" } }
}
```

Errores de validación de varios campos a la vez:

```json
{
  "code": "edu_invalid_params",
  "message": "Parámetros inválidos.",
  "data": {
    "status": 400,
    "params": {
      "score": "La nota debe estar entre 0 y 10.",
      "trimester_id": "El trimestre no pertenece a la institución activa."
    }
  }
}
```

| HTTP | Cuándo |
|---|---|
| 200 | Lectura o actualización correcta |
| 201 | Recurso creado (con cabecera `Location`) |
| 204 | Borrado correcto, sin cuerpo |
| 400 | Parámetros inválidos |
| 401 | Sin token, token vencido o inválido |
| 403 | Autenticado pero sin capability, o fuera de su institución/alcance |
| 404 | No existe, o el módulo está desactivado |
| 409 | Conflicto de estado: parcial/trimestre cerrado, componente con notas, pago ya pagado |
| 413 | Archivo supera 10 MB |
| 422 | Regla de negocio violada (ej. pesos de componentes inválidos) |
| 429 | Rate limit (login) |
| 500 | Error inesperado |

**Catálogo de códigos de error** en §11.

---

## 4. Autenticación

El frontend propio vive en **otro dominio**, así que las cookies de WordPress no sirven.
Se definen tres vías, en orden de prioridad de implementación:

### 4.1 Token propio (vía principal para la SPA)

Endpoints `POST /auth/token`, `POST /auth/refresh`, `POST /auth/revoke`.

- **Access token:** JWT compacto (`header.payload.firma`), firmado **HMAC-SHA256** con un
  secreto propio (`wp_options.edu_api_secret`, generado en la activación con
  `wp_generate_password(64, true, true)`). Se implementa a mano en ~60 líneas —
  `hash_hmac()` + `base64url` — **sin agregar dependencias de Composer**, según la decisión
  vigente del proyecto.
- **TTL del access token: 15 minutos.** No se puede revocar uno a uno; se acepta esa ventana.
- **Refresh token:** cadena opaca de 64 caracteres, **rotatoria** (cada refresh emite uno
  nuevo e invalida el anterior). Se guarda **hasheado con SHA-256** en
  `usermeta.edu_refresh_tokens`, junto con `expires_at`, `device_label`, `last_used_at` e IP.
  **TTL: 30 días.**
- **Revocación masiva:** `usermeta.edu_token_version` va dentro del payload (`ver`). Subir ese
  entero invalida de golpe todos los tokens del usuario. Se sube automáticamente al
  **suspender la cuenta**, al **cambiar la contraseña** y en `POST /auth/revoke?all=true`.

Payload del access token:

```json
{
  "iss": "https://colegio.edu.ec",
  "sub": 128,
  "iat": 1786000000,
  "exp": 1786000900,
  "ver": 3,
  "inst": 1,
  "roles": ["edu_docente"]
}
```

`inst` es la institución resuelta al momento de emitir. Es informativo: **el servidor
siempre revalida la institución en cada request**, nunca confía en el token para eso.

Petición y respuesta:

```http
POST /wp-json/edu/v1/auth/token
Content-Type: application/json

{ "username": "0912345678", "password": "•••", "device_label": "iPhone de Ana" }
```

```json
{
  "access_token": "eyJhbGciOi...",
  "token_type": "Bearer",
  "expires_in": 900,
  "refresh_token": "9f2c...",
  "refresh_expires_in": 2592000,
  "user": { "...": "objeto Usuario, §9.1" }
}
```

Transporte en cada request: `Authorization: Bearer <access_token>`.

**Reglas obligatorias del endpoint de login:**

1. Autenticar con `wp_authenticate()`, para que sigan corriendo los filtros existentes —
   incluido el bloqueo de cuentas suspendidas (`authenticate`, prioridad 30).
2. Si `edu_account_status === 'suspended'` → **403 `edu_account_suspended`** (no 401: el
   cliente no debe reintentar con otras credenciales).
3. Si el usuario no tiene ningún rol `edu_*` ni `manage_options` → **403 `edu_not_allowed`**.
4. **Rate limit: 5 intentos fallidos por (usuario + IP) cada 15 minutos** → 429, con
   `Retry-After`. Contador en transient.
5. Nunca revelar si falló el usuario o la contraseña: un solo código `edu_invalid_credentials`.
6. Registrar cada login exitoso y cada bloqueo en `wp_edu_audit`.

### 4.2 Application Passwords (integraciones y scripts)

WordPress core ya las soporta vía Basic Auth sobre HTTPS. Se aceptan **tal cual, sin código
adicional**, para integraciones servidor-a-servidor (un ERP, un script de importación). No se
recomiendan para la SPA: son equivalentes a una contraseña y no rotan.

### 4.3 Cookie + nonce (mismo dominio)

Si la SPA se sirviera desde el mismo dominio de WordPress, la cookie de sesión funciona
enviando `X-WP-Nonce` con un nonce `wp_rest`. Se mantiene soportada para no romper llamadas
desde los portales actuales.

> **Nota de despliegue (Apache).** Apache suele **descartar la cabecera `Authorization`** antes
> de llegar a PHP. Hace falta `CGIPassAuth On` o la regla
> `RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]`. Es la misma clase de gotcha
> que la regla de Nginx para `edu-privado`: va documentada en el manual de instalación.

### 4.4 CORS

La SPA está en otro origen, así que el plugin debe responder el preflight:

- Opción `edu_api_allowed_origins` (lista blanca de orígenes, **nunca `*`**).
- `Access-Control-Allow-Origin: <origen si está en la lista>` + `Vary: Origin`.
- `Access-Control-Allow-Headers: Authorization, Content-Type, X-Edu-Institution`.
- `Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS`.
- **`Access-Control-Allow-Credentials: false`** — con Bearer no hacen falta cookies, y así se
  elimina toda la superficie de CSRF.

> **Cuidado con el comportamiento por defecto de WordPress.** `rest_send_cors_headers()` del
> core devuelve `Access-Control-Allow-Origin` reflejando **cualquier** origen, y además con
> `Allow-Credentials: true`. Para `edu/v1` eso no sirve: si el origen no está en la lista
> blanca hay que **retirar** esas cabeceras con `header_remove()`, no solo dejar de añadir las
> propias. Se detectó probando con un origen hostil; sin el `header_remove()` la lista blanca
> era decorativa.

---

## 5. Autorización, alcance y multi-institución

### 5.1 Los tres niveles de control

Cada endpoint aplica, en este orden:

1. **Capability** — la misma que hoy usa la pantalla equivalente, vía `Edu_Context::can()`
   (que ya incluye el bypass del Superadmin Editorial por `manage_options`).
2. **Institución** — todo recurso debe pertenecer a la institución resuelta.
   Si no: **403 `edu_invalid_scope`**.
3. **Alcance personal** — un docente solo toca grados/materias de sus `teacher_assignments`
   activas; un estudiante solo sus propios datos; un representante solo los de sus hijos
   vinculados en `parent_student`. Si no: **403 `edu_out_of_scope`**.

El nivel 3 es el que más IDORs cerró en el hardening v1.0.9. **Se valida en el servicio, no en
el endpoint**, para que valga igual desde wp-admin.

### 5.2 Resolución de la institución

| Usuario | Institución |
|---|---|
| `edu_rector`, `edu_docente`, `edu_estudiante`, `edu_padre` | `usermeta.edu_institution_id` (fija; ignora cualquier parámetro que mande el cliente) |
| Superadmin Editorial (`manage_options`) | Cabecera `X-Edu-Institution: <id>`; si falta, `usermeta.edu_current_institution_id` |

Si no hay institución resoluble → **409 `edu_no_institution`**.

`POST /institutions/{id}/activate` cambia la institución guardada del superadmin
(equivale al selector `_institution-switcher.php`).

### 5.3 Mapa de capabilities por dominio

| Dominio | Lectura | Escritura |
|---|---|---|
| Institución, períodos, grados, materias, pensum | `edu_view_all` \| docente autenticado (solo lectura) | `edu_manage_grades` / `edu_manage_subjects` / `edu_manage_curriculum` |
| Docentes / estudiantes / representantes | `edu_view_all` | `edu_manage_teachers` / `edu_manage_students` / `edu_manage_parents` |
| Componentes evaluables | `edu_grade_students` \| `edu_view_all` | `edu_manage_curriculum` \| `edu_grade_students` (propios, ver §7.2) |
| Notas y gradebook | `edu_grade_students` \| `edu_view_all` \| `edu_view_own_grades` (propias) | `edu_grade_students` |
| Cierres de parcial y trimestre | `edu_view_all` | `edu_close_partial` |
| Tareas y entregas | `edu_view_assignments` \| `edu_create_assignment` | `edu_create_assignment` (docente) / `edu_submit_assignment` (estudiante) |
| Asistencia | `edu_view_all` \| `edu_view_child_attendance` | `edu_take_attendance` |
| Comunicados | `edu_read_announcements` | `edu_send_institutional_announcements` / `edu_send_grade_announcement` |
| Pagos | `edu_view_all` \| representante (propios) | `edu_view_all` |
| Boletines y exportes | `edu_generate_reports` \| `edu_view_all` | — |
| Auditoría | `edu_view_audit` | — |

---

## 6. Módulos activables

`Edu_Modules::is_active()` gobierna 10 módulos. La API los respeta:

- Los endpoints de un módulo apagado devuelven **404 `edu_module_disabled`** (404 y no 403:
  para el cliente, la ruta no existe en esta instalación).
- `GET /me` incluye `modules: {tareas: true, pagos: false, ...}` para que la SPA **oculte la
  navegación** en vez de descubrirlo con un 404.

Módulos y prefijos afectados:

| Módulo | Rutas |
|---|---|
| `tareas` | `/assignments/*`, `/submissions/*` |
| `comunicados` | `/announcements/*`, `/announcement-templates` |
| `asistencia` | `/attendance/*` |
| `boletines` | `/reports/boletin*` |
| `pagos` | `/payments/*`, `/payment-config` (**no** `/payphone/webhook`) |
| `exportes` | `/reports/mineduc/*` |
| `cuentas` | `/accounts/*` |
| `whatsapp`, `pwa`, `textos` | sin rutas propias en v1 |

`/payphone/webhook` queda fuera del gate a propósito: si se apaga el módulo con pagos en
vuelo, la pasarela debe poder seguir confirmando.

---

## 7. Catálogo de endpoints

Leyenda: **Cap** es la capability mínima. Las rutas marcadas 🔒 exigen además validación de
alcance personal (§5.1 nivel 3).

### 7.1 Sesión y perfil

| Método | Ruta | Cap | Notas |
|---|---|---|---|
| POST | `/auth/token` | pública | Login. Rate-limited |
| POST | `/auth/refresh` | pública | Rotación de refresh token |
| POST | `/auth/revoke` | autenticado | `?all=true` sube `edu_token_version` |
| GET | `/me` | autenticado | Perfil + roles + caps + institución + módulos + hijos/asignaciones |
| PUT | `/me/password` | autenticado | Sube `edu_token_version` (cierra otras sesiones) |
| GET | `/me/sessions` | autenticado | Dispositivos con sesión abierta |
| GET | `/me/announcements` | `edu_read_announcements` | Bandeja del usuario, con `read_at` |
| GET | `/me/children` | `edu_view_child_grades` | Hijos del representante |

### 7.2 Estructura institucional

| Método | Ruta | Cap |
|---|---|---|
| GET / POST | `/institutions` | `edu_view_all` / `manage_options` |
| GET / PUT / DELETE | `/institutions/{id}` | `edu_view_all` / `manage_options` |
| POST | `/institutions/{id}/activate` | `manage_options` |
| GET / POST | `/periods` | `edu_view_all` / `edu_manage_grades` |
| GET / PUT / DELETE | `/periods/{id}` | idem |
| POST | `/periods/{id}/activate` | `edu_manage_grades` |
| GET | `/periods/{id}/trimesters` | autenticado |
| PUT | `/trimesters/{id}` | `edu_manage_grades` |
| GET / POST | `/grades` | autenticado / `edu_manage_grades` |
| GET / PUT / DELETE | `/grades/{id}` | idem |
| GET | `/subjects-catalog` | autenticado |
| GET / POST | `/subjects` | autenticado / `edu_manage_subjects` |
| GET / PUT / DELETE | `/subjects/{id}` | idem |
| POST | `/subjects/adopt` | `edu_manage_subjects` | adopta del catálogo Mineduc |
| GET / PUT | `/grades/{id}/pensum` | autenticado / `edu_manage_curriculum` |

`GET /grades` acepta `?level=basica&sub_level=media&period_id=3`.
El `sub_level` viaja siempre en el recurso Grado porque **determina la fórmula de cálculo**
(§9.4) y la SPA lo necesita para rotular "Examen 30%" vs. "Examen + Proyecto 30%".

### 7.3 Personas

| Método | Ruta | Cap |
|---|---|---|
| GET / POST | `/teachers` | `edu_view_all` / `edu_manage_teachers` |
| GET / PUT / DELETE | `/teachers/{id}` | idem |
| GET / POST | `/students` | `edu_view_all` / `edu_manage_students` |
| GET / PUT / DELETE | `/students/{id}` 🔒 | idem |
| GET / POST | `/parents` | `edu_view_all` / `edu_manage_parents` |
| GET / PUT / DELETE | `/parents/{id}` | idem |
| GET / POST / DELETE | `/teacher-assignments` | `edu_view_all` / `edu_manage_teachers` |
| GET | `/imports/{entity}/template` | según entidad | CSV; `entity` ∈ grades·teachers·students·parents |
| POST | `/imports/{entity}` | según entidad | multipart; devuelve resumen fila por fila |

`POST /imports/{entity}` responde **207-like en 200** con el detalle, porque una importación
parcial es un resultado legítimo:

```json
{ "processed": 120, "created": 113, "skipped": 5, "errors": [ { "row": 18, "code": "duplicate_cedula", "message": "..." } ] }
```

### 7.4 Calificaciones (núcleo)

| Método | Ruta | Cap | Notas |
|---|---|---|---|
| GET | `/components` | `edu_grade_students` | filtros `subject_id`, `trimester_id`, `parcial_num` |
| PUT | `/components` | `edu_manage_curriculum` | guardado batch del parcial |
| POST | `/components` | `edu_grade_students` 🔒 | **creación al vuelo** (v1.1.0) |
| DELETE | `/components/{id}` | `edu_manage_curriculum` | 409 si tiene notas |
| GET | `/gradebook` | `edu_grade_students` 🔒 | matriz completa, ver §8.1 |
| POST | `/gradebook/scores` | `edu_grade_students` 🔒 | captura batch, ver §8.2 |
| GET / PUT | `/trimester-scores` | `edu_grade_students` 🔒 | examen final + proyecto |
| POST | `/trimester-scores/close-parcial` | `edu_close_partial` | |
| POST | `/trimester-scores/close-trimester` | `edu_close_partial` | |
| GET | `/year-scores` | `edu_view_all` \| `edu_view_own_grades` 🔒 | resumen anual |
| PUT | `/year-scores/{id}/recovery` | `edu_close_partial` | supletorio/remedial/gracia |
| GET | `/students/{id}/scores` 🔒 | `edu_view_own_grades` \| `edu_view_child_grades` | boleta en JSON |
| GET | `/students/{id}/component-breakdown` 🔒 | sesión iniciada | de qué está hecha cada nota, ver §8.3 |
| PUT | `/submissions/{id}/return` | `edu_grade_students` \| `edu_view_all` | devolver el trabajo, ver §8.4 |

**Alcance de `/students/{id}/component-breakdown`.** No basta con una capability: quién puede
pedirlo depende del vínculo con **ese** estudiante. Rector y superadmin, toda su institución;
el propio estudiante, sus materias; el representante, las de sus hijos; el docente, solo las
materias que dicta en ese grado. Cuidado al tocarlo: usar `can_view_grade_subject()` aquí
rebota al estudiante y al representante, porque ese helper exige asignación docente.

`POST /components` replica la lógica de v1.1.0: peso por defecto **1.00**, y si ya existe un
componente con el mismo nombre en el mismo `(subject_id, trimester_id, parcial_num)` **no
duplica** — devuelve `200` con el existente y `"reused": true` en vez de `201`.

### 7.5 Tareas y entregas

| Método | Ruta | Cap |
|---|---|---|
| GET / POST | `/assignments` | `edu_view_assignments` / `edu_create_assignment` 🔒 |
| GET / PUT / DELETE | `/assignments/{id}` 🔒 | idem |
| POST | `/assignments/{id}/publish` 🔒 | `edu_create_assignment` |
| POST | `/assignments/{id}/close` 🔒 | `edu_create_assignment` |
| POST | `/assignments/{id}/files` 🔒 | `edu_create_assignment` (multipart) |
| DELETE | `/assignment-files/{id}` 🔒 | `edu_create_assignment` |
| GET | `/assignments/{id}/submissions` 🔒 | `edu_create_assignment` |
| POST | `/assignments/{id}/submissions` 🔒 | `edu_submit_assignment` (multipart) |
| PUT | `/submissions/{id}/grade` 🔒 | `edu_grade_students` |
| PUT | `/assignments/{id}/recovery-settings` 🔒 | `edu_create_assignment` |
| POST | `/submissions/{id}/recovery` 🔒 | `edu_submit_assignment` |
| PUT | `/submissions/{id}/recovery-grade` 🔒 | `edu_grade_students` |
| GET | `/files/{id}/link` 🔒 | según el padre (tarea o entrega) — devuelve URL firmada, §10 |

`POST /assignments` acepta el campo unificado de v1.1.0:

```json
{
  "grade_id": 5, "subject_id": 12, "trimester_id": 2, "parcial_num": 1,
  "title": "Ensayo sobre la Amazonía", "due_date": "2026-09-05T23:59:00-05:00",
  "component": { "mode": "create", "name": "Trabajos" },
  "max_score": 10.00, "allow_recovery": true
}
```

`component.mode` ∈ `existing` (con `id`) · `create` (con `name`) · `none`.
El campo `type` **no se envía**: lo deriva el servidor con `derive_type()`. Se acepta si
llega, por compatibilidad con integraciones externas (el puente Flipbook previsto).

### 7.6 Asistencia

| Método | Ruta | Cap |
|---|---|---|
| GET | `/attendance` | `edu_view_all` \| `edu_take_attendance` 🔒 |
| PUT | `/attendance` | `edu_take_attendance` 🔒 |
| GET | `/students/{id}/attendance` 🔒 | `edu_view_child_attendance` \| `edu_view_own_grades` |

`GET /attendance?grade_id=5&date=2026-08-11[&subject_id=12]` devuelve una fila por estudiante
con su estado del día (o `null` si aún no se tomó). `PUT` guarda el día completo en batch
(upsert sobre la clave única `student_id + subject_id + date`). `subject_id` ausente o `null`
significa **asistencia diaria general**, que es la que dispara `edu_attendance_absence`.

### 7.7 Comunicados

| Método | Ruta | Cap |
|---|---|---|
| GET / POST | `/announcements` | `edu_read_announcements` / `edu_send_*_announcement` |
| GET / DELETE | `/announcements/{id}` | idem |
| POST | `/announcements/{id}/acknowledge` 🔒 | `edu_acknowledge_announcement` |
| GET | `/announcements/{id}/recipients` | `edu_view_all` |
| GET | `/announcement-templates` | `edu_send_grade_announcement` |

`POST /announcements` con `channels: ["portal","email","whatsapp"]`. El envío masivo por
WhatsApp **ya está diferido a cron** (`edu_wa_send_announcement`) desde el hardening, así que
la respuesta es **202 Accepted** con el recuento de destinatarios encolados, no 201.

### 7.8 Pagos

| Método | Ruta | Cap |
|---|---|---|
| GET | `/payments` 🔒 | `edu_view_all` \| representante |
| GET / PUT | `/payment-config` | `edu_view_all` |
| POST | `/payments/generate-monthly` | `edu_view_all` |
| POST | `/payments/{id}/init` 🔒 | `edu_view_all` \| representante |
| POST | `/payments/{id}/manual` | `edu_view_all` |
| POST | `/payments/{id}/waive` | `edu_view_all` |
| POST | `/payments/{id}/link` | `edu_view_all` |
| POST | `/payments/suspend-overdue` | `edu_view_all` |
| POST\|GET | `/payphone/webhook` | **pública** (ya existe) |

**Invariante de seguridad, heredada del hardening v1.0.9:** ningún endpoint marca un pago como
pagado por su cuenta. `POST /payments/{id}/init` devuelve la URL de Payphone y el pago sigue
`pending`; solo `Edu_Payphone::confirm_and_mark_paid()` —disparado por el retorno o el
webhook— cambia el estado. `POST /payments/{id}/manual` es la única excepción y exige
`edu_view_all` + auditoría.

### 7.9 Reportes, boletines y auditoría

| Método | Ruta | Cap | Notas |
|---|---|---|---|
| GET | `/reports/boletin` 🔒 | `edu_generate_reports` \| representante | `?student_id&period_id` → PDF |
| GET | `/reports/boletines.zip` | `edu_generate_reports` | `?grade_id&period_id` |
| GET | `/reports/mineduc/{tipo}` | `edu_generate_reports` | `.xlsx`; tipo ∈ acta-consolidada · nomina-amie · distributivo-docente · asistencia-acumulada |
| GET | `/dashboard/rector` | `edu_view_all` | métricas, rendimiento y alertas |
| GET | `/dashboard/docente` | `edu_grade_students` | asistencia del día y pendientes |
| GET | `/dashboard/teacher-panel` | `edu_view_all` | panel de docentes (avance por asignación) |
| GET | `/audit` | `edu_view_audit` | filtros `entity_type`, `user_id`, `from`, `to` |

Los binarios (PDF, XLSX, ZIP) **no se devuelven en base64**. Estos endpoints responden
`application/pdf` / `application/vnd.openxmlformats-...` con `Content-Disposition: attachment`.
Como `fetch()` no puede mandar `Authorization` en una descarga directa del navegador, el patrón
para la SPA es: pedir `GET /reports/boletin?...&as=url` → recibir `{ "url": "...", "expires_at": "..." }`
con una URL firmada de un solo uso (§10), y abrir esa URL.

`GET /dashboard/teacher-panel` cubre además la **deuda técnica #3** de la bitácora: hoy el
panel de docentes solo existe en wp-admin, y con este endpoint la SPA lo tiene gratis.

---

## 8. Los dos endpoints críticos, en detalle

Son el corazón del negocio y donde más importa fijar el contrato.

### 8.1 `GET /gradebook` — la matriz de notas

```
GET /gradebook?grade_id=5&subject_id=12&trimester_id=2&parcial_num=1
```

```json
{
  "context": {
    "grade":     { "id": 5, "name": "8vo EGB", "paralelo": "A", "sub_level": "superior" },
    "subject":   { "id": 12, "name": "Matemática" },
    "trimester": { "id": 2, "number": 1, "is_closed": false },
    "parcial_num": 1,
    "is_closed": false,
    "formula": "sumativa_proyecto"
  },
  "components": [
    { "id": 31, "name": "Tareas",     "weight": 1.00, "created_by": 0,   "is_own": false, "editable": false },
    { "id": 32, "name": "Lecciones",  "weight": 1.00, "created_by": 128, "is_own": true,  "editable": true }
  ],
  "students": [
    {
      "student_id": 77,
      "nombres": "Ana Lucía", "apellidos": "Moreira Vera",
      "scores": { "31": 8.50, "32": null },
      "computed_score": 8.50,
      "cualitativa": { "codigo": "A-", "color": "#16a34a" }
    }
  ]
}
```

Puntos fijados por el contrato:

- `scores` es un **mapa `component_id → nota|null`**, no un array. Evita que el cliente tenga
  que cruzar índices.
- Una nota `null` significa **componente sin calificar**, y el cálculo lo **excluye y
  renormaliza los pesos** — no cuenta como cero. Es la regla vigente del motor y la API no
  la puede contradecir.
- El valor de `scores` es el **promedio** de todas las filas de `grades_log` de ese
  `(student_id, component_id)`, no la última. Corrige el bug ya resuelto en el plugin.
- `cualitativa` viene **calculada por el servidor** con `Edu_Qualitativa_Helper`. La SPA no
  reimplementa la tabla del Instructivo 2025: si la escala cambia, cambia en un solo lugar.
- `context.formula` ∈ `elemental` | `sumativa_proyecto`, derivada del `sub_level`. Le dice al
  cliente qué campos mostrar sin duplicar el `in_array(['media','superior','bg','bt'])`.
- `is_closed` a nivel de contexto: si es `true`, la SPA debe renderizar la matriz en solo lectura.

### 8.2 `POST /gradebook/scores` — captura batch

```json
{
  "grade_id": 5, "subject_id": 12, "trimester_id": 2, "parcial_num": 1,
  "scores": [
    { "student_id": 77, "component_id": 31, "score": 8.50 },
    { "student_id": 78, "component_id": 31, "score": 9.00 },
    { "student_id": 78, "component_id": 32, "score": null }
  ]
}
```

Se usa un **array de tripletas**, no la matriz anidada del formulario actual: es más fácil de
validar, permite errores por celda y deja mandar envíos parciales (autoguardado de una sola
celda mientras el docente escribe).

Respuesta **200**:

```json
{
  "saved": 2,
  "skipped": 1,
  "recalculated": [
    { "student_id": 77, "computed_score": 8.50, "cualitativa": { "codigo": "A-", "color": "#16a34a" } },
    { "student_id": 78, "computed_score": 9.00, "cualitativa": { "codigo": "A-", "color": "#16a34a" } }
  ],
  "errors": []
}
```

Reglas:

- `score: null` **no borra** la nota anterior: se ignora (`skipped`). Para dejar sin nota un
  componente ya calificado hace falta `DELETE /gradebook/scores/{grades_log_id}`, que es
  auditado. `grades_log` es un **log append-only** y el contrato lo preserva.
- Fuera de 0–10 → la celda entra en `errors`, las demás se guardan igual. **Nunca se rechaza
  el lote entero por una celda mala**: un docente que carga 35 estudiantes no puede perder el
  trabajo por un tipeo.
- Si el parcial de un estudiante está cerrado, esa celda se salta y entra en `errors` con
  código `partial_closed`; las de los demás estudiantes se guardan igual. **El cierre es por
  estudiante** (`wp_edu_parcial_scores.is_closed`), no por grado: por eso no hay un 409 que
  tumbe el lote entero.
- El recálculo del parcial corre **una sola vez por estudiante** al final del lote, como hoy.
- La respuesta devuelve el `computed_score` recalculado para que la SPA actualice la columna
  sin volver a pedir el gradebook.
- **La grilla reemplaza, no acumula.** Tiene un input por componente, así que al guardar se
  borran las notas manuales previas de esa celda y se inserta la nueva. Las notas que vienen
  de una tarea (`assignment_id` no nulo) **no se tocan**: varias tareas en un mismo componente
  se siguen promediando, que es el modelo académico. Antes cada guardado insertaba una fila
  más, así que corregir un 6.00 a 8.00 dejaba al estudiante con 7.00. La sustitución se audita
  y se devuelve en `replaced`.

### 8.4 `PUT /submissions/{id}/return` — devolver el trabajo

Única forma de deshacer una calificación. Deja la entrega en `returned`, **borra su fila de
`grades_log`** para que la nota deje de contar en el promedio, recalcula el parcial y lo audita.
El estudiante puede reenviar y el docente volver a calificar.

Existe porque las tres reglas de integridad de la v1.11.0 cierran los atajos:

- **Una entrega por estudiante.** `POST /assignments/{id}/submissions` responde `409
  already_submitted` si ya hay una entrega en `submitted`, `late` o `graded`. En `returned` sí
  se admite: esa segunda entrega la pidió el docente.
- **Una calificación por entrega.** `PUT /submissions/{id}/grade` responde `409 already_graded`
  si la entrega ya está calificada.
- **La grilla no pisa notas con respaldo.** `POST /gradebook/scores` rechaza con
  `graded_from_assignment` las celdas cuya nota vino de una entrega calificada, y
  `GET /gradebook` las marca en `score_locked`.

El principio: **una nota con respaldo no se sustituye por una sin respaldo.** La que sale de
calificar una entrega se apoya en el archivo que subió el estudiante; una tecleada en la grilla
no se apoya en nada.

### 8.3 `GET /students/{id}/component-breakdown` — de qué está hecha cada nota

```
GET /students/77/component-breakdown?subject_id=12&trimester_id=2&parcial_num=1
```

```json
{
  "student_id": 77, "subject_id": 12, "trimester_id": 2, "parcial_num": 1,
  "components": [
    {
      "component_id": 31, "name": "Tareas", "weight": 1.00,
      "average": 7.83, "count": 3,
      "entries": [
        { "id": 551, "score": 8.00, "registered_at": "2026-08-12T10:04:00",
          "origin": "assignment", "assignment_id": 21,
          "assignment_title": "Deber de fracciones", "assignment_type": "deber" },
        { "id": 549, "score": 8.00, "registered_at": "2026-08-05T09:12:00",
          "origin": "manual", "assignment_id": null,
          "assignment_title": null, "assignment_type": null }
      ]
    }
  ]
}
```

- La celda de un componente es el **promedio** de sus filas de `grades_log`. Sin este endpoint
  nadie puede explicar de dónde sale ese número, que es justo lo que preguntan los
  representantes cuando reclaman una nota.
- `origin` ∈ `assignment` | `manual`. Solo las notas puestas **calificando una entrega** llevan
  tarea; las escritas en la grilla no tienen ninguna que atribuir, porque la grilla no sabe a
  qué tarea corresponden.
- `average` se recalcula sobre las mismas filas que se listan, para que el número y su
  desglose no puedan discrepar nunca.
- `count` viaja también en `/gradebook`, como `score_counts`, para que la grilla pueda avisar
  de cuántas notas hay detrás de cada celda sin pedir el detalle.
- Solo lectura: no toca el cálculo ni los cierres.

---

## 9. Formas canónicas de los recursos

Solo los que la SPA usa en más de una pantalla. El resto es proyección directa de las columnas
del esquema.

### 9.1 Usuario (`GET /me`)

```json
{
  "id": 128,
  "email": "docente@colegio.edu.ec",
  "nombres": "Carlos", "apellidos": "Zambrano Ruiz",
  "display_name": "Carlos Zambrano Ruiz",
  "roles": ["edu_docente"],
  "capabilities": ["edu_grade_students", "edu_create_assignment", "..."],
  "account_status": "active",
  "institution": { "id": 1, "name": "Unidad Educativa X", "logo_url": "...", "regime": "sierra" },
  "active_period": { "id": 3, "name": "2026-2027", "num_trimesters": 3 },
  "modules": { "tareas": true, "comunicados": true, "asistencia": true, "pagos": false, "...": true },
  "profile": {
    "type": "teacher",
    "teacher_id": 9,
    "assignments": [ { "id": 44, "grade_id": 5, "grade_name": "8vo EGB A", "sub_level": "superior", "subject_id": 12, "subject_name": "Matemática", "period_id": 3 } ],
    "student_id": null,
    "grade": null,
    "parent_id": null,
    "children": []
  }
}
```

`profile.type` ∈ `superadmin` · `rector` · `teacher` · `student` · `parent`.
**Un solo request y la SPA ya sabe qué navegación pintar.**

Las claves del perfil están **siempre presentes**, con `null` o `[]` cuando no aplican: así
el cliente no necesita comprobar existencia antes de leer. Y se rellenan **todas las que
correspondan**, no solo las del tipo primario — un rector que además dicta clases trae su
`teacher_id` y sus `assignments`, y un docente que es representante de un alumno trae sus
`children`. `type` solo indica cuál es su rol principal, para decidir la pantalla de inicio.

Nota de implementación: `nombres`/`apellidos` salen de `wp_usermeta` (`first_name`/`last_name`),
**no** de `wp_edu_students` — esa tabla no los tiene.

### 9.2 Nota de trimestre

```json
{
  "student_id": 77, "subject_id": 12, "trimester_id": 2,
  "parcial1_score": 8.50, "parcial2_score": 9.00,
  "final_exam_score": 8.00, "proyecto_score": 9.00,
  "recovery_score": null,
  "computed_score": 8.68,
  "cualitativa": { "codigo": "A-", "color": "#16a34a" },
  "is_closed": false,
  "formula": "sumativa_proyecto"
}
```

### 9.3 Nota anual

```json
{
  "student_id": 77, "subject_id": 12, "period_id": 3,
  "trim1": 8.68, "trim2": 7.90, "trim3": 8.20,
  "average": 8.26,
  "supletorio_score": null, "remedial_score": null, "gracia_score": null,
  "status": "aprobado",
  "cualitativa": { "codigo": "B+", "color": "#1d4ed8" }
}
```

### 9.4 Fórmulas — el servidor manda

La API **nunca** entrega notas crudas esperando que el cliente calcule. `computed_score` y
`average` vienen calculados por `Edu_Grade_Calculator`, y el campo `formula` es informativo
para rotular la UI:

```
formula = "elemental"          → ((P1+P2)/2)×0.70 + Examen×0.30
formula = "sumativa_proyecto"  → ((P1+P2)/2)×0.70 + ((Examen+Proyecto)/2)×0.30
```

Motivo: si la fórmula viviera también en JavaScript, un cambio del Instructivo obligaría a
desplegar dos veces y abriría la puerta a que app y boletín PDF muestren notas distintas.

---

## 10. Archivos

- **Subida:** `multipart/form-data`, campo `file` (o `file[]`). Se reusan los límites actuales:
  **10 MB** (`Edu_Assignment_Task_Controller::MAX_FILE_SIZE`) y la lista blanca de
  `ALLOWED_TYPES`: pdf, doc/docx, ppt/pptx, xls/xlsx, jpg/jpeg, png, zip.
  Exceso → **413 `edu_file_too_large`**; extensión no permitida → **400 `edu_file_type`**.
- **Almacenamiento:** sigue siendo `wp-content/uploads/edu-privado/`, fuera del árbol público.
  La API **no** devuelve nunca la ruta física ni una URL directa.
- **Descarga:** `GET /files/{id}/link?type=assignment|submission` valida el permiso y devuelve

  ```json
  { "url": "https://.../wp-json/edu/v1/files/download?token=...&_wpnonce=...", "expires_at": "2026-08-11T15:05:00-05:00" }
  ```

  El token es HMAC de `(kind, args, user_id, exp)` con `edu_api_secret`, **válido 5 minutos**.
  Así el navegador puede abrir la descarga en una pestaña sin poder mandar la cabecera
  `Authorization`, y el enlace no sirve compartido ni caduca en el historial.
- **El nonce va dentro de la URL, y es necesario.** Una navegación normal no puede añadir la
  cabecera `X-WP-Nonce`, y sin nonce WordPress descarta la cookie en REST
  (`rest_cookie_check_errors` hace `wp_set_current_user( 0 )`): la petición llega anónima y la
  comprobación de identidad del token falla con 401. Llevarlo en la URL conserva la garantía de
  que el enlace es personal —sin la cookie de quien lo pidió, el usuario sigue siendo 0—.
- **El permiso se decide por el padre, no por el archivo.** `Edu_File_Service::locate_attachment()`
  sube a la tarea o a la entrega y aplica `Edu_Assignment_Service::can_access()` o
  `Edu_Submission_Service::can_download()`. Se revalida al descargar, porque entre la emisión del
  enlace y su uso el alcance del usuario pudo cambiar.
- Mismo mecanismo para los binarios generados: `kind` vale `attachment`, `boletin` o `mineduc`.
- La verificación de propiedad es la misma del hardening: el estudiante solo sus entregas, el
  docente solo las de sus asignaciones, el representante solo las de sus hijos.

> **Multipart y `PUT`.** PHP solo parsea `multipart/form-data` en POST. Los envíos con archivos
> van siempre como POST y piden el método real con `X-HTTP-Method-Override: PUT`, que WP REST
> interpreta. Un `PUT` multipart directo llega con `$_POST` y `$_FILES` vacíos.

---

## 11. Catálogo de códigos de error

| Código | HTTP | Significado |
|---|---|---|
| `edu_invalid_credentials` | 401 | Usuario o contraseña incorrectos |
| `edu_token_expired` | 401 | Access token vencido — usar refresh |
| `edu_token_invalid` | 401 | Firma inválida o `ver` desactualizado |
| `edu_account_suspended` | 403 | Cuenta suspendida (no reintentar) |
| `edu_not_allowed` | 403 | Sin la capability requerida |
| `edu_invalid_scope` | 403 | El recurso es de otra institución |
| `edu_out_of_scope` | 403 | Fuera de sus asignaciones / hijos / propios datos |
| `edu_no_institution` | 409 | No hay institución activa resoluble |
| `edu_module_disabled` | 404 | El módulo está apagado en esta instalación |
| `edu_not_found` | 404 | El recurso no existe |
| `edu_invalid_params` | 400 | Validación de campos (ver `data.params`) |
| `edu_invalid_parcial` | 400 | `parcial_num` distinto de 1 o 2 |
| `edu_no_components` | 422 | El parcial no tiene componentes definidos |
| `edu_partial_closed` | 409 | Parcial cerrado |
| `edu_trimester_closed` | 409 | Trimestre cerrado |
| `edu_component_has_scores` | 409 | No se puede borrar: tiene notas |
| `edu_assignment_closed` | 409 | La tarea ya no admite entregas |
| `edu_payment_not_pending` | 409 | El pago ya está pagado, exonerado o anulado |
| `edu_file_too_large` | 413 | Supera 10 MB |
| `edu_file_type` | 400 | Extensión no permitida |
| `edu_rate_limited` | 429 | Demasiados intentos (ver `Retry-After`) |

Los códigos reutilizan literalmente los strings internos que ya usan los controllers
(`invalid_scope`, `no_components`, `invalid_parcial`), con el prefijo `edu_`.

---

## 12. Seguridad — invariantes que la API no puede romper

Todo lo cerrado en el hardening v1.0.9 sigue vigente. La API **no puede** ser una puerta trasera:

1. **Validación de capability y de institución en el servicio**, nunca solo en el endpoint.
2. **Alcance personal** (docente → sus asignaciones, padre → sus hijos, estudiante → sí mismo)
   verificado en cada lectura y escritura. Es el nivel donde vivían los IDORs.
3. **Pagos:** confirmación server-side obligatoria antes de marcar pagado.
4. **Archivos privados:** nunca URL directa; siempre token firmado y de vida corta.
5. **Auditoría:** toda escritura sensible escribe en `wp_edu_audit`, con el mismo detalle que
   desde wp-admin. El registro debe distinguir el origen (`admin` vs. `api`) — se propone
   agregar el canal dentro de `new_value` para no tocar el esquema.
6. **Parciales y trimestres cerrados** no se recalculan ni se sobrescriben.
7. **HTTPS obligatorio.** Sobre HTTP, el endpoint de token responde 403: son datos de menores.
8. **Sin `Access-Control-Allow-Origin: *`** y sin credenciales en CORS.
9. Los endpoints públicos son exactamente **tres**: `/auth/token`, `/auth/refresh` y
   `/payphone/webhook`. Cualquier otro exige autenticación.

---

## 13. Plan de implementación

Este documento cierra la Fase 1. La construcción se propone en cinco etapas, cada una
entregable y probable por separado:

| Etapa | Alcance | Por qué en este orden |
|---|---|---|
| **1a ✅** | Infraestructura: `includes/api/` con `Edu_Api`, autenticación por token, CORS, `GET /me`, gate de módulos, mapeo `WP_Error` → HTTP | Sin esto no se puede probar ningún otro endpoint |
| **1b ✅** | Extracción del servicio de **calificaciones** (`Edu_Score_Service`, `Edu_Trimester_Score_Service`, `Edu_Curriculum_Service`) + refactor de los controllers a adaptadores | Es el dominio crítico y el que valida que el patrón funciona sin romper wp-admin |
| **1c ✅** | Endpoints de lectura de todos los dominios (los `GET`) | Permite empezar la SPA de la Fase 2 en paralelo, sin riesgo de escritura |
| **1d ✅** | Servicios y endpoints de escritura de tareas, asistencia y comunicados | Los tres módulos que usan a diario docentes y representantes |
| **1e ✅** | Pagos, boletines, exportes, auditoría y dashboards | Los de menor frecuencia de uso |

**Criterio de aceptación transversal:** después de cada etapa, **las pantallas de wp-admin
deben seguir funcionando exactamente igual**. El refactor a servicios no puede tener regresiones;
mientras no exista suite PHPUnit (deuda técnica #7), la verificación es manual con el
`docs/MANUAL_PANTALLAS.md` como checklist.

Se sugiere aprovechar la etapa 1b para **arrancar la suite PHPUnit** sobre los servicios:
son funciones puras que devuelven arrays, es decir, lo más fácil de testear del plugin, y
son justamente las que no pueden fallar.

**Cambios de esquema previstos: ninguno.** Todo lo nuevo vive en `wp_options` y `wp_usermeta`
(`edu_api_secret`, `edu_api_allowed_origins`, `edu_token_version`, `edu_refresh_tokens`).
`EDU_DB_VERSION` se queda en 1.0.9.

### 13.1 Etapa 1a — entregada (v1.2.0, 11 ago 2026)

| Archivo | Rol |
|---|---|
| `includes/api/class-edu-api-jwt.php` | Firma y verificación HS256, base64url, rotación del secreto |
| `includes/api/class-edu-api-auth.php` | Login, refresh rotatorio, revocación, rate limit, resolución del Bearer |
| `includes/api/class-edu-api.php` | Arranque, CORS, institución de la request, gate de módulos, helpers de respuesta y error |
| `includes/api/routes/class-edu-api-auth-routes.php` | `/auth/token`, `/auth/refresh`, `/auth/revoke` |
| `includes/api/routes/class-edu-api-me-routes.php` | `/me`, `/me/password`, `/me/sessions` |

Ajustes de apoyo: `Edu_Context::override_institution_id()` (institución solo para la request,
sin tocar el selector de wp-admin), nuevo hook `do_action( 'edu_account_suspended', $user_id )`
en `Edu_Account_Controller`, generación del secreto en la activación, y sección **API REST**
en Ajustes (dominios autorizados + casilla para cerrar todas las sesiones).

Verificado con 39 pruebas end-to-end por HTTP contra el sitio real (login, token inválido,
rotación del refresh, revocación inmediata, cuenta suspendida, rate limit y CORS con origen
hostil) y 14 pruebas unitarias del JWT, incluido el rechazo de `alg=none` y de payload
manipulado.

**Pendiente de 1a:** los endpoints todavía no usan `Edu_Api::require_module()` ni
`resolve_institution()` en rutas de dominio, porque aún no existen esas rutas. La
infraestructura está lista y probada; le falta clientela.

### 13.2 Etapa 1b — entregada (v1.3.0, 11 ago 2026)

Capa de servicios del dominio de calificaciones, y los tres controllers reducidos a
adaptadores de transporte.

| Archivo | Contenido |
|---|---|
| `includes/services/class-edu-service.php` | Base: `error()`, `require_cap()`, `require_institution()`, `validate_parcial()`, `check_scope()`, `active_student_ids()`, `uses_sumativa()`, `formula()`, `parse_score()` |
| `includes/services/class-edu-score-service.php` | `save_batch()` (captura de notas) y `flatten_matrix()` |
| `includes/services/class-edu-trimester-score-service.php` | `save_exam()`, `close_parcial()`, `close_trimester()` |
| `includes/services/class-edu-curriculum-service.php` | `save_pensum()`, `save_components()`, `resolve_or_create_component()`, `puede_crear_componente()` |

`Edu_Score_Controller`, `Edu_Trimester_Score_Controller` y `Edu_Curriculum_Controller` pasaron
de ~1.000 líneas de lógica a solo nonce, traducción de `$_POST` y redirección.
`Edu_Curriculum_Controller::resolve_or_create_component()` se conserva como envoltorio
delegante porque lo llama el controller de tareas y lo llamarán las integraciones externas.

**Contrato de la entrada del servicio de notas.** `save_batch()` recibe la lista plana de
celdas `{student_id, component_id, score}` que define el §8.2, no la matriz anidada de los
formularios. `Edu_Score_Service::flatten_matrix()` hace la conversión para el adaptador de
admin. La API manda la lista plana directamente.

**Corrección al §8.2 del contrato.** Se escribió que un parcial cerrado devolvería un 409 para
todo el lote. Es incorrecto: `wp_edu_parcial_scores.is_closed` es **por estudiante**, no por
(grado, materia, trimestre, parcial). El servicio salta a los estudiantes cerrados y los
reporta en `errors` con código `partial_closed`, sin tumbar el lote de los demás. Es lo
coherente con el modelo de datos y con la pantalla, que ya deshabilita solo esas casillas.

**Hardening incluido.** Dos huecos que existían antes y que la extracción dejó a la vista:

1. La captura de notas no comprobaba el cierre del parcial en el servidor. La pantalla
   deshabilita las casillas, pero un POST fabricado insertaba filas en `grades_log`. Ahora se
   rechazan.
2. `close_parcial()` y `close_trimester()` solo verificaban la capability, no la institución:
   un rector podía cerrar un parcial de otra institución con un POST fabricado. Ahora pasan
   por `check_scope()`.

Ninguna de las dos afecta al uso normal de wp-admin.

**Verificación.** 41 pruebas funcionales de los servicios sobre una institución de prueba
creada y borrada por la propia suite (renormalización de pesos, promedio de varias notas en un
componente, fórmula sumativa, conservación del examen al enviar solo el proyecto, cierres,
protección de componentes con notas, aislamiento entre instituciones y permisos), más 10
pruebas de los adaptadores que ejecutan cada handler con nonce y `$_POST` reales y comparan la
URL de redirección con la que esperaba cada vista. Todas en verde, y las 39 de la etapa 1a
siguen pasando.

### 13.3 Etapa 1c — entregada (v1.4.0, 11 ago 2026)

**27 endpoints de lectura**, con lo que el namespace pasa a **36 rutas**. Con esto la SPA de la
Fase 2 ya puede construirse entera sin tocar ninguna escritura.

| Servicio de lectura | Cubre |
|---|---|
| `Edu_Catalog_Service` | instituciones, períodos, trimestres, grados, catálogo Mineduc, materias, pensum |
| `Edu_People_Service` | docentes, estudiantes (paginado), representantes, asignaciones |
| `Edu_Gradebook_Service` | componentes, `GET /gradebook`, notas de trimestre, resumen anual, boleta del estudiante |
| `Edu_Activity_Service` | tareas, entregas, asistencia, comunicados, bandeja propia, pagos, auditoría |

Rutas en `includes/api/routes/`: `catalog`, `gradebook` y `activity`.

**Alcance personal aplicado en todas las lecturas** (§5.1 nivel 3), con helpers nuevos en
`Edu_Service`: `identity()`, `own_children_ids()`, `own_grade_ids()`,
`teacher_has_assignment()`, `can_view_student()`, `can_view_grade_subject()`. El docente solo ve
sus grados, materias, estudiantes y asignaciones; el representante solo a sus hijos; el
estudiante solo a sí mismo. Verificado con pruebas que intentan el acceso cruzado y esperan 403.

**`GET /gradebook` cumple el §8.1**: `scores` como mapa `component_id → nota|null`, promedio y
no última nota, `computed_score` y `cualitativa` calculados en el servidor, y `context.formula`
derivada del subnivel.

**Corrección de seguridad detectada probando.** Sin token, `/gradebook` respondía **400** en vez
de 401: WordPress valida los parámetros obligatorios de la ruta **antes** de llamar al
`permission_callback`, de modo que la petición anónima moría en la validación y de paso revelaba
qué parámetros espera la ruta. Se añadió un corte en `rest_authentication_errors` que exige
sesión en todas las rutas privadas de `edu/v1` antes del dispatch. Se exceptúan `/auth/token`,
`/auth/refresh`, `/payphone/webhook`, el índice del namespace y el preflight `OPTIONS`.

**Verificación.** 60 pruebas por HTTP real con cuatro sesiones simultáneas (rector, docente,
representante y estudiante) sobre una institución de prueba con dos grados, dos materias, tres
estudiantes, notas y un docente asignado a una sola materia. Cubren el contenido del gradebook,
la paginación con `X-WP-Total`, el gate de módulos encendido y apagado, la validación de fechas,
el 401 sin sesión y —sobre todo— los accesos cruzados que deben fallar. Las suites de 1a (39),
1b (41) y JWT (14) siguen en verde, y los adaptadores de wp-admin siguen devolviendo las mismas
URLs.

---

## 14. Decisiones abiertas

Pendientes de confirmación antes de escribir código:

1. **Vida del access token.** Se propone 15 min + refresh de 30 días. Más corto es más seguro
   y más chatty; más largo alarga la ventana de un token robado.
2. ~~**Dominio del frontend.**~~ **✅ Resuelto (12 ago 2026): la SPA se sirve desde el mismo
   dominio que WordPress.** Consecuencias, todas a favor:
   - **No hay que configurar CORS.** `Edu_Api::is_origin_allowed()` ya autoriza siempre al
     propio sitio; el campo "Dominios autorizados" de Ajustes queda vacío y sin uso. El
     mecanismo se mantiene por si algún día hace falta otro origen.
   - **Desaparece el gotcha de Apache** con la cabecera `Authorization`: al ser mismo origen,
     la SPA puede autenticarse con la cookie de WordPress + `X-WP-Nonce` (§4.3) y dejar el
     token Bearer para la app instalada y las integraciones.
   - La PWA existente sigue sirviendo tal cual: mismo origen, mismo service worker.
   - El token Bearer **no se retira**: es lo que permitirá una app nativa el día que se quiera.
3. **¿La SPA reemplaza los portales shortcode o convive con ellos?** Si conviven, los 4
   shortcodes se mantienen y el trabajo se duplica en cada feature nueva. Recomiendo declarar
   la SPA como reemplazo y congelar los portales una vez alcanzada la paridad.
4. **Multi-institución en la SPA.** ¿El Superadmin Editorial necesita el selector de institución
   también en la app, o la app es solo para una institución?
5. **`GET /dashboard/*`.** Hoy cada dashboard es una vista con muchas queries. Definir si la API
   devuelve el bloque ya armado (rápido de construir, poco flexible) o métricas atómicas que la
   SPA compone (más trabajo, más reutilizable).

### 13.4 Etapa 1d — entregada (v1.5.0, 12 ago 2026)

Los cuatro dominios que faltaban salieron de sus controllers, y con ellos llegaron **15
endpoints de escritura**. El namespace queda en **44 rutas**.

| Servicio nuevo | Contenido |
|---|---|
| `Edu_File_Service` | Almacenamiento privado compartido: constantes de tamaño y extensiones, `ensure_protected_dir()`, `store_uploads()`, `url_to_path()`, `delete_physical()`, `download_url()`, `stream()` |
| `Edu_Assignment_Service` | `save()`, `publish()`, `close()`, `delete()`, `attach_files()`, `remove_files()`, `derive_type()`, `load_for_manage()`, `can_access()` |
| `Edu_Submission_Service` | `submit()`, `grade()`, `save_recovery_settings()`, `submit_recovery()`, `grade_recovery()`, `can_download()` |
| `Edu_Attendance_Service` | `save()`, `flatten_matrix()` |
| `Edu_Announcement_Service` | `send()`, `acknowledge()`, `delete()` |

Endpoints en `includes/api/routes/class-edu-api-write-routes.php`:

```
POST   /assignments                        PUT/PATCH /assignments/{id}
DELETE /assignments/{id}                   POST      /assignments/{id}/publish
POST   /assignments/{id}/close             PUT       /assignments/{id}/recovery-settings
POST   /assignments/{id}/submissions       POST      /assignments/{id}/recovery
PUT    /submissions/{id}/grade             PUT       /submissions/{id}/recovery-grade
PUT    /attendance
POST   /announcements                      DELETE    /announcements/{id}
POST   /announcements/{id}/acknowledge
```

**Semántica de `PUT /attendance`.** Guarda el día completo del grado: los estudiantes que no
vengan en la petición quedan **presente**. Es lo que ya hacía el formulario de wp-admin (los
radios sin marcar no se envían) y lo que corresponde a un PUT. Para tocar solo a algunos
estudiantes hay que mandar la lista completa.

**Adjuntos.** Viajan como `multipart/form-data` en el campo `files[]`, la misma validación de
siempre (10 MB y lista blanca de extensiones). Las respuestas de lectura **nunca** incluyen
`file_url`: las descargas van por enlace firmado.

**Hardening incluido.**

1. `DELETE /announcements/{id}` y el borrado desde wp-admin no comprobaban nada más que la
   capability: cualquiera con `edu_grade_students` podía borrar el comunicado de otro con solo
   saber el ID. Ahora quien no ve toda la institución solo borra lo que él envió, y el rector
   solo dentro de su institución.
2. El acuse de recibo no verificaba que el comunicado fuese para uno: ahora exige ser
   destinatario.
3. La configuración de la mejora y la calificación de entregas pasan por
   `can_view_grade_subject()`: un docente ya no puede calificar entregas de materias ajenas.

**Corrección menor.** `handle_save_recovery_settings()` pasaba `'NULL'` como formato de `$wpdb`
(los válidos son `%s`, `%d`, `%f`). Ahora usa `%s`, que es lo correcto para guardar un valor
nulo.

**Verificación.** 46 pruebas por HTTP real que recorren el circuito completo —crear tarea con
componente al vuelo, publicar, entregar, calificar con normalización a 0–10, comprobar que la
nota aparece en el gradebook, cerrar, habilitar la mejora, entregarla y calificarla conservando
la mejor de las dos notas—, más asistencia, comunicados con acuse de recibo y los accesos
cruzados que deben fallar. Y 13 pruebas de adaptadores que comprueban que las nueve pantallas
de wp-admin siguen redirigiendo exactamente igual. Las suites anteriores (14 + 39 + 41 + 60)
siguen en verde.

### 13.5 Etapa 1e — entregada (v1.6.0, 12 ago 2026). **Fase 1 completa.**

Últimos 12 endpoints. El namespace queda en **56 rutas** y la API cubre los seis dominios del
§1: la app de la Fase 2 ya puede construirse entera sin ninguna pantalla de WordPress.

| Servicio nuevo | Contenido |
|---|---|
| `Edu_Payment_Service` | `get_config()`, `save_config()`, `generate_monthly()`, `register_manual()`, `waive()`, `generate_link()`, `suspend_overdue()` |
| `Edu_Report_Service` | `dashboard_rector()`, `dashboard_docente()`, `teacher_panel()`, `boletin_url()`, `mineduc_url()` |

```
GET/PUT /payment-config                POST /payments/generate-monthly
POST    /payments/{id}/manual          POST /payments/{id}/waive
POST    /payments/{id}/link            POST /payments/suspend-overdue
GET     /reports/boletin               GET  /reports/mineduc/{tipo}
GET     /dashboard/rector              GET  /dashboard/docente
GET     /dashboard/teacher-panel       GET  /files/download
```

**Descargas firmadas (§10), ya implementadas.** `GET /reports/boletin` y
`GET /reports/mineduc/{tipo}` **no devuelven el binario**: validan el permiso y responden
`{url, expires_at}` con un token HMAC de **5 minutos** atado al usuario que lo pidió. El
navegador abre esa URL en una pestaña —sin poder mandar `Authorization`— y `GET /files/download`
verifica firma, vencimiento y titular, **revalida el permiso** (el alcance pudo cambiar desde
que se emitió) y recién entonces sirve el PDF o el .xlsx con los generadores de siempre.
Compartir el enlace no sirve de nada: emitido para otra cuenta, responde 403.

**Decisión 5 del §14, resuelta.** Los dashboards se entregan **ya armados**, no como métricas
atómicas: son pantallas concretas y componerlas en el cliente costaría media docena de llamadas
por carga. `/dashboard/rector` incluye métricas, rendimiento por grado con cualitativa, alertas
de asistencia y resumen de cobranza; los bloques de módulos apagados simplemente no vienen.

**Qué NO se tocó a propósito.** El circuito de Payphone —inicio del pago, retorno del navegador
y webhook— sigue en `Edu_Payment_Controller`: son redirecciones y llamadas de la pasarela, no
operaciones de negocio. La invariante del hardening v1.0.9 queda intacta: **un pago solo se
marca como pagado desde `confirm_and_mark_paid()`**, con confirmación server-side. La única
excepción sigue siendo el registro manual, que exige `edu_view_all` y ahora además queda
auditado.

**Hardening.** `waive`, `register_manual` y `generate_link` solo comprobaban la capability:
con el ID bastaba para exonerar o marcar pagada una cuota **de otra institución**. Ahora todas
pasan por `load_payment()`, que valida pertenencia. `suspend_overdue` tampoco validaba el
período ni acotaba por institución: podía suspender cuentas de otra. Corregido. Se añadió
auditoría a exonerar, registrar pago manual y suspender morosos, que antes no dejaban rastro.

**Verificación.** 40 pruebas por HTTP real: los tres dashboards con sus cifras comprobadas
(promedio 8.40 → cualitativa B+, alerta de asistencia al 25%), el ciclo completo de pagos
(configurar, generar cuotas, emitir link, registrar manual, rechazar el doble cobro, exonerar
un pago ya cobrado, suspender morosos) y las URLs firmadas, incluyendo que un token emitido
para otra cuenta y uno manipulado se rechazan. Las cinco suites anteriores (14 + 39 + 41 + 60 +
46) siguen en verde, y los 13 adaptadores de wp-admin devuelven las mismas URLs de siempre.

### 13.6 Corrección: las escrituras de calificaciones faltaban (v1.8.0, 12 ago 2026)

Al construir la grilla del docente en la Fase 2 se descubrió que **los endpoints de escritura
del dominio de calificaciones nunca se habían registrado**. Los servicios existían desde la
etapa 1b, pero al repartir el trabajo la 1d cubrió tareas, asistencia y comunicados, y la 1e
pagos y reportes: los de notas se quedaron entre las dos.

Añadidos en `class-edu-api-write-routes.php`:

```
POST /gradebook/scores                     captura batch de notas (§8.2)
POST /components                           crear al vuelo; 200 + reused:true si ya existía
PUT  /components                           guardado en bloque del set
PUT  /trimester-scores                     examen final y proyecto
POST /trimester-scores/close-parcial
POST /trimester-scores/close-trimester
PUT  /grades/{id}/pensum
```

El namespace queda en **59 rutas**. Con esto la afirmación de la §13.5 —que la API cubre los
seis dominios— es cierta también para la escritura de calificaciones, que era el hueco.

**Lección para el resto del proyecto:** tener el servicio no significa tener el endpoint. Al
cerrar una etapa conviene contrastar el catálogo del §7 contra las rutas realmente
registradas, no contra los servicios escritos.

---

### 13.7 Fase 2 completa: el circuito de archivos (v1.9.0, 12 ago 2026)

Al construir el portal del rector se probaron por fin las descargas **desde el navegador**, y
aparecieron tres cosas que ninguna prueba por HTTP con `Authorization` podía ver.

**1. Ninguna descarga funcionaba.** El enlace firmado se abre con `window.open`, y una
navegación no puede añadir `X-WP-Nonce`. Sin nonce, WordPress descarta la cookie en REST y la
petición llega anónima, así que la comprobación de `uid` del token respondía 401 — en las cinco
descargas: boletín y los cuatro exportes Mineduc. Se corrigió incluyendo el nonce en la URL
firmada (§10). Se descartó recuperar la identidad desde el token: habría convertido el enlace
en una credencial portátil, y tratándose de datos de menores la garantía de "enlace personal"
pesa más.

**2. `PUT` con `multipart/form-data` llegaba vacío.** PHP solo parsea multipart en POST. Editar
una tarea adjuntando un archivo perdía todos los campos. Los envíos con archivos van ahora como
POST con `X-HTTP-Method-Override` (§10).

**3. Faltaba la descarga de adjuntos.** El §10 la describía y el código la mencionaba, pero el
tipo `attachment` nunca se implementó: la app veía el nombre del archivo y nada más. Se añadió
`GET /files/{id}/link?type=assignment|submission` — **la ruta 59** — y el caso `attachment` en
`/files/download`. Además `GET /assignments/{id}/submissions` incluye ahora los archivos de cada
entrega, en una sola consulta, para que el docente pueda abrir lo que le entregaron.

**Integridad referencial.** Se verificó contra `information_schema` que **no existe ni una sola
FOREIGN KEY** en las tablas `wp_edu_*`: `dbDelta()` las descarta, tal como advierte el CLAUDE.md.
Tres sitios confiaban en el `ON DELETE CASCADE` del esquema y dejaban filas huérfanas al borrar
—tareas, comunicados y grados—; ahora borran los hijos explícitamente.

**Lección, en la misma línea que la §13.6:** un endpoint probado solo con `Authorization` no
está probado para el navegador. La cookie de WordPress en REST se comporta distinto, y es el
camino que usa la app.
