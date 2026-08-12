# Fase 7 — Integraciones: Cuentas, Pagos, WhatsApp, Exportes Mineduc
**Fecha:** 2026-06-12  
**Estado:** Pendiente aprobación  
**Plugin:** Sistema Educativo Integral (WordPress)

---

## Resumen ejecutivo

La Fase 7 incorpora cuatro sub-fases independientes que completan el ciclo operativo de la institución educativa:

| Sub-fase | Área | Dependencia |
|---|---|---|
| 7A | Gestión de cuentas (activar/desactivar) | Ninguna |
| 7B | Módulo de pagos (Payphone + manual) | 7A |
| 7C | Notificaciones WhatsApp | 7B (para recordatorio de pago) |
| 7D | Exportes Mineduc (Excel XLSX) | Ninguna |

Orden de implementación: **7A → 7B → 7C → 7D**

---

## Sub-fase 7A — Gestión de cuentas

### Objetivo
El rector puede activar o suspender cuentas de padres de familia y estudiantes desde el panel. Suspender un padre suspende automáticamente todos sus hijos vinculados.

### Modelo de datos
No se añade tabla nueva. Se usa `wp_usermeta`:

```
meta_key:  edu_account_status
meta_value: 'active' | 'suspended'
```

Todos los usuarios `edu_padre` y `edu_estudiante` arrancan con `active` (valor por defecto ausente = activo).

### Lógica de bloqueo de login
Hook en `authenticate` (prioridad 30, después de que WP valide credenciales):

```php
add_filter('authenticate', function($user, $username, $password) {
    if (!($user instanceof WP_User)) return $user;
    $status = get_user_meta($user->ID, 'edu_account_status', true);
    if ('suspended' === $status) {
        return new WP_Error('account_suspended',
            'Tu cuenta está suspendida. Contacta a la institución.');
    }
    return $user;
}, 30, 3);
```

### Cascada padre → hijos
Al suspender un padre: se consultan todos los `student_id` vinculados en `wp_edu_parent_student` → se actualiza `edu_account_status = suspended` en `wp_usermeta` de cada estudiante.  
Al reactivar un padre: se reactivan automáticamente todos sus hijos.

### Vista admin — tab "Cuentas"
**Ruta:** `admin/views/cuentas.php`  
Filtros: por grado, por estado (todos / activos / suspendidos).  
Tabla: Nombre · Rol · Grado (si es estudiante) · Estado · Fecha suspensión · Acción (toggle).  
Botones masivos: "Suspender seleccionados" / "Reactivar seleccionados".

### Controller
**Archivo:** `includes/controllers/class-edu-account-controller.php`  
Acciones admin-post: `edu_toggle_account_status`, `edu_bulk_account_status`  
Capability requerida: `edu_view_all`  
Registro en `wp_edu_audit` de cada cambio: usuario afectado, estado anterior, nuevo estado, quién lo hizo, cuándo.

---

## Sub-fase 7B — Módulo de pagos

### Objetivo
Registrar pensiones mensuales, cobrar en línea con Payphone, permitir registro manual, y vincular el estado de pago a la suspensión de cuentas.

### Tablas nuevas

