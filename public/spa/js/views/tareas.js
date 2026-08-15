/**
 * Tareas del estudiante en foco, con el estado de su entrega.
 */

import { eduApi } from '@edu/api.js';
import { store, currentStudent, isParent, formatDate, formatScore } from '@edu/store.js';

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

		/* Formulario de entrega. Se reinicia al abrir otra tarea. */
		comentario: '',
		archivos: [],
		enviando: false,
		errorEntrega: null,
		entregada: false,
	} ),

	computed: {
		estudiante() {
			return currentStudent.value;
		},

		/**
		 * El representante ve las tareas de su hijo pero no entrega por él: la
		 * capability `edu_submit_assignment` es solo del estudiante y el
		 * servidor rechazaría la llamada igual.
		 */
		esPadre() {
			return isParent.value;
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
			this.limpiarFormulario();

			try {
				this.detalle = await eduApi.get( `/assignments/${ tarea.id }` );
			} catch ( e ) {
				this.detalle = { files: [] };
			}
		},

		limpiarFormulario() {
			this.comentario = '';
			this.archivos = [];
			this.errorEntrega = null;
			this.entregada = false;
		},

		/** La entrega propia de esta tarea, si ya existe. */
		miEntrega( tarea ) {
			return this.entregas[ tarea.id ] || null;
		},

		/**
		 * Una tarea se entrega UNA vez.
		 *
		 * Vencida no bloquea: el servidor la marca `late`, que es justo lo que
		 * espera ver el docente. Lo que sí bloquea es haber entregado ya, porque
		 * si no al docente le constan varias entregas del mismo estudiante. La
		 * segunda oportunidad va por la recuperación, que el docente habilita.
		 *
		 * `returned` sí admite reenvío: esa segunda entrega la pidió el docente.
		 */
		puedeEntregar( tarea ) {
			if ( this.esPadre ) return false;
			if ( 'published' !== tarea.status ) return false;

			const estado = this.miEntrega( tarea )?.status;

			return ! [ 'submitted', 'late', 'graded' ].includes( estado );
		},

		/** Por qué no se puede entregar, para decírselo al estudiante. */
		motivoBloqueo( tarea ) {
			if ( this.esPadre ) return '';
			if ( 'published' !== tarea.status ) {
				return 'La tarea está cerrada y ya no admite entregas.';
			}

			const estado = this.miEntrega( tarea )?.status;

			if ( 'graded' === estado ) {
				return 'Tu entrega ya fue calificada y no admite cambios.';
			}

			if ( [ 'submitted', 'late' ].includes( estado ) ) {
				return 'Ya entregaste esta tarea. Si necesitas volver a entregarla, pídele a tu docente que habilite la recuperación.';
			}

			return '';
		},

		elegirArchivos( evento ) {
			this.archivos = Array.from( evento.target.files || [] );
		},

		quitarArchivo( indice ) {
			this.archivos.splice( indice, 1 );
		},

		async entregar( tarea ) {
			if ( this.enviando ) return;

			if ( ! this.archivos.length && ! this.comentario.trim() ) {
				this.errorEntrega = { message: 'Adjunta un archivo o escribe un comentario antes de entregar.' };
				return;
			}

			this.enviando = true;
			this.errorEntrega = null;
			this.entregada = false;

			try {
				await eduApi.postForm(
					`/assignments/${ tarea.id }/submissions`,
					{ comment: this.comentario },
					this.archivos
				);

				// Releer solo esta tarea: recarga completa sería tirar el listado entero.
				const lista = await eduApi.get( `/assignments/${ tarea.id }/submissions` ).catch( () => [] );
				const mia = ( lista || [] ).find( ( s ) => s.student_id === store.studentId );
				if ( mia ) this.entregas[ tarea.id ] = mia;

				this.comentario = '';
				this.archivos = [];
				if ( this.$refs.archivoInput ) this.$refs.archivoInput.value = '';
				this.entregada = true;
			} catch ( e ) {
				this.errorEntrega = e;
			} finally {
				this.enviando = false;
			}
		},

		async descargarEntrega( archivo ) {
			if ( this.bajando ) return;

			this.bajando = archivo.id;
			this.errorEntrega = null;

			try {
				await eduApi.abrirAdjunto( archivo.id, 'submission' );
			} catch ( e ) {
				this.errorEntrega = e;
			} finally {
				this.bajando = null;
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

											<div class="edu-entrega" @click.stop>
												<h4 class="edu-entrega-titulo">Mi entrega</h4>

												<div v-if="miEntrega(t)" class="edu-entrega-actual">
													<p class="edu-small">
														<edu-badge :texto="estadoDe(t).texto" :tono="estadoDe(t).tono" />
														<span class="edu-muted">
															Enviada el {{ formatDate(miEntrega(t).submitted_at, true) }}
														</span>
													</p>
													<p v-if="miEntrega(t).comment" class="edu-detalle-tarea">
														{{ miEntrega(t).comment }}
													</p>
													<div v-if="miEntrega(t).files && miEntrega(t).files.length"
													     class="edu-acciones-archivos">
														<button v-for="a in miEntrega(t).files" :key="a.id"
														        class="edu-btn edu-btn-archivo"
														        :disabled="bajando === a.id"
														        @click.stop="descargarEntrega(a)">
															📎 {{ a.file_name }} · {{ pesoLegible(a.file_size) }}
														</button>
													</div>
												</div>
												<p v-else-if="!esPadre" class="edu-muted edu-small">
													Todavía no has entregado esta tarea.
												</p>
												<p v-else class="edu-muted edu-small">
													Tu representado todavía no ha entregado esta tarea.
												</p>

												<div v-if="!esPadre && !puedeEntregar(t) && motivoBloqueo(t)"
												     class="edu-aviso"
												     :class="miEntrega(t)?.status === 'graded' ? 'edu-aviso-ok' : 'edu-aviso-cerrado'">
													{{ motivoBloqueo(t) }}
												</div>

												<form v-else-if="puedeEntregar(t)" class="edu-form edu-entrega-form"
												      @submit.prevent="entregar(t)">
													<p v-if="vencida(t)" class="edu-aviso edu-aviso-parcial">
														La fecha de entrega ya pasó. Tu entrega quedará marcada
														como <strong>atrasada</strong>.
													</p>

													<label>
														<span>Comentario para el docente</span>
														<textarea class="edu-input" rows="3"
														          v-model="comentario"
														          placeholder="Opcional"></textarea>
													</label>

													<label>
														<span>Archivos</span>
														<input class="edu-input" type="file" multiple
														       ref="archivoInput"
														       @change="elegirArchivos">
													</label>

													<ul v-if="archivos.length" class="edu-lista edu-small">
														<li v-for="(a, i) in archivos" :key="a.name + i">
															📎 {{ a.name }} · {{ pesoLegible(a.size) }}
															<button type="button" class="edu-enlace"
															        @click="quitarArchivo(i)">quitar</button>
														</li>
													</ul>

													<div v-if="errorEntrega" class="edu-texto-error edu-small">
														{{ errorEntrega.message || 'No se pudo enviar la entrega.' }}
													</div>

													<p class="edu-muted edu-small">
														Solo se entrega una vez. Revisa los archivos antes de enviar.
													</p>

													<div class="edu-acciones">
														<button type="submit" class="edu-btn edu-btn-primary"
														        :disabled="enviando">
															{{ enviando ? 'Enviando…' : 'Entregar tarea' }}
														</button>
													</div>
												</form>

												<!-- Fuera del formulario: al entregar, el formulario desaparece. -->
												<div v-if="entregada" class="edu-aviso edu-aviso-ok">
													Entrega enviada correctamente.
												</div>
											</div>
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
