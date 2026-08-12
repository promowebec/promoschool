<?php
/**
 * Servicio de cálculo de calificaciones.
 *
 * Métodos puros que recalculan los agregados (parcial, trimestre, año) a partir
 * de los registros atómicos de wp_edu_grades_log. Pensado para ser invocado
 * por hooks (edu_grade_logged, edu_partial_closed, edu_trimester_closed) o
 * directamente por los controllers tras un batch de notas.
 *
 * Fórmula parcial:
 *   computed = Σ (score_componente × weight_componente)
 *   Si Σ(weight) ≠ 1.00 se normaliza dividiendo por Σ(weight) para no inflar
 *   o deflar artificialmente la nota del parcial. Componentes sin nota
 *   registrada se excluyen del cálculo (no cuentan como 0).
 *
 * @package SistemaEducativo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Edu_Grade_Calculator {

	/**
	 * Recalcula y persiste la nota del parcial para (estudiante, materia, trimestre, parcial).
	 * Si el parcial está cerrado (is_closed = 1) no toca el registro.
	 *
	 * @return float|false Nota recalculada (0.00–10.00) o false si está cerrado.
	 */
	public static function recalculate_parcial( $student_id, $subject_id, $trimester_id, $parcial_num ) {
		global $wpdb;
		$tc = $wpdb->prefix . 'edu_grade_components';
		$tl = $wpdb->prefix . 'edu_grades_log';
		$tp = $wpdb->prefix . 'edu_parcial_scores';

		$student_id   = (int) $student_id;
		$subject_id   = (int) $subject_id;
		$trimester_id = (int) $trimester_id;
		$parcial_num  = (int) $parcial_num;

		// Si ya está cerrado, no se recalcula.
		$existing = $wpdb->get_row( $wpdb->prepare(
			"SELECT id, is_closed FROM $tp WHERE student_id = %d AND subject_id = %d AND trimester_id = %d AND parcial_num = %d",
			$student_id, $subject_id, $trimester_id, $parcial_num
		) );
		if ( $existing && (int) $existing->is_closed === 1 ) {
			return false;
		}

		// Componentes del set.
		$components = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, weight FROM $tc WHERE subject_id = %d AND trimester_id = %d AND parcial_num = %d",
			$subject_id, $trimester_id, $parcial_num
		) );

		$weighted_sum   = 0.0;
		$weight_present = 0.0;

		foreach ( $components as $c ) {
			$score = $wpdb->get_var( $wpdb->prepare(
				"SELECT AVG(score) FROM $tl WHERE student_id = %d AND component_id = %d",
				$student_id, (int) $c->id
			) );
			if ( null === $score ) {
				continue; // Sin nota registrada: no se considera.
			}
			$w               = (float) $c->weight;
			$weighted_sum   += (float) $score * $w;
			$weight_present += $w;
		}

		$computed = ( $weight_present > 0 ) ? round( $weighted_sum / $weight_present, 2 ) : 0.0;

		if ( $existing ) {
			$wpdb->update(
				$tp,
				array( 'computed_score' => $computed ),
				array( 'id' => (int) $existing->id ),
				array( '%f' ),
				array( '%d' )
			);
		} else {
			$wpdb->insert(
				$tp,
				array(
					'student_id'     => $student_id,
					'subject_id'     => $subject_id,
					'trimester_id'   => $trimester_id,
					'parcial_num'    => $parcial_num,
					'computed_score' => $computed,
					'is_closed'      => 0,
				),
				array( '%d', '%d', '%d', '%d', '%f', '%d' )
			);
		}

		// Si el trimestre ya tiene fila (p.ej. el examen se guardó antes de que
		// terminaran de ingresarse las notas de componentes), refrescar su
		// computed_score para que boletines y portales no muestren un valor
		// obsoleto. recalculate_trimester no toca trimestres cerrados.
		$tts          = $wpdb->prefix . 'edu_trimester_scores';
		$has_tri_row  = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM $tts WHERE student_id = %d AND subject_id = %d AND trimester_id = %d",
			$student_id, $subject_id, $trimester_id
		) );
		if ( $has_tri_row ) {
			self::recalculate_trimester( $student_id, $subject_id, $trimester_id );
		}

		return $computed;
	}

	/**
	 * Listener para el hook edu_grade_logged.
	 */
	public static function on_grade_logged( $student_id, $component_id, $score ) {
		global $wpdb;
		$tc        = $wpdb->prefix . 'edu_grade_components';
		$component = $wpdb->get_row( $wpdb->prepare(
			"SELECT subject_id, trimester_id, parcial_num FROM $tc WHERE id = %d",
			(int) $component_id
		) );
		if ( ! $component ) {
			return;
		}
		self::recalculate_parcial(
			(int) $student_id,
			(int) $component->subject_id,
			(int) $component->trimester_id,
			(int) $component->parcial_num
		);
	}

	/**
	 * Subniveles que usan evaluación sumativa separada (examen + proyecto).
	 * EGB Media (5–7), Superior (8–10), Bachillerato General y Técnico.
	 */
	private static $subniveles_sumativa = array( 'media', 'superior', 'bg', 'bt' );

	/**
	 * Devuelve true si el grado del estudiante usa la fórmula con evaluación sumativa.
	 */
	private static function usa_evaluacion_sumativa( $student_id ) {
		global $wpdb;
		$sub_level = $wpdb->get_var( $wpdb->prepare(
			"SELECT g.sub_level
			 FROM {$wpdb->prefix}edu_students s
			 INNER JOIN {$wpdb->prefix}edu_grades g ON g.id = s.grade_id
			 WHERE s.id = %d",
			(int) $student_id
		) );
		return in_array( $sub_level, self::$subniveles_sumativa, true );
	}

	/**
	 * Recalcula y persiste la nota del trimestre para (estudiante, materia, trimestre).
	 *
	 * Fórmula Media/Superior/Bachillerato:
	 *   ((P1+P2)/2) × 0.70 + ((Examen+Proyecto)/2) × 0.30
	 *
	 * Fórmula Elemental y otros:
	 *   ((P1+P2)/2) × 0.70 + Examen × 0.30
	 *
	 * Solo calcula si hay al menos un parcial. Si el trimestre está cerrado no toca.
	 *
	 * @return float|false Nota calculada o false si el trimestre está cerrado.
	 */
	public static function recalculate_trimester( $student_id, $subject_id, $trimester_id ) {
		global $wpdb;
		$tps = $wpdb->prefix . 'edu_parcial_scores';
		$tts = $wpdb->prefix . 'edu_trimester_scores';

		$student_id   = (int) $student_id;
		$subject_id   = (int) $subject_id;
		$trimester_id = (int) $trimester_id;

		$existing = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM $tts WHERE student_id = %d AND subject_id = %d AND trimester_id = %d",
			$student_id, $subject_id, $trimester_id
		) );
		if ( $existing && (int) $existing->is_closed === 1 ) {
			return false;
		}

		$p1 = $wpdb->get_var( $wpdb->prepare(
			"SELECT computed_score FROM $tps WHERE student_id = %d AND subject_id = %d AND trimester_id = %d AND parcial_num = 1",
			$student_id, $subject_id, $trimester_id
		) );
		$p2 = $wpdb->get_var( $wpdb->prepare(
			"SELECT computed_score FROM $tps WHERE student_id = %d AND subject_id = %d AND trimester_id = %d AND parcial_num = 2",
			$student_id, $subject_id, $trimester_id
		) );

		$exam    = $existing ? (float) $existing->final_exam_score : 0.0;
		$proyecto = $existing ? (float) $existing->proyecto_score  : 0.0;

		$p1_val      = ( null !== $p1 ) ? (float) $p1 : 0.0;
		$p2_val      = ( null !== $p2 ) ? (float) $p2 : 0.0;
		$parcial_avg = ( $p1_val + $p2_val ) / 2;

		if ( self::usa_evaluacion_sumativa( $student_id ) ) {
			$computed = round( $parcial_avg * 0.70 + ( ( $exam + $proyecto ) / 2 ) * 0.30, 2 );
		} else {
			$computed = round( $parcial_avg * 0.70 + $exam * 0.30, 2 );
		}

		if ( $existing ) {
			$wpdb->update(
				$tts,
				array(
					'parcial1_score' => $p1_val,
					'parcial2_score' => $p2_val,
					'computed_score' => $computed,
				),
				array( 'id' => (int) $existing->id ),
				array( '%f', '%f', '%f' ),
				array( '%d' )
			);
		} else {
			$wpdb->insert(
				$tts,
				array(
					'student_id'       => $student_id,
					'subject_id'       => $subject_id,
					'trimester_id'     => $trimester_id,
					'parcial1_score'   => $p1_val,
					'parcial2_score'   => $p2_val,
					'final_exam_score' => 0,
					'proyecto_score'   => 0,
					'computed_score'   => $computed,
					'is_closed'        => 0,
				),
				array( '%d', '%d', '%d', '%f', '%f', '%f', '%f', '%f', '%d' )
			);
		}

		return $computed;
	}

	/**
	 * Listener para edu_partial_closed: recalcula el trimestre.
	 */
	public static function on_partial_closed( $student_id, $subject_id, $trimester_id, $parcial_num ) {
		self::recalculate_trimester( (int) $student_id, (int) $subject_id, (int) $trimester_id );
	}

	/**
	 * Recalcula y persiste la nota anual para (estudiante, materia, período).
	 *
	 * Fórmula: (T1 + T2 + T3) / 3
	 * Estados: ≥ 7 → aprobado · 5–6.99 → supletorio · < 5 → en_curso
	 * (remedial/gracia se actualizan cuando se ingresen esas notas).
	 *
	 * @return float|false Promedio anual o false si no hay notas de trimestre.
	 */
	public static function recalculate_year( $student_id, $subject_id, $trimester_id ) {
		global $wpdb;
		$tts = $wpdb->prefix . 'edu_trimester_scores';
		$tt  = $wpdb->prefix . 'edu_trimesters';
		$tys = $wpdb->prefix . 'edu_year_scores';

		$student_id   = (int) $student_id;
		$subject_id   = (int) $subject_id;
		$trimester_id = (int) $trimester_id;

		// Obtener period_id del trimestre.
		$period_id = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT period_id FROM $tt WHERE id = %d",
			$trimester_id
		) );
		if ( ! $period_id ) {
			return false;
		}

		// Todos los trimestres del período, con su número (1, 2, 3).
		$trimesters = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, number FROM $tt WHERE period_id = %d ORDER BY number",
			$period_id
		) );

		// Mapear cada nota a SU número de trimestre: si falta el T2 pero existe
		// el T3, la nota del T3 debe ir en trim3 (no reindexar por presencia).
		$trim_map = array();
		foreach ( $trimesters as $tri ) {
			$row = $wpdb->get_row( $wpdb->prepare(
				"SELECT computed_score, is_closed FROM $tts WHERE student_id = %d AND subject_id = %d AND trimester_id = %d",
				$student_id, $subject_id, (int) $tri->id
			) );
			if ( $row ) {
				$trim_map[ (int) $tri->number ] = (float) $row->computed_score;
			}
		}

		if ( empty( $trim_map ) ) {
			return false;
		}

		// Promedio de los trimestres con nota registrada (a fin de año = (T1+T2+T3)/3).
		$average = round( array_sum( $trim_map ) / count( $trim_map ), 2 );

		// Estado según promedio.
		if ( $average >= 7.0 ) {
			$status = 'aprobado';
		} elseif ( $average >= 5.0 ) {
			$status = 'supletorio';
		} else {
			$status = 'en_curso';
		}

		$t1 = $trim_map[1] ?? 0.0;
		$t2 = $trim_map[2] ?? 0.0;
		$t3 = $trim_map[3] ?? 0.0;

		$existing = $wpdb->get_row( $wpdb->prepare(
			"SELECT id FROM $tys WHERE student_id = %d AND subject_id = %d AND period_id = %d",
			$student_id, $subject_id, $period_id
		) );

		if ( $existing ) {
			$wpdb->update(
				$tys,
				array(
					'trim1'   => $t1,
					'trim2'   => $t2,
					'trim3'   => $t3,
					'average' => $average,
					'status'  => $status,
				),
				array( 'id' => (int) $existing->id ),
				array( '%f', '%f', '%f', '%f', '%s' ),
				array( '%d' )
			);
		} else {
			$wpdb->insert(
				$tys,
				array(
					'student_id' => $student_id,
					'subject_id' => $subject_id,
					'period_id'  => $period_id,
					'trim1'      => $t1,
					'trim2'      => $t2,
					'trim3'      => $t3,
					'average'    => $average,
					'status'     => $status,
				),
				array( '%d', '%d', '%d', '%f', '%f', '%f', '%f', '%s' )
			);
		}

		return $average;
	}

	/**
	 * Listener para edu_trimester_closed: recalcula el año.
	 */
	public static function on_trimester_closed( $student_id, $subject_id, $trimester_id ) {
		self::recalculate_year( (int) $student_id, (int) $subject_id, (int) $trimester_id );
	}

	/**
	 * Recalcula el estado de recuperación tras ingresar supletorio/remedial/gracia.
	 *
	 * Transiciones:
	 *   supletorio_score >= 7 → aprobado  |  < 7 → remedial
	 *   remedial_score   >= 7 → aprobado  |  < 7 → gracia
	 *   gracia_score     >= 7 → aprobado  |  < 7 → reprobado
	 *
	 * Solo avanza al siguiente estado si el actual permite la transición.
	 * No sobreescribe estados ya cerrados (aprobado/reprobado).
	 *
	 * @param  object $row  Fila de wp_edu_year_scores (debe incluir todos los campos).
	 * @return string       Nuevo status calculado.
	 */
	public static function recalculate_recovery_status( $row ) {
		$status           = $row->status;
		$supletorio_score = ( null !== $row->supletorio_score ) ? (float) $row->supletorio_score : null;
		$remedial_score   = ( null !== $row->remedial_score )   ? (float) $row->remedial_score   : null;
		$gracia_score     = ( null !== $row->gracia_score )     ? (float) $row->gracia_score     : null;

		// Partiendo del promedio anual, aplicar transiciones en cascada.
		if ( in_array( $status, array( 'aprobado', 'reprobado' ), true ) ) {
			return $status; // Ya finalizado.
		}

		// Supletorio.
		if ( null !== $supletorio_score ) {
			if ( $supletorio_score >= 7.0 ) {
				return 'aprobado';
			}
			$status = 'remedial';
		}

		// Remedial.
		if ( 'remedial' === $status && null !== $remedial_score ) {
			if ( $remedial_score >= 7.0 ) {
				return 'aprobado';
			}
			$status = 'gracia';
		}

		// Gracia.
		if ( 'gracia' === $status && null !== $gracia_score ) {
			return ( $gracia_score >= 7.0 ) ? 'aprobado' : 'reprobado';
		}

		return $status;
	}
}
