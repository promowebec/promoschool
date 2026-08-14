/**
 * Tareas del estudiante en foco, con el estado de su entrega.
 */

import { eduApi } from '@edu/api.js';
import { store, currentStudent, formatDate, formatScore } from '@edu/store.js';

export const VistaTareas = {
	data: () => ( {
		cargando: true,
		error: null,
		tareas: [],
		entregas: {},
		filtro: 'todas',

		/** Tarea desplegada y sus adjuntos, que se piden al abrirla. */
		abierta: null,
		detalle: null,
		bajando: null,
	} ),

	computed: {
		estudiante() {
			return currentStudent.value;
		},

		visibles() {
			if ( this.filtro === 'todas' ) return this.tareas;

			return this.tareas.filter( ( t ) => {
				const e = this.entregas[ t.id ];
				const entregada = e && [ 'submitted', 'late', 'graded' ].includes( e.status );

				return this.filtro === 'pendientes' ? ! entregada : entregada;
			} );
		},

		pendientes() {
			return this.tareas.filter( ( t ) => {
				const e = this.entregas[ t.id ];
				return ! e || ! [ 'submitted', 'late', 'graded' ].includes( e.status );
			} ).length;
		},

		calificadas() {
			return Object.values( this.entregas ).filter( ( e ) => e.status === 'graded' ).length;
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
			this.entregas = {};

			try {
				const grado = this.estudiante?.grade?.id;
				this.tareas = await eduApi.get( '/assignments', grado ? { grade_id: grado } : {} );

				// La entrega propia viene de cada tarea; se piden en paralelo.
				const resultados = await Promise.all(
					this.tareas.map( ( t ) =>
						eduApi.get( `/assignments/${ t.id }/submissions` ).catch( () => [] )
					)
				);

				resultados.forEach( ( lista, i ) => {
					const mia = ( lista || [] ).find( ( s ) => s.student_id === store.studentId );
					if ( mia ) this.entregas[ this.tareas[ i ].id ] = mia;
				} );
			} catch ( e ) {
				this.error = e;
			} finally {
				this.cargando = false;
			}
		},

		estadoDe( tarea ) {
			const e = this.entregas[ tarea.id ];

			if ( e?.status === 'graded' ) return { texto: 'Calificada', tono: 'ok' };
			if ( e?.status === 'late' ) return { texto: 'Entregada con atraso', tono: 'aviso' };
			if ( e?.status === 'submitted' ) return { texto: 'Entregada', tono: 'info' };
			if ( tarea.status === 'closed' ) return { texto: 'Cerrada sin entregar', tono: 'malo' };

			return { texto: 'Pendiente', tono: 'neutro' };
		},

		nota( tarea ) {
			return this.entregas[ tarea.id ]?.score ?? null;
		},

		vencida( tarea ) {
			return tarea.due_date && new Date( tarea.due_date ) < new Date();
		},

		/**
		 * Despliega la tarea. El detalle (enunciado y material) se pide solo al
		 * abrirla: el listado de un trimestre puede traer decenas.
		 */
		async abrir( tarea ) {
			if ( this.abierta === tarea.id ) {
				this.abierta = null;
				return;
			}

			this.abierta = tarea.id;
			this.detalle = null;

			try {
				this.detalle = await eduApi.get( `/assignments/${ tarea.id }` );
			} catch ( e ) {
				this.detalle = { files: [] };
			}
		},

		async descargar( archivo ) {
			if ( this.bajando ) return;

			this.bajando = archivo.id;
			this.error = null;

			try {
				await eduApi.abrirAdjunto( archivo.id, 'assignment' );
			} catch ( e ) {
				this.error = e;
			} finally {
				this.bajando = null;
			}
		},

		pesoLegible( bytes ) {
			const b = Number( bytes ) || 0;
			if ( b < 1024 ) return b + ' B';
			if ( b < 1024 * 1024 ) return Math.round( b / 1024 ) + ' KB';
			return ( b / 1024 / 1024 ).toFixed( 1 ) + ' MB';
		},

		formatDate,
		formatScore,
	},

	template: `
	<div>
		<div class="edu-content-header">
			<h2 class="edu-page-title">Tareas</h2>
			<p class="edu-page-sub" v-if="estudiante">
				{{ estudiante.nombres }} {{ estudiante.apellidos }}
			</p>
		</div>

		<edu-loading v-if="cargando" texto="Cargando tareas…" />
		<edu-error v-else-if="error" :error="error" @retry="cargar" />
		<edu-empty v-else-if="!tareas.length" texto="No hay tareas publicadas todavía." />

		<template v-else>
			<div class="edu-stats-row">
				<edu-stat label="Tareas" :value="tareas.length" />
				<edu-stat label="Pendientes" :value="pendientes"
				          :color="pendientes ? '#d97706' : '#059669'" />
				<edu-stat label="Calificadas" :value="calificadas" color="#1d4ed8" />
			</div>

			<div class="edu-spa-filtros">
				<button v-for="f in ['todas','pendientes','entregadas']" :key="f"
				        class="edu-chip" :class="{ active: filtro === f }"
				        @click="filtro = f">{{ f }}</button>
			</div>

			<edu-card>
				<edu-empty v-if="!visibles.length" texto="Nada en este filtro." />
				<div v-else class="edu-table-wrap">
					<table class="edu-table">
						<thead>
							<tr>
								<th>Tarea</th>
								<th>Materia</th>
								<th>Entrega</th>
								<th>Estado</th>
								<th class="edu-th-num">Nota</th>
							</tr>
						</thead>
						<tbody>
							<template v-for="t in visibles" :key="t.id">
								<tr class="edu-fila-abrible" @click="abrir(t)">
									<td>
										<strong>{{ t.title }}</strong>
										<div class="edu-muted edu-small">{{ t.type }}</div>
									</td>
									<td>{{ t.subject_name }}</td>
									<td>
										<span :class="{ 'edu-vencida': vencida(t) && estadoDe(t).tono === 'neutro' }">
											{{ formatDate(t.due_date, true) }}
										</span>
									</td>
									<td>
										<edu-badge :texto="estadoDe(t).texto" :tono="estadoDe(t).tono" />
									</td>
									<td class="edu-td-num">
										<template v-if="nota(t) !== null">
											{{ formatScore(nota(t)) }} <span class="edu-muted">/ {{ formatScore(t.max_score) }}</span>
										</template>
										<span v-else class="edu-muted">—</span>
									</td>
								</tr>

								<tr v-if="abierta === t.id">
									<td colspan="5">
										<div v-if="!detalle" class="edu-muted edu-small">Cargando…</div>
										<template v-else>
											<p v-if="detalle.description" class="edu-detalle-tarea">
												{{ detalle.description }}
											</p>
											<div v-if="detalle.files && detalle.files.length" class="edu-acciones-archivos">
												<button v-for="a in detalle.files" :key="a.id"
												        class="edu-btn edu-btn-archivo"
												        :disabled="bajando === a.id"
												        @click.stop="descargar(a)">
													📎 {{ a.file_name }} · {{ pesoLegible(a.file_size) }}
												</button>
											</div>
											<p v-else class="edu-muted edu-small">
												Esta tarea no trae material adjunto.
											</p>
										</template>
									</td>
								</tr>
							</template>
						</tbody>
					</table>
				</div>
			</edu-card>
		</template>
	</div>`,
};
