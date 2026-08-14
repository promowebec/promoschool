/**
 * Punto de entrada de la app propia (Fase 2).
 *
 * Monta la SPA en #edu-app: barra superior, menú lateral y la vista activa.
 * La navegación va por hash (#/notas), así que funciona dentro de cualquier
 * página de WordPress sin tocar reglas de reescritura.
 *
 * Cubre los cuatro portales: estudiante, representante, docente y rector.
 * Los shortcodes equivalentes quedan congelados y se retirarán cuando la app
 * alcance paridad completa (checklist en docs/MANUAL_PANTALLAS.md).
 */

import { eduApi } from '@edu/api.js';
import {
	store,
	profile,
	portal,
	isParent,
	children,
	moduleActive,
	initials,
	avatarColor,
} from '@edu/store.js';
import { componentes } from '@edu/components.js';

import { VistaInicio } from '@edu/views/inicio.js';
import { VistaNotas } from '@edu/views/notas.js';
import { VistaTareas } from '@edu/views/tareas.js';
import { VistaAsistencia } from '@edu/views/asistencia.js';
import { VistaComunicados } from '@edu/views/comunicados.js';
import { VistaPagos } from '@edu/views/pagos.js';
import { VistaBoletines } from '@edu/views/boletines.js';

import { VistaDocenteInicio } from '@edu/views/docente/inicio.js';
import { VistaDocenteCalificaciones } from '@edu/views/docente/calificaciones.js';
import { VistaDocenteTareas } from '@edu/views/docente/tareas.js';
import { VistaDocenteAsistencia } from '@edu/views/docente/asistencia.js';
import { VistaDocenteComunicados } from '@edu/views/docente/comunicados.js';

import { VistaRectorInicio } from '@edu/views/rector/inicio.js';
import { VistaRectorDocentes } from '@edu/views/rector/docentes.js';
import { VistaRectorCierres } from '@edu/views/rector/cierres.js';
import { VistaRectorPagos } from '@edu/views/rector/pagos.js';
import { VistaRectorReportes } from '@edu/views/rector/reportes.js';
import { VistaRectorComunicados } from '@edu/views/rector/comunicados.js';

const { createApp } = Vue;

/** Rutas de estudiante y representante. */
const RUTAS_FAMILIA = {
	inicio:      { titulo: 'Inicio',      icono: '🏠', vista: VistaInicio },
	notas:       { titulo: 'Notas',       icono: '📊', vista: VistaNotas },
	tareas:      { titulo: 'Tareas',      icono: '📋', vista: VistaTareas, modulo: 'tareas' },
	asistencia:  { titulo: 'Asistencia',  icono: '✅', vista: VistaAsistencia, modulo: 'asistencia' },
	comunicados: { titulo: 'Comunicados', icono: '📣', vista: VistaComunicados, modulo: 'comunicados' },
	pagos:       { titulo: 'Pagos',       icono: '💳', vista: VistaPagos, modulo: 'pagos', soloPadre: true },
	boletines:   { titulo: 'Boletines',   icono: '📄', vista: VistaBoletines, modulo: 'boletines' },
};

/** Rutas del rector. */
const RUTAS_RECTOR = {
	inicio:      { titulo: 'Panel',       icono: '🏠', vista: VistaRectorInicio },
	docentes:    { titulo: 'Docentes',    icono: '👩‍🏫', vista: VistaRectorDocentes },
	cierres:     { titulo: 'Cierres',     icono: '🔒', vista: VistaRectorCierres },
	comunicados: { titulo: 'Comunicados', icono: '📣', vista: VistaRectorComunicados, modulo: 'comunicados' },
	pagos:       { titulo: 'Pagos',       icono: '💳', vista: VistaRectorPagos, modulo: 'pagos' },
	reportes:    { titulo: 'Reportes',    icono: '📄', vista: VistaRectorReportes },
};

/** Rutas del docente. */
const RUTAS_DOCENTE = {
	inicio:         { titulo: 'Inicio',         icono: '🏠', vista: VistaDocenteInicio },
	calificaciones: { titulo: 'Calificaciones', icono: '📊', vista: VistaDocenteCalificaciones },
	tareas:         { titulo: 'Tareas',         icono: '📋', vista: VistaDocenteTareas, modulo: 'tareas' },
	asistencia:     { titulo: 'Asistencia',     icono: '✅', vista: VistaDocenteAsistencia, modulo: 'asistencia' },
	comunicados:    { titulo: 'Comunicados',    icono: '📣', vista: VistaDocenteComunicados, modulo: 'comunicados' },
};

