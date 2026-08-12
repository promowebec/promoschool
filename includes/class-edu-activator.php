<?php
/**
 * Activación del plugin: crea las 28 tablas, precarga el catálogo Mineduc y registra roles.
 *
 * Notas dbDelta:
 *   - dbDelta ignora FOREIGN KEYs; la integridad referencial se gestiona desde PHP.
 *   - Reglas estrictas: PRIMARY KEY seguido de DOS espacios, cada campo en su línea,
 *     `KEY` en lugar de `INDEX`, tipos en minúsculas.
 *   - JSON nativo se reemplaza por longtext (más portable y compatible con dbDelta).
 *
 * @package SistemaEducativo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Edu_Activator {

	public static function activate() {
		self::check_requirements();
		self::create_tables();
		self::run_incremental_migrations();
		self::seed_subjects_catalog();
		Edu_Roles::register_roles();

		update_option( 'edu_db_version', EDU_DB_VERSION );
		update_option( 'edu_plugin_version', EDU_VERSION );

		// Proteger el directorio de archivos privados (tareas/entregas).
		if ( class_exists( 'Edu_Assignment_Task_Controller' ) ) {
			Edu_Assignment_Task_Controller::ensure_protected_dir();
		}

		// Secreto de firma de la API REST (tokens y URLs firmadas de descarga).
		// Edu_Api_Jwt::secret() lo genera si todavía no existe.
		if ( class_exists( 'Edu_Api_Jwt' ) ) {
			Edu_Api_Jwt::secret();
		}

		// Registrar rewrite rules del PWA para que estén disponibles de inmediato.
		Edu_PWA::flush();
	}

	/**
	 * Migraciones incrementales, con gate por versión de BD.
	 *
	 * Se llama desde edu_bootstrap() en cada carga del plugin, pero solo actúa
	 * cuando wp_options.edu_db_version difiere de EDU_DB_VERSION: entonces
	 * reaplica dbDelta() sobre el esquema canónico (28 tablas), ejecuta los
	 * ALTER/CREATE incrementales y actualiza la versión. Así, actualizar los
	 * archivos del plugin sin reactivarlo también aplica el esquema nuevo,
	 * y en cargas normales no se ejecuta ningún SHOW COLUMNS.
	 */
	public static function maybe_migrate() {
		if ( get_option( 'edu_db_version' ) === EDU_DB_VERSION ) {
			return;
		}

		self::create_tables();
		self::run_incremental_migrations();

		// Proteger el directorio de archivos privados también en upgrades sin reactivación.
		if ( class_exists( 'Edu_Assignment_Task_Controller' ) ) {
			Edu_Assignment_Task_Controller::ensure_protected_dir();
		}

		update_option( 'edu_db_version', EDU_DB_VERSION );
		update_option( 'edu_plugin_version', EDU_VERSION );
	}

	/**
	 * ALTER/CREATE incrementales para instalaciones que ya tenían tablas
	 * creadas antes de que las columnas existieran en el esquema canónico.
	 * Idempotente: comprueba existencia antes de cada cambio.
	 */
	private static function run_incremental_migrations() {
		global $wpdb;
		$p = $wpdb->prefix . 'edu_';

		// assignments.component_id (v1.0.1)
		$cols = $wpdb->get_col( "SHOW COLUMNS FROM {$p}assignments LIKE 'component_id'" );
		if ( empty( $cols ) ) {
			$wpdb->query( "ALTER TABLE {$p}assignments ADD COLUMN component_id bigint(20) unsigned DEFAULT NULL AFTER parcial_num" );
		}

		// assignments: allow_recovery, recovery_due_date (v1.0.4)
		$cols = $wpdb->get_col( "SHOW COLUMNS FROM {$p}assignments LIKE 'allow_recovery'" );
		if ( empty( $cols ) ) {
			$wpdb->query( "ALTER TABLE {$p}assignments ADD COLUMN allow_recovery tinyint(1) NOT NULL DEFAULT 0 AFTER notify_parents" );
			$wpdb->query( "ALTER TABLE {$p}assignments ADD COLUMN recovery_due_date datetime DEFAULT NULL AFTER allow_recovery" );
		}

		// submissions: recovery_* (v1.0.4)
		$cols = $wpdb->get_col( "SHOW COLUMNS FROM {$p}submissions LIKE 'recovery_status'" );
		if ( empty( $cols ) ) {
			$wpdb->query( "ALTER TABLE {$p}submissions ADD COLUMN recovery_status enum('none','available','submitted','graded') DEFAULT 'none' AFTER graded_by" );
			$wpdb->query( "ALTER TABLE {$p}submissions ADD COLUMN recovery_comment text DEFAULT NULL AFTER recovery_status" );
			$wpdb->query( "ALTER TABLE {$p}submissions ADD COLUMN recovery_submitted_at datetime DEFAULT NULL AFTER recovery_comment" );
			$wpdb->query( "ALTER TABLE {$p}submissions ADD COLUMN recovery_score decimal(4,2) DEFAULT NULL AFTER recovery_submitted_at" );
			$wpdb->query( "ALTER TABLE {$p}submissions ADD COLUMN recovery_feedback text DEFAULT NULL AFTER recovery_score" );
		}

		// trimester_scores: final_exam_score (v1.0.5 — fórmula sumativa).
		$cols = $wpdb->get_col( "SHOW COLUMNS FROM {$p}trimester_scores LIKE 'final_exam_score'" );
		if ( empty( $cols ) ) {
			$wpdb->query( "ALTER TABLE {$p}trimester_scores ADD COLUMN final_exam_score decimal(4,2) NOT NULL DEFAULT 0.00 AFTER parcial2_score" );
		}

		// trimester_scores: proyecto_score (v1.0.5 — fórmula sumativa).
		$cols = $wpdb->get_col( "SHOW COLUMNS FROM {$p}trimester_scores LIKE 'proyecto_score'" );
		if ( empty( $cols ) ) {
			$wpdb->query( "ALTER TABLE {$p}trimester_scores ADD COLUMN proyecto_score decimal(4,2) NOT NULL DEFAULT 0.00 AFTER final_exam_score" );
		}

		// Tablas de pagos (v1.0.6 — módulo de pensiones Payphone).
		$exists_config = $wpdb->get_var( "SHOW TABLES LIKE '{$p}payment_config'" );
		if ( ! $exists_config ) {
			$wpdb->query(
				"CREATE TABLE IF NOT EXISTS {$p}payment_config (
					id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
					institution_id bigint(20) unsigned NOT NULL,
					period_id bigint(20) unsigned NOT NULL,
					grade_id bigint(20) unsigned DEFAULT NULL,
					monthly_amount decimal(8,2) NOT NULL DEFAULT 0.00,
					matricula_amount decimal(8,2) NOT NULL DEFAULT 0.00,
					due_day tinyint(4) NOT NULL DEFAULT 5,
					grace_days tinyint(4) NOT NULL DEFAULT 5,
					PRIMARY KEY  (id),
					UNIQUE KEY uk_config (institution_id,period_id,grade_id)
				) {$wpdb->get_charset_collate()}"
			);
		}

		// grade_components: created_by (v1.0.7 — componentes propios del docente).
		// 0 = componente institucional (admin/rector); >0 = user_id del docente creador.
		$cols = $wpdb->get_col( "SHOW COLUMNS FROM {$p}grade_components LIKE 'created_by'" );
		if ( empty( $cols ) ) {
			$wpdb->query( "ALTER TABLE {$p}grade_components ADD COLUMN created_by bigint(20) unsigned NOT NULL DEFAULT 0 AFTER weight" );
		}

		// students: sexo, direccion (v1.0.8 — nómina AMIE).
		$cols = $wpdb->get_col( "SHOW COLUMNS FROM {$p}students LIKE 'sexo'" );
		if ( empty( $cols ) ) {
			$wpdb->query( "ALTER TABLE {$p}students ADD COLUMN sexo enum('M','F') DEFAULT NULL AFTER birth_date" );
			$wpdb->query( "ALTER TABLE {$p}students ADD COLUMN direccion varchar(255) DEFAULT NULL AFTER sexo" );
		}

		$exists_payments = $wpdb->get_var( "SHOW TABLES LIKE '{$p}payments'" );
		if ( ! $exists_payments ) {
			$wpdb->query(
				"CREATE TABLE IF NOT EXISTS {$p}payments (
					id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
					student_id bigint(20) unsigned NOT NULL,
					period_id bigint(20) unsigned NOT NULL,
					month char(7) NOT NULL,
					concept enum('matricula','pension') NOT NULL DEFAULT 'pension',
					amount decimal(8,2) NOT NULL,
					due_date date NOT NULL,
					status enum('pending','paid','overdue','waived') NOT NULL DEFAULT 'pending',
					paid_at datetime DEFAULT NULL,
					payment_method enum('payphone','manual','transfer','check','link') DEFAULT NULL,
					payment_ref varchar(100) DEFAULT NULL,
					payphone_transaction_id varchar(100) DEFAULT NULL,
					registered_by bigint(20) unsigned DEFAULT NULL,
					PRIMARY KEY  (id),
					UNIQUE KEY uk_payment (student_id,period_id,month,concept),
					KEY idx_payments_period (period_id),
					KEY idx_payments_status (status)
				) {$wpdb->get_charset_collate()}"
			);
		}
	}

	private static function check_requirements() {
		global $wp_version;

		if ( version_compare( PHP_VERSION, '8.2', '<' ) ) {
			deactivate_plugins( EDU_PLUGIN_BASENAME );
			wp_die(
				esc_html__( 'Sistema Educativo requiere PHP 8.2 o superior.', 'sistema-educativo' ),
				esc_html__( 'Requisito no cumplido', 'sistema-educativo' ),
				array( 'back_link' => true )
			);
		}

		if ( version_compare( $wp_version, '6.0', '<' ) ) {
			deactivate_plugins( EDU_PLUGIN_BASENAME );
			wp_die(
				esc_html__( 'Sistema Educativo requiere WordPress 6.0 o superior.', 'sistema-educativo' ),
				esc_html__( 'Requisito no cumplido', 'sistema-educativo' ),
				array( 'back_link' => true )
			);
		}
	}

	private static function create_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$p               = $wpdb->prefix . 'edu_';

		$tables = array();

		// 1. CORE INSTITUCIONAL ----------------------------------------------

		$tables[] = "CREATE TABLE {$p}institutions (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			name varchar(200) NOT NULL,
			ruc varchar(13) DEFAULT NULL,
			address text DEFAULT NULL,
			phone varchar(20) DEFAULT NULL,
			email varchar(100) DEFAULT NULL,
			logo_url varchar(255) DEFAULT NULL,
			regime enum('sierra','costa') DEFAULT 'sierra',
			created_at datetime DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY uk_institutions_ruc (ruc)
		) $charset_collate;";

		$tables[] = "CREATE TABLE {$p}periods (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			institution_id bigint(20) unsigned NOT NULL,
			name varchar(50) NOT NULL,
			regime enum('sierra','costa') NOT NULL DEFAULT 'sierra',
			start_date date NOT NULL,
			end_date date NOT NULL,
			working_days smallint(6) DEFAULT 200,
			is_active tinyint(1) NOT NULL DEFAULT 0,
			num_trimesters tinyint(4) DEFAULT 3,
			PRIMARY KEY  (id),
			KEY idx_periods_institution (institution_id)
		) $charset_collate;";

		$tables[] = "CREATE TABLE {$p}trimesters (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			period_id bigint(20) unsigned NOT NULL,
			number tinyint(4) NOT NULL,
			start_date date NOT NULL,
			end_date date NOT NULL,
			is_closed tinyint(1) NOT NULL DEFAULT 0,
			closed_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uk_period_number (period_id,number)
		) $charset_collate;";

		$tables[] = "CREATE TABLE {$p}grades (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			institution_id bigint(20) unsigned NOT NULL,
			level enum('inicial','basica','bachillerato') NOT NULL,
			sub_level enum('inicial_1','inicial_2','preparatoria','elemental','media','superior','bg','bt') NOT NULL,
			name varchar(50) NOT NULL,
			paralelo char(2) NOT NULL,
			specialty varchar(100) DEFAULT NULL,
			tutor_user_id bigint(20) unsigned DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY idx_inst_level (institution_id,level,sub_level),
			KEY idx_tutor (tutor_user_id)
		) $charset_collate;";

		$tables[] = "CREATE TABLE {$p}subjects_catalog (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			name varchar(150) NOT NULL,
			sub_levels set('preparatoria','elemental','media','superior','bg','bt') NOT NULL,
			area varchar(50) DEFAULT NULL,
			is_active tinyint(1) NOT NULL DEFAULT 1,
			source enum('mineduc','institution') DEFAULT 'mineduc',
			PRIMARY KEY  (id),
			UNIQUE KEY uk_catalog_name (name)
		) $charset_collate;";

		$tables[] = "CREATE TABLE {$p}subjects (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			institution_id bigint(20) unsigned NOT NULL,
			catalog_id bigint(20) unsigned DEFAULT NULL,
			name varchar(150) NOT NULL,
			code varchar(20) DEFAULT NULL,
			area varchar(50) DEFAULT NULL,
			is_custom tinyint(1) NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY idx_subjects_institution (institution_id),
			KEY idx_subjects_catalog (catalog_id)
		) $charset_collate;";

		$tables[] = "CREATE TABLE {$p}grade_subjects (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			grade_id bigint(20) unsigned NOT NULL,
			subject_id bigint(20) unsigned NOT NULL,
			hours_week tinyint(4) DEFAULT 5,
			PRIMARY KEY  (id),
			UNIQUE KEY uk_grade_subject (grade_id,subject_id)
		) $charset_collate;";

		// 2. PERSONAS --------------------------------------------------------

		$tables[] = "CREATE TABLE {$p}teachers (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL,
			cedula varchar(13) DEFAULT NULL,
			phone varchar(20) DEFAULT NULL,
			title varchar(50) DEFAULT NULL,
			hire_date date DEFAULT NULL,
			is_active tinyint(1) NOT NULL DEFAULT 1,
			PRIMARY KEY  (id),
			UNIQUE KEY uk_teachers_user (user_id),
			UNIQUE KEY uk_teachers_cedula (cedula)
		) $charset_collate;";

		$tables[] = "CREATE TABLE {$p}students (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL,
			grade_id bigint(20) unsigned NOT NULL,
			cedula varchar(13) DEFAULT NULL,
			birth_date date DEFAULT NULL,
			sexo enum('M','F') DEFAULT NULL,
			direccion varchar(255) DEFAULT NULL,
			enrollment_date date DEFAULT NULL,
			photo_url varchar(255) DEFAULT NULL,
			status enum('active','inactive','transferred','graduated') DEFAULT 'active',
			PRIMARY KEY  (id),
			UNIQUE KEY uk_students_user (user_id),
			UNIQUE KEY uk_students_cedula (cedula),
			KEY idx_grade_status (grade_id,status)
		) $charset_collate;";

		$tables[] = "CREATE TABLE {$p}parents (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL,
			cedula varchar(13) DEFAULT NULL,
			phone varchar(20) DEFAULT NULL,
			whatsapp varchar(20) DEFAULT NULL,
			occupation varchar(100) DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uk_parents_user (user_id),
			UNIQUE KEY uk_parents_cedula (cedula)
		) $charset_collate;";

		$tables[] = "CREATE TABLE {$p}parent_student (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			parent_id bigint(20) unsigned NOT NULL,
			student_id bigint(20) unsigned NOT NULL,
			relationship enum('padre','madre','tutor','otro') NOT NULL,
			is_primary tinyint(1) NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY uk_parent_student (parent_id,student_id)
		) $charset_collate;";

		$tables[] = "CREATE TABLE {$p}teacher_assignments (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			teacher_id bigint(20) unsigned NOT NULL,
			grade_id bigint(20) unsigned NOT NULL,
			subject_id bigint(20) unsigned NOT NULL,
			period_id bigint(20) unsigned NOT NULL,
			is_active tinyint(1) NOT NULL DEFAULT 1,
			PRIMARY KEY  (id),
			UNIQUE KEY uk_teacher_assignment (teacher_id,grade_id,subject_id,period_id)
		) $charset_collate;";

		// 3. ACADÉMICO -------------------------------------------------------

		$tables[] = "CREATE TABLE {$p}assignments (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			teacher_id bigint(20) unsigned NOT NULL,
			grade_id bigint(20) unsigned NOT NULL,
			subject_id bigint(20) unsigned NOT NULL,
			trimester_id bigint(20) unsigned NOT NULL,
			parcial_num tinyint(4) NOT NULL DEFAULT 1,
			component_id bigint(20) unsigned DEFAULT NULL,
			type enum('tarea','leccion','trabajo','deber','examen','correccion') NOT NULL,
			title varchar(200) NOT NULL,
			description text DEFAULT NULL,
			due_date datetime DEFAULT NULL,
			max_score decimal(4,2) DEFAULT 10.00,
			weight decimal(4,2) DEFAULT 1.00,
			status enum('draft','published','closed') DEFAULT 'draft',
			notify_parents tinyint(1) NOT NULL DEFAULT 1,
			allow_recovery tinyint(1) NOT NULL DEFAULT 0,
			recovery_due_date datetime DEFAULT NULL,
			created_at datetime DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY idx_grade_subject_trim (grade_id,subject_id,trimester_id)
		) $charset_collate;";

		$tables[] = "CREATE TABLE {$p}assignment_files (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			assignment_id bigint(20) unsigned NOT NULL,
			file_url varchar(500) NOT NULL,
			file_name varchar(200) DEFAULT NULL,
			file_type varchar(50) DEFAULT NULL,
			file_size int(11) DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY idx_assignment_files (assignment_id)
		) $charset_collate;";

		$tables[] = "CREATE TABLE {$p}submissions (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			assignment_id bigint(20) unsigned NOT NULL,
			student_id bigint(20) unsigned NOT NULL,
			status enum('pending','submitted','graded','returned','late') DEFAULT 'pending',
			comment text DEFAULT NULL,
			submitted_at datetime DEFAULT NULL,
			score decimal(4,2) DEFAULT NULL,
			feedback text DEFAULT NULL,
			graded_at datetime DEFAULT NULL,
			graded_by bigint(20) unsigned DEFAULT NULL,
			recovery_status enum('none','available','submitted','graded') DEFAULT 'none',
			recovery_comment text DEFAULT NULL,
			recovery_submitted_at datetime DEFAULT NULL,
			recovery_score decimal(4,2) DEFAULT NULL,
			recovery_feedback text DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uk_assignment_student (assignment_id,student_id)
		) $charset_collate;";

		$tables[] = "CREATE TABLE {$p}submission_files (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			submission_id bigint(20) unsigned NOT NULL,
			file_url varchar(500) NOT NULL,
			file_name varchar(200) DEFAULT NULL,
			file_type varchar(50) DEFAULT NULL,
			file_size int(11) DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY idx_submission_files (submission_id)
		) $charset_collate;";

		$tables[] = "CREATE TABLE {$p}rubrics (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			assignment_id bigint(20) unsigned NOT NULL,
			criterion varchar(200) NOT NULL,
			descriptor text DEFAULT NULL,
			max_score decimal(4,2) DEFAULT 10.00,
			PRIMARY KEY  (id),
			KEY idx_rubrics_assignment (assignment_id)
		) $charset_collate;";

		$tables[] = "CREATE TABLE {$p}rubric_scores (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			submission_id bigint(20) unsigned NOT NULL,
			rubric_id bigint(20) unsigned NOT NULL,
			score decimal(4,2) NOT NULL,
			PRIMARY KEY  (id),
			KEY idx_rubric_scores_submission (submission_id),
			KEY idx_rubric_scores_rubric (rubric_id)
		) $charset_collate;";

		$tables[] = "CREATE TABLE {$p}grade_components (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			subject_id bigint(20) unsigned NOT NULL,
			trimester_id bigint(20) unsigned NOT NULL,
			parcial_num tinyint(4) NOT NULL,
			name varchar(100) NOT NULL,
			weight decimal(4,2) NOT NULL,
			created_by bigint(20) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY idx_components_subject_trim (subject_id,trimester_id,parcial_num)
		) $charset_collate;";

		$tables[] = "CREATE TABLE {$p}grades_log (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			student_id bigint(20) unsigned NOT NULL,
			component_id bigint(20) unsigned NOT NULL,
			assignment_id bigint(20) unsigned DEFAULT NULL,
			score decimal(4,2) NOT NULL,
			registered_at datetime DEFAULT CURRENT_TIMESTAMP,
			registered_by bigint(20) unsigned NOT NULL,
			PRIMARY KEY  (id),
			KEY idx_student_component (student_id,component_id)
		) $charset_collate;";

		$tables[] = "CREATE TABLE {$p}parcial_scores (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			student_id bigint(20) unsigned NOT NULL,
			subject_id bigint(20) unsigned NOT NULL,
			trimester_id bigint(20) unsigned NOT NULL,
			parcial_num tinyint(4) NOT NULL,
			computed_score decimal(4,2) DEFAULT 0.00,
			is_closed tinyint(1) NOT NULL DEFAULT 0,
			updated_at datetime DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY uk_parcial (student_id,subject_id,trimester_id,parcial_num)
		) $charset_collate;";

		$tables[] = "CREATE TABLE {$p}trimester_scores (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			student_id bigint(20) unsigned NOT NULL,
			subject_id bigint(20) unsigned NOT NULL,
			trimester_id bigint(20) unsigned NOT NULL,
			parcial1_score decimal(4,2) DEFAULT 0.00,
			parcial2_score decimal(4,2) DEFAULT 0.00,
			final_exam_score decimal(4,2) DEFAULT 0.00,
			proyecto_score decimal(4,2) DEFAULT 0.00,
			recovery_score decimal(4,2) DEFAULT NULL,
			computed_score decimal(4,2) DEFAULT 0.00,
			is_closed tinyint(1) NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY uk_trimester_score (student_id,subject_id,trimester_id)
		) $charset_collate;";

		$tables[] = "CREATE TABLE {$p}year_scores (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			student_id bigint(20) unsigned NOT NULL,
			subject_id bigint(20) unsigned NOT NULL,
			period_id bigint(20) unsigned NOT NULL,
			trim1 decimal(4,2) DEFAULT 0.00,
			trim2 decimal(4,2) DEFAULT 0.00,
			trim3 decimal(4,2) DEFAULT 0.00,
			average decimal(4,2) DEFAULT 0.00,
			supletorio_score decimal(4,2) DEFAULT NULL,
			remedial_score decimal(4,2) DEFAULT NULL,
			gracia_score decimal(4,2) DEFAULT NULL,
			status enum('en_curso','aprobado','supletorio','remedial','gracia','reprobado') DEFAULT 'en_curso',
			PRIMARY KEY  (id),
			UNIQUE KEY uk_year_score (student_id,subject_id,period_id)
		) $charset_collate;";

		// 4. ASISTENCIA ------------------------------------------------------

		$tables[] = "CREATE TABLE {$p}attendance (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			student_id bigint(20) unsigned NOT NULL,
			subject_id bigint(20) unsigned DEFAULT NULL,
			teacher_id bigint(20) unsigned NOT NULL,
			date date NOT NULL,
			status enum('presente','atraso','falta_justificada','falta_injustificada') NOT NULL,
			justification text DEFAULT NULL,
			registered_at datetime DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY uk_attendance (student_id,subject_id,date),
			KEY idx_date_status (date,status)
		) $charset_collate;";

		// 5. COMUNICACIÓN ----------------------------------------------------

		$tables[] = "CREATE TABLE {$p}announcement_templates (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			name varchar(100) NOT NULL,
			subject_template varchar(200) DEFAULT NULL,
			body_template text DEFAULT NULL,
			category varchar(50) DEFAULT NULL,
			created_by bigint(20) unsigned NOT NULL,
			created_at datetime DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id)
		) $charset_collate;";

		$tables[] = "CREATE TABLE {$p}announcements (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			sender_user_id bigint(20) unsigned NOT NULL,
			scope enum('student','grade','multi_grade','institution') NOT NULL,
			target_grade_id bigint(20) unsigned DEFAULT NULL,
			target_student_id bigint(20) unsigned DEFAULT NULL,
			template_id bigint(20) unsigned DEFAULT NULL,
			title varchar(200) NOT NULL,
			body text DEFAULT NULL,
			channels set('email','portal','whatsapp') DEFAULT 'email,portal',
			sent_at datetime DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY idx_announcements_sender (sender_user_id),
			KEY idx_announcements_scope (scope)
		) $charset_collate;";

		$tables[] = "CREATE TABLE {$p}announcement_recipients (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			announcement_id bigint(20) unsigned NOT NULL,
			user_id bigint(20) unsigned NOT NULL,
			channel enum('email','portal','whatsapp') NOT NULL,
			sent_at datetime DEFAULT NULL,
			delivered_at datetime DEFAULT NULL,
			read_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY idx_user_read (user_id,read_at)
		) $charset_collate;";

		// 6. AUDITORÍA -------------------------------------------------------

		$tables[] = "CREATE TABLE {$p}audit (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL,
			action varchar(50) NOT NULL,
			entity_type varchar(50) NOT NULL,
			entity_id bigint(20) unsigned DEFAULT NULL,
			old_value longtext DEFAULT NULL,
			new_value longtext DEFAULT NULL,
			ip_address varchar(45) DEFAULT NULL,
			user_agent varchar(255) DEFAULT NULL,
			timestamp datetime DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY idx_entity (entity_type,entity_id),
			KEY idx_user_time (user_id,timestamp)
		) $charset_collate;";

		foreach ( $tables as $sql ) {
			dbDelta( $sql );
		}
	}

	/**
	 * Pobla el catálogo del Mineduc con las 18 materias oficiales.
	 * Idempotente: si el catálogo ya tiene filas, no hace nada.
	 */
	private static function seed_subjects_catalog() {
		global $wpdb;
		$table = $wpdb->prefix . 'edu_subjects_catalog';

		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table" );
		if ( $count > 0 ) {
			return;
		}

		$subjects = array(
			array( 'Currículo Integrador por ámbitos de aprendizaje', 'preparatoria', 'Integradora' ),
			array( 'Lengua y Literatura', 'elemental,media,superior,bg', 'Lengua' ),
			array( 'Matemática', 'elemental,media,superior,bg', 'Ciencias exactas' ),
			array( 'Estudios Sociales', 'elemental,media,superior', 'Ciencias sociales' ),
			array( 'Ciencias Naturales', 'elemental,media,superior', 'Ciencias naturales' ),
			array( 'Inglés', 'elemental,media,superior,bg', 'Lengua extranjera' ),
			array( 'Educación Cultural y Artística', 'preparatoria,elemental,media,superior,bg', 'Artística' ),
			array( 'Educación Física', 'preparatoria,elemental,media,superior,bg', 'Física' ),
			array( 'Acompañamiento integral en el aula', 'elemental,media,superior,bg', 'Tutoría' ),
			array( 'Animación a la lectura', 'elemental,media,superior', 'Lengua' ),
			array( 'Orientación vocacional y profesional', 'superior', 'Tutoría' ),
			array( 'Física', 'bg', 'Ciencias naturales' ),
			array( 'Química', 'bg', 'Ciencias naturales' ),
			array( 'Biología', 'bg', 'Ciencias naturales' ),
			array( 'Historia', 'bg', 'Ciencias sociales' ),
			array( 'Educación para la ciudadanía', 'bg', 'Ciencias sociales' ),
			array( 'Filosofía', 'bg', 'Ciencias sociales' ),
			array( 'Emprendimiento y Gestión', 'bg', 'Económica' ),
		);

		foreach ( $subjects as $row ) {
			$wpdb->insert(
				$table,
				array(
					'name'       => $row[0],
					'sub_levels' => $row[1],
					'area'       => $row[2],
					'is_active'  => 1,
					'source'     => 'mineduc',
				),
				array( '%s', '%s', '%s', '%d', '%s' )
			);
		}
	}
}
