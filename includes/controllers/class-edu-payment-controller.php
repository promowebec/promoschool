<?php
/**
 * Controller HTTP del módulo de pagos.
 *
 * Acciones admin-post:
 *   edu_save_payment_config      — rector guarda configuración de pensiones + credenciales Payphone.
 *   edu_init_payment             — padre inicia pago en línea (redirige a Payphone).
 *   edu_payment_return           — URL de retorno después del pago Payphone.
 *   edu_pay_via_link             — pago desde link compartido (nopriv).
 *   edu_register_manual_payment  — rector registra pago manual.
 *   edu_generate_payment_link    — rector genera link de pago compartible.
 *   edu_suspend_overdue_payments — rector suspende cuentas con mora.
 *   edu_waive_payment            — rector exonera un pago.
 *
 * REST:
 *   POST /wp-json/edu/v1/payphone/webhook  — webhook de confirmación de Payphone.
 *
 * @package SistemaEducativo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Edu_Payment_Controller {

	/**
	 * Devuelve la URL base a la que redirigir después de una acción.
	 * Si el POST incluye _return_url (URL interna válida) la usa; de lo contrario
	 * cae al panel de pagos en wp-admin.
	 */
	private static function return_base( string $admin_query_string = 'admin.php?page=edu-pagos' ): string {
		if ( ! empty( $_POST['_return_url'] ) ) {
			$candidate = esc_url_raw( wp_unslash( $_POST['_return_url'] ) );
			if ( $candidate && wp_validate_redirect( $candidate, '' ) ) {
				return $candidate;
			}
		}
		return admin_url( $admin_query_string );
	}

	/* -----------------------------------------------------------------------
	 * Configuración (rector / admin)
	 * -------------------------------------------------------------------- */

	public static function handle_save_config(): void {
		check_admin_referer( 'edu_save_payment_config' );

		if ( ! Edu_Context::can( 'edu_view_all' ) ) {
			wp_die( esc_html__( 'Sin permiso.', 'sistema-educativo' ) );
		}

		global $wpdb;
		$p              = $wpdb->prefix . 'edu_';
		$institution_id = Edu_Context::current_institution_id();
		$period_id      = isset( $_POST['config_period_id'] ) ? (int) $_POST['config_period_id'] : 0;

		if ( $institution_id && $period_id && ! empty( $_POST['cfg'] ) && is_array( $_POST['cfg'] ) ) {
			foreach ( $_POST['cfg'] as $grade_key => $vals ) {
				$grade_id      = ( 'all' === $grade_key ) ? null : (int) $grade_key;
				$monthly       = round( (float) ( $vals['monthly_amount'] ?? 0 ), 2 );
				$matricula     = round( (float) ( $vals['matricula_amount'] ?? 0 ), 2 );
				$due_day       = max( 1, min( 28, (int) ( $vals['due_day'] ?? 5 ) ) );
				$grace_days    = max( 0, min( 30, (int) ( $vals['grace_days'] ?? 5 ) ) );

				// Buscar fila existente.
				if ( null === $grade_id ) {
					$existing_id = (int) $wpdb->get_var( $wpdb->prepare(
						"SELECT id FROM {$p}payment_config
						 WHERE institution_id = %d AND period_id = %d AND grade_id IS NULL",
						$institution_id, $period_id
					) );
				} else {
					$existing_id = (int) $wpdb->get_var( $wpdb->prepare(
						"SELECT id FROM {$p}payment_config
						 WHERE institution_id = %d AND period_id = %d AND grade_id = %d",
						$institution_id, $period_id, $grade_id
					) );
				}

				$data = array(
					'institution_id'   => $institution_id,
					'period_id'        => $period_id,
					'grade_id'         => $grade_id,
					'monthly_amount'   => $monthly,
					'matricula_amount' => $matricula,
					'due_day'          => $due_day,
					'grace_days'       => $grace_days,
				);

				if ( $existing_id ) {
					$wpdb->update( "{$p}payment_config", $data, array( 'id' => $existing_id ) );
				} else {
					$wpdb->insert( "{$p}payment_config", $data );
				}
			}
		}

		// Credenciales Payphone (autoload=no: no cargarlas en memoria en cada request).
		if ( isset( $_POST['edu_payphone_token'] ) ) {
			update_option( 'edu_payphone_token', sanitize_text_field( wp_unslash( $_POST['edu_payphone_token'] ) ), false );
		}
		if ( isset( $_POST['edu_payphone_store_id'] ) ) {
			update_option( 'edu_payphone_store_id', sanitize_text_field( wp_unslash( $_POST['edu_payphone_store_id'] ) ), false );
		}

		wp_safe_redirect( add_query_arg( array(
			'pago_sub'    => 'config',
			'status'      => 'updated',
			'pago_period' => $period_id,
		), self::return_base( 'admin.php?page=edu-pagos' ) ) );
		exit;
	}

	/* -----------------------------------------------------------------------
	 * Test de conexión Payphone (rector)
	 * -------------------------------------------------------------------- */

	public static function handle_test_connection(): void {
		check_admin_referer( 'edu_test_payphone_connection' );

		if ( ! Edu_Context::can( 'edu_view_all' ) ) {
			wp_die( esc_html__( 'Sin permiso.', 'sistema-educativo' ) );
		}

		$result = Edu_Payphone::test_connection();

		wp_safe_redirect( add_query_arg( array(
			'pago_sub'    => 'config',
			'ph_test'     => $result['ok'] ? 'ok' : 'error',
			'ph_test_msg' => rawurlencode( $result['message'] ),
		), self::return_base( 'admin.php?page=edu-pagos' ) ) );
		exit;
	}

	/* -----------------------------------------------------------------------
	 * Generar cuotas del mes (rector)
	 * -------------------------------------------------------------------- */

	public static function handle_generate_monthly(): void {
		check_admin_referer( 'edu_generate_monthly_payments' );

		if ( ! Edu_Context::can( 'edu_view_all' ) ) {
			wp_die( esc_html__( 'Sin permiso.', 'sistema-educativo' ) );
		}

		$period_id      = isset( $_POST['period_id'] ) ? (int) $_POST['period_id'] : 0;
		$institution_id = Edu_Context::current_institution_id();

		if ( $period_id && $institution_id ) {
			Edu_Payment_Manager::generate_monthly_payments( $period_id, $institution_id );
		}

		wp_safe_redirect( add_query_arg( 'status', 'updated', self::return_base( 'admin.php?page=edu-pagos' ) ) );
		exit;
	}

	/* -----------------------------------------------------------------------
	 * Pago en línea (portal padre)
	 * -------------------------------------------------------------------- */

	/**
	 * Inicia el proceso de pago con Payphone.
	 * POST: payment_id, _wpnonce.
	 */
	public static function handle_init_payment(): void {
		check_admin_referer( 'edu_init_payment' );

		if ( ! is_user_logged_in() || ! current_user_can( 'edu_view_child_grades' ) ) {
			wp_die( esc_html__( 'Sin permiso.', 'sistema-educativo' ) );
		}

		$payment_id = isset( $_POST['payment_id'] ) ? (int) $_POST['payment_id'] : 0;
		$redirect   = isset( $_POST['_redirect'] ) ? esc_url_raw( wp_unslash( $_POST['_redirect'] ) ) : '';
		$cancel_url = $redirect ?: home_url( '?edu_tab=pagos' );

		if ( ! $payment_id ) {
			wp_safe_redirect( add_query_arg( 'edu_payment_error', 'invalid', $cancel_url ) );
			exit;
		}

		global $wpdb;
		$p = $wpdb->prefix . 'edu_';

		$payment = $wpdb->get_row( $wpdb->prepare(
			"SELECT pay.*, st.user_id AS student_user_id
			 FROM {$p}payments pay
			 INNER JOIN {$p}students st ON st.id = pay.student_id
			 WHERE pay.id = %d AND pay.status IN ('pending','overdue')",
			$payment_id
		) );

		if ( ! $payment ) {
			wp_safe_redirect( add_query_arg( 'edu_payment_error', 'not_found', $cancel_url ) );
			exit;
		}

		// Verificar que el padre tenga acceso a este estudiante.
		$uid    = get_current_user_id();
		$access = $wpdb->get_var( $wpdb->prepare(
			"SELECT p.id FROM {$p}parents p
			 INNER JOIN {$p}parent_student ps ON ps.parent_id = p.id
			 WHERE p.user_id = %d AND ps.student_id = %d
			 LIMIT 1",
			$uid, (int) $payment->student_id
		) );

		if ( ! $access ) {
			wp_die( esc_html__( 'Sin permiso para pagar este estudiante.', 'sistema-educativo' ) );
		}

		// clientTransactionId máx 15 chars — usar helper de Edu_Payphone.
		$client_txn_id = Edu_Payphone::make_txn_id( $payment_id );

		// Guardar return_url para que el webhook redirija al padre tras el pago.
		$padre_page_id = (int) get_option( 'edu_portal_padre_page_id', 0 );
		$portal_url    = $padre_page_id ? get_permalink( $padre_page_id ) : home_url( '/' );
		$return_url    = add_query_arg( array(
			'edu_tab'           => 'pagos',
			'edu_pay_return'    => '1',
			'edu_payment_id'    => $payment_id,
			'edu_client_txn_id' => $client_txn_id,
		), $portal_url );
		set_transient( 'edu_pay_return_' . $client_txn_id, $return_url, HOUR_IN_SECONDS );

		$reference = 'Pension ' . Edu_Payment_Manager::month_label( $payment->month );

		// API Link Payphone: no lleva responseUrl en el body — se configura en el portal Payphone.
		$payphone_url = Edu_Payphone::create_payment(
			(float) $payment->amount,
			$client_txn_id,
			$reference
		);

		if ( is_wp_error( $payphone_url ) ) {
			wp_safe_redirect( add_query_arg( 'edu_payment_error', rawurlencode( $payphone_url->get_error_message() ), $cancel_url ) );
			exit;
		}

		// Guardar clientTransactionId en BD (payment_ref) para que el webhook lo encuentre
		// incluso si el transient ya expiró (persiste más allá de HOUR_IN_SECONDS).
		$wpdb->update(
			"{$p}payments",
			array( 'payment_ref' => $client_txn_id ),
			array( 'id' => $payment_id )
		);

		// Guardar mapeo clientTransactionId → payment_id para el webhook.
		set_transient( 'edu_pay_ctxn_' . $client_txn_id, $payment_id, HOUR_IN_SECONDS );

		wp_redirect( esc_url_raw( $payphone_url ) );
		exit;
	}

	/**
	 * Confirma la transacción contra la API de Payphone y, solo si Payphone
	 * la reporta aprobada con el monto correcto, marca el pago como pagado.
	 *
	 * Nunca se debe llamar a Edu_Payment_Manager::mark_paid() para pagos
	 * Payphone sin pasar por aquí: los datos del retorno GET y del webhook
	 * son forjables por el cliente.
	 */
	private static function confirm_and_mark_paid( int $payment_id, string $client_txn_id, string $transaction_id ): bool {
		global $wpdb;
		$payment = $wpdb->get_row( $wpdb->prepare(
			"SELECT id, status, amount, payment_ref FROM {$wpdb->prefix}edu_payments WHERE id = %d",
			$payment_id
		) );

		if ( ! $payment ) {
			return false;
		}
		if ( 'paid' === $payment->status ) {
			return true;
		}

		// El clientTransactionId debe ser el que este plugin generó para este pago.
		if ( ! $payment->payment_ref || ! hash_equals( (string) $payment->payment_ref, $client_txn_id ) ) {
			return false;
		}

		$data = Edu_Payphone::confirm_payment( $client_txn_id, (int) $transaction_id );
		if ( is_wp_error( $data ) ) {
			return false;
		}

		$data_ci     = array_change_key_case( (array) $data, CASE_LOWER );
		$status_code = isset( $data_ci['statuscode'] ) ? (int) $data_ci['statuscode'] : null;
		$status_txt  = strtolower( (string) ( $data_ci['transactionstatus'] ?? '' ) );
		$approved    = ( 3 === $status_code ) || ( 'approved' === $status_txt );

		// Payphone confirma clientTransactionId y monto (en centavos).
		$ctxn_ok   = ! isset( $data_ci['clienttransactionid'] )
			|| hash_equals( $client_txn_id, (string) $data_ci['clienttransactionid'] );
		$expected  = (int) round( ( (float) $payment->amount ) * 100 );
		$amount_ok = ! isset( $data_ci['amount'] ) || (int) $data_ci['amount'] >= $expected;

		if ( ! $approved || ! $ctxn_ok || ! $amount_ok ) {
			return false;
		}

		return Edu_Payment_Manager::mark_paid( $payment_id, 'payphone', $client_txn_id, $transaction_id );
	}

	/**
	 * Maneja el retorno desde Payphone (response_url / return_url).
	 * Se llama desde el shortcode del portal padre cuando ?edu_pay_return=1 está presente.
	 * Devuelve 'success' | 'failed' | null.
	 */
	public static function process_payment_return(): ?string {
		if ( empty( $_GET['edu_pay_return'] ) || empty( $_GET['edu_payment_id'] ) ) {
			return null;
		}

		$payment_id     = (int) wp_unslash( $_GET['edu_payment_id'] );
		$client_txn_id  = sanitize_text_field( wp_unslash( $_GET['edu_client_txn_id'] ?? '' ) );
		// Payphone puede usar 'transactionId' o 'id' indistintamente en el redirect.
		$transaction_id = sanitize_text_field( wp_unslash( $_GET['transactionId'] ?? $_GET['id'] ?? '' ) );
		$txn_status_raw = $_GET['transactionStatus'] ?? null;
		$txn_status     = ( null !== $txn_status_raw ) ? (int) $txn_status_raw : null;

		if ( ! $payment_id ) {
			return 'failed';
		}

		global $wpdb;
		$payment = $wpdb->get_row( $wpdb->prepare(
			"SELECT id, status FROM {$wpdb->prefix}edu_payments WHERE id = %d",
			$payment_id
		) );

		if ( ! $payment ) {
			return 'failed';
		}

		// El webhook POST ya lo marcó pagado antes de que llegara el usuario.
		if ( 'paid' === $payment->status ) {
			return 'success';
		}

		// Si transactionStatus está presente y no es 3 → rechazo explícito.
		if ( null !== $txn_status && 3 !== $txn_status ) {
			return 'failed';
		}

		// Los parámetros del retorno GET los envía el navegador del usuario y son
		// forjables: SIEMPRE confirmar la transacción contra la API de Payphone.
		if ( $transaction_id && $client_txn_id ) {
			$paid = self::confirm_and_mark_paid( $payment_id, $client_txn_id, $transaction_id );
			return $paid ? 'success' : 'failed';
		}

		return 'failed';
	}

	/* -----------------------------------------------------------------------
	 * Pago desde link compartido (nopriv)
	 * -------------------------------------------------------------------- */

	public static function handle_pay_via_link(): void {
		$payment_id = isset( $_POST['payment_id'] ) ? (int) $_POST['payment_id'] : 0;
		$token      = isset( $_POST['edu_pago_token'] ) ? sanitize_text_field( wp_unslash( $_POST['edu_pago_token'] ) ) : '';

		check_admin_referer( 'edu_pay_via_link_' . $payment_id );

		$valid_id = Edu_Payment_Manager::validate_payment_token( $token );
		if ( ! $valid_id || $valid_id !== $payment_id ) {
			wp_die( esc_html__( 'Link inválido o expirado.', 'sistema-educativo' ) );
		}

		global $wpdb;
		$p = $wpdb->prefix . 'edu_';

		$payment = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$p}payments WHERE id = %d AND status IN ('pending','overdue')",
			$payment_id
		) );

		if ( ! $payment ) {
			wp_die( esc_html__( 'Este pago ya fue procesado o no existe.', 'sistema-educativo' ) );
		}

		$client_txn_id = Edu_Payphone::make_txn_id( $payment_id );
		$return_url    = add_query_arg( array(
			'edu_pago_token'    => $token,
			'edu_pay_return'    => '1',
			'edu_payment_id'    => $payment_id,
			'edu_client_txn_id' => $client_txn_id,
		), home_url( '/' ) );
		set_transient( 'edu_pay_return_' . $client_txn_id, $return_url, HOUR_IN_SECONDS );

		$reference    = 'Pension ' . Edu_Payment_Manager::month_label( $payment->month );
		$payphone_url = Edu_Payphone::create_payment(
			(float) $payment->amount,
			$client_txn_id,
			$reference
		);

		if ( is_wp_error( $payphone_url ) ) {
			wp_die( esc_html( $payphone_url->get_error_message() ) );
		}

		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . 'edu_payments',
			array( 'payment_ref' => $client_txn_id ),
			array( 'id' => $payment_id )
		);

		set_transient( 'edu_pay_ctxn_' . $client_txn_id, $payment_id, HOUR_IN_SECONDS );

		wp_redirect( esc_url_raw( $payphone_url ) );
		exit;
	}

	/* -----------------------------------------------------------------------
	 * Registro manual (rector / admin)
	 * -------------------------------------------------------------------- */

	public static function handle_register_manual(): void {
		check_admin_referer( 'edu_register_manual_payment' );

		if ( ! Edu_Context::can( 'edu_view_all' ) ) {
			wp_die( esc_html__( 'Sin permiso.', 'sistema-educativo' ) );
		}

		$payment_id = isset( $_POST['payment_id'] ) ? (int) $_POST['payment_id'] : 0;
		$method     = sanitize_key( wp_unslash( $_POST['payment_method'] ?? 'manual' ) );
		$ref        = sanitize_text_field( wp_unslash( $_POST['payment_ref'] ?? '' ) );

		if ( ! $payment_id ) {
			wp_safe_redirect( add_query_arg( 'status', 'error', self::return_base( 'admin.php?page=edu-pagos' ) ) );
			exit;
		}

		$result = Edu_Payment_Manager::mark_paid( $payment_id, $method, $ref, '', get_current_user_id() );

		wp_safe_redirect( add_query_arg( 'status', $result ? 'paid' : 'error', self::return_base( 'admin.php?page=edu-pagos' ) ) );
		exit;
	}

	/* -----------------------------------------------------------------------
	 * Generar link de pago (rector)
	 * -------------------------------------------------------------------- */

	public static function handle_generate_link(): void {
		check_admin_referer( 'edu_generate_payment_link' );

		if ( ! Edu_Context::can( 'edu_view_all' ) ) {
			wp_die( esc_html__( 'Sin permiso.', 'sistema-educativo' ) );
		}

		$payment_id = isset( $_POST['payment_id'] ) ? (int) $_POST['payment_id'] : 0;
		if ( ! $payment_id ) {
			wp_safe_redirect( self::return_base( 'admin.php?page=edu-pagos' ) );
			exit;
		}

		$token    = Edu_Payment_Manager::generate_payment_token( $payment_id );
		$link_url = add_query_arg( 'edu_pago_token', rawurlencode( $token ), home_url( '/' ) );

		wp_safe_redirect( add_query_arg( array(
			'status'   => 'link_ok',
			'pay_link' => rawurlencode( $link_url ),
		), self::return_base( 'admin.php?page=edu-pagos' ) ) );
		exit;
	}

	/* -----------------------------------------------------------------------
	 * Exonerar pago (rector)
	 * -------------------------------------------------------------------- */

	public static function handle_waive(): void {
		check_admin_referer( 'edu_waive_payment' );

		if ( ! Edu_Context::can( 'edu_view_all' ) ) {
			wp_die( esc_html__( 'Sin permiso.', 'sistema-educativo' ) );
		}

		$payment_id = isset( $_POST['payment_id'] ) ? (int) $_POST['payment_id'] : 0;
		if ( ! $payment_id ) {
			wp_safe_redirect( self::return_base( 'admin.php?page=edu-pagos' ) );
			exit;
		}

		global $wpdb;
		$wpdb->update( $wpdb->prefix . 'edu_payments', array( 'status' => 'waived' ), array( 'id' => $payment_id ) );

		wp_safe_redirect( add_query_arg( 'status', 'waived', self::return_base( 'admin.php?page=edu-pagos' ) ) );
		exit;
	}

	/* -----------------------------------------------------------------------
	 * Suspender morosos (rector)
	 * -------------------------------------------------------------------- */

	public static function handle_suspend_overdue(): void {
		check_admin_referer( 'edu_suspend_overdue_payments' );

		if ( ! Edu_Context::can( 'edu_view_all' ) ) {
			wp_die( esc_html__( 'Sin permiso.', 'sistema-educativo' ) );
		}

		global $wpdb;
		$p              = $wpdb->prefix . 'edu_';
		$period_id      = isset( $_POST['period_id'] ) ? (int) $_POST['period_id'] : 0;
		$days_threshold = max( 1, (int) ( $_POST['days_threshold'] ?? 30 ) );
		$today          = gmdate( 'Y-m-d' );

		if ( ! $period_id ) {
			wp_safe_redirect( add_query_arg( 'status', 'error', self::return_base( 'admin.php?page=edu-pagos' ) ) );
			exit;
		}

		$student_ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT DISTINCT student_id FROM {$p}payments
			 WHERE period_id = %d AND status = 'overdue'
			   AND DATEDIFF(%s, due_date) >= %d",
			$period_id, $today, $days_threshold
		) );

		$changed = 0;
		foreach ( $student_ids as $sid ) {
			$user_id = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT user_id FROM {$p}students WHERE id = %d", (int) $sid
			) );
			if ( $user_id && Edu_Account_Controller::set_status( $user_id, 'suspended' ) ) {
				$changed++;
			}
		}

		wp_safe_redirect( add_query_arg( array(
			'status'  => 'suspended',
			'changed' => $changed,
		), self::return_base( 'admin.php?page=edu-pagos' ) ) );
		exit;
	}

	/* -----------------------------------------------------------------------
	 * Webhook REST (Payphone → plugin)
	 * -------------------------------------------------------------------- */

	/**
	 * Registra el endpoint REST del webhook.
	 * Llamado desde `rest_api_init`.
	 */
	public static function register_rest_routes(): void {
		register_rest_route( 'edu/v1', '/payphone/webhook', array(
			'methods'             => \WP_REST_Server::ALLMETHODS, // POST (server-to-server) + GET (redirección usuario).
			'callback'            => array( __CLASS__, 'handle_webhook' ),
			'permission_callback' => '__return_true',
		) );
	}

	/**
	 * Procesa el webhook de Payphone.
	 *
	 * Payphone llama esta URL de dos formas:
	 *   POST: server-to-server con JSON del resultado → confirmamos el pago en DB.
	 *   GET:  redirección del usuario tras pagar → lo enviamos de vuelta al portal.
	 */
	public static function handle_webhook( \WP_REST_Request $request ): \WP_REST_Response {
		// Si el usuario llega vía GET (redirección desde Payphone), redirigirlo al portal.
		if ( 'GET' === $request->get_method() ) {
			$client_txn_id  = sanitize_text_field( $request->get_param( 'clientTransactionId' ) ?? '' );
			// Payphone puede usar 'transactionId' o 'id' indistintamente.
			$transaction_id = sanitize_text_field(
				$request->get_param( 'transactionId' ) ??
				$request->get_param( 'id' ) ?? ''
			);
			$txn_status     = $request->get_param( 'transactionStatus' );
			$return_url     = $client_txn_id ? get_transient( 'edu_pay_return_' . $client_txn_id ) : '';

			if ( $return_url ) {
				$return_url = add_query_arg( array(
					'transactionId'     => $transaction_id,
					'transactionStatus' => ( null !== $txn_status ) ? (int) $txn_status : '',
				), $return_url );
			} else {
				$return_url = home_url( '/' );
			}

			status_header( 302 );
			header( 'Location: ' . esc_url_raw( $return_url ) );
			die();
		}

		// POST: notificación server-to-server de Payphone.
		$body = $request->get_json_params();
		if ( empty( $body ) ) {
			$body = $request->get_body_params();
		}

		// Guardar último webhook recibido para diagnóstico.
		update_option( 'edu_debug_last_webhook', array(
			'time'   => current_time( 'mysql' ),
			'body'   => $body,
			'raw'    => substr( $request->get_body(), 0, 800 ),
		), false );

		// Normalizar claves (Payphone puede variar capitalización entre versiones).
		$body_ci        = array_change_key_case( (array) $body, CASE_LOWER );
		$client_txn_id  = sanitize_text_field( (string) ( $body_ci['clienttransactionid'] ?? '' ) );
		$transaction_id = sanitize_text_field( (string) ( $body_ci['transactionid'] ?? $body_ci['id'] ?? '' ) );

		// transactionStatus: si está presente y no es 3 = rechazado.
		$has_status = array_key_exists( 'transactionstatus', $body_ci );
		$txn_status = $has_status ? (int) $body_ci['transactionstatus'] : null;

		if ( empty( $client_txn_id ) ) {
			return new \WP_REST_Response( array( 'status' => 'no_client_txn_id' ), 200 );
		}

		if ( $has_status && 3 !== $txn_status ) {
			return new \WP_REST_Response( array( 'status' => 'not_approved', 'transactionStatus' => $txn_status ), 200 );
		}

		$payment_id = (int) get_transient( 'edu_pay_ctxn_' . $client_txn_id );

		if ( ! $payment_id ) {
			global $wpdb;
			$payment_id = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}edu_payments
				 WHERE payphone_transaction_id = %s OR payment_ref = %s
				 LIMIT 1",
				$transaction_id, $client_txn_id
			) );
		}

		// El webhook no lleva firma: el body es forjable por cualquiera que conozca
		// la URL. Solo se marca pagado si Payphone confirma la transacción.
		if ( $payment_id && $transaction_id ) {
			$paid = self::confirm_and_mark_paid( $payment_id, $client_txn_id, $transaction_id );
			if ( $paid ) {
				delete_transient( 'edu_pay_ctxn_' . $client_txn_id );
				delete_transient( 'edu_pay_return_' . $client_txn_id );
			}
			return new \WP_REST_Response( array( 'status' => $paid ? 'ok' : 'not_confirmed' ), 200 );
		}

		return new \WP_REST_Response( array( 'status' => 'ok' ), 200 );
	}
}