```sql
CREATE TABLE wp_edu_payment_config (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  institution_id  BIGINT UNSIGNED NOT NULL,
  period_id       BIGINT UNSIGNED NOT NULL,
  grade_id        BIGINT UNSIGNED NULL COMMENT 'NULL = aplica a todos los grados',
  monthly_amount  DECIMAL(8,2) NOT NULL DEFAULT 0,
  matricula_amount DECIMAL(8,2) NOT NULL DEFAULT 0,
  due_day         TINYINT NOT NULL DEFAULT 5 COMMENT 'Día del mes de vencimiento',
  grace_days      TINYINT NOT NULL DEFAULT 5 COMMENT 'Días de gracia antes de marcar overdue',
  UNIQUE KEY uk_config (institution_id, period_id, grade_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE wp_edu_payments (
  id                     BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  student_id             BIGINT UNSIGNED NOT NULL,
  period_id              BIGINT UNSIGNED NOT NULL,
  month                  CHAR(7) NOT NULL COMMENT 'YYYY-MM',
  concept                ENUM('matricula','pension') DEFAULT 'pension',
  amount                 DECIMAL(8,2) NOT NULL,
  due_date               DATE NOT NULL,
  status                 ENUM('pending','paid','overdue','waived') DEFAULT 'pending',
  paid_at                DATETIME NULL,
  payment_method         ENUM('payphone','manual','link') NULL,
  payment_ref            VARCHAR(100) NULL COMMENT 'Referencia o comprobante manual',
  payphone_transaction_id VARCHAR(100) NULL,
  registered_by          BIGINT UNSIGNED NULL COMMENT 'wp_users.ID si fue manual',
  UNIQUE KEY uk_payment (student_id, period_id, month, concept),
  INDEX idx_student_period (student_id, period_id),
  INDEX idx_status_due (status, due_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### Cron diario
`edu_payment_daily_cron` — ejecuta dos tareas:
1. **Generación:** Para cada estudiante activo del período activo, crea registros `wp_edu_payments` para el mes corriente si no existen.
2. **Vencimiento:** Cambia a `overdue` todos los `pending` cuya `due_date + grace_days < hoy`.

### Flujo de pago en línea (Payphone)

**Clase:** `modules/pagos/class-edu-payphone.php`  
Método principal: `Edu_Payphone::create_payment($amount, $reference, $return_url, $cancel_url)`  
Retorna URL de redirección a Payphone.

**Webhook endpoint:** `wp-json/edu/v1/payphone/webhook`  
Verifica firma HMAC de Payphone → actualiza `wp_edu_payments.status = paid` → dispara hook `edu_payment_confirmed($payment_id)`.

**Flujo completo:**
```
Padre clic "Pagar" (mes X)
  → POST edu_init_payment
    → Payphone::create_payment()
    → redirect a URL Payphone
      → Padre ingresa tarjeta
        → Payphone webhook → edu_payment_confirmed
          → Actualizar wp_edu_payments
          → Si pagó todo: reactivar cuenta (7A)
          → Enviar WA confirmación (7C)
        → Redirect return_url → portal padre tab Pagos (mensaje éxito)
```

### Link de pago manual (rector)
El rector puede generar una URL firmada para un pago específico:  
`/edu-pago/{token}` → muestra nombre, monto, mes → botón "Pagar con Payphone"  
El token expira en 72 horas. Se puede enviar por WA/email.

### Registro manual (rector)
Formulario en el panel: seleccionar estudiante + mes + monto + método + referencia → registra `paid` inmediatamente.

### Portal padre — tab "Pagos"
Tabla por mes del período activo:

| Mes | Monto | Vencimiento | Estado | Acción |
|---|---|---|---|---|
| Enero 2026 | $45.00 | 05/01/2026 | ✅ Pagado | — |
| Febrero 2026 | $45.00 | 05/02/2026 | ⚠️ Vencido | **Pagar** |
| Marzo 2026 | $45.00 | 05/03/2026 | 🔵 Pendiente | **Pagar** |

### Panel rector — vista "Pagos"
- Configuración: monto mensual por grado, día de vencimiento, días de gracia.
- Dashboard: tabla con semáforo por estudiante × mes (verde/amarillo/rojo).
- Filtros: grado, mes, estado.
- Acciones masivas: "Enviar recordatorio WA a morosos" / "Suspender cuentas vencidas > X días".

### Archivos
- `modules/pagos/class-edu-payphone.php` (nuevo)
- `modules/pagos/class-edu-payment-manager.php` (nuevo)
- `includes/controllers/class-edu-payment-controller.php` (nuevo)
- `admin/views/pagos.php` (nueva vista admin)
- `public/shortcodes/class-edu-shortcode-padre.php` (agregar tab Pagos)
- `sistema-educativo.php`: registrar cron, webhook REST, admin-post actions

---

## Sub-fase 7C — Notificaciones WhatsApp

### Objetivo
Enviar notificaciones automáticas vía WhatsApp a padres y estudiantes. El rector controla qué tipos de notificación están activos y configura el proveedor desde Ajustes.

### Proveedores soportados

| Proveedor | Costo | Requisito |
|---|---|---|
| Meta WhatsApp Cloud API | Gratis primeras 1.000 conv/mes | Número verificado en Meta Business Manager |
| Twilio | ~$0.005/mensaje | Cuenta Twilio + número habilitado para WA |
| UltraMsg | ~$14.99/1.000 mensajes | Cuenta UltraMsg |

El rector elige el proveedor activo desde Ajustes. Si no está configurado, las notificaciones no se envían (sin errores visibles al padre).

### Configuración en Ajustes (wp_options)

```
edu_wa_provider        = 'meta' | 'twilio' | ultramsg'
edu_wa_api_key         = '...'
edu_wa_phone_number_id = '...'  (solo Meta)
edu_wa_from_number     = '+593...'
edu_wa_twilio_sid      = '...'  (solo Twilio)
edu_wa_ultramsg_instance = '...' (solo UltraMsg)

