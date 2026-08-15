/**
 * Tareas del docente: crear, publicar, cerrar, eliminar y calificar entregas.
 *
 * El formulario usa el campo unificado "Se evalúa como" (v1.1.0): un
 * componente existente, uno nuevo escrito ahí mismo, o ninguno. El tipo de la
 * actividad lo deduce el servidor a partir del nombre del componente.
 */

import { eduApi } from '@edu/api.js';
import { formatDate, formatScore } from '@edu/store.js';
import { EduSelectorCurso } from '@edu/views/docente/selector.js';

export const VistaDocenteTareas = {
	components: { EduSelectorCurso },

	data: () => ( {
		curso: null,
		cargando: false,
		error: null,
		tareas: [],

		/** Vista actual: lista | formulario | entregas. */
		modo: 'lista',
		guardando: false,
		aviso: '',

		componentes: [],
		formulario: null,

		/** Adjuntos: nuevos por subir e IDs marcados para borrar. */
		archivos: [],
		aEliminar: [],
		adjuntos: [],
		bajando: null,

		/** Calificación de entregas. */
		tareaActiva: null,
		entregas: [],
		notas: {},
		comentarios: {},
		calificando: null,
		devolviendo: null,
	} ),

	computed: {
		publicadas() {
			return this.tareas.filter( ( t ) => 'published' === t.status ).length;
		},

		porCalificar() {
			return this.tareas.reduce(
				( n, t ) => n + Math.max( 0, ( t.submissions_count || 0 ) - ( t.graded_count || 0 ) ),
				0
			);
		},
	},

	methods: {
		async cambioCurso( seleccion ) {
			this.curso = seleccion;
			this.modo = 'lista';
			await this.cargar();
		},

		async cargar() {
			if ( ! this.curso ) return;

			this.cargando = true;
			this.error = null;

			try {
				this.tareas = await eduApi.get( '/assignments', {
					grade_id: this.curso.grade_id,
					subject_id: this.curso.subject_id,
					trimester_id: this.curso.trimester_id,
				} );
			} catch ( e ) {
				this.error = e;
			} finally {
				this.cargando = false;
			}
		},

		/* ── Formulario ────────────────────────────────────────────── */

		async nueva() {
			this.aviso = '';
			this.archivos = [];
			this.aEliminar = [];
			this.adjuntos = [];
			this.formulario = {
				id: 0,
				title: '',
				description: '',
				due_date: '',
				max_score: 10,
				publish_now: false,
				modo_componente: 'none',
				component_id: null,
				component_name: '',
			};

			await this.cargarComponentes();
			this.modo = 'formulario';
		},

		async editar( tarea ) {
			this.aviso = '';
			this.archivos = [];
			this.aEliminar = [];

			// El detalle trae los adjuntos ya guardados.
			try {
				const detalle = await eduApi.get( `/assignments/${ tarea.id }` );
				this.adjuntos = detalle.files || [];
			} catch ( e ) {
				this.adjuntos = [];
			}

			this.formulario = {
				id: tarea.id,
				title: tarea.title,
				description: tarea.description || '',
				due_date: tarea.due_date ? tarea.due_date.slice( 0, 16 ) : '',
				max_score: tarea.max_score ?? 10,
				publish_now: 'published' === tarea.status,
				modo_componente: tarea.component_id ? 'existing' : 'none',
				component_id: tarea.component_id,
				component_name: '',
			};

			await this.cargarComponentes();
			this.modo = 'formulario';
		},

		async cargarComponentes() {
			try {
				this.componentes = await eduApi.get( '/components', {
					subject_id: this.curso.subject_id,
					trimester_id: this.curso.trimester_id,
					parcial_num: this.curso.parcial_num,
				} );
			} catch ( e ) {
				this.componentes = [];
			}
		},

		async guardar() {
			if ( this.guardando ) return;

			const f = this.formulario;

			if ( ! f.title.trim() ) {
				this.error = { message: 'La tarea necesita un título.' };
				return;
			}

			this.guardando = true;
			this.error = null;

			const cuerpo = {
				...this.curso,
				title: f.title,
				description: f.description,
				due_date: f.due_date,
				max_score: f.max_score,
				publish_now: f.publish_now ? 1 : 0,
				component: {
					mode: f.modo_componente,
					id: f.component_id,
					name: f.component_name,
				},
				delete_files: this.aEliminar,
			};

			try {
				const ruta = f.id ? `/assignments/${ f.id }` : '/assignments';

				// Con adjuntos hay que ir en multipart; si no, JSON basta.
				if ( this.archivos.length ) {
					await eduApi.postForm( ruta, cuerpo, this.archivos, f.id ? 'PUT' : 'POST' );
				} else if ( f.id ) {
					await eduApi.put( ruta, cuerpo );
				} else {
					await eduApi.post( ruta, cuerpo );
				}

				this.aviso = f.id ? 'Tarea actualizada.' : 'Tarea creada.';
				this.archivos = [];
				this.aEliminar = [];
				this.modo = 'lista';
				await this.cargar();
			} catch ( e ) {
				this.error = e;
			} finally {
				this.guardando = false;
			}
		},

		elegirArchivos( evento ) {
			this.archivos = Array.from( evento.target.files || [] );
		},

		marcarBorrado( fileId ) {
			const i = this.aEliminar.indexOf( fileId );
			if ( i === -1 ) {
				this.aEliminar.push( fileId );
			} else {
				this.aEliminar.splice( i, 1 );
			}
		},

		pesoLegible( bytes ) {
			const b = Number( bytes ) || 0;
			if ( b < 1024 ) return b + ' B';
			if ( b < 1024 * 1024 ) return Math.round( b / 1024 ) + ' KB';
			return ( b / 1024 / 1024 ).toFixed( 1 ) + ' MB';
		},

		async descargar( archivo, tipo = 'assignment' ) {
			if ( this.bajando ) return;

			this.bajando = archivo.id;
			this.error = null;

			try {
				await eduApi.abrirAdjunto( archivo.id, tipo );
			} catch ( e ) {
				this.error = e;
			} finally {
				this.bajando = null;
			}
		},

		/* ── Estado de la tarea ────────────────────────────────────── */

		async accion( tarea, accion ) {
			this.error = null;

			try {
				if ( 'delete' === accion ) {
					await eduApi.del( `/assignments/${ tarea.id }` );
					this.aviso = 'Tarea eliminada.';
				} else {
					await eduApi.post( `/assignments/${ tarea.id }/${ accion }` );
					this.aviso = 'publish' === accion ? 'Tarea publicada.' : 'Tarea cerrada.';
				}

				await this.cargar();
			} catch ( e ) {
				this.error = e;
			}
		},

		/* ── Entregas ──────────────────────────────────────────────── */

		async verEntregas( tarea ) {
			this.tareaActiva = tarea;
			this.entregas = [];
			this.notas = {};
			this.comentarios = {};
			this.error = null;
			this.aviso = '';
			this.modo = 'entregas';

			try {
				this.entregas = await eduApi.get( `/assignments/${ tarea.id }/submissions` );

				this.entregas.forEach( ( s ) => {
					this.notas[ s.id ] = s.score === null || s.score === undefined ? '' : String( s.score );
					this.comentarios[ s.id ] = s.feedback || '';
				} );
			} catch ( e ) {
				this.error = e;
			}
		},

		/**
		 * Una entrega calificada no se vuelve a calificar.
		 *
		 * Su nota tiene respaldo —el archivo que subió el estudiante— y
		 * recalificarla a mano rompe ese vínculo sin dejar constancia de por qué
		 * cambió. Para dar otra oportunidad está la recuperación; para corregir
		 * un error, devolver el trabajo.
		 */
		yaCalificada( entrega ) {
			return 'graded' === entrega.status;
		},

		/**
		 * Devuelve el trabajo al estudiante: es la única forma de deshacer una
		 * calificación, y a diferencia de recalificar en silencio deja rastro.
		 * La nota se borra del registro y el parcial se recalcula.
		 */
		async devolver( entrega ) {
			if ( this.devolviendo ) return;

			this.devolviendo = entrega.id;
			this.error = null;

			try {
				await eduApi.put( `/submissions/${ entrega.id }/return`, {} );
				await this.verEntregas( this.tareaActiva );
			} catch ( e ) {
				this.error = e;
			} finally {
				this.devolviendo = null;
			}
		},

		async calificar( entrega ) {
			if ( this.calificando ) return;

			this.calificando = entrega.id;
			this.error = null;

			try {
				await eduApi.put( `/submissions/${ entrega.id }/grade`, {
					score: this.notas[ entrega.id ],
					feedback: this.comentarios[ entrega.id ],
				} );

				await this.verEntregas( this.tareaActiva );
				this.aviso = 'Entrega calificada.';
			} catch ( e ) {
				this.error = e;
			} finally {
				this.calificando = null;
			}
		},

		estadoTono( estado ) {
			return { draft: 'neutro', published: 'ok', closed: 'info' }[ estado ] || 'neutro';
		},

		estadoTexto( estado ) {
			return { draft: 'Borrador', published: 'Publicada', closed: 'Cerrada' }[ estado ] || estado;
		},

		entregaTono( estado ) {
			return { pending: 'neutro', submitted: 'info', late: 'aviso', graded: 'ok', returned: 'info' }[ estado ] || 'neutro';
		},

		entregaTexto( estado ) {
			return {
				pending: 'Sin entregar',
				submitted: 'Entregada',
				late: 'Con atraso',
				graded: 'Calificada',
				returned: 'Devuelta',
			}[ estado ] || estado;
		},

		formatDate,
		formatScore,
	},

	template: `
	<div>
		<div class="edu-content-header">
			<h2 class="edu-page-title">Tareas y lecciones</h2>
			<p class="edu-page-sub">Crea actividades y califica las entregas.</p>
		</div>

		<edu-selector-curso @cambio="cambioCurso" />

		<div v-if="aviso" class="edu-aviso edu-aviso-ok">{{ aviso }}</div>
		<edu-error v-if="error" :error="error" @retry="cargar" />

		<!-- ── Lista ─────────────────────────────────────────────── -->
		<template v-if="modo === 'lista'">
			<edu-loading v-if="cargando" texto="Cargando tareas…" />

			<template v-else>
				<div class="edu-stats-row">
					<edu-stat label="Tareas" :value="tareas.length" />
					<edu-stat label="Publicadas" :value="publicadas" color="#059669" />
					<edu-stat label="Entregas por calificar" :value="porCalificar"
					          :color="porCalificar ? '#d97706' : '#1e293b'" />
				</div>

				<div class="edu-barra-guardar">
					<button class="edu-btn edu-btn-primary" @click="nueva">Nueva tarea</button>
				</div>

				<edu-card>
					<edu-empty v-if="!tareas.length" texto="No hay tareas en este trimestre." />
					<div v-else class="edu-table-wrap">
						<table class="edu-table">
							<thead>
								<tr>
									<th>Tarea</th>
									<th>Entrega</th>
									<th>Estado</th>
									<th class="edu-th-num">Entregas</th>
									<th></th>
								</tr>
							</thead>
							<tbody>
								<tr v-for="t in tareas" :key="t.id">
									<td>
										<strong>{{ t.title }}</strong>
										<div class="edu-muted edu-small">
											{{ t.type }}<template v-if="t.component_name"> · {{ t.component_name }}</template>
										</div>
									</td>
									<td>{{ formatDate(t.due_date, true) }}</td>
									<td><edu-badge :texto="estadoTexto(t.status)" :tono="estadoTono(t.status)" /></td>
									<td class="edu-td-num">
										{{ t.graded_count }} / {{ t.submissions_count }}
									</td>
									<td class="edu-td-right edu-acciones">
										<button class="edu-btn" @click="verEntregas(t)">Entregas</button>
										<button v-if="t.status !== 'closed'" class="edu-btn" @click="editar(t)">Editar</button>
										<button v-if="t.status === 'draft'" class="edu-btn" @click="accion(t, 'publish')">Publicar</button>
										<button v-if="t.status === 'published'" class="edu-btn" @click="accion(t, 'close')">Cerrar</button>
									</td>
								</tr>
							</tbody>
						</table>
					</div>
				</edu-card>
			</template>
		</template>

		<!-- ── Formulario ────────────────────────────────────────── -->
		<edu-card v-else-if="modo === 'formulario'"
		          :titulo="formulario.id ? 'Editar tarea' : 'Nueva tarea'">
			<div class="edu-form">
				<label>
					<span>Título</span>
					<input class="edu-input" type="text" v-model="formulario.title"
					       placeholder="Ejercicios de fracciones">
				</label>

				<label>
					<span>Descripción</span>
					<textarea class="edu-input" rows="3" v-model="formulario.description"
					          placeholder="Instrucciones para el estudiante"></textarea>
				</label>

				<div class="edu-form-fila">
					<label>
						<span>Fecha de entrega</span>
						<input class="edu-input" type="datetime-local" v-model="formulario.due_date">
					</label>
					<label>
						<span>Nota máxima</span>
						<input class="edu-input" type="number" step="0.01" min="0" v-model="formulario.max_score">
					</label>
				</div>

				<label>
					<span>Se evalúa como</span>
					<select class="edu-select" v-model="formulario.modo_componente">
						<option value="none">Sin vincular a un componente</option>
						<option v-if="componentes.length" value="existing">Un componente existente</option>
						<option value="create">➕ Crear componente nuevo…</option>
					</select>
				</label>

				<label v-if="formulario.modo_componente === 'existing'">
					<span>Componente</span>
					<select class="edu-select" v-model="formulario.component_id">
						<option v-for="c in componentes" :key="c.id" :value="c.id">{{ c.name }}</option>
					</select>
				</label>

				<label v-if="formulario.modo_componente === 'create'">
					<span>Nombre del componente</span>
					<input class="edu-input" type="text" v-model="formulario.component_name"
					       placeholder="Tareas, Lecciones, Prueba…">
					<span class="edu-muted edu-small">
						Nace con peso 1.00. Si ya existe uno con ese nombre, se reutiliza.
					</span>
				</label>

				<label>
					<span>Adjuntos</span>
					<input class="edu-input" type="file" multiple @change="elegirArchivos">
					<span class="edu-muted edu-small">
						Máximo 10 MB por archivo. PDF, Word, Excel, PowerPoint, imágenes o ZIP.
					</span>
				</label>

				<ul v-if="adjuntos.length" class="edu-lista">
					<li v-for="a in adjuntos" :key="a.id">
						<span :class="{ 'edu-tachado': aEliminar.includes(a.id) }">
							{{ a.file_name }}
							<span class="edu-muted edu-small">· {{ pesoLegible(a.file_size) }}</span>
						</span>
						<span class="edu-acciones">
							<button class="edu-btn" @click="descargar(a)">
								{{ bajando === a.id ? 'Abriendo…' : 'Descargar' }}
							</button>
							<button class="edu-btn" @click="marcarBorrado(a.id)">
								{{ aEliminar.includes(a.id) ? 'Conservar' : 'Quitar' }}
							</button>
						</span>
					</li>
				</ul>

				<label class="edu-check">
					<input type="checkbox" v-model="formulario.publish_now">
					<span>Publicar de inmediato (si no, queda en borrador)</span>
				</label>

				<div class="edu-barra-guardar">
					<button class="edu-btn" @click="modo = 'lista'">Cancelar</button>
					<button class="edu-btn edu-btn-primary" :disabled="guardando" @click="guardar">
						{{ guardando ? 'Guardando…' : 'Guardar tarea' }}
					</button>
				</div>
			</div>
		</edu-card>

		<!-- ── Entregas ──────────────────────────────────────────── -->
		<template v-else-if="modo === 'entregas'">
			<edu-card :titulo="'Entregas · ' + tareaActiva.title">
				<p class="edu-muted edu-small">
					Nota sobre {{ formatScore(tareaActiva.max_score) }}.
					Al calificar, la nota se lleva a escala 0–10 en el registro de calificaciones.
				</p>

				<edu-empty v-if="!entregas.length" texto="Todavía no hay entregas." />

				<div v-else class="edu-table-wrap">
					<table class="edu-table">
						<thead>
							<tr>
								<th class="edu-col-alumno">Estudiante</th>
								<th>Estado</th>
								<th>Entregada</th>
								<th class="edu-th-num">Nota</th>
								<th>Comentario</th>
								<th></th>
							</tr>
						</thead>
						<tbody>
							<tr v-for="s in entregas" :key="s.id">
								<td class="edu-col-alumno">
									{{ s.apellidos }} {{ s.nombres }}
									<div v-if="s.files && s.files.length" class="edu-acciones-archivos">
										<button v-for="a in s.files" :key="a.id"
										        class="edu-btn edu-btn-archivo"
										        :disabled="bajando === a.id"
										        @click="descargar(a, 'submission')">
											📎 {{ a.file_name }}
										</button>
									</div>
								</td>
								<td><edu-badge :texto="entregaTexto(s.status)" :tono="entregaTono(s.status)" /></td>
								<td class="edu-muted edu-small">{{ formatDate(s.submitted_at, true) }}</td>
								<td class="edu-td-num">
									<input v-if="!yaCalificada(s)" class="edu-nota-input" type="text"
									       inputmode="decimal" placeholder="—" v-model="notas[s.id]">
									<strong v-else>{{ formatScore(s.score) }}</strong>
								</td>
								<td>
									<input v-if="!yaCalificada(s)" class="edu-input" type="text"
									       placeholder="Retroalimentación" v-model="comentarios[s.id]">
									<span v-else class="edu-muted edu-small">{{ s.feedback || '—' }}</span>
								</td>
								<td class="edu-td-right">
									<button v-if="!yaCalificada(s)" class="edu-btn edu-btn-primary"
									        :disabled="calificando === s.id"
									        @click="calificar(s)">
										{{ calificando === s.id ? '…' : 'Calificar' }}
									</button>
									<button v-else class="edu-btn"
									        :disabled="devolviendo === s.id"
									        title="La entrega vuelve al estudiante para que la corrija y la reenvíe. La nota deja de contar."
									        @click="devolver(s)">
										{{ devolviendo === s.id ? '…' : 'Devolver' }}
									</button>
								</td>
							</tr>
						</tbody>
					</table>
				</div>

				<div class="edu-barra-guardar">
					<button class="edu-btn" @click="modo = 'lista'; cargar()">Volver a la lista</button>
				</div>
			</edu-card>
		</template>
	</div>`,
};
