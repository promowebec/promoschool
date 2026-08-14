<?php
/**
 * Arranque de la aplicación propia (Fase 2).
 *
 * Registra el shortcode [edu_app], que monta la SPA en cualquier página de
 * WordPress. Como la app vive en el mismo dominio que WordPress, se autentica
 * con la cookie de sesión más un nonce `wp_rest`: no hace falta CORS ni
 * guardar tokens en el navegador. El token Bearer de la API sigue disponible
 * para una app instalada o integraciones externas.
 *
 * La SPA reutiliza `public/css/portales.css`: mismo lenguaje visual que los
 * portales que va a reemplazar.
 *
 * @package SistemaEducativo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Edu_Spa {

	const HANDLE_VUE = 'edu-vue';
	const HANDLE_APP = 'edu-spa-app';
	const HANDLE_CSS = 'edu-spa-css';

	/** Versión de Vue empaquetada en el plugin. */
	const VUE_VERSION = '3.5.13';

	public static function register() {
		add_shortcode( 'edu_app', array( __CLASS__, 'render' ) );
		add_filter( 'script_loader_tag', array( __CLASS__, 'as_module' ), 10, 3 );
	}

	/**
	 * Renderiza el contenedor de la app.
	 *
	 * @param array $atts Atributos del shortcode. `portal` fija el portal a
	 *                    mostrar; por defecto se deduce del rol.
	 * @return string
	 */
	public static function render( $atts = array() ) {
		$atts = shortcode_atts( array( 'portal' => '' ), (array) $atts, 'edu_app' );

		if ( ! is_user_logged_in() ) {
			return self::login_notice();
		}

		$user = wp_get_current_user();

		if ( ! Edu_Api_Auth::user_may_use_api( $user ) ) {
			return '<div class="edu-portal-wrap"><div class="edu-spa-notice">'
				. esc_html__( 'Esta cuenta no tiene acceso al sistema educativo.', 'sistema-educativo' )
				. '</div></div>';
		}

		self::enqueue( $atts['portal'] );

		return '<div class="edu-portal-wrap"><div id="edu-app">'
			. '<div class="edu-spa-boot">' . esc_html__( 'Cargando…', 'sistema-educativo' ) . '</div>'
			. '</div></div>';
	}

	/**
	 * Aviso para visitantes sin sesión, respetando la página de login del sitio
	 * (Ultimate Member u otra).
	 *
	 * @return string
	 */
	private static function login_notice() {
		$login_url = wp_login_url( get_permalink() );

		return '<div class="edu-portal-wrap"><div class="edu-spa-notice">'
			. '<p>' . esc_html__( 'Necesitas iniciar sesión para ver esta página.', 'sistema-educativo' ) . '</p>'
			. '<p><a class="edu-btn edu-btn-primary" href="' . esc_url( $login_url ) . '">'
			. esc_html__( 'Iniciar sesión', 'sistema-educativo' ) . '</a></p>'
			. '</div></div>';
	}

	/**
	 * Encola Vue, la hoja de estilos y el punto de entrada de la app.
	 *
	 * @param string $portal Portal forzado por el shortcode.
	 */
	private static function enqueue( $portal = '' ) {
		$base = EDU_PLUGIN_URL . 'public/';
		$dir  = EDU_PLUGIN_DIR . 'public/';

		wp_enqueue_style(
			'edu-portales',
			$base . 'css/portales.css',
			array(),
			file_exists( $dir . 'css/portales.css' ) ? (string) filemtime( $dir . 'css/portales.css' ) : EDU_VERSION
		);

		wp_enqueue_style(
			self::HANDLE_CSS,
			$base . 'spa/css/app.css',
			array( 'edu-portales' ),
			file_exists( $dir . 'spa/css/app.css' ) ? (string) filemtime( $dir . 'spa/css/app.css' ) : EDU_VERSION
		);

		wp_enqueue_script(
			self::HANDLE_VUE,
			$base . 'spa/vendor/vue.global.prod.js',
			array(),
			self::VUE_VERSION,
			true
		);

		// Datos de arranque. Va como script clásico colgado de Vue, así que se
		// ejecuta antes que el módulo de la app.
		wp_add_inline_script(
			self::HANDLE_VUE,
			'window.eduSpa = ' . wp_json_encode( self::bootstrap_data( $portal ) ) . ';',
			'before'
		);

		/*
		 * El mapa de importación tiene que estar en el documento antes que el
		 * módulo de entrada. Va en el pie con prioridad 5 porque wp_head ya se
		 * imprimió cuando el shortcode se renderiza (esto corre dentro de
		 * the_content), y los scripts del pie salen en la prioridad 20.
		 */
		add_action( 'wp_footer', array( __CLASS__, 'print_import_map' ), 5 );

		wp_enqueue_script(
			self::HANDLE_APP,
			$base . 'spa/js/app.js',
			array( self::HANDLE_VUE ),
			file_exists( $dir . 'spa/js/app.js' ) ? (string) filemtime( $dir . 'spa/js/app.js' ) : EDU_VERSION,
			true
		);
	}

	/**
	 * Datos mínimos que la app necesita antes de su primera llamada.
	 *
	 * Deliberadamente escuetos: el perfil completo lo trae `GET /me`, para no
	 * duplicar aquí la lógica de permisos.
	 *
	 * @param string $portal Portal forzado.
	 * @return array
	 */
	private static function bootstrap_data( $portal = '' ) {
		return array(
			'restUrl'  => esc_url_raw( rest_url( Edu_Api::API_NAMESPACE ) ),
			'nonce'    => wp_create_nonce( 'wp_rest' ),
			'logoutUrl'=> esc_url_raw( wp_logout_url( home_url() ) ),
			'portal'   => sanitize_key( $portal ),
			'locale'   => get_locale(),
			'version'  => EDU_VERSION,
		);
	}

	/**
	 * Imprime el mapa de importación de los módulos de la app.
	 *
	 * Los módulos ES se importan por URL, y esas URLs no llevan versión: sin
	 * esto, el navegador seguiría sirviendo de su caché la versión anterior de
	 * cualquier archivo que no fuese el punto de entrada. El mapa asocia cada
	 * especificador `@edu/...` a su URL real con la fecha de modificación del
	 * archivo, de modo que al cambiar un módulo cambia su URL y el navegador lo
	 * vuelve a pedir. Es la alternativa sin compilación al hash de un bundler.
	 */
	public static function print_import_map() {
		$dir  = EDU_PLUGIN_DIR . 'public/spa/js/';
		$base = EDU_PLUGIN_URL . 'public/spa/js/';

		if ( ! is_dir( $dir ) ) {
			return;
		}

		$imports  = array();
		$archivos = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) );

		foreach ( $archivos as $archivo ) {
			if ( ! $archivo->isFile() || 'js' !== strtolower( $archivo->getExtension() ) ) {
				continue;
			}

			$relativo = str_replace( '\\', '/', substr( $archivo->getPathname(), strlen( $dir ) ) );

			$imports[ '@edu/' . $relativo ] = add_query_arg(
				'ver',
				(string) $archivo->getMTime(),
				$base . $relativo
			);
		}

		if ( empty( $imports ) ) {
			return;
		}

		ksort( $imports );

		printf(
			'<script type="importmap">%s</script>' . "\n",
			wp_json_encode( array( 'imports' => $imports ) ) // phpcs:ignore WordPress.Security.EscapeOutput -- wp_json_encode escapa el contenido.
		);
	}

	/**
	 * Marca el punto de entrada como módulo ES.
	 *
	 * Es lo que permite usar import/export sin ningún paso de compilación.
	 *
	 * @param string $tag    Etiqueta <script> completa.
	 * @param string $handle Handle del script.
	 * @param string $src    URL.
	 * @return string
	 */
	public static function as_module( $tag, $handle, $src ) {
		if ( self::HANDLE_APP !== $handle ) {
			return $tag;
		}

		return sprintf(
			'<script type="module" src="%s" id="%s-js"></script>' . "\n",
			esc_url( $src ),
			esc_attr( $handle )
		);
	}
}