edu_wa_notify_calificacion = 1 | 0
edu_wa_notify_tarea        = 1 | 0
edu_wa_notify_comunicado   = 1 | 0
edu_wa_notify_pago         = 1 | 0
```

### Toggles por tipo de notificación (rector en Ajustes)

| Toggle | Disparo | Destinatario |
|---|---|---|
| ✅/❌ Nueva calificación | Hook `edu_grade_logged` | Padre del estudiante |
| ✅/❌ Tarea nueva publicada | Assignment `status → published` | Padre + Estudiante |
| ✅/❌ Comunicado institucional | `edu_announcement_sent` (si rector marcó checkbox WA) | Padres del scope |
| ✅/❌ Recordatorio de pago vencido | Cron diario / botón manual rector | Padre con mora |

**Para comunicados:** el toggle global debe estar ON para que aparezca el checkbox "Enviar también por WhatsApp" al redactar el comunicado. El rector decide mensaje a mensaje si envía por WA, independientemente del toggle global.

### Clase principal

**Archivo:** `modules/comunicados/class-edu-whatsapp.php`

```php
class Edu_WhatsApp {
    public static function send(int $user_id, string $event, array $vars): bool
    // Obtiene teléfono de wp_usermeta (whatsapp o phone)
    // Verifica toggle activo para el event
    // Enruta al proveedor configurado
    // Registra en wp_edu_whatsapp_log
    
