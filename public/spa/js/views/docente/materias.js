/**
 * Mis materias: lo que dicta el docente, agrupado por grado.
 *
 * Es la vista de entrada práctica del docente — de un vistazo ve dónde tiene
 * trabajo pendiente y salta directo a calificar. Portada del tab "Mis materias"
 * del portal shortcode, que la SPA no cubría.
 */

import { eduApi } from '@edu/api.js';
import { store } from '@edu/store.js';

export const VistaDocenteMaterias = {
	data: () => ( {
		cargando: true,
		error: null,
		asignaciones: [],
	} ),

	computed: {
		/** Agrupadas por grado, que es como el docente piensa su horario. */
		porGrado() {
			const grupos = new Map();

			this.asignaciones.forEach( ( a ) => {
				if ( ! grupos.has( a.grade_id ) ) {
					grupos.set( a.grade_id, {
						grade_id: a.grade_id,
						grade_name: a.grade_name,
						sub_level: a.sub_level,
						materias: [],
					} );
				}

				grupos.get( a.grade_id ).materias.push( a );
			} );

			return [ ...grupos.values() ];
		},

		totalMaterias() {
			return this.asignaciones.length;
		},

		totalTareas() {
			return this.asignaciones.reduce( ( n, a ) => n + ( a.n_tareas || 0 ), 0 );
		},

		porRevisar() {
			return this.asignaciones.reduce( ( n, a ) => n + ( a.n_entregas || 0 ), 0 );
		},
	},

	mounted() {
		this.cargar();
	},

	methods: {
		async cargar() {
			this.cargando = true;
			this.error = null;

			try {
				// El servicio ya acota a las asignaciones propias del docente.
				this.asignaciones = await eduApi.get( '/teacher-assignments', { is_active: true } );
			} catch ( e ) {
				this.error = e;
			} finally {
				this.cargando = false;
			}
		},

		nivelLegible( subNivel ) {
			if ( ! subNivel ) return '';

			const s = String( subNivel ).replace( /_/g, ' ' );

			return s.charAt( 0 ).toUpperCase() + s.slice( 1 );
		},

		/**
		 * Salta a otra sección con este curso ya elegido. El selector de la vista
		 * destino consume `cursoPendiente` al montarse: así el docente no tiene
		 * que volver a escoger grado y materia que acaba de pulsar.
		 */
		irA( seccion, asignacion ) {
			store.cursoPendiente = {
				grade_id: asignacion.grade_id,
				subject_id: asignacion.subject_id,
			};

			this.$root.navegar( seccion );
		},
	},

	template: `
	<div>
		<div class="edu-content-header">
			<h2 class="edu-page-title">Mis materias</h2>
			<p class="edu-page-sub">Lo que dictas en el período activo.</p>
		</div>

		<edu-loading v-if="cargando" texto="Cargando tus materias…" />
		<edu-error v-else-if="error" :error="error" @retry="cargar" />
		<edu-empty v-else-if="!asignaciones.length"
		           texto="No tienes materias asignadas en el período activo." />

		<template v-else>
			<div class="edu-stats-row">
				<edu-stat label="Materias" :value="totalMaterias" />
				<edu-stat label="Tareas publicadas" :value="totalTareas" color="#1d4ed8" />
				<edu-stat label="Entregas por revisar" :value="porRevisar"
				          :color="porRevisar ? '#d97706' : '#059669'" />
			</div>

			<edu-card v-for="g in porGrado" :key="g.grade_id">
				<h3 class="edu-grupo-titulo">
					{{ g.grade_name }}
					<span class="edu-muted edu-small">{{ nivelLegible(g.sub_level) }}</span>
				</h3>

				<div class="edu-table-wrap">
					<table class="edu-table">
						<thead>
							<tr>
								<th>Materia</th>
								<th class="edu-th-num">Tareas</th>
								<th class="edu-th-num">Por revisar</th>
								<th class="edu-td-right">Acciones</th>
							</tr>
						</thead>
						<tbody>
							<tr v-for="m in g.materias" :key="m.id">
								<td><strong>{{ m.subject_name }}</strong></td>
								<td class="edu-td-num">
									<edu-badge v-if="m.n_tareas" :texto="String(m.n_tareas)" tono="info" />
									<span v-else class="edu-muted">—</span>
								</td>
								<td class="edu-td-num">
									<edu-badge v-if="m.n_entregas" :texto="String(m.n_entregas)" tono="aviso" />
									<span v-else class="edu-muted">—</span>
								</td>
								<td class="edu-td-right">
									<div class="edu-acciones">
										<button class="edu-btn" @click="irA('tareas', m)">Tareas</button>
										<button class="edu-btn edu-btn-primary"
										        @click="irA('calificaciones', m)">Notas</button>
									</div>
								</td>
							</tr>
						</tbody>
					</table>
				</div>
			</edu-card>
		</template>
	</div>`,
};
