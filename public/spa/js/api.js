/**
 * Cliente de la API edu/v1.
 *
 * La app vive en el mismo dominio que WordPress, así que se autentica con la
 * cookie de sesión más la cabecera X-WP-Nonce. No se guardan tokens en el
 * navegador.
 *
 * Todos los errores se normalizan a { code, message, status } para que las
 * vistas no tengan que distinguir entre un fallo de red y uno de la API.
 */

const cfg = window.eduSpa || {};

/** Error de la API, ya legible para mostrar en pantalla. */
export class EduApiError extends Error {
	constructor( code, message, status, details ) {
		super( message );
		this.code = code;
		this.status = status;
		this.details = details || null;
	}

	/** ¿La sesión caducó y hay que recargar? */
	get isSessionExpired() {
		return this.status === 401 ||
			this.code === 'rest_cookie_invalid_nonce' ||
			this.code === 'edu_not_authenticated';
	}
}

function buildUrl( path, params ) {
	const url = new URL( cfg.restUrl + path, window.location.origin );

	Object.entries( params || {} ).forEach( ( [ key, value ] ) => {
		if ( value === null || value === undefined || value === '' ) return;
		url.searchParams.set( key, value );
	} );

	return url.toString();
}

async function request( method, path, { params, body, form, headers } = {} ) {
	const options = {
		method,
		credentials: 'same-origin',
		headers: {
			'X-WP-Nonce': cfg.nonce,
			Accept: 'application/json',
			...( headers || {} ),
		},
	};

	if ( form instanceof FormData ) {
		// Sin Content-Type: el navegador pone el suyo con el boundary.
		options.body = form;
	} else if ( body !== undefined ) {
		options.headers['Content-Type'] = 'application/json';
		options.body = JSON.stringify( body );
	}

	let response;

	try {
		response = await fetch( buildUrl( path, params ), options );
	} catch ( e ) {
		throw new EduApiError(
			'network_error',
			'No se pudo conectar con el servidor. Revisa tu conexión.',
			0
		);
	}

	if ( response.status === 204 ) return null;

	let payload = null;

	try {
		payload = await response.json();
	} catch ( e ) {
		payload = null;
	}

	if ( ! response.ok ) {
		throw new EduApiError(
			payload?.code || 'unknown_error',
			payload?.message || 'Ocurrió un error inesperado.',
			response.status,
			payload?.data?.details || payload?.data?.params || null
		);
	}

	return payload;
}

export const eduApi = {
	get: ( path, params ) => request( 'GET', path, { params } ),
	post: ( path, body, params ) => request( 'POST', path, { body, params } ),
	put: ( path, body, params ) => request( 'PUT', path, { body, params } ),
	del: ( path, params ) => request( 'DELETE', path, { params } ),

	/**
	 * Envío multipart, para los adjuntos.
	 *
	 * @param {string}   path   Ruta de la API.
	 * @param {Object}   campos Campos simples; los objetos se serializan a JSON.
	 * @param {File[]}   files  Archivos, que viajan en `files[]`.
	 * @param {string}   method Método real: POST (por defecto) o PUT.
	 */
	postForm: ( path, campos = {}, files = [], method = 'POST' ) => {
		const form = new FormData();

		Object.entries( campos ).forEach( ( [ clave, valor ] ) => {
			if ( valor === null || valor === undefined ) return;
			form.append( clave, typeof valor === 'object' ? JSON.stringify( valor ) : valor );
		} );

		Array.from( files || [] ).forEach( ( f ) => form.append( 'files[]', f ) );

		/*
		 * Siempre se envía como POST: PHP solo parsea multipart/form-data en
		 * POST, así que un PUT real llegaba con $_POST y $_FILES vacíos y el
		 * servidor respondía "falta el título". El método verdadero se pide
		 * con la cabecera de override, que WP REST ya interpreta.
		 */
		const extra = 'POST' === method ? {} : { 'X-HTTP-Method-Override': method };

		return request( 'POST', path, { form, headers: extra } );
	},

	/**
	 * Abre un adjunto de tarea o de entrega.
	 *
	 * El binario nunca viaja en la respuesta: la API comprueba el permiso y
	 * devuelve una URL firmada de 5 minutos, atada a quien la pidió. Por eso el
	 * enlace se pide en el momento del clic y se abre enseguida.
	 *
	 * @param {number} id   ID del archivo.
	 * @param {string} tipo 'assignment' (material del docente) o 'submission'.
	 */
	abrirAdjunto: async ( id, tipo = 'assignment' ) => {
		const r = await request( 'GET', `/files/${ id }/link`, { params: { type: tipo } } );
		window.open( r.url, '_blank', 'noopener' );
	},

	config: cfg,
};
