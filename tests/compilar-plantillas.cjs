/**
 * Compila las plantillas de las vistas de la SPA con el mismo Vue que se sirve
 * en producción. Un error de plantilla no lo detecta `node --check`: solo
 * aparece al montar el componente en el navegador.
 */

const fs = require( 'fs' );
const path = require( 'path' );

const RAIZ = path.join( __dirname, '..', 'public', 'spa' );

// Cargar el build global de Vue tal cual lo sirve el plugin.
/*
 * Vue decodifica entidades HTML con el DOM real:
 *
 *   decoder.innerHTML = `<div foo="${ raw }">`;
 *   return decoder.children[0].getAttribute('foo');
 *
 * Se dispara con cualquier `&` dentro de un atributo — y las plantillas usan
 * `&&` en las expresiones a cada rato, así que hace falta un stub que de
 * verdad devuelva el valor, no un objeto vacío.
 */
function decodificar( texto ) {
	const nombradas = { amp: '&', lt: '<', gt: '>', quot: '"', apos: "'", nbsp: ' ' };

	return String( texto )
		.replace( /&#x([0-9a-f]+);/gi, ( _, h ) => String.fromCodePoint( parseInt( h, 16 ) ) )
		.replace( /&#(\d+);/g, ( _, d ) => String.fromCodePoint( parseInt( d, 10 ) ) )
		.replace( /&([a-z]+);/gi, ( todo, n ) => nombradas[ n.toLowerCase() ] ?? todo );
}

global.window = global;
global.document = {
	createElement: () => {
		let html = '';
		return {
			set innerHTML( v ) { html = v; },
			get innerHTML() { return html; },
			get textContent() { return decodificar( html ); },
			get children() {
				const m = html.match( /^<div foo="([\s\S]*)">$/ );
				const bruto = m ? m[ 1 ].replace( /&quot;/g, '"' ) : '';
				return [ { getAttribute: () => decodificar( bruto ) } ];
			},
		};
	},
	querySelector: () => null,
};

/*
 * El build global declara `var Vue = (function(){...})()`. Bajo `require()` esa
 * variable queda en el ámbito del módulo, no en el global, así que se evalúa a
 * mano y se devuelve el binding.
 */
const codigo = fs.readFileSync( path.join( RAIZ, 'vendor/vue.global.prod.js' ), 'utf8' );
const Vue = new Function( codigo + '\n;return Vue;' )(); // eslint-disable-line no-new-func

/*
 * El código de render que genera el build global empieza con `const _Vue = Vue`
 * y se evalúa con `new Function`, que resuelve `Vue` en el ámbito global. Sin
 * esto, toda compilación falla con "Vue is not defined".
 */
global.Vue = Vue;

if ( ! Vue || ! Vue.compile ) {
	console.log( 'Este build de Vue no trae el compilador; no se puede validar.' );
	process.exit( 2 );
}

function vistasDe( dir ) {
	return fs.readdirSync( dir, { withFileTypes: true } ).flatMap( ( e ) => {
		const p = path.join( dir, e.name );
		return e.isDirectory() ? vistasDe( p ) : ( e.name.endsWith( '.js' ) ? [ p ] : [] );
	} );
}

const archivos = vistasDe( path.join( RAIZ, 'js' ) );
let total = 0;
let fallos = 0;

for ( const archivo of archivos ) {
	const src = fs.readFileSync( archivo, 'utf8' );

	/*
	 * Greedy y anclado al final del archivo: la plantilla es siempre la última
	 * propiedad del objeto. Con búsqueda perezosa se corta en el primer
	 * backtick interior (varias plantillas llevan interpolaciones) y el HTML
	 * truncado falla al compilar aunque el original esté bien.
	 */
	const m = src.match( /template:\s*`([\s\S]*)`,?\s*\n\};?\s*$/ );
	if ( ! m ) continue;

	total++;
	const rel = path.relative( RAIZ, archivo ).replace( /\\/g, '/' );

	const plantilla = m[ 1 ];

	try {
		const errores = [];
		Vue.compile( plantilla, {
			onError: ( e ) => errores.push( e.message ),
			onWarn: ( e ) => errores.push( 'aviso: ' + e.message ),
		} );

		if ( errores.length ) {
			fallos++;
			console.log( `FALLA ${ rel }` );
			errores.forEach( ( e ) => console.log( `   ${ e }` ) );
		} else {
			console.log( `OK    ${ rel }` );
		}
	} catch ( e ) {
		fallos++;
		console.log( `FALLA ${ rel }` );
		console.log( `   ${ e.message }` );
	}
}

console.log( `\n=== ${ total } plantillas compiladas, ${ fallos } con error ===` );
process.exit( fallos ? 1 : 0 );
