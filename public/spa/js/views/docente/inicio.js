/**
 * Inicio del docente: lo que necesita ver al entrar.
 *
 * Todo sale de GET /dashboard/docente, que ya devuelve el bloque armado.
 */

import { eduApi } from '@edu/api.js';
import { store, moduleActive, formatDate } from '@edu/store.js';

export const VistaDocenteInicio = {
	data: () => ( {
		cargando: true,
		error: null,
		datos: null,
		sinLeer: 0,
	} ),

	computed: {
		saludo() {
			const h = new Date().getHours();
			return h < 12 ? 'Buenos días' : h < 19 ? 'Buenas tardes' : 'Buenas noches';
		},

		nombre() {
			return store.me?.nombres || store.me?.display_name || '';
		},

		asignaciones() {
			return this.datos?.assignments || [];
		},

		porCalificar() {
			return this.datos?.por_calificar || [];
		},

		totalPorCalificar() {
			return this.porCalificar.reduce( ( n, t ) => n + t.pendientes, 0 );
		},

		asistenciaHoy() {
			return this.datos?.asistencia_hoy || [];
		},

		gradosSinTomar() {
			return this.asistenciaHoy.filter( ( g ) => ! g.tomada );
		},
	},

	mounted() {
		this.cargar();
	},

	methods: {
		async cargar() {
			this.cargando = true;
			this.error = null;

			const [ datos, comunicados ] = await Promise.all( [
				eduApi.get( '/dashboard/docente' ).catch( ( e ) => {
					this.error = e;
					return null;
				} ),
				moduleActive( 'comunicados' )
					? eduApi.get( '/me/announcements' ).catch( () => [] )
					: Promise.resolve( [] ),
			] );

			this.datos = datos;
			this.sinLeer = ( comunicados || [] ).filter( ( c ) => ! c.is_read ).length;
			this.cargando = false;
		},

		ir( ruta ) {
			window.location.hash = '#/' + ruta;
		},

		formatDate,
	},

	template: `
	<div>
		<div class="edu-content-header">
			<h2 class="edu-page-title">{{ saludo }}, {{ nombre }} 👋</h2>
			<p class="edu-page-sub" v-if="store.me?.active_period">
				{{ store.me.active_period.name }}
			</p>
		</div>

		<edu-loading v-if="cargando" texto="Preparando tu resumen…" />
		<edu-error v-else-if="error" :error="error" @retry="cargar" />

		<template v-else-if="datos">
			<div class="edu-stats-row">
				<edu-stat label="Entregas por calificar" :value="totalPorCalificar"
				          :color="totalPorCalificar ? '#d97706' : '#059669'" />
				<edu-stat label="Mis materias" :value="asignaciones.length" />
				<edu-stat label="Asistencia de hoy"
				          :value="asistenciaHoy.length - gradosSinTomar.length + ' / ' + asistenciaHoy.length"
				          :color="gradosSinTomar.length ? '#d97706' : '#059669'"
				          sub="grados registrados" />
				<edu-stat label="Comunicados sin leer" :value="sinLeer"
				          :color="sinLeer ? '#7c3aed' : '#1e293b'" />
			</div>

			<div class="edu-spa-cols">
				<edu-card titulo="Entregas por calificar">
					<edu-empty v-if="!porCalificar.length" texto="Nada pendiente de calificar." />
					<ul v-else class="edu-lista">
						<li v-for="t in porCalificar.slice(0, 6)" :key="t.assignment_id">
							<span>
								<strong>{{ t.title }}</strong>
								<span class="edu-muted"> · {{ t.grade_name }} · {{ t.subject_name }}</span>
							</span>
							<span class="edu-badge edu-badge-aviso">{{ t.pendientes }}</span>
						</li>
					</ul>
					<p><button class="edu-btn" @click="ir('tareas')">Ir a tareas</button></p>
				</edu-card>

				<edu-card titulo="Asistencia de hoy">
					<edu-empty v-if="!asistenciaHoy.length" texto="No tienes grados asignados." />
					<ul v-else class="edu-lista">
						<li v-for="g in asistenciaHoy" :key="g.grade_id">
							<span>{{ g.grade_name }}</span>
							<edu-badge v-if="g.tomada" texto="Tomada" tono="ok" />
							<edu-badge v-else :texto="g.registrados + ' / ' + g.estudiantes" tono="aviso" />
						</li>
					</ul>
					<p><button class="edu-btn" @click="ir('asistencia')">Tomar asistencia</button></p>
				</edu-card>
			</div>

			<edu-card titulo="Mis materias">
				<edu-empty v-if="!asignaciones.length" texto="No tienes asignaciones activas." />
				<div v-else class="edu-table-wrap">
					<table class="edu-table">
						<thead>
							<tr><th>Grado</th><th>Materia</th><th></th></tr>
						</thead>
						<tbody>
							<tr v-for="a in asignaciones" :key="a.id">
								<td>{{ a.grade_name }}</td>
								<td>{{ a.subject_name }}</td>
								<td class="edu-td-right">
									<button class="edu-btn" @click="ir('calificaciones')">Calificar</button>
								</td>
							</tr>
						</tbody>
					</table>
				</div>
			</edu-card>
		</template>
	</div>`,

	setup() {
		return { store };
	},
};
