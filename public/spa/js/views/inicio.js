/**
 * Pantalla de inicio del estudiante y del representante: un vistazo de cómo
 * va el estudiante en foco.
 */

import { eduApi } from '@edu/api.js';
import { store, currentStudent, moduleActive, formatDate, formatScore } from '@edu/store.js';

export const VistaInicio = {
	data: () => ( {
		cargando: true,
		error: null,
		notas: null,
		tareas: [],
		asistencia: null,
		comunicados: [],
	} ),

	computed: {
		estudiante() {
			return currentStudent.value;
		},

		saludo() {
			const h = new Date().getHours();
			return h < 12 ? 'Buenos días' : h < 19 ? 'Buenas tardes' : 'Buenas noches';
		},

		nombre() {
			return store.me?.nombres || store.me?.display_name || '';
		},

		periodo() {
			return store.me?.active_period?.name || '';
		},

		promedio() {
			const notas = ( this.notas?.subjects || [] )
				.map( ( m ) => m.year?.average )
				.filter( ( n ) => n !== null && n !== undefined );

			if ( ! notas.length ) return null;

			return notas.reduce( ( a, b ) => a + Number( b ), 0 ) / notas.length;
		},

		proximas() {
			const ahora = new Date();

			return this.tareas
				.filter( ( t ) => t.due_date && new Date( t.due_date ) >= ahora )
				.sort( ( a, b ) => new Date( a.due_date ) - new Date( b.due_date ) )
				.slice( 0, 5 );
		},

		sinLeer() {
			return this.comunicados.filter( ( c ) => ! c.is_read ).length;
		},

		porcentajeAsistencia() {
			return this.asistencia?.percentage;
		},

		colorAsistencia() {
			const p = this.porcentajeAsistencia;
			if ( p === null || p === undefined ) return '#1e293b';
			return p >= 90 ? '#059669' : p >= 75 ? '#d97706' : '#dc2626';
		},

		hayTareas() {
			return moduleActive( 'tareas' );
		},

		hayAsistencia() {
			return moduleActive( 'asistencia' );
		},

		hayComunicados() {
			return moduleActive( 'comunicados' );
		},
	},

	watch: {
		'$root.studentId'() {
			this.cargar();
		},
	},

	mounted() {
		this.cargar();
	},

	methods: {
		async cargar() {
			if ( ! store.studentId ) return;

			this.cargando = true;
			this.error = null;

			const sid = store.studentId;
			const grado = this.estudiante?.grade?.id;

			// Cada bloque falla por su cuenta: que no haya asistencia no debe
			// dejar la pantalla en blanco.
			const [ notas, tareas, asistencia, comunicados ] = await Promise.all( [
				eduApi.get( `/students/${ sid }/scores` ).catch( ( e ) => {
					this.error = this.error || e;
					return null;
				} ),
				this.hayTareas
					? eduApi.get( '/assignments', grado ? { grade_id: grado } : {} ).catch( () => [] )
					: Promise.resolve( [] ),
				this.hayAsistencia
					? eduApi.get( `/students/${ sid }/attendance` ).catch( () => null )
					: Promise.resolve( null ),
				this.hayComunicados
					? eduApi.get( '/me/announcements' ).catch( () => [] )
					: Promise.resolve( [] ),
			] );

			this.notas = notas;
			this.tareas = tareas || [];
			this.asistencia = asistencia;
			this.comunicados = comunicados || [];
			this.cargando = false;
		},

		ir( ruta ) {
			window.location.hash = '#/' + ruta;
		},

		formatDate,
		formatScore,
	},

	template: `
	<div>
		<div class="edu-content-header">
			<h2 class="edu-page-title">{{ saludo }}, {{ nombre }} 👋</h2>
			<p class="edu-page-sub">
				<template v-if="estudiante">
					{{ estudiante.nombres }} {{ estudiante.apellidos }}
					<span v-if="estudiante.grade"> · {{ estudiante.grade.name }} {{ estudiante.grade.paralelo }}</span>
				</template>
				<span v-if="periodo"> · {{ periodo }}</span>
			</p>
		</div>

		<edu-loading v-if="cargando" texto="Preparando tu resumen…" />

		<template v-else>
			<edu-error v-if="error" :error="error" @retry="cargar" />

			<div class="edu-stats-row">
				<edu-stat label="Promedio general"
				          :value="promedio === null ? '—' : formatScore(promedio)"
				          color="#1d4ed8" />
				<edu-stat v-if="hayTareas" label="Próximas entregas" :value="proximas.length" />
				<edu-stat v-if="hayAsistencia" label="Asistencia"
				          :value="porcentajeAsistencia === null || porcentajeAsistencia === undefined ? '—' : porcentajeAsistencia + '%'"
				          :color="colorAsistencia" />
				<edu-stat v-if="hayComunicados" label="Comunicados sin leer"
				          :value="sinLeer" :color="sinLeer ? '#7c3aed' : '#1e293b'" />
			</div>

			<div class="edu-spa-cols">
				<edu-card v-if="hayTareas" titulo="Próximas entregas">
					<edu-empty v-if="!proximas.length" texto="Nada pendiente por ahora." />
					<ul v-else class="edu-lista">
						<li v-for="t in proximas" :key="t.id">
							<span>
								<strong>{{ t.title }}</strong>
								<span class="edu-muted"> · {{ t.subject_name }}</span>
							</span>
							<span class="edu-muted edu-small">{{ formatDate(t.due_date) }}</span>
						</li>
					</ul>
					<p><button class="edu-btn" @click="ir('tareas')">Ver todas</button></p>
				</edu-card>

				<edu-card titulo="Notas por materia">
					<edu-empty v-if="!notas || !notas.subjects.length" texto="Sin calificaciones todavía." />
					<ul v-else class="edu-lista">
						<li v-for="m in notas.subjects.slice(0, 6)" :key="m.subject_id">
							<span>{{ m.subject_name }}</span>
							<edu-nota v-if="m.year" :score="m.year.average" :cualitativa="m.year.cualitativa" />
							<span v-else class="edu-muted">—</span>
						</li>
					</ul>
					<p><button class="edu-btn" @click="ir('notas')">Ver detalle</button></p>
				</edu-card>
			</div>
		</template>
	</div>`,
};
