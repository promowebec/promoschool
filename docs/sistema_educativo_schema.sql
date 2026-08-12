-- =====================================================
-- Sistema Educativo Integral
-- Esquema MySQL / MariaDB
-- WordPress Plugin · Versión 1.0
-- Fecha: Mayo 2026
--
-- Notas:
--   * Asume prefijo "wp_" de WordPress. Cambia con buscar/reemplazar si tu prefijo es distinto.
--   * En el plugin, usar dbDelta() de WordPress para crear/actualizar estas tablas dentro de
--     register_activation_hook(). dbDelta tiene reglas estrictas: ver inc/class-activator.php.
--   * Foreign keys se incluyen como referencia pero dbDelta las ignora; gestionar integridad
--     desde la capa PHP o aplicar las FOREIGN KEY manualmente con un migrador.
-- =====================================================

SET FOREIGN_KEY_CHECKS = 0;
SET NAMES utf8mb4;

-- =====================================================
-- 1. CORE INSTITUCIONAL
-- =====================================================

CREATE TABLE IF NOT EXISTS `wp_edu_institutions` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(200) NOT NULL,
  `ruc` VARCHAR(13) UNIQUE,
  `address` TEXT,
  `phone` VARCHAR(20),
  `email` VARCHAR(100),
  `logo_url` VARCHAR(255),
  `regime` ENUM('sierra','costa') DEFAULT 'sierra',
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `wp_edu_periods` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `institution_id` BIGINT UNSIGNED NOT NULL,
  `name` VARCHAR(50) NOT NULL COMMENT 'Ej: 2026-2027',
  `regime` ENUM('sierra','costa') NOT NULL DEFAULT 'sierra' COMMENT 'Sierra: sep-jul, Costa: may-feb',
  `start_date` DATE NOT NULL,
  `end_date` DATE NOT NULL,
  `working_days` SMALLINT DEFAULT 200 COMMENT 'Días laborables totales (estándar Mineduc)',
  `is_active` BOOLEAN DEFAULT FALSE,
  `num_trimesters` TINYINT DEFAULT 3,
  FOREIGN KEY (`institution_id`) REFERENCES `wp_edu_institutions`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `wp_edu_trimesters` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `period_id` BIGINT UNSIGNED NOT NULL,
  `number` TINYINT NOT NULL,
  `start_date` DATE NOT NULL,
  `end_date` DATE NOT NULL,
  `is_closed` BOOLEAN DEFAULT FALSE,
  `closed_at` DATETIME NULL,
  FOREIGN KEY (`period_id`) REFERENCES `wp_edu_periods`(`id`) ON DELETE CASCADE,
  UNIQUE KEY uk_period_number (`period_id`,`number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `wp_edu_grades` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `institution_id` BIGINT UNSIGNED NOT NULL,
  `level` ENUM('inicial','basica','bachillerato') NOT NULL,
  `sub_level` ENUM('inicial_1','inicial_2','preparatoria','elemental','media','superior','bg','bt') NOT NULL COMMENT 'EGB: preparatoria(1ro), elemental(2-4), media(5-7), superior(8-10) · Bach: bg(General), bt(Técnico)',
  `name` VARCHAR(50) NOT NULL COMMENT 'Ej: 5to EGB, 1ro BG, 2do BT',
  `paralelo` CHAR(2) NOT NULL COMMENT 'A, B, C, D...',
  `specialty` VARCHAR(100) NULL COMMENT 'Solo BT: Informática, Contabilidad, Electrónica, etc.',
  `tutor_user_id` BIGINT UNSIGNED NULL COMMENT 'Docente tutor (FK wp_users). Un docente puede ser tutor de N grados.',
  FOREIGN KEY (`institution_id`) REFERENCES `wp_edu_institutions`(`id`) ON DELETE CASCADE,
  INDEX idx_inst_level (`institution_id`,`level`,`sub_level`),
  INDEX idx_tutor (`tutor_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Catálogo oficial del Ministerio de Educación (precargado en activación del plugin)
CREATE TABLE IF NOT EXISTS `wp_edu_subjects_catalog` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(150) NOT NULL,
  `sub_levels` SET('preparatoria','elemental','media','superior','bg','bt') NOT NULL COMMENT 'Subniveles donde se dicta esta materia',
  `area` VARCHAR(50),
  `is_active` BOOLEAN DEFAULT TRUE,
  `source` ENUM('mineduc','institution') DEFAULT 'mineduc',
  UNIQUE KEY uk_catalog_name (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `wp_edu_subjects` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `institution_id` BIGINT UNSIGNED NOT NULL,
  `catalog_id` BIGINT UNSIGNED NULL COMMENT 'NULL si es materia propia de la institución',
  `name` VARCHAR(150) NOT NULL,
  `code` VARCHAR(20),
  `area` VARCHAR(50),
  `is_custom` BOOLEAN DEFAULT FALSE COMMENT 'TRUE si es materia propia, no del Mineduc',
  FOREIGN KEY (`institution_id`) REFERENCES `wp_edu_institutions`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`catalog_id`) REFERENCES `wp_edu_subjects_catalog`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- Datos iniciales: Catálogo Ministerio de Educación Ecuador
-- =====================================================

INSERT INTO `wp_edu_subjects_catalog` (`name`, `sub_levels`, `area`) VALUES
-- EGB Preparatoria (1ro EGB)
('Currículo Integrador por ámbitos de aprendizaje', 'preparatoria', 'Integradora'),
-- Comunes a Elemental, Media y Superior
('Lengua y Literatura', 'elemental,media,superior,bg', 'Lengua'),
('Matemática', 'elemental,media,superior,bg', 'Ciencias exactas'),
('Estudios Sociales', 'elemental,media,superior', 'Ciencias sociales'),
('Ciencias Naturales', 'elemental,media,superior', 'Ciencias naturales'),
('Inglés', 'elemental,media,superior,bg', 'Lengua extranjera'),
('Educación Cultural y Artística', 'preparatoria,elemental,media,superior,bg', 'Artística'),
('Educación Física', 'preparatoria,elemental,media,superior,bg', 'Física'),
('Acompañamiento integral en el aula', 'elemental,media,superior,bg', 'Tutoría'),
('Animación a la lectura', 'elemental,media,superior', 'Lengua'),
-- Solo EGB Superior
('Orientación vocacional y profesional', 'superior', 'Tutoría'),
-- Bachillerato General
('Física', 'bg', 'Ciencias naturales'),
('Química', 'bg', 'Ciencias naturales'),
('Biología', 'bg', 'Ciencias naturales'),
('Historia', 'bg', 'Ciencias sociales'),
('Educación para la ciudadanía', 'bg', 'Ciencias sociales'),
('Filosofía', 'bg', 'Ciencias sociales'),
('Emprendimiento y Gestión', 'bg', 'Económica');

CREATE TABLE IF NOT EXISTS `wp_edu_grade_subjects` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `grade_id` BIGINT UNSIGNED NOT NULL,
  `subject_id` BIGINT UNSIGNED NOT NULL,
  `hours_week` TINYINT DEFAULT 5,
  FOREIGN KEY (`grade_id`) REFERENCES `wp_edu_grades`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`subject_id`) REFERENCES `wp_edu_subjects`(`id`) ON DELETE CASCADE,
  UNIQUE KEY uk_grade_subject (`grade_id`,`subject_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 2. PERSONAS (vinculadas a wp_users)
-- =====================================================

CREATE TABLE IF NOT EXISTS `wp_edu_teachers` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` BIGINT UNSIGNED NOT NULL UNIQUE,
  `cedula` VARCHAR(13) UNIQUE,
  `phone` VARCHAR(20),
  `title` VARCHAR(50) COMMENT 'Lcdo, Ing, MSc...',
  `hire_date` DATE,
  `is_active` BOOLEAN DEFAULT TRUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `wp_edu_students` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` BIGINT UNSIGNED NOT NULL UNIQUE,
  `grade_id` BIGINT UNSIGNED NOT NULL,
  `cedula` VARCHAR(13) UNIQUE,
  `birth_date` DATE,
  `sexo` ENUM('M','F') NULL COMMENT 'Requerido por nómina AMIE',
  `direccion` VARCHAR(255) NULL COMMENT 'Domicilio — requerido por nómina AMIE',
  `enrollment_date` DATE,
  `photo_url` VARCHAR(255),
  `status` ENUM('active','inactive','transferred','graduated') DEFAULT 'active',
  FOREIGN KEY (`grade_id`) REFERENCES `wp_edu_grades`(`id`),
  INDEX idx_grade_status (`grade_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `wp_edu_parents` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` BIGINT UNSIGNED NOT NULL UNIQUE,
  `cedula` VARCHAR(13) UNIQUE,
  `phone` VARCHAR(20),
  `whatsapp` VARCHAR(20),
  `occupation` VARCHAR(100)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `wp_edu_parent_student` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `parent_id` BIGINT UNSIGNED NOT NULL,
  `student_id` BIGINT UNSIGNED NOT NULL,
  `relationship` ENUM('padre','madre','tutor','otro') NOT NULL,
  `is_primary` BOOLEAN DEFAULT FALSE COMMENT 'Receptor principal de comunicados',
  FOREIGN KEY (`parent_id`) REFERENCES `wp_edu_parents`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`student_id`) REFERENCES `wp_edu_students`(`id`) ON DELETE CASCADE,
  UNIQUE KEY uk_parent_student (`parent_id`,`student_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `wp_edu_teacher_assignments` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `teacher_id` BIGINT UNSIGNED NOT NULL,
  `grade_id` BIGINT UNSIGNED NOT NULL,
  `subject_id` BIGINT UNSIGNED NOT NULL,
  `period_id` BIGINT UNSIGNED NOT NULL,
  `is_active` BOOLEAN DEFAULT TRUE,
  FOREIGN KEY (`teacher_id`) REFERENCES `wp_edu_teachers`(`id`),
  FOREIGN KEY (`grade_id`) REFERENCES `wp_edu_grades`(`id`),
  FOREIGN KEY (`subject_id`) REFERENCES `wp_edu_subjects`(`id`),
  FOREIGN KEY (`period_id`) REFERENCES `wp_edu_periods`(`id`),
  UNIQUE KEY uk_teacher_assignment (`teacher_id`,`grade_id`,`subject_id`,`period_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 3. ACADÉMICO
-- =====================================================

CREATE TABLE IF NOT EXISTS `wp_edu_assignments` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `teacher_id` BIGINT UNSIGNED NOT NULL,
  `grade_id` BIGINT UNSIGNED NOT NULL,
  `subject_id` BIGINT UNSIGNED NOT NULL,
  `trimester_id` BIGINT UNSIGNED NOT NULL,
  `parcial_num` TINYINT NOT NULL DEFAULT 1,
  `type` ENUM('tarea','leccion','trabajo','deber','examen','correccion') NOT NULL,
  `title` VARCHAR(200) NOT NULL,
  `description` TEXT,
  `due_date` DATETIME,
  `max_score` DECIMAL(4,2) DEFAULT 10.00,
  `weight` DECIMAL(4,2) DEFAULT 1.00,
  `status` ENUM('draft','published','closed') DEFAULT 'draft',
  `notify_parents` BOOLEAN DEFAULT TRUE,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`teacher_id`) REFERENCES `wp_edu_teachers`(`id`),
  FOREIGN KEY (`grade_id`) REFERENCES `wp_edu_grades`(`id`),
  FOREIGN KEY (`subject_id`) REFERENCES `wp_edu_subjects`(`id`),
  FOREIGN KEY (`trimester_id`) REFERENCES `wp_edu_trimesters`(`id`),
  INDEX idx_grade_subject_trim (`grade_id`,`subject_id`,`trimester_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `wp_edu_assignment_files` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `assignment_id` BIGINT UNSIGNED NOT NULL,
  `file_url` VARCHAR(500) NOT NULL,
  `file_name` VARCHAR(200),
  `file_type` VARCHAR(50),
  `file_size` INT,
  FOREIGN KEY (`assignment_id`) REFERENCES `wp_edu_assignments`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `wp_edu_submissions` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `assignment_id` BIGINT UNSIGNED NOT NULL,
  `student_id` BIGINT UNSIGNED NOT NULL,
  `status` ENUM('pending','submitted','graded','returned','late') DEFAULT 'pending',
  `comment` TEXT,
  `submitted_at` DATETIME NULL,
  `score` DECIMAL(4,2) NULL,
  `feedback` TEXT,
  `graded_at` DATETIME NULL,
  `graded_by` BIGINT UNSIGNED NULL,
  FOREIGN KEY (`assignment_id`) REFERENCES `wp_edu_assignments`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`student_id`) REFERENCES `wp_edu_students`(`id`),
  UNIQUE KEY uk_assignment_student (`assignment_id`,`student_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `wp_edu_submission_files` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `submission_id` BIGINT UNSIGNED NOT NULL,
  `file_url` VARCHAR(500) NOT NULL,
  `file_name` VARCHAR(200),
  `file_type` VARCHAR(50),
  `file_size` INT,
  FOREIGN KEY (`submission_id`) REFERENCES `wp_edu_submissions`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `wp_edu_rubrics` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `assignment_id` BIGINT UNSIGNED NOT NULL,
  `criterion` VARCHAR(200) NOT NULL,
  `descriptor` TEXT,
  `max_score` DECIMAL(4,2) DEFAULT 10.00,
  FOREIGN KEY (`assignment_id`) REFERENCES `wp_edu_assignments`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `wp_edu_rubric_scores` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `submission_id` BIGINT UNSIGNED NOT NULL,
  `rubric_id` BIGINT UNSIGNED NOT NULL,
  `score` DECIMAL(4,2) NOT NULL,
  FOREIGN KEY (`submission_id`) REFERENCES `wp_edu_submissions`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`rubric_id`) REFERENCES `wp_edu_rubrics`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `wp_edu_grade_components` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `subject_id` BIGINT UNSIGNED NOT NULL,
  `trimester_id` BIGINT UNSIGNED NOT NULL,
  `parcial_num` TINYINT NOT NULL,
  `name` VARCHAR(100) NOT NULL COMMENT 'Tareas, Lecciones, Trabajos, Prueba...',
  `weight` DECIMAL(4,2) NOT NULL COMMENT 'Suma de pesos del parcial = 1.00 (se renormaliza si difiere)',
  `created_by` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '0 = institucional (admin/rector); >0 = user_id del docente creador',
  FOREIGN KEY (`subject_id`) REFERENCES `wp_edu_subjects`(`id`),
  FOREIGN KEY (`trimester_id`) REFERENCES `wp_edu_trimesters`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `wp_edu_grades_log` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `student_id` BIGINT UNSIGNED NOT NULL,
  `component_id` BIGINT UNSIGNED NOT NULL,
  `assignment_id` BIGINT UNSIGNED NULL,
  `score` DECIMAL(4,2) NOT NULL COMMENT 'Escala 0.00 a 10.00',
  `registered_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `registered_by` BIGINT UNSIGNED NOT NULL,
  FOREIGN KEY (`student_id`) REFERENCES `wp_edu_students`(`id`),
  FOREIGN KEY (`component_id`) REFERENCES `wp_edu_grade_components`(`id`),
  INDEX idx_student_component (`student_id`,`component_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `wp_edu_parcial_scores` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `student_id` BIGINT UNSIGNED NOT NULL,
  `subject_id` BIGINT UNSIGNED NOT NULL,
  `trimester_id` BIGINT UNSIGNED NOT NULL,
  `parcial_num` TINYINT NOT NULL,
  `computed_score` DECIMAL(4,2) DEFAULT 0,
  `is_closed` BOOLEAN DEFAULT FALSE,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`student_id`) REFERENCES `wp_edu_students`(`id`),
  FOREIGN KEY (`subject_id`) REFERENCES `wp_edu_subjects`(`id`),
  FOREIGN KEY (`trimester_id`) REFERENCES `wp_edu_trimesters`(`id`),
  UNIQUE KEY uk_parcial (`student_id`,`subject_id`,`trimester_id`,`parcial_num`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `wp_edu_trimester_scores` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `student_id` BIGINT UNSIGNED NOT NULL,
  `subject_id` BIGINT UNSIGNED NOT NULL,
  `trimester_id` BIGINT UNSIGNED NOT NULL,
  `parcial1_score` DECIMAL(4,2) DEFAULT 0,
  `parcial2_score` DECIMAL(4,2) DEFAULT 0,
  `final_exam_score` DECIMAL(4,2) DEFAULT 0 COMMENT 'parte del 30% sumativo',
  `proyecto_score` DECIMAL(4,2) DEFAULT 0 COMMENT 'parte del 30% sumativo',
  `recovery_score` DECIMAL(4,2) NULL,
  `computed_score` DECIMAL(4,2) DEFAULT 0 COMMENT '(P1+P2)/2*0.7+((EF+Proy)/2)*0.3 para media/superior/bach',
  `is_closed` BOOLEAN DEFAULT FALSE,
  FOREIGN KEY (`student_id`) REFERENCES `wp_edu_students`(`id`),
  FOREIGN KEY (`subject_id`) REFERENCES `wp_edu_subjects`(`id`),
  FOREIGN KEY (`trimester_id`) REFERENCES `wp_edu_trimesters`(`id`),
  UNIQUE KEY uk_trimester_score (`student_id`,`subject_id`,`trimester_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `wp_edu_year_scores` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `student_id` BIGINT UNSIGNED NOT NULL,
  `subject_id` BIGINT UNSIGNED NOT NULL,
  `period_id` BIGINT UNSIGNED NOT NULL,
  `trim1` DECIMAL(4,2) DEFAULT 0,
  `trim2` DECIMAL(4,2) DEFAULT 0,
  `trim3` DECIMAL(4,2) DEFAULT 0,
  `average` DECIMAL(4,2) DEFAULT 0,
  `supletorio_score` DECIMAL(4,2) NULL,
  `remedial_score` DECIMAL(4,2) NULL,
  `gracia_score` DECIMAL(4,2) NULL,
  `status` ENUM('en_curso','aprobado','supletorio','remedial','gracia','reprobado') DEFAULT 'en_curso',
  FOREIGN KEY (`student_id`) REFERENCES `wp_edu_students`(`id`),
  FOREIGN KEY (`subject_id`) REFERENCES `wp_edu_subjects`(`id`),
  FOREIGN KEY (`period_id`) REFERENCES `wp_edu_periods`(`id`),
  UNIQUE KEY uk_year_score (`student_id`,`subject_id`,`period_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 4. ASISTENCIA
-- =====================================================

CREATE TABLE IF NOT EXISTS `wp_edu_attendance` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `student_id` BIGINT UNSIGNED NOT NULL,
  `subject_id` BIGINT UNSIGNED NULL COMMENT 'NULL si es asistencia diaria general',
  `teacher_id` BIGINT UNSIGNED NOT NULL,
  `date` DATE NOT NULL,
  `status` ENUM('presente','atraso','falta_justificada','falta_injustificada') NOT NULL,
  `justification` TEXT,
  `registered_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`student_id`) REFERENCES `wp_edu_students`(`id`),
  FOREIGN KEY (`subject_id`) REFERENCES `wp_edu_subjects`(`id`),
  FOREIGN KEY (`teacher_id`) REFERENCES `wp_edu_teachers`(`id`),
  UNIQUE KEY uk_attendance (`student_id`,`subject_id`,`date`),
  INDEX idx_date_status (`date`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 5. COMUNICACIÓN
-- =====================================================

CREATE TABLE IF NOT EXISTS `wp_edu_announcement_templates` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(100) NOT NULL,
  `subject_template` VARCHAR(200),
  `body_template` TEXT,
  `category` VARCHAR(50),
  `created_by` BIGINT UNSIGNED NOT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `wp_edu_announcements` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `sender_user_id` BIGINT UNSIGNED NOT NULL,
  `scope` ENUM('student','grade','multi_grade','institution') NOT NULL,
  `target_grade_id` BIGINT UNSIGNED NULL,
  `target_student_id` BIGINT UNSIGNED NULL,
  `template_id` BIGINT UNSIGNED NULL,
  `title` VARCHAR(200) NOT NULL,
  `body` TEXT,
  `channels` SET('email','portal','whatsapp') DEFAULT 'email,portal',
  `sent_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`template_id`) REFERENCES `wp_edu_announcement_templates`(`id`),
  FOREIGN KEY (`target_grade_id`) REFERENCES `wp_edu_grades`(`id`),
  FOREIGN KEY (`target_student_id`) REFERENCES `wp_edu_students`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `wp_edu_announcement_recipients` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `announcement_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `channel` ENUM('email','portal','whatsapp') NOT NULL,
  `sent_at` DATETIME NULL,
  `delivered_at` DATETIME NULL,
  `read_at` DATETIME NULL COMMENT 'Acuse de recibo',
  FOREIGN KEY (`announcement_id`) REFERENCES `wp_edu_announcements`(`id`) ON DELETE CASCADE,
  INDEX idx_user_read (`user_id`,`read_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 6. AUDITORÍA
-- =====================================================

CREATE TABLE IF NOT EXISTS `wp_edu_audit` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `action` VARCHAR(50) NOT NULL COMMENT 'create, update, delete, login, close_partial...',
  `entity_type` VARCHAR(50) NOT NULL,
  `entity_id` BIGINT UNSIGNED,
  `old_value` JSON,
  `new_value` JSON,
  `ip_address` VARCHAR(45),
  `user_agent` VARCHAR(255),
  `timestamp` DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_entity (`entity_type`,`entity_id`),
  INDEX idx_user_time (`user_id`,`timestamp`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- =====================================================
-- Total: 28 tablas + catálogo precargado del Mineduc
-- Cambios v1.1:
--   + Tabla wp_edu_subjects_catalog (currículo oficial)
--   + Períodos: regime (sierra/costa) + working_days (200)
--   + Grados: sub_level (preparatoria/elemental/media/superior/bg/bt)
--   + Grados: specialty (para BT: Informática, Contabilidad, etc.)
--   + Datos iniciales: 18 materias del currículo Mineduc Ecuador
-- Fin del script
-- =====================================================