    private static function send_meta(string $phone, string $template, array $vars): bool
    private static function send_twilio(string $phone, string $message): bool
    private static function send_ultramsg(string $phone, string $message): bool
}
```

### Mensajes por evento (texto plano, compatible todos los proveedores)

| Evento | Mensaje |
|---|---|
| calificacion | "📊 *{nombre_institucion}*: {nombre_estudiante} tiene nueva nota en {materia}: *{nota}* (T{trimestre}). Ingresa al portal para ver el detalle." |
| tarea | "📋 *{nombre_institucion}*: Nueva tarea publicada para {nombre_estudiante} en {materia}: *{titulo_tarea}*. Fecha límite: {fecha}." |
| comunicado | "📢 *{nombre_institucion}*: {titulo_comunicado}. Ingresa al portal del representante para leer el comunicado completo." |
| pago | "💳 *{nombre_institucion}*: La pensión de {mes} de {nombre_estudiante} está vencida desde {fecha_vencimiento}. Ingresa al portal para pagar." |

### Tabla de log

```sql
CREATE TABLE wp_edu_whatsapp_log (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  to_phone        VARCHAR(20) NOT NULL,
  to_user_id      BIGINT UNSIGNED NOT NULL,
  provider        VARCHAR(20) NOT NULL,
  event_type      VARCHAR(50) NOT NULL,
  message_preview VARCHAR(150) NOT NULL,
  status          ENUM('sent','failed','queued') DEFAULT 'queued',
  error_message   TEXT NULL,
  sent_at         DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_user_event (to_user_id, event_type),
  INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### Archivos
- `modules/comunicados/class-edu-whatsapp.php` (nuevo)
- `admin/views/ajustes.php` (nueva sección WhatsApp: proveedor + API keys + 4 toggles)
- `admin/views/comunicados.php` (checkbox WA al redactar)
- `public/shortcodes/class-edu-shortcode-rector.php` (checkbox WA en comunicado frontend)

---

## Sub-fase 7D — Exportes Mineduc

### Objetivo
Generar archivos Excel (.xlsx) en formato compatible con SIME/AMIE del Ministerio de Educación Ecuador, descargables directamente desde el panel admin.

### Dependencia
PhpSpreadsheet v1.x vía Composer. No reemplaza mPDF (boletines PDF siguen iguales).  
`composer require phpoffice/phpspreadsheet`

### 4 tipos de reporte

#### 1. Acta consolidada de calificaciones
**Filtros:** Período + Grado + Paralelo  
**Columnas:** N° · Cédula · Apellidos · Nombres · [Por cada materia: P1 · P2 · T1 · T2 · T3 · Promedio · Estado]  
**Formato:** Una fila por estudiante, materias en columnas agrupadas. Encabezado con nombre institución, grado, período, fecha de generación.

#### 2. Nómina de estudiantes (AMIE)
**Filtros:** Período + Grado  
**Columnas:** N° · Cédula · Apellidos · Nombres · Fecha nacimiento · Sexo · Dirección · Teléfono representante · Cédula representante  
**Formato:** Compatible con importación AMIE del Mineduc.

#### 3. Distributivo docente
**Filtros:** Período  
**Columnas:** N° · Cédula docente · Apellidos · Nombres · Título · Materia · Grado · Paralelo · Horas/semana  

#### 4. Asistencia acumulada
**Filtros:** Período + Grado  
**Columnas:** N° · Cédula · Apellidos · Nombres · Días asistidos · Faltas justificadas · Faltas injustificadas · Atrasos · Total días laborables · % Asistencia  

### Vista admin
**Archivo:** `admin/views/exportes-mineduc.php`  
Formulario con: tipo de reporte (radio) + período (select) + grado (select, si aplica) + botón "Descargar Excel".  
La descarga es directa (Content-Disposition: attachment), sin almacenamiento en servidor.

### Clase exportadora
**Archivo:** `modules/reportes/class-edu-mineduc-exporter.php`

```php
class Edu_Mineduc_Exporter {
    public static function acta_consolidada(int $period_id, int $grade_id): void
    public static function nomina_amie(int $period_id, int $grade_id): void
    public static function distributivo_docente(int $period_id): void
    public static function asistencia_acumulada(int $period_id, int $grade_id): void
    // Cada método setea headers HTTP y hace exit tras enviar el archivo
}
```

### Archivos
- `modules/reportes/class-edu-mineduc-exporter.php` (nuevo)
- `admin/views/exportes-mineduc.php` (nueva vista admin)
- `composer.json` (agregar phpoffice/phpspreadsheet)
- `sistema-educativo.php` (registrar menú admin "Exportes Mineduc")

---

## Resumen de archivos nuevos/modificados

| Archivo | Sub-fase | Tipo |
|---|---|---|
| `includes/controllers/class-edu-account-controller.php` | 7A | Nuevo |
| `admin/views/cuentas.php` | 7A | Nuevo |
| `sistema-educativo.php` | 7A/7B/7C | Modificar |
| `modules/pagos/class-edu-payphone.php` | 7B | Nuevo |
| `modules/pagos/class-edu-payment-manager.php` | 7B | Nuevo |
| `includes/controllers/class-edu-payment-controller.php` | 7B | Nuevo |
| `admin/views/pagos.php` | 7B | Nuevo |
| `public/shortcodes/class-edu-shortcode-padre.php` | 7B | Modificar (tab Pagos) |
| `modules/comunicados/class-edu-whatsapp.php` | 7C | Nuevo |
| `admin/views/ajustes.php` | 7C | Modificar |
| `admin/views/comunicados.php` | 7C | Modificar |
| `public/shortcodes/class-edu-shortcode-rector.php` | 7C | Modificar |
| `modules/reportes/class-edu-mineduc-exporter.php` | 7D | Nuevo |
| `admin/views/exportes-mineduc.php` | 7D | Nuevo |
| `composer.json` | 7D | Modificar |
| `includes/class-edu-activator.php` | 7B/7C | Modificar (nuevas tablas) |

---

## Decisiones de diseño clave

1. **Payphone es el único gateway de pago** — no se añade soporte multi-gateway para evitar complejidad innecesaria. El campo `payment_method` en `wp_edu_payments` permite extensión futura.

2. **Estado de cuenta en usermeta, no en tabla nueva** — más simple, integra con el hook `authenticate` de WordPress sin queries adicionales en cada login.

3. **WhatsApp con texto plano por defecto** — compatible con los 3 proveedores sin necesitar aprobación de plantillas de Meta para Twilio/UltraMsg. Meta Cloud API puede usar templates aprobados si la institución los tiene.

4. **PhpSpreadsheet descarga directa** — no se guardan archivos en el servidor para evitar exposición de datos sensibles.

5. **Cascada padre→hijos en memoria PHP** — no con trigger de BD, para mantener compatibilidad con dbDelta y tener registro en `wp_edu_audit` de cada cambio individual.
