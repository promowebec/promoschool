<?php
/**
 * Servicio: administración de pensiones y matrículas.
 *
 * Cubre lo que hace el rectorado: configurar valores, generar las cuotas del
 * mes, registrar pagos manuales, exonerar, emitir links de pago y suspender
 * morosos.
 *
 * NO cubre el circuito de Payphone (inicio del pago, retorno del navegador y
 * webhook): eso sigue en Edu_Payment_Controller porque son redirecciones y
 * llamadas de la pasarela, no operaciones de negocio. Invariante del
 * hardening v1.0.9 que se mantiene intacta: **un pago solo se marca como
 * pagado desde confirm_and_mark_paid()**, previa confirmación server-side. La
 * única excepción es el registro manual, que exige `edu_view_all` y queda
 * auditado.
 *
 * @package SistemaEducativo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Edu_Payment_Service {

	/* ─────────────────────────────────────────────────────────────────────
	 * Configuración
	 * ────────────────────────────────────────────────────────────────── */

	/**
	 * Guarda los valores de pensión y matrícula de un período.
	 *
	 * La clave `all` (o grade_id nulo) define el valor por defecto para todos
	 * los grados; una fila con grade_id lo sobrescribe para ese grado.
	 *
	 * @param array $input {
	 *     @type int   $period_id
	 *     @type array $config Lista de array( grade_id|null, monthly_amount,
	 *                         matricula_amount, due_day, grace_days ).
	 * }
	 * @return array|WP_Error
	 */
	public static function save_config( array $input ) {
		$cap = Edu_Service::require_cap( 'edu_view_all' );
		if ( is_wp_error( $cap ) ) {
			return $cap;
		}

		$institution_id = Edu_Service::require_institution();
		if ( is_wp_error( $institution_id ) ) {
			return $institution_id;
		}

		$period_id = isset( $input['period_id'] ) ? (int) $input['period_id'] : 0;

		if ( ! $period_id || ! self::period_in_institution( $period_id, $institution_id ) ) {
			return Edu_Service::error(
				'invalid_period',
				__( 'El período no pertenece a la institución activa.', 'sistema-educativo' ),
				403
			);
		}

		global $wpdb;
		$table = $wpdb->prefix . 'edu_payment_config';
		$saved = 0;

		foreach ( (array) ( $input['config'] ?? array() ) as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$grade_id = ( ! isset( $row['grade_id'] ) || null === $row['grade_id'] || 'all' === $row['grade_id'] )
				? null
				: (int) $row['grade_id'];

			// Un grado concreto debe ser de la institución activa.
			if ( null !== $grade_id ) {
				$scope = Edu_Service::check_scope( array( 'grade_id' => $grade_id ) );
				if ( is_wp_error( $scope ) ) {
					continue;
				}
			}

			$data = array(
				'institution_id'   => $institution_id,
				'period_id'        => $period_id,
				'grade_id'         => $grade_id,
				'monthly_amount'   => round( (float) ( $row['monthly_amount'] ?? 0 ), 2 ),
				'matricula_amount' => round( (float) ( $row['matricula_amount'] ?? 0 ), 2 ),
				'due_day'          => max( 1, min( 28, (int) ( $row['due_day'] ?? 5 ) ) ),
				'grace_days'       => max( 0, min( 30, (int) ( $row['grace_days'] ?? 5 ) ) ),
			);

			$existing_id = null === $grade_id
				? (int) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT id FROM $table WHERE institution_id = %d AND period_id = %d AND grade_id IS NULL",
						$institution_id,
						$period_id
					)
				)
				: (int) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT id FROM $table WHERE institution_id = %d AND period_id = %d AND grade_id = %d",
						$institution_id,
						$period_id,
						$grade_id
					)
				);

			if ( $existing_id ) {
				$wpdb->update( $table, $data, array( 'id' => $existing_id ) );
			} else {
				$wpdb->insert( $table, $data );
			}

			$saved++;
		}

		return array(
			'period_id' => $period_id,
			'saved'     => $saved,
		);
	}

	/**
	 * Configuración vigente de un período.
	 *
	 * @param int $period_id Período.
	 * @return array|WP_Error
	 */
	public static function get_config( $period_id ) {
		$cap = Edu_Service::require_cap( 'edu_view_all' );
		if ( is_wp_error( $cap ) ) {
			return $cap;
		}

		$institution_id = Edu_Service::require_institution();
		if ( is_wp_error( $institution_id ) ) {
			return $institution_id;
		}

		$period_id = (int) $period_id;

		if ( ! self::period_in_institution( $period_id, $institution_id ) ) {
			return Edu_Service::error(
				'invalid_period',
				__( 'El período no pertenece a la institución activa.', 'sistema-educativo' ),
				403
			);
		}

		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}edu_payment_config
				 WHERE institution_id = %d AND period_id = %d
				 ORDER BY grade_id IS NULL DESC, grade_id",
				$institution_id,
				$period_id
			)
		);

		return array_map(
			static function ( $row ) {
				return array(
					'id'               => (int) $row->id,
					'period_id'        => (int) $row->period_id,
					'grade_id'         => $row->grade_id ? (int) $row->grade_id : null,
					'monthly_amount'   => Edu_Api::decimal( $row->monthly_amount ),
					'matricula_amount' => Edu_Api::decimal( $row->matricula_amount ),
					'due_day'          => (int) $row->due_day,
					'grace_days'       => (int) $row->grace_days,
				);
			},
			(array) $rows
		);
	}

	/* ─────────────────────────────────────────────────────────────────────
	 * Generación de cuotas
	 * ────────────────────────────────────────────────────────────────── */

	/**
	 * Genera las cuotas del mes para todos los estudiantes activos.
	 *
	 * @param int $period_id Período.
	 * @return array|WP_Error
	 */
	public static function generate_monthly( $period_id ) {
		$cap = Edu_Service::require_cap( 'edu_view_all' );
		if ( is_wp_error( $cap ) ) {
			return $cap;
		}

		$institution_id = Edu_Service::require_institution();
		if ( is_wp_error( $institution_id ) ) {
			return $institution_id;
		}

		$period_id = (int) $period_id;

		if ( ! $period_id || ! self::period_in_institution( $period_id, $institution_id ) ) {
			return Edu_Service::error(
				'invalid_period',
				__( 'El período no pertenece a la institución activa.', 'sistema-educativo' ),
				403
			);
		}

		global $wpdb;
		$antes = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}edu_payments WHERE period_id = %d", $period_id )
		);

		Edu_Payment_Manager::generate_monthly_payments( $period_id, $institution_id );

		$despues = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}edu_payments WHERE period_id = %d", $period_id )
		);

		return array(
			'period_id' => $period_id,
			'created'   => max( 0, $despues - $antes ),
			'total'     => $despues,
		);
	}

	/* ─────────────────────────────────────────────────────────────────────
	 * Operaciones sobre un pago
	 * ────────────────────────────────────────────────────────────────── */

	/**
	 * Registra un pago cobrado fuera de la pasarela (efectivo, transferencia…).
	 *
	 * @param array $input payment_id, payment_method, payment_ref.
	 * @return array|WP_Error
	 */
	public static function register_manual( array $input ) {
		$payment = self::load_payment( $input['payment_id'] ?? 0 );
		if ( is_wp_error( $payment ) ) {
			return $payment;
		}

		if ( 'paid' === $payment->status ) {
			return Edu_Service::error(
				'payment_not_pending',
				__( 'Este pago ya está registrado como pagado.', 'sistema-educativo' ),
				409
			);
		}

		$method = sanitize_key( (string) ( $input['payment_method'] ?? 'manual' ) );
		if ( ! in_array( $method, array( 'manual', 'transfer', 'check', 'link', 'payphone' ), true ) ) {
			$method = 'manual';
		}

		$ref = sanitize_text_field( (string) ( $input['payment_ref'] ?? '' ) );

		$ok = Edu_Payment_Manager::mark_paid( (int) $payment->id, $method, $ref, '', get_current_user_id() );

		if ( ! $ok ) {
			return Edu_Service::error( 'db_error', __( 'No se pudo registrar el pago.', 'sistema-educativo' ), 500 );
		}

		Edu_Audit::log(
			'pago_manual',
			'payment',
			(int) $payment->id,
			$payment->status,
			array(
				'metodo'     => $method,
				'referencia' => $ref,
				'monto'      => $payment->amount,
			)
		);

		return array(
			'id'             => (int) $payment->id,
			'status'         => 'paid',
			'payment_method' => $method,
			'payment_ref'    => $ref,
		);
	}

	/**
	 * Exonera un pago.
	 *
	 * @param int $payment_id Pago.
	 * @return array|WP_Error
	 */
	public static function waive( $payment_id ) {
		$payment = self::load_payment( $payment_id );
		if ( is_wp_error( $payment ) ) {
			return $payment;
		}

		if ( 'paid' === $payment->status ) {
			return Edu_Service::error(
				'payment_not_pending',
				__( 'Un pago ya cobrado no se puede exonerar.', 'sistema-educativo' ),
				409
			);
		}

		global $wpdb;

		$wpdb->update(
			$wpdb->prefix . 'edu_payments',
			array( 'status' => 'waived' ),
			array( 'id' => (int) $payment->id ),
			array( '%s' ),
			array( '%d' )
		);

		Edu_Audit::log( 'pago_exonerado', 'payment', (int) $payment->id, $payment->status, 'waived' );

		return array(
			'id'     => (int) $payment->id,
			'status' => 'waived',
		);
	}

	/**
	 * Emite un link de pago público para un pago pendiente.
	 *
	 * @param int $payment_id Pago.
	 * @return array|WP_Error
	 */
	public static function generate_link( $payment_id ) {
		$payment = self::load_payment( $payment_id );
		if ( is_wp_error( $payment ) ) {
			return $payment;
		}

		if ( in_array( $payment->status, array( 'paid', 'waived' ), true ) ) {
			return Edu_Service::error(
				'payment_not_pending',
				__( 'Este pago ya no admite cobro.', 'sistema-educativo' ),
				409
			);
		}

		$token = Edu_Payment_Manager::generate_payment_token( (int) $payment->id );

		return array(
			'id'  => (int) $payment->id,
			'url' => add_query_arg( 'edu_pago_token', rawurlencode( $token ), home_url( '/' ) ),
		);
	}

	/**
	 * Suspende las cuentas de los estudiantes con mora antigua.
	 *
	 * @param array $input period_id, days_threshold.
	 * @return array|WP_Error
	 */
	public static function suspend_overdue( array $input ) {
		$cap = Edu_Service::require_cap( 'edu_view_all' );
		if ( is_wp_error( $cap ) ) {
			return $cap;
		}

		$institution_id = Edu_Service::require_institution();
		if ( is_wp_error( $institution_id ) ) {
			return $institution_id;
		}

		$period_id = isset( $input['period_id'] ) ? (int) $input['period_id'] : 0;
		$days      = max( 1, (int) ( $input['days_threshold'] ?? 30 ) );

		if ( ! $period_id || ! self::period_in_institution( $period_id, $institution_id ) ) {
			return Edu_Service::error(
				'invalid_period',
				__( 'El período no pertenece a la institución activa.', 'sistema-educativo' ),
				403
			);
		}

		global $wpdb;
		$p = $wpdb->prefix . 'edu_';

		$student_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT pay.student_id
				 FROM {$p}payments pay
				 INNER JOIN {$p}students s ON s.id = pay.student_id
				 INNER JOIN {$p}grades g   ON g.id = s.grade_id
				 WHERE pay.period_id = %d AND pay.status = 'overdue'
				   AND g.institution_id = %d
				   AND DATEDIFF(%s, pay.due_date) >= %d",
				$period_id,
				$institution_id,
				gmdate( 'Y-m-d' ),
				$days
			)
		);

		$changed = 0;
		foreach ( (array) $student_ids as $sid ) {
			$user_id = (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT user_id FROM {$p}students WHERE id = %d", (int) $sid )
			);

			if ( $user_id && Edu_Account_Controller::set_status( $user_id, 'suspended' ) ) {
				$changed++;
			}
		}

		Edu_Audit::log(
			'morosos_suspendidos',
			'payment',
			$period_id,
			null,
			array(
				'periodo_id'  => $period_id,
				'dias'        => $days,
				'suspendidos' => $changed,
			)
		);

		return array(
			'period_id'      => $period_id,
			'days_threshold' => $days,
			'suspended'      => $changed,
			'candidates'     => count( (array) $student_ids ),
		);
	}

	/* ─── Internos ──────────────────────────────────────────────────────── */

	/**
	 * Carga un pago comprobando permiso y que sea de la institución activa.
	 *
	 * Antes estas operaciones solo miraban la capability: con el ID bastaba
	 * para exonerar o marcar pagada la cuota de otra institución.
	 *
	 * @param int $payment_id Pago.
	 * @return object|WP_Error
	 */
	private static function load_payment( $payment_id ) {
		$cap = Edu_Service::require_cap( 'edu_view_all' );
		if ( is_wp_error( $cap ) ) {
			return $cap;
		}

		$institution_id = Edu_Service::require_institution();
		if ( is_wp_error( $institution_id ) ) {
			return $institution_id;
		}

		$payment_id = (int) $payment_id;

		if ( ! $payment_id ) {
			return Edu_Service::not_found( __( 'El pago no existe.', 'sistema-educativo' ) );
		}

		global $wpdb;
		$p = $wpdb->prefix . 'edu_';

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT pay.*, g.institution_id
				 FROM {$p}payments pay
				 INNER JOIN {$p}students s ON s.id = pay.student_id
				 INNER JOIN {$p}grades g   ON g.id = s.grade_id
				 WHERE pay.id = %d",
				$payment_id
			)
		);

		if ( ! $row ) {
			return Edu_Service::not_found( __( 'El pago no existe.', 'sistema-educativo' ) );
		}

		if ( ! Edu_Context::is_superadmin_editorial() && (int) $row->institution_id !== $institution_id ) {
			return Edu_Service::invalid_scope();
		}

		return $row;
	}

	/**
	 * ¿El período pertenece a la institución?
	 *
	 * @param int $period_id      Período.
	 * @param int $institution_id Institución.
	 * @return bool
	 */
	private static function period_in_institution( $period_id, $institution_id ) {
		if ( Edu_Context::is_superadmin_editorial() ) {
			return (bool) $period_id;
		}

		global $wpdb;

		return (int) $institution_id === (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT institution_id FROM {$wpdb->prefix}edu_periods WHERE id = %d", (int) $period_id )
		);
	}
}
