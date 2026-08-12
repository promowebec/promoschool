<?php
/**
 * PWA: Web App Manifest + Service Worker para los portales del Sistema Educativo.
 *
 * Sirve /edu-manifest.json y /edu-sw.js como rutas de WordPress (rewrite rules).
 * Inyecta el <link rel="manifest"> y el registro del SW en wp_head de las páginas
 * de los portales (si están configuradas en Ajustes del plugin).
 *
 * @package SistemaEducativo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Edu_PWA {

	const CACHE_VERSION = 'edu-v2'; // v2: ya no se cachea HTML autenticado.

	public static function register() {
		// Interceptación directa por URI — funciona SIN rewrite rules (prioridad 1 = muy temprano).
		add_action( 'init', array( __CLASS__, 'intercept_direct' ), 1 );
		// Rewrite rules para el path correcto de WordPress.
		add_action( 'init', array( __CLASS__, 'add_rewrite_rules' ) );
		// Auto-flush una sola vez por versión (sana automáticamente al actualizar el plugin).
		add_action( 'init', array( __CLASS__, 'maybe_flush_once' ), 99 );
		add_filter( 'query_vars',        array( __CLASS__, 'add_query_vars' ) );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_serve' ) );
		add_action( 'wp_head',           array( __CLASS__, 'inject_head' ) );
		add_action( 'wp_footer',         array( __CLASS__, 'inject_install_banner' ), 30 );
	}

	/* ─── Rewrite rules ──────────────────────────────────────────────────── */

	public static function add_rewrite_rules() {
		add_rewrite_rule( '^edu-manifest\.json$', 'index.php?edu_pwa=manifest', 'top' );
		add_rewrite_rule( '^edu-sw\.js$',         'index.php?edu_pwa=sw',       'top' );
		add_rewrite_rule( '^edu-offline$',        'index.php?edu_pwa=offline',  'top' );
	}

	public static function add_query_vars( $vars ) {
		$vars[] = 'edu_pwa';
		return $vars;
	}

	public static function flush() {
		self::add_rewrite_rules();
		flush_rewrite_rules();
	}

	/**
	 * Flush automático: si la versión guardada en la opción no coincide con la actual,
	 * regenera las rewrite rules sin tocar .htaccess. Solo corre una vez por versión.
	 */
	public static function maybe_flush_once() {
		if ( get_option( 'edu_pwa_rules_ver' ) !== EDU_VERSION ) {
			flush_rewrite_rules( false );
			update_option( 'edu_pwa_rules_ver', EDU_VERSION );
		}
	}

	/**
	 * Intercepta las URLs del PWA directamente por REQUEST_URI,
	 * sin depender de que las rewrite rules estén registradas en la BD.
	 * Cubre el caso de plugin ya activo antes de agregar el código PWA.
	 */
	public static function intercept_direct() {
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		$uri         = strtok( $request_uri, '?' );
		$site_path   = rtrim( (string) wp_parse_url( home_url(), PHP_URL_PATH ), '/' );

		if ( $uri === $site_path . '/edu-manifest.json' ) {
			self::serve_manifest();
		} elseif ( $uri === $site_path . '/edu-sw.js' ) {
			self::serve_sw();
		} elseif ( $uri === $site_path . '/edu-offline' ) {
			self::serve_offline_page();
		}
	}

	/* ─── Interceptor vía query_var (rewrite rule path) ─────────────────── */

	public static function maybe_serve() {
		$pwa = get_query_var( 'edu_pwa' );
		if ( 'manifest' === $pwa ) {
			self::serve_manifest();
		} elseif ( 'sw' === $pwa ) {
			self::serve_sw();
		} elseif ( 'offline' === $pwa ) {
			self::serve_offline_page();
		}
	}

	/* ─── wp_head ─────────────────────────────────────────────────────────── */

	/**
	 * Mapeo slug → opción de página de portal.
	 */
	private static function portal_map() {
		return array(
			'rector'     => 'edu_portal_rector_page_id',
			'docente'    => 'edu_portal_docente_page_id',
			'estudiante' => 'edu_portal_estudiante_page_id',
			'padre'      => 'edu_portal_padre_page_id',
		);
	}

	public static function inject_head() {
		if ( is_admin() ) {
			return;
		}
		$portal_map      = self::portal_map();
		$portal_page_ids = array_map( fn( $opt ) => (int) get_option( $opt, 0 ), $portal_map );
		$portal_page_ids = array_filter( $portal_page_ids );

		$current_id = get_the_ID();

		// Inyectar solo en páginas de portales configuradas, o en todo el frontend si no hay config.
		if ( ! empty( $portal_page_ids ) && ! in_array( $current_id, $portal_page_ids, true ) ) {
			return;
		}

		// Detectar qué portal es esta página para personalizar start_url.
		$portal_slug = '';
		foreach ( $portal_map as $slug => $opt ) {
			if ( (int) get_option( $opt, 0 ) === $current_id ) {
				$portal_slug = $slug;
				break;
			}
		}

		$manifest_url = home_url( '/edu-manifest.json' );
		if ( $portal_slug ) {
			$manifest_url = add_query_arg( 'portal', $portal_slug, $manifest_url );
		}
		$sw_url = home_url( '/edu-sw.js' );
		$theme_color  = '#4f46e5';
		$site_name    = esc_attr( get_bloginfo( 'name' ) );
		?>
<link rel="manifest" href="<?php echo esc_url( $manifest_url ); ?>">
<meta name="theme-color" content="<?php echo esc_attr( $theme_color ); ?>">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="apple-mobile-web-app-title" content="<?php echo $site_name; ?>">
<script>
if ('serviceWorker' in navigator) {
	window.addEventListener('load', function() {
		navigator.serviceWorker.register('<?php echo esc_js( $sw_url ); ?>', { scope: '/' })
			.catch(function(e) { console.warn('SW:', e); });
	});
}
</script>
		<?php
	}

	/* ─── Manifest JSON ──────────────────────────────────────────────────── */

	private static function serve_manifest() {
		$site_name = get_bloginfo( 'name' );
		$icons     = array();

		// Usar el ícono del sitio configurado en WordPress si existe.
		$icon_512 = get_site_icon_url( 512 );
		if ( $icon_512 ) {
			$icons[] = array(
				'src'     => $icon_512,
				'sizes'   => '512x512',
				'type'    => 'image/png',
				'purpose' => 'any maskable',
			);
		}
		$icon_192 = get_site_icon_url( 192 );
		if ( $icon_192 ) {
			$icons[] = array(
				'src'   => $icon_192,
				'sizes' => '192x192',
				'type'  => 'image/png',
			);
		}

		// Fallback: si el admin de WP no configuró site icon, usar el SVG incluido en el plugin.
		// sizes:'any' es el valor correcto para SVG en la spec del manifest.
		if ( empty( $icons ) ) {
			$icons[] = array(
				'src'     => EDU_PLUGIN_URL . 'public/images/edu-icon.svg',
				'sizes'   => 'any',
				'type'    => 'image/svg+xml',
				'purpose' => 'any',
			);
		}

		// Determinar start_url: si se solicita el manifest desde un portal específico,
		// apuntar directamente a esa página para que el ícono de la app lleve al portal correcto.
		$portal    = isset( $_GET['portal'] ) ? sanitize_key( $_GET['portal'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$start_url = home_url( '/' );
		$portal_map = self::portal_map();
		if ( $portal && isset( $portal_map[ $portal ] ) ) {
			$page_id = (int) get_option( $portal_map[ $portal ], 0 );
			if ( $page_id ) {
				$start_url = get_permalink( $page_id );
			}
		}

		$manifest = array(
			'name'             => $site_name,
			'short_name'       => mb_substr( $site_name, 0, 12 ),
			'description'      => __( 'Portal del Sistema Educativo Integral', 'sistema-educativo' ),
			'start_url'        => $start_url,
			'scope'            => home_url( '/' ),
			'display'          => 'standalone',
			'orientation'      => 'portrait-primary',
			'background_color' => '#ffffff',
			'theme_color'      => '#4f46e5',
			'lang'             => 'es',
			'icons'            => $icons,
			'shortcuts'        => self::build_shortcuts(),
		);

		header( 'Content-Type: application/manifest+json; charset=utf-8' );
		header( 'Cache-Control: public, max-age=3600' );
		echo wp_json_encode( $manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
		exit;
	}

	private static function build_shortcuts() {
		$map = array(
			'edu_portal_rector_page_id'     => __( 'Portal Rector',        'sistema-educativo' ),
			'edu_portal_docente_page_id'    => __( 'Portal Docente',       'sistema-educativo' ),
			'edu_portal_estudiante_page_id' => __( 'Portal Estudiante',    'sistema-educativo' ),
			'edu_portal_padre_page_id'      => __( 'Portal Representante', 'sistema-educativo' ),
		);
		$shortcuts = array();
		foreach ( $map as $option => $label ) {
			$page_id = (int) get_option( $option, 0 );
			if ( $page_id ) {
				$shortcuts[] = array(
					'name' => $label,
					'url'  => get_permalink( $page_id ),
				);
			}
		}
		return $shortcuts;
	}

	/* ─── Banner de instalación (wp_footer) ─────────────────────────────── */

	public static function inject_install_banner() {
		if ( is_admin() ) {
			return;
		}

		// Solo en páginas de portales (misma lógica que inject_head).
		$portal_map      = self::portal_map();
		$portal_page_ids = array_filter(
			array_map( fn( $opt ) => (int) get_option( $opt, 0 ), $portal_map )
		);
		$current_id = get_the_ID();
		if ( ! empty( $portal_page_ids ) && ! in_array( $current_id, $portal_page_ids, true ) ) {
			return;
		}
		?>
<style>
#edu-pwa-banner{position:fixed;bottom:24px;left:50%;transform:translateX(-50%) translateY(120px);opacity:0;pointer-events:none;background:#4f46e5;color:#fff;border-radius:16px;padding:14px 18px;display:flex;align-items:center;gap:12px;box-shadow:0 6px 28px rgba(79,70,229,.35);z-index:99999;transition:transform .35s cubic-bezier(.34,1.56,.64,1),opacity .35s ease;max-width:calc(100vw - 40px);font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif}
#edu-pwa-banner.edu-visible{transform:translateX(-50%) translateY(0);opacity:1;pointer-events:all}
#edu-pwa-banner svg{flex-shrink:0}
.edu-pwa-text{font-size:.85rem;line-height:1.35;flex:1;min-width:0}
.edu-pwa-text strong{display:block;font-size:.9rem}
#edu-pwa-install{background:#fff;color:#4f46e5;border:none;border-radius:8px;padding:8px 14px;font-size:.82rem;font-weight:700;cursor:pointer;white-space:nowrap;flex-shrink:0}
#edu-pwa-install:hover{background:#e0e7ff}
#edu-pwa-close{background:none;border:none;color:rgba(255,255,255,.65);font-size:1.3rem;line-height:1;cursor:pointer;padding:0 2px;flex-shrink:0}
#edu-pwa-close:hover{color:#fff}
</style>
<div id="edu-pwa-banner" role="complementary" aria-label="<?php esc_attr_e( 'Instalar aplicación', 'sistema-educativo' ); ?>" aria-live="polite">
	<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 36 36" width="36" height="36" aria-hidden="true">
		<rect width="36" height="36" rx="8" fill="rgba(255,255,255,.15)"/>
		<polygon points="18,8 30,15 18,22 6,15" fill="white"/>
		<rect x="9" y="17" width="18" height="8" rx="1.5" fill="#e0e7ff"/>
		<rect x="15" y="25" width="6" height="2" rx="1" fill="#c7d2fe"/>
		<line x1="30" y1="15" x2="30" y2="23" stroke="white" stroke-width="1.5" stroke-linecap="round"/>
		<circle cx="30" cy="25" r="2" fill="#c7d2fe"/>
	</svg>
	<div class="edu-pwa-text">
		<strong id="edu-pwa-title"><?php esc_html_e( 'Instala el portal', 'sistema-educativo' ); ?></strong>
		<span id="edu-pwa-desc"><?php esc_html_e( 'Acceso rápido desde tu pantalla de inicio', 'sistema-educativo' ); ?></span>
	</div>
	<button id="edu-pwa-install"><?php esc_html_e( 'Instalar', 'sistema-educativo' ); ?></button>
	<button id="edu-pwa-close" aria-label="<?php esc_attr_e( 'Cerrar', 'sistema-educativo' ); ?>">&#x2715;</button>
</div>
<script>
(function () {
	var DISMISSED_KEY = 'edu_pwa_dismissed';
	if (localStorage.getItem(DISMISSED_KEY)) return;

	var banner     = document.getElementById('edu-pwa-banner');
	var installBtn = document.getElementById('edu-pwa-install');
	var closeBtn   = document.getElementById('edu-pwa-close');
	var titleEl    = document.getElementById('edu-pwa-title');
	var descEl     = document.getElementById('edu-pwa-desc');
	var prompt     = null;

	function show() {
		banner.classList.add('edu-visible');
	}
	function hide(permanent) {
		banner.classList.remove('edu-visible');
		if (permanent) localStorage.setItem(DISMISSED_KEY, '1');
	}

	closeBtn.addEventListener('click', function () { hide(true); });

	/* ── Android / Desktop Chrome ── */
	window.addEventListener('beforeinstallprompt', function (e) {
		e.preventDefault();
		prompt = e;
		setTimeout(show, 1500);
	});

	installBtn.addEventListener('click', function () {
		if (!prompt) return;
		prompt.prompt();
		prompt.userChoice.then(function (choice) {
			prompt = null;
			hide(choice.outcome === 'accepted');
		});
	});

	/* ── iOS Safari (sin beforeinstallprompt) ── */
	var ua         = navigator.userAgent.toLowerCase();
	var isIos      = /iphone|ipad|ipod/.test(ua);
	var isStandalone = ('standalone' in navigator) && navigator.standalone;

	if (isIos && !isStandalone) {
		titleEl.textContent = 'Añade el portal a inicio';
		descEl.textContent  = 'Safari → Compartir → Añadir a pantalla de inicio';
		installBtn.textContent = 'Entendido';
		installBtn.addEventListener('click', function () { hide(true); });
		setTimeout(show, 2500);
	}

	/* ── App ya instalada ── */
	window.addEventListener('appinstalled', function () { hide(true); });
})();
</script>
		<?php
	}

	/* ─── Página offline ─────────────────────────────────────────────────── */

	private static function serve_offline_page() {
		$site_name = esc_html( get_bloginfo( 'name' ) );

		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'Cache-Control: no-store' );

		echo <<<HTML
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="theme-color" content="#4f46e5">
<title>Sin conexión — {$site_name}</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;min-height:100vh;display:flex;align-items:center;justify-content:center;background:#f5f3ff;color:#1e1b4b;padding:24px}
.card{background:#fff;border-radius:20px;padding:48px 40px;max-width:400px;width:100%;text-align:center;box-shadow:0 4px 32px rgba(79,70,229,.12)}
.icon{margin-bottom:28px}
h1{font-size:1.5rem;font-weight:700;margin-bottom:10px}
p{font-size:.95rem;color:#6b7280;line-height:1.6;margin-bottom:28px}
button{background:#4f46e5;color:#fff;border:none;border-radius:10px;padding:12px 32px;font-size:.95rem;font-weight:600;cursor:pointer;transition:background .15s}
button:hover{background:#4338ca}
.site{font-size:.8rem;color:#a5b4fc;margin-top:28px}
</style>
</head>
<body>
<div class="card">
  <div class="icon">
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 80 80" width="80" height="80">
      <rect width="80" height="80" rx="16" fill="#4f46e5"/>
      <polygon points="40,18 67,33 40,48 13,33" fill="white"/>
      <rect x="21" y="37" width="38" height="17" rx="2" fill="#e0e7ff"/>
      <rect x="34" y="54" width="12" height="3" rx="1.5" fill="#c7d2fe"/>
      <line x1="67" y1="33" x2="67" y2="52" stroke="white" stroke-width="2.5" stroke-linecap="round"/>
      <circle cx="67" cy="55" r="3" fill="#c7d2fe"/>
    </svg>
  </div>
  <h1>Sin conexión</h1>
  <p>No hay acceso a internet en este momento.<br>Revisa tu conexión e inténtalo de nuevo.</p>
  <button onclick="location.reload()">Reintentar</button>
  <p class="site">{$site_name}</p>
</div>
</body>
</html>
HTML;
		exit;
	}

	/* ─── Service Worker JS ──────────────────────────────────────────────── */

	private static function serve_sw() {
		$cache_name    = self::CACHE_VERSION;
		$offline_url   = esc_js( home_url( '/edu-offline' ) );
		$static_assets = wp_json_encode(
			array(
				home_url( '/edu-offline' ),
				EDU_PLUGIN_URL . 'public/css/portales.css',
			),
			JSON_UNESCAPED_SLASHES
		);

		header( 'Content-Type: application/javascript; charset=utf-8' );
		header( 'Service-Worker-Allowed: /' );
		header( 'Cache-Control: no-store' );

		echo <<<JS
const CACHE_NAME    = '{$cache_name}';
const OFFLINE_URL   = '{$offline_url}';
const STATIC_ASSETS = {$static_assets};

/* ─── Instalación: pre-cachear assets + página offline ───────────────── */
self.addEventListener('install', function(event) {
	event.waitUntil(
		caches.open(CACHE_NAME).then(function(cache) {
			return cache.addAll(STATIC_ASSETS);
		}).catch(function() {})
	);
	self.skipWaiting();
});

/* ─── Activación: limpiar cachés antiguas ────────────────────────────── */
self.addEventListener('activate', function(event) {
	event.waitUntil(
		caches.keys().then(function(keys) {
			return Promise.all(
				keys.filter(function(k) { return k !== CACHE_NAME; })
					.map(function(k) { return caches.delete(k); })
			);
		})
	);
	self.clients.claim();
});

/* ─── Fetch: Network-first con fallback a caché / offline ────────────── */
self.addEventListener('fetch', function(event) {
	var req = event.request;
	var url = new URL(req.url);

	// Solo GET del mismo origen
	if (req.method !== 'GET' || url.origin !== location.origin) return;

	// No interceptar admin, AJAX ni formularios
	if (url.pathname.includes('/wp-admin') ||
		url.pathname.includes('admin-ajax.php') ||
		url.pathname.includes('admin-post.php') ||
		url.pathname.includes('wp-login.php')) return;

	event.respondWith(
		caches.open(CACHE_NAME).then(function(cache) {
			return fetch(req).then(function(response) {
				// No persistir HTML (páginas de portal con datos personales de
				// sesión) en Cache Storage: en un dispositivo compartido quedaría
				// legible tras cerrar sesión. Solo se cachean assets estáticos;
				// las navegaciones sin red caen a la página offline.
				var isHTML = req.mode === 'navigate' ||
					(response.headers.get('content-type') || '').includes('text/html');
				if (response && response.status === 200 && response.type === 'basic' && !isHTML) {
					cache.put(req, response.clone());
				}
				return response;
			}).catch(function() {
				return cache.match(req).then(function(cached) {
					if (cached) return cached;
					// Navegación sin caché y sin red: mostrar página offline
					if (req.mode === 'navigate') {
						return cache.match(OFFLINE_URL);
					}
				});
			});
		})
	);
});
JS;
		exit;
	}
}
