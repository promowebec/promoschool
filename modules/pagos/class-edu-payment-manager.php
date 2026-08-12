<?php
/**
 * Lógica de negocio del módulo de pagos.
 *
 * Responsabilidades:
 *   - Generar registros de pensión mensuales para todos los estudiantes activos.
 *   - Marcar como 'overdue' los pagos que superaron la fecha de gracia.
 *   - Registrar un pago como pagado (webhook o manual).
 *   - Generar / validar tokens para links de pago.
 *
 * @package SistemaEducativo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Edu_Payment_Manager {

	/* -----------------------------------------------------------------------
	 * Cron diario
	 * -------------------------------------------------------------------- */

	/**
	 * Tarea del cron diario `edu_payment_daily_cron`.
	 * Procesa todos los períodos activos.
	 */
	public static function daily_cron(): void {
		global $wpdb;
		$p = $wpdb->prefix . 'edu_';

		$periods = $wpdb->get_results(
			"SELECT id, institution_id FROM {$p}periods WHERE is_active = 1"
		);

		foreach ( $periods as $period ) {
			self::generate_monthly_payments( (int) $period->id, (int) $period->institution_id );
			self::mark_overdue( (int) $period->id );
		}
	}

	/**
	 * Crea registros de pensión para el mes en curso si aún no existen.
	 */
	public static function generate_monthly_payments( int $period_id, int $institution_id ): void {
		global $wpdb;
		$p     = $wpdb->prefix . 'edu_';
		$month = gmdate( 'Y-m' );

		$students = $wpdb->get_results( $wpdb->prepare(
			"SELECT s.id AS student_id, s.grade_id
			 FROM {$p}students s
			 INNER JOIN {$p}grades g ON g.id = s.grade_id
			 LEFT JOIN {$wpdb->usermeta} um ON um.user_id = s.user_id AND um.meta_key = 'edu_account_status'
			 WHERE g.institution_id = %d
			   AND COALESCE(um.meta_value,'active') = 'active'",
			$institution_id
		) );

		foreach ( $students as $st ) {
			$exists = $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM {$p}payments
				 WHERE student_id = %d AND period_id = %d AND month = %s AND concept = 'pension'",
				(int) $st->student_id, $period_id, $month
			) );

			if ( $exists ) {
				continue;
			}

			$config = self::get_config( $institution_id, $period_id, (int) $st->grade_id );
			if ( ! $config || (float) $config->monthly_amount <= 0 ) {
				continue;
			}

			$due_day  = max( 1, min( 28, (int) $config->due_day ) );
			$due_date = gmdate( 'Y-m-' ) . sprintf( '%02d', $due_day );

			$wpdb->insert( "{$p}payments", array(
				'student_id' => (int) $st->student_id,
				'period_id'  => $period_id,
				'month'      => $month,
				'concept'    => 'pension',
				'amount'     => (float) $config->monthly_amount,
				'due_date'   => $due_date,
				'status'     => 'pending',
			) );
		}
	}

	/**
	 * Marca como 'overdue' los pagos 'pending' que superaron el plazo de gracia.
	 */
	public static function mark_overdue( int $period_id ): void {
		global $wpdb;
		$p     = $wpdb->prefix . 'edu_';
		$today = gmdate( 'Y-m-d' );

		// Obtener pagos pendientes con sus datos de configuración.
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT pay.id, pay.due_date, st.grade_id,
			        COALESCE(cfg_g.grace_days, cfg_all.grace_days, 5) AS grace_days
			 FROM {$p}payments pay
			 INNER JOIN {$p}students st ON st.id = pay.student_id
			 INNER JOIN {$p}grades g   ON g.id = st.grade_id
			 LEFT JOIN {$p}payment_config cfg_g
			   ON cfg_g.period_id = pay.period_id AND cfg_g.grade_id = st.grade_id
			 LEFT JOIN {$p}payment_config cfg_all
			   ON cfg_all.period_id = pay.period_id AND cfg_all.grade_id IS NULL
			   AND cfg_all.institution_id = g.institution_id
			 WHERE pay.period_id = %d AND pay.status = 'pending'",
			$period_id
		) );

		foreach ( $rows as $row ) {
			$cutoff = gmdate( 'Y-m-d', strtotime( $row->due_date . ' +' . (int) $row->grace_days . ' days' ) );
			if ( $today > $cutoff ) {
				$updated = $wpdb->update( "{$p}payments", array( 'status' => 'overdue' ), array( 'id' => (int) $row->id ) );
				if ( $updated ) {
					$student_id = (int) $wpdb->get_var( $wpdb->prepare(
						"SELECT student_id FROM {$p}payments WHERE id = %d",
						(int) $row->id
					) );
					do_action( 'edu_payment_overdue', (int) $row->id, $student_id );
				}
			}
		}
	}

	/* -----------------------------------------------------------------------
	 * Registro de pago
	 * -------------------------------------------------------------------- */

	/**
	 * Marca un pago como pagado (idempotente).
	 *
	 * @param int         $payment_id      ID en wp_edu_payments.
	 * @param string      $method          'payphone' | 'manual' | 'link'.
	 * @param string      $ref             Referencia / comprobante.
	 * @param string      $transaction_id  ID de transacción de Payphone (si aplica).
	 * @param int|null    $registered_by   wp_users.ID del usuario que lo registró (manual).
	 * @return bool
	 */
	public static function mark_paid(
		int $payment_id,
		string $method,
		string $ref = '',
		string $transaction_id = '',
		?int $registered_by = null
	): bool {
		global $wpdb;
		$p = $wpdb->prefix . 'edu_';

		$payment = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$p}payments WHERE id = %d", $payment_id
		) );

		if ( ! $payment ) {
			return false;
		}
		if ( 'paid' === $payment->status ) {
			return true; // Ya estaba pagado — idempotente.
		}

		$result = $wpdb->update( "{$p}payments", array(
			'status'                   => 'paid',
			'paid_at'                  => current_time( 'mysql' ),
			'payment_method'           => $method,
			'payment_ref'              => $ref,
			'payphone_transaction_id'  => $transaction_id ?: null,
			'registered_by'            => $registered_by,
		), array( 'id' => $payment_id ) );

		if ( false === $result ) {
			return false;
		}

		do_action( 'edu_payment_confirmed', $payment_id, (int) $payment->student_id );
		return true;
	}

	/* -----------------------------------------------------------------------
	 * Configuración
	 * -------------------------------------------------------------------- */

	/**
	 * Devuelve la configuración de pago para un grado/período.
	 * Prioridad: config específica del grado > config general (grade_id IS NULL).
	 */
	public static function get_config( int $institution_id, int $period_id, int $grade_id ): ?object {
		global $wpdb;
		$p = $wpdb->prefix . 'edu_';

		return $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$p}payment_config
			 WHERE institution_id = %d AND period_id = %d
			   AND (grade_id = %d OR grade_id IS NULL)
			 ORDER BY grade_id DESC
			 LIMIT 1",
			$institution_id, $period_id, $grade_id
		) ) ?: null;
	}

	/* -----------------------------------------------------------------------
	 * Links de pago
	 * -------------------------------------------------------------------- */

	/**
	 * Genera un token firmado para un link de pago compartible (expira en 72 h).
	 */
	public static function generate_payment_token( int $payment_id ): string {
		$expires = time() + ( 72 * HOUR_IN_SECONDS );
		$data    = $payment_id . '|' . $expires;
		$hmac    = hash_hmac( 'sha256', $data, wp_salt( 'auth' ) );
		return rtrim( base64_encode( $data . '|' . $hmac ), '=' );
	}

	/**
	 * Valida un token de pago y devuelve el payment_id, o false si es inválido/expirado.
	 */
	public static function validate_payment_token( string $token ): int|false {
		$raw = base64_decode( str_pad( $token, strlen( $token ) + ( 4 - strlen( $token ) % 4 ) % 4, '=' ) );
		if ( ! $raw ) {
			return false;
		}

		$parts = explode( '|', $raw );
		if ( count( $parts ) !== 3 ) {
			return false;
		}

		[ $payment_id, $expires, $hmac ] = $parts;
		$data     = $payment_id . '|' . $expires;
		$expected = hash_hmac( 'sha256', $data, wp_salt( 'auth' ) );

		if ( ! hash_equals( $expected, $hmac ) ) {
			return false;
		}
		if ( time() > (int) $expires ) {
			return false;
		}

		return (int) $payment_id;
	}

	/* -----------------------------------------------------------------------
	 * Página de link de pago (template_redirect)
	 * -------------------------------------------------------------------- */

	/**
	 * Renderiza la página de pago para un link compartido.
	 * Llamado desde template_redirect cuando ?edu_pago_token={token} está presente.
	 */
	public static function render_payment_link_page(): void {
		$token = isset( $_GET['edu_pago_token'] ) ? sanitize_text_field( wp_unslash( $_GET['edu_pago_token'] ) ) : '';
		if ( ! $token ) {
			return;
		}

		$payment_id = self::validate_payment_token( $token );
		if ( ! $payment_id ) {
			wp_die( esc_html__( 'El link de pago ha expirado o no es válido. Solicita uno nuevo.', 'sistema-educativo' ) );
		}

		global $wpdb;
		$p = $wpdb->prefix . 'edu_';

		$payment = $wpdb->get_row( $wpdb->prepare(
			"SELECT pay.*, st.user_id AS student_user_id,
			        COALESCE(um_fn.meta_value,'') AS first_name,
			        COALESCE(um_ln.meta_value, u.display_name) AS last_name,
			        g.name AS grade_name, g.paralelo,
			        inst.name AS inst_name
			 FROM {$p}payments pay
			 INNER JOIN {$p}students st ON st.id = pay.student_id
			 INNER JOIN {$p}grades g ON g.id = st.grade_id
			 INNER JOIN {$p}institutions inst ON inst.id = g.institution_id
			 INNER JOIN {$wpdb->users} u ON u.ID = st.user_id
			 LEFT JOIN {$wpdb->usermeta} um_fn ON um_fn.user_id = st.user_id AND um_fn.meta_key = 'first_name'
			 LEFT JOIN {$wpdb->usermeta} um_ln ON um_ln.user_id = st.user_id AND um_ln.meta_key = 'last_name'
			 WHERE pay.id = %d",
			$payment_id
		) );

		if ( ! $payment ) {
			wp_die( esc_html__( 'Pago no encontrado.', 'sistema-educativo' ) );
		}

		if ( 'paid' === $payment->status ) {
			wp_die( esc_html__( 'Este pago ya fue registrado. Gracias.', 'sistema-educativo' ) );
		}

		$student_name = trim( $payment->first_name . ' ' . $payment->last_name );
		$month_label  = self::month_label( $payment->month );
		$nonce        = wp_create_nonce( 'edu_pay_link_' . $payment_id );

		get_header();
		?>
		<div style="max-width:500px;margin:60px auto;padding:0 20px;font-family:sans-serif;">
			<h2 style="color:#1d4ed8;"><?php echo esc_html( $payment->inst_name ); ?></h2>
			<div style="border:1px solid #e2e8f0;border-radius:10px;padding:24px;background:#fff;">
				<p style="font-size:14px;color:#64748b;margin:0 0 16px;"><?php esc_html_e( 'Detalle del pago', 'sistema-educativo' ); ?></p>
				<table style="width:100%;font-size:15px;">
					<tr><td style="color:#64748b;padding:4px 0;"><?php esc_html_e( 'Estudiante', 'sistema-educativo' ); ?></td><td style="font-weight:600;"><?php echo esc_html( $student_name ); ?></td></tr>
					<tr><td style="color:#64748b;padding:4px 0;"><?php esc_html_e( 'Grado', 'sistema-educativo' ); ?></td><td><?php echo esc_html( $payment->grade_name . ' ' . $payment->paralelo ); ?></td></tr>
					<tr><td style="color:#64748b;padding:4px 0;"><?php esc_html_e( 'Concepto', 'sistema-educativo' ); ?></td><td><?php echo esc_html( 'Pensión ' . $month_label ); ?></td></tr>
					<tr><td style="color:#64748b;padding:4px 0;"><?php esc_html_e( 'Monto', 'sistema-educativo' ); ?></td><td style="font-size:20px;font-weight:800;color:#1d4ed8;">$<?php echo esc_html( number_format( (float) $payment->amount, 2 ) ); ?></td></tr>
				</table>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:24px;">
					<input type="hidden" name="action" value="edu_pay_via_link">
					<input type="hidden" name="payment_id" value="<?php echo (int) $payment_id; ?>">
					<input type="hidden" name="edu_pago_token" value="<?php echo esc_attr( $token ); ?>">
					<?php wp_nonce_field( 'edu_pay_via_link_' . $payment_id ); ?>
					<button type="submit" style="width:100%;padding:14px;background:#1d4ed8;color:#fff;border:none;border-radius:8px;font-size:16px;font-weight:700;cursor:pointer;">
						💳 <?php esc_html_e( 'Pagar con Payphone', 'sistema-educativo' ); ?>
					</button>
				</form>
			</div>
		</div>
		<?php
		get_footer();
		exit;
	}

	/* -----------------------------------------------------------------------
	 * Helpers
	 * -------------------------------------------------------------------- */

	/**
	 * Convierte 'YYYY-MM' a nombre legible en español (ej: "Enero 2026").
	 */
	public static function month_label( string $ym ): string {
		$meses = array(
			'01' => 'Enero', '02' => 'Febrero', '03' => 'Marzo',
			'04' => 'Abril', '05' => 'Mayo',    '06' => 'Junio',
			'07' => 'Julio', '08' => 'Agosto',  '09' => 'Septiembre',
			'10' => 'Octubre','11' => 'Noviembre','12' => 'Diciembre',
		);
		[ $year, $month ] = explode( '-', $ym . '-01' );
		return ( $meses[ $month ] ?? $month ) . ' ' . $year;
	}

	/**
	 * Etiqueta de estado de pago.
	 */
	public static function status_label( string $status ): string {
		$labels = array(
			'pending'  => '🔵 Pendiente',
			'paid'     => '✅ Pagado',
			'overdue'  => '🔴 Vencido',
			'waived'   => '⚪ Exonerado',
		);
		return $labels[ $status ] ?? $status;
	}
}