const App = {
	data: () => ( {
		ruta: 'inicio',
		studentId: null,
	} ),

	computed: {
		cargando: () => store.loading,
		error: () => store.error,
		me: () => store.me,
		perfil: () => profile.value,
		esPadre: () => isParent.value,
		hijos: () => children.value,

		institucion() {
			return store.me?.institution || null;
		},

		/** Mapa de rutas del portal en curso. */
		rutas() {
			if ( 'rector' === portal.value ) return RUTAS_RECTOR;
			if ( 'docente' === portal.value ) return RUTAS_DOCENTE;
			return RUTAS_FAMILIA;
		},

		/** Menú visible: se ocultan las secciones de módulos apagados. */
		menu() {
			return Object.entries( this.rutas )
				.filter( ( [ , def ] ) => {
					if ( def.soloPadre && ! this.esPadre ) return false;
					if ( def.modulo && ! moduleActive( def.modulo ) ) return false;
					return true;
				} )
				.map( ( [ clave, def ] ) => ( { clave, ...def } ) );
		},

		vistaActual() {
			return ( this.rutas[ this.ruta ] || this.rutas.inicio ).vista;
		},

		/** Portales que la app todavía no cubre. */
		portalNoCubierto() {
			return ! [ 'estudiante', 'padre', 'docente', 'rector' ].includes( portal.value );
		},

		/** Docente y rector no eligen estudiante: cada pantalla trae su selector. */
		esDocente() {
			return [ 'docente', 'rector' ].includes( portal.value );
		},

		iniciales() {
			return initials( store.me?.nombres, store.me?.apellidos );
		},

		colorAvatar() {
			return avatarColor( store.me?.display_name || store.me?.email );
		},

		subtituloUsuario() {
			const tipos = {
				student: 'Estudiante',
				parent: 'Representante',
				teacher: 'Docente',
				rector: 'Rector',
				superadmin: 'Administrador',
			};
			return tipos[ this.perfil.type ] || '';
		},

		logoutUrl() {
			return eduApi.config.logoutUrl;
		},
	},

	watch: {
		// Las vistas observan $root.studentId para recargar al cambiar de hijo.
		'studentId'( valor ) {
			store.studentId = valor;
		},
	},

	async mounted() {
		window.addEventListener( 'hashchange', this.leerHash );
		this.leerHash();
		await this.cargarPerfil();
	},

	beforeUnmount() {
		window.removeEventListener( 'hashchange', this.leerHash );
	},

	methods: {
		async cargarPerfil() {
			store.loading = true;
			store.error = null;

			try {
				store.me = await eduApi.get( '/me' );

				// Estudiante en foco: uno mismo, o el primer hijo.
				const inicial = isParent.value
					? children.value[ 0 ]?.student_id || null
					: profile.value.student_id || null;

				this.studentId = inicial;
				store.studentId = inicial;

				// Se relee el hash: hasta ahora no se sabía qué portal era, así
				// que un enlace directo a una ruta del docente caía en Inicio.
				this.leerHash();
			} catch ( e ) {
				store.error = e;
			} finally {
				store.loading = false;
			}
		},

		leerHash() {
			const clave = ( window.location.hash || '' ).replace( /^#\/?/, '' ).split( '?' )[ 0 ];
			this.ruta = this.rutas[ clave ] ? clave : 'inicio';
		},

		navegar( clave ) {
			window.location.hash = '#/' + clave;
		},

		reintentar() {
			this.cargarPerfil();
		},
	},

	template: `
	<div v-if="cargando" class="edu-spa-boot">
		<span class="edu-spa-spinner"></span> Cargando…
	</div>

	<edu-error v-else-if="error" :error="error" @retry="reintentar" />

	<div v-else-if="portalNoCubierto" class="edu-spa-notice">
		<p><strong>Esta parte todavía no está en la nueva aplicación.</strong></p>
		<p class="edu-muted">
			Tu cuenta no corresponde a ninguno de los portales de la aplicación.
		</p>
	</div>

	<template v-else>
		<div class="edu-topbar">
			<div class="edu-topbar-brand">
				<div class="edu-topbar-logo">{{ (institucion?.name || 'SE').substring(0, 2).toUpperCase() }}</div>
				<div class="edu-topbar-info">
					<div class="edu-topbar-name">{{ institucion?.name || 'Sistema Educativo' }}</div>
					<div class="edu-topbar-sub" v-if="me?.active_period">
						Año lectivo {{ me.active_period.name }}
					</div>
				</div>
			</div>
			<div class="edu-topbar-roles">
				<a class="edu-role-btn" :href="logoutUrl">Salir</a>
			</div>
		</div>

		<div class="edu-layout">
			<aside class="edu-sidebar">
				<div class="edu-sidebar-card">
					<div class="edu-avatar" :style="{ background: colorAvatar }">{{ iniciales }}</div>
					<div class="edu-user-name">{{ me?.nombres }} {{ me?.apellidos }}</div>
					<div class="edu-user-role">{{ subtituloUsuario }}</div>

					<div v-if="esPadre && hijos.length > 1" class="edu-sidebar-selector">
						<label class="edu-sidenav-section">Estudiante</label>
						<select v-model="studentId" class="edu-select">
							<option v-for="h in hijos" :key="h.student_id" :value="h.student_id">
								{{ h.nombres }} {{ h.apellidos }}
							</option>
						</select>
					</div>

					<div class="edu-sidenav-sep"></div>
					<div class="edu-sidenav-section">{{ subtituloUsuario }}</div>

					<nav class="edu-sidenav">
						<a v-for="item in menu" :key="item.clave"
						   href="javascript:void(0)"
						   class="edu-sidenav-item"
						   :class="{ active: ruta === item.clave }"
						   @click="navegar(item.clave)">
							<span class="edu-sidenav-icon">{{ item.icono }}</span>
							{{ item.titulo }}
						</a>
					</nav>
				</div>
			</aside>

			<main class="edu-content">
				<component :is="vistaActual" :key="ruta + '-' + (esDocente ? 'd' : studentId)" />
			</main>
		</div>
	</template>`,
};

const app = createApp( App );

Object.entries( componentes ).forEach( ( [ nombre, def ] ) => {
	// EduLoading → edu-loading
	const kebab = nombre.replace( /([a-z])([A-Z])/g, '$1-$2' ).toLowerCase();
	app.component( kebab, def );
} );

app.mount( '#edu-app' );
