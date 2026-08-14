/**
 * Estado compartido de la app.
 *
 * Guarda el perfil que devuelve GET /me y el estudiante que se está viendo:
 * para el estudiante es él mismo, para el representante el hijo seleccionado.
 * Así las vistas de notas, tareas y asistencia sirven para ambos portales sin
 * duplicarse.
 */

const { reactive, computed } = Vue;

export const store = reactive( {
	me: null,
	loading: true,
	error: null,

	/** Estudiante en foco. */
	studentId: null,
} );

/** Perfil resumido. */
export const profile = computed( () => store.me?.profile || {} );

/**
 * Tipo de portal a mostrar: lo fija el shortcode o se deduce del rol.
 *
 * `GET /me` devuelve el tipo en inglés (student, parent, teacher, rector,
 * superadmin); aquí se traduce al nombre del portal.
 */
const PORTAL_POR_TIPO = {
	student: 'estudiante',
	parent: 'padre',
	teacher: 'docente',
	rector: 'rector',
	superadmin: 'rector',
};

export const portal = computed( () => {
	const forced = window.eduSpa?.portal;
	if ( forced ) return forced;

	return PORTAL_POR_TIPO[ profile.value.type ] || profile.value.type;
} );

export const isParent = computed( () => portal.value === 'padre' );

/** Hijos del representante. */
export const children = computed( () => profile.value.children || [] );

/** El estudiante en foco, con su grado. */
export const currentStudent = computed( () => {
	if ( ! store.studentId ) return null;

	if ( isParent.value ) {
		return children.value.find( ( c ) => c.student_id === store.studentId ) || null;
	}

	return {
		student_id: profile.value.student_id,
		nombres: store.me?.nombres,
		apellidos: store.me?.apellidos,
		grade: profile.value.grade,
	};
} );

/** ¿Está encendido este módulo en la institución? */
export function moduleActive( slug ) {
	return store.me?.modules?.[ slug ] !== false;
}

/** Iniciales para el avatar. */
export function initials( nombres, apellidos ) {
	return ( ( nombres || '' ).charAt( 0 ) + ( apellidos || '' ).charAt( 0 ) ).toUpperCase() || '·';
}

/** Color estable derivado del nombre, para el avatar. */
export function avatarColor( seed ) {
	const colors = [ '#1d4ed8', '#7c3aed', '#0891b2', '#059669', '#d97706', '#dc2626' ];
	let sum = 0;
	for ( const ch of String( seed || '' ) ) sum += ch.charCodeAt( 0 );
	return colors[ sum % colors.length ];
}

/** Fecha ISO → texto corto en español. */
export function formatDate( iso, withTime = false ) {
	if ( ! iso ) return '—';

	const d = new Date( iso );
	if ( isNaN( d ) ) return '—';

	const fecha = d.toLocaleDateString( 'es-EC', { day: '2-digit', month: 'short', year: 'numeric' } );
	if ( ! withTime ) return fecha;

	return fecha + ' · ' + d.toLocaleTimeString( 'es-EC', { hour: '2-digit', minute: '2-digit' } );
}

/** Nota con 2 decimales, o guion si no hay. */
export function formatScore( value ) {
	return value === null || value === undefined ? '—' : Number( value ).toFixed( 2 );
}
