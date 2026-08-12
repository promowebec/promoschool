<?php
/**
 * Servicio: pensum (wp_edu_grade_subjects) y componentes evaluables
 * (wp_edu_grade_components).
 *
 * @package SistemaEducativo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Edu_Curriculum_Service {

	/* ─────────────────────────────────────────────────────────────────────
	 * Pensum
	 * ────────────────────────────────────────────────────────────────── */

	/**
	 * Guarda en bloque las materias que se dictan en un grado.
	 *
	 * Reemplazo total: se borran las filas del grado y se reinsertan las
	 * seleccionadas. No hay riesgo de huérfanos porque grade_subjects solo
	 * declara qué se dicta; las notas cuelgan de subject_id, no de aquí.
	 *
	 * @param array $input {
	 *     @type int   $grade_id
	 *     @type array $subjects   IDs de materia seleccionadas.
	 *     @type array $hours_week Mapa subject_id => horas/semana.
	 * }
	 * @return array|WP_Error
	 */
	public static function save_pensum( array $input ) {
		$cap = Edu_Service::require_cap( 'edu_manage_curriculum' );
		if ( is_wp_error( $cap ) ) {
			return $cap;
		}

		$institution_id = Edu_Service::require_institution();
		if ( is_wp_error( $institution_id ) ) {
			return $institution_id;
		}

		$grade_id = isset( $input['grade_id'] ) ? (int) $input['grade_id'] : 0;

		global $wpdb;
		$tgs = $wpdb->prefix . 'edu_grade_subjects';
		$tg  = $wpdb->prefix . 'edu_grades';
		$ts  = $wpdb->prefix . 'edu_subjects';

		$grade_ok = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT id FROM $tg WHERE id = %d AND institution_id = %d", $grade_id, $institution_id )
		);

		if ( ! $grade_ok && ! Edu_Context::is_superadmin_editorial() ) {
			return Edu_Service::error(
				'invalid_grade',
				__( 'El grado no pertenece a la institución activa.', 'sistema-educativo' ),
				403
			);
		}

		$selected = (array) ( $input['subjects'] ?? array() );
		$hours    = (array) ( $input['hours_week'] ?? array() );

		$wpdb->delete( $tgs, array( 'grade_id' => $grade_id ), array( '%d' ) );

		$seen = array();
		foreach ( $selected as $subject_id_raw ) {
			$subject_id = (int) $subject_id_raw;

			if ( ! $subject_id || in_array( $subject_id, $seen, true ) ) {
				continue;
			}

			$belongs = (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT id FROM $ts WHERE id = %d AND institution_id = %d", $subject_id, $institution_id )
			);

			if ( ! $belongs && ! Edu_Context::is_superadmin_editorial() ) {
				continue;
			}

			$hw = isset( $hours[ $subject_id ] ) ? max( 1, (int) $hours[ $subject_id ] ) : 5;

			$wpdb->insert(
				$tgs,
				array(
					'grade_id'   => $grade_id,
					'subject_id' => $subject_id,
					'hours_week' => $hw,
				),
				array( '%d', '%d', '%d' )
			);

			$seen[] = $subject_id;
		}

		return array(
			'grade_id' => $grade_id,
			'subjects' => count( $seen ),
		);
	}

	/* ─────────────────────────────────────────────────────────────────────
	 * Componentes evaluables
	 * ────────────────────────────────────────────────────────────────── */

	/**
	 * Guardado en bloque de los componentes de un (materia, trimestre, parcial).
	 *
	 * Es un UPSERT por ID, no un reemplazo total: los IDs se preservan para no
	 * dejar huérfanas las filas de wp_edu_grades_log.component_id.
	 *
	 * Permisos:
	 * - edu_manage_curriculum (rector/admin): edita todo el set; sus filas
	 *   nuevas son institucionales (created_by = 0).
	 * - edu_grade_students (docente): solo materias de sus asignaciones activas
	 *   y solo sus propias filas. Las institucionales y las de otros docentes
	 *   son intocables.
	 *
	 * Un componente con notas registradas nunca se elimina.
	 * Los pesos no necesitan sumar 1.00: el cálculo renormaliza.
	 *
	 * @param array $input {
	 *     @type int   $subject_id
	 *     @type int   $trimester_id
	 *     @type int   $parcial_num
	 *     @type array $rows Lista de array( id, name, weight ).
	 *                       Las filas ausentes del set se eliminan si se puede.
	 * }
	 * @return array|WP_Error
	 */
	public static function save_components( array $input ) {
		$puede_todo = Edu_Context::can( 'edu_manage_curriculum' );
		$es_docente = ! $puede_todo && current_user_can( 'edu_grade_students' );

		if ( ! $puede_todo && ! $es_docente ) {
			return Edu_Service::error( 'forbidden', __( 'Sin permiso.', 'sistema-educativo' ), 403 );
		}

		$subject_id   = isset( $input['subject_id'] ) ? (int) $input['subject_id'] : 0;
		$trimester_id = isset( $input['trimester_id'] ) ? (int) $input['trimester_id'] : 0;
		$parcial_num  = isset( $input['parcial_num'] ) ? (int) $input['parcial_num'] : 0;

		$valid = Edu_Service::validate_parcial( $parcial_num );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		$allowed = self::check_component_scope( $subject_id, $trimester_id, $es_docente );
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		global $wpdb;
		$tc  = $wpdb->prefix . 'edu_grade_components';
		$tl  = $wpdb->prefix . 'edu_grades_log';
		$uid = get_current_user_id();

		// Filas existentes del set, indexadas por id.
		$existentes = array();
		foreach (
			$wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, name, weight, created_by FROM $tc
					 WHERE subject_id = %d AND trimester_id = %d AND parcial_num = %d",
					$subject_id,
					$trimester_id,
					$parcial_num
				)
			) as $row
		) {
			$existentes[ (int) $row->id ] = $row;
		}

		$es_editable = static function ( $row ) use ( $puede_todo, $uid ) {
			return $puede_todo || (int) $row->created_by === $uid;
		};

		$ids_posteados = array();
		$creados       = 0;
		$actualizados  = 0;

		foreach ( (array) ( $input['rows'] ?? array() ) as $row_in ) {
			if ( ! is_array( $row_in ) ) {
				continue;
			}

			$name = sanitize_text_field( (string) ( $row_in['name'] ?? '' ) );
			if ( '' === $name ) {
				continue;
			}

			$weight = self::normalize_weight( $row_in['weight'] ?? '' );
			$id     = isset( $row_in['id'] ) ? (int) $row_in['id'] : 0;

			if ( $id && isset( $existentes[ $id ] ) ) {
				if ( ! $es_editable( $existentes[ $id ] ) ) {
					continue; // Fila ajena: se ignora en silencio.
				}

				$ids_posteados[] = $id;

				if ( $existentes[ $id ]->name !== $name || abs( (float) $existentes[ $id ]->weight - $weight ) > 0.001 ) {
					$wpdb->update(
						$tc,
						array(
							'name'   => $name,
							'weight' => $weight,
						),
						array( 'id' => $id ),
						array( '%s', '%f' ),
						array( '%d' )
					);
					$actualizados++;
				}
			} else {
				$wpdb->insert(
					$tc,
					array(
						'subject_id'   => $subject_id,
						'trimester_id' => $trimester_id,
						'parcial_num'  => $parcial_num,
						'name'         => $name,
						'weight'       => $weight,
						'created_by'   => $puede_todo ? 0 : $uid,
					),
					array( '%d', '%d', '%d', '%s', '%f', '%d' )
				);

				$ids_posteados[] = (int) $wpdb->insert_id;
				$creados++;
			}
		}

		// Eliminaciones: filas editables que ya no vienen y que no tienen notas.
		$protegidos = 0;
		$eliminados = 0;

		foreach ( $existentes as $id => $row ) {
			if ( in_array( $id, $ids_posteados, true ) || ! $es_editable( $row ) ) {
				continue;
			}

			$n_notas = (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT COUNT(*) FROM $tl WHERE component_id = %d", $id )
			);

			if ( $n_notas > 0 ) {
				$protegidos++; // Con notas registradas: no se elimina.
				continue;
			}

			$wpdb->delete( $tc, array( 'id' => $id ), array( '%d' ) );
			$eliminados++;
		}

		// Suma final de pesos de TODO el set (institucionales + de docentes).
		$weight_sum = (float) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(weight),0) FROM $tc
				 WHERE subject_id = %d AND trimester_id = %d AND parcial_num = %d",
				$subject_id,
				$trimester_id,
				$parcial_num
			)
		);

		return array(
			'creados'      => $creados,
			'actualizados' => $actualizados,
			'eliminados'   => $eliminados,
			'protegidos'   => $protegidos,
			'weight_sum'   => round( $weight_sum, 2 ),
		);
	}

	/* ─────────────────────────────────────────────────────────────────────
	 * Resolución de componentes desde otros módulos
	 * ────────────────────────────────────────────────────────────────── */

	/**
	 * Resuelve el componente con el que se calificará una actividad: reutiliza
	 * uno existente o lo crea al vuelo.
	 *
	 * Así el docente no tiene que pasar antes por la pantalla de componentes:
	 * al crear la tarea escribe el nombre y el componente nace ahí. También lo
	 * usan las integraciones externas (Flipbook, H5P).
	 *
	 * Si ya existe uno con el mismo nombre en el set no se duplica: se devuelve
	 * el existente, de modo que varias actividades lo comparten y se promedian.
	 *
	 * @param int    $component_id ID de un componente existente (0 = ninguno).
	 * @param string $nuevo_nombre Nombre a crear ('' = no crear).
	 * @param float  $nuevo_peso   Peso del nuevo (<= 0 → 1.00).
	 * @param int    $subject_id   Materia.
	 * @param int    $trimester_id Trimestre.
	 * @param int    $parcial_num  Parcial (1 o 2).
	 * @return int|null ID del componente, o null si no hay vínculo.
	 */
	public static function resolve_or_create_component( $component_id, $nuevo_nombre, $nuevo_peso, $subject_id, $trimester_id, $parcial_num ) {
		global $wpdb;
		$tc = $wpdb->prefix . 'edu_grade_components';

		$component_id = (int) $component_id;
		$subject_id   = (int) $subject_id;
		$trimester_id = (int) $trimester_id;
		$parcial_num  = (int) $parcial_num;
		$nuevo_nombre = sanitize_text_field( (string) $nuevo_nombre );

		if ( ! $subject_id || ! $trimester_id || ! in_array( $parcial_num, array( 1, 2 ), true ) ) {
			return null;
		}

		// Caso 1: crear un componente nuevo.
		if ( '' !== $nuevo_nombre ) {
			if ( ! self::puede_crear_componente( $subject_id, $trimester_id ) ) {
				return null;
			}

			// Reutilizar si ya existe uno con el mismo nombre en el set.
			$existente = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM $tc
					 WHERE subject_id = %d AND trimester_id = %d AND parcial_num = %d AND name = %s",
					$subject_id,
					$trimester_id,
					$parcial_num,
					$nuevo_nombre
				)
			);

			if ( $existente ) {
				return $existente;
			}

			$wpdb->insert(
				$tc,
				array(
					'subject_id'   => $subject_id,
					'trimester_id' => $trimester_id,
					'parcial_num'  => $parcial_num,
					'name'         => $nuevo_nombre,
					'weight'       => self::normalize_weight( $nuevo_peso ),
					'created_by'   => Edu_Context::can( 'edu_manage_curriculum' ) ? 0 : get_current_user_id(),
				),
				array( '%d', '%d', '%d', '%s', '%f', '%d' )
			);

			return $wpdb->insert_id ? (int) $wpdb->insert_id : null;
		}

		// Caso 2: componente existente. Debe pertenecer al set indicado.
		if ( $component_id > 0 ) {
			$ok = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM $tc
					 WHERE id = %d AND subject_id = %d AND trimester_id = %d AND parcial_num = %d",
					$component_id,
					$subject_id,
					$trimester_id,
					$parcial_num
				)
			);

			return $ok ?: null;
		}

		return null;
	}

	/**
	 * ¿Puede el usuario actual crear un componente en esta materia y trimestre?
	 *
	 * Rector/admin (edu_manage_curriculum): sí, en cualquier materia de su
	 * institución. Docente: solo en materias de sus asignaciones activas.
	 *
	 * @param int $subject_id   Materia.
	 * @param int $trimester_id Trimestre.
	 * @return bool
	 */
	public static function puede_crear_componente( $subject_id, $trimester_id ) {
		global $wpdb;

		if ( Edu_Context::can( 'edu_manage_curriculum' ) ) {
			if ( Edu_Context::is_superadmin_editorial() ) {
				return true;
			}

			$subj_inst = Edu_Service::subject_institution( $subject_id );

			return $subj_inst && $subj_inst === Edu_Context::current_institution_id();
		}

		if ( ! current_user_can( 'edu_grade_students' ) ) {
			return false;
		}

		$teacher_id = self::current_teacher_id();
		if ( ! $teacher_id ) {
			return false;
		}

		// La materia debe estar en una asignación activa del docente y el
		// trimestre pertenecer a un período donde tenga asignación.
		return (bool) (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*)
				 FROM {$wpdb->prefix}edu_teacher_assignments ta
				 INNER JOIN {$wpdb->prefix}edu_trimesters t ON t.period_id = ta.period_id
				 WHERE ta.teacher_id = %d AND ta.subject_id = %d AND ta.is_active = 1 AND t.id = %d",
				$teacher_id,
				(int) $subject_id,
				(int) $trimester_id
			)
		);
	}

	/* ─── Internos ──────────────────────────────────────────────────────── */

	/**
	 * Valida que el usuario pueda tocar los componentes de este set.
	 *
	 * @param int  $subject_id   Materia.
	 * @param int  $trimester_id Trimestre.
	 * @param bool $es_docente   Si actúa como docente (sin edu_manage_curriculum).
	 * @return true|WP_Error
	 */
	private static function check_component_scope( $subject_id, $trimester_id, $es_docente ) {
		global $wpdb;
		$tt = $wpdb->prefix . 'edu_trimesters';

		if ( $es_docente ) {
			$teacher_id = self::current_teacher_id();

			$asignado = $teacher_id
				? (int) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT COUNT(*) FROM {$wpdb->prefix}edu_teacher_assignments
						 WHERE teacher_id = %d AND subject_id = %d AND is_active = 1",
						$teacher_id,
						$subject_id
					)
				)
				: 0;

			if ( ! $asignado ) {
				return Edu_Service::error(
					'invalid_subject',
					__( 'No tienes una asignación activa en esta materia.', 'sistema-educativo' ),
					403
				);
			}

			$tri_ok = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM $tt t
					 INNER JOIN {$wpdb->prefix}edu_teacher_assignments ta ON ta.period_id = t.period_id
					 WHERE t.id = %d AND ta.teacher_id = %d AND ta.is_active = 1",
					$trimester_id,
					$teacher_id
				)
			);

			if ( ! $tri_ok ) {
				return Edu_Service::error(
					'invalid_trimester',
					__( 'El trimestre no corresponde a un período con asignación activa.', 'sistema-educativo' ),
					403
				);
			}

			return true;
		}

		$institution_id = Edu_Service::require_institution();
		if ( is_wp_error( $institution_id ) ) {
			return $institution_id;
		}

		$subj_inst = Edu_Service::subject_institution( $subject_id );
		if ( ! $subj_inst || ( ! Edu_Context::is_superadmin_editorial() && $subj_inst !== $institution_id ) ) {
			return Edu_Service::error(
				'invalid_subject',
				__( 'La materia no pertenece a la institución activa.', 'sistema-educativo' ),
				403
			);
		}

		$tri_inst = Edu_Service::trimester_institution( $trimester_id );
		if ( ! $tri_inst || ( ! Edu_Context::is_superadmin_editorial() && $tri_inst !== $institution_id ) ) {
			return Edu_Service::error(
				'invalid_trimester',
				__( 'El trimestre no pertenece a la institución activa.', 'sistema-educativo' ),
				403
			);
		}

		return true;
	}

	/**
	 * Peso normalizado: vacío, no positivo o mayor que 1 → 1.00.
	 *
	 * Dejar todos los componentes en 1.00 equivale a "todos pesan igual",
	 * porque el cálculo renormaliza sobre los que tienen nota.
	 *
	 * @param mixed $raw Peso crudo.
	 * @return float
	 */
	private static function normalize_weight( $raw ) {
		$value = trim( (string) $raw );
		$peso  = ( '' === $value ) ? 1.00 : (float) str_replace( ',', '.', $value );

		if ( $peso <= 0 || $peso > 1 ) {
			$peso = 1.00;
		}

		return round( $peso, 2 );
	}

	private static function current_teacher_id() {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}edu_teachers WHERE user_id = %d",
				get_current_user_id()
			)
		);
	}
}
