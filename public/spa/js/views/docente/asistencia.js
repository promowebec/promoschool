/**
 * Toma de asistencia del día para un grado.
 *
 * Guarda el día completo: quien no se marque queda PRESENTE, que es lo que
 * hacía el formulario de siempre y lo que corresponde a un PUT.
 */

import { eduApi } from '@edu/api.js';
import { store, profile } from '@edu/store.js';

const ESTADOS = [
	{ valor: 'presente', etiqueta: 'Presente', tono: 'ok' },
	{ valor: 'atraso', etiqueta: 'Atraso', tono: 'aviso' },
	{ valor: 'falta_justificada', etiqueta: 'Falta justificada', tono: 'info' },
	{ valor: 'falta_injustificada', etiqueta: 'Falta injustificada', tono: 'malo' },
];

export const VistaDocenteAsistencia = {
	data() {
		return {
			estados: ESTADOS,
			gradeId: null,
			fecha: new Date().toISOString().slice( 0, 10 ),
			cargando: false,
			guardando: false,
			error: null,
			datos: null,
			marcas: {},
			justificaciones: {},
			guardado: false,
		};
	},

	computed: {
		grados() {
			const vistos = new Map();

			( profile.value.assignments || [] ).forEach( ( a ) => {
				if ( ! vistos.has( a.grade_id ) ) {
					vistos.set( a.grade_id, { id: a.grade_id, nombre: a.grade_name } );
				}
			} );

			return [ ...vistos.values() ];
		},

		items() {
			return this.datos?.items || [];
		},

		resumen() {
			const r = { presente: 0, atraso: 0, falta_justificada: 0, falta_injustificada: 0 };

			this.items.forEach( ( i ) => {
				r[ this.marcas[ i.student_id ] || 'presente' ]++;
			} );

			return r;
		},

		yaTomada() {
			return this.items.some( ( i ) => i.status !== null );
		},
	},

	mounted() {
		this.gradeId = this.grados[ 0 ]?.id || null;
		this.cargar();
	},

	methods: {
		async cargar() {
			if ( ! this.gradeId ) return;

			this.cargando = true;
			this.error = null;
			this.guardado = false;

			try {
				this.datos = await eduApi.get( '/attendance', {
					grade_id: this.gradeId,
					date: this.fecha,
				} );

				// Lo ya registrado se precarga; el resto arranca en presente.
				this.marcas = {};
				this.justificaciones = {};

				this.items.forEach( ( i ) => {
					this.marcas[ i.student_id ] = i.status || 'presente';
					this.justificaciones[ i.student_id ] = i.justification || '';
				} );
			} catch ( e ) {
				this.error = e;
				this.datos = null;
			} finally {
				this.cargando = false;
			}
		},

		necesitaJustificacion( studentId ) {
			return 'falta_justificada' === this.marcas[ studentId ];
		},

		marcarTodos( valor ) {
			this.items.forEach( ( i ) => {
				this.marcas[ i.student_id ] = valor;
			} );
		},

		async guardar() {
			if ( this.guardando ) return;

			this.guardando = true;
			this.error = null;
			this.guardado = false;

			try {
				await eduApi.put( '/attendance', {
					grade_id: this.gradeId,
					date: this.fecha,
					students: this.items.map( ( i ) => ( {
						student_id: i.student_id,
						status: this.marcas[ i.student_id ] || 'presente',
						justification: this.justificaciones[ i.student_id ] || '',
					} ) ),
				} );

				// Se recarga primero: cargar() limpia el aviso, así que se fija
				// después para que quede a la vista.
				await this.cargar();
				this.guardado = true;
			} catch ( e ) {
				this.error = e;
			} finally {
				this.guardando = false;
			}
		},
	},

	template: `
	<div>
		<div class="edu-content-header">
			<h2 class="edu-page-title">Asistencia</h2>
			<p class="edu-page-sub">
				Se guarda el día completo: quien no marques queda presente.
			</p>
		</div>

		<div class="edu-selector-curso">
			<label>
				<span>Grado</span>
				<select v-model="gradeId" class="edu-select" @change="cargar">
					<option v-for="g in grados" :key="g.id" :value="g.id">{{ g.nombre }}</option>
				</select>
			</label>
			<label>
				<span>Fecha</span>
				<input type="date" v-model="fecha" class="edu-select" @change="cargar">
			</label>
		</div>

		<edu-loading v-if="cargando" texto="Cargando la lista…" />
		<edu-error v-else-if="error" :error="error" @retry="cargar" />
		<edu-empty v-else-if="!items.length" texto="Este grado no tiene estudiantes activos." />

		<template v-else>
			<div class="edu-stats-row">
				<edu-stat label="Presentes" :value="resumen.presente" color="#059669" />
				<edu-stat label="Atrasos" :value="resumen.atraso" color="#d97706" />
				<edu-stat label="Faltas"
				          :value="resumen.falta_justificada + resumen.falta_injustificada"
				          color="#dc2626" />
				<edu-stat label="Estado"
				          :value="yaTomada ? 'Tomada' : 'Sin tomar'"
				          :color="yaTomada ? '#059669' : '#94a3b8'" />
			</div>

			<div class="edu-spa-filtros">
				<span class="edu-muted edu-small">Marcar a todos:</span>
				<button v-for="e in estados" :key="e.valor" class="edu-chip"
				        @click="marcarTodos(e.valor)">{{ e.etiqueta }}</button>
			</div>

			<edu-card>
				<div class="edu-table-wrap">
					<table class="edu-table">
						<thead>
							<tr>
								<th class="edu-col-alumno">Estudiante</th>
								<th>Estado</th>
								<th>Justificación</th>
							</tr>
						</thead>
						<tbody>
							<tr v-for="i in items" :key="i.student_id">
								<td class="edu-col-alumno">{{ i.apellidos }} {{ i.nombres }}</td>
								<td>
									<div class="edu-radios">
										<label v-for="e in estados" :key="e.valor" class="edu-radio">
											<input type="radio"
											       :name="'a' + i.student_id"
											       :value="e.valor"
											       v-model="marcas[i.student_id]">
											<span :class="'edu-badge edu-badge-' + e.tono">{{ e.etiqueta }}</span>
										</label>
									</div>
								</td>
								<td>
									<input v-if="necesitaJustificacion(i.student_id)"
									       class="edu-input" type="text" placeholder="Motivo"
									       v-model="justificaciones[i.student_id]">
									<span v-else class="edu-muted">—</span>
								</td>
							</tr>
						</tbody>
					</table>
				</div>
			</edu-card>

			<div v-if="guardado" class="edu-aviso edu-aviso-ok">
				Asistencia guardada.
			</div>

			<div class="edu-barra-guardar">
				<button class="edu-btn edu-btn-primary" :disabled="guardando" @click="guardar">
					{{ guardando ? 'Guardando…' : 'Guardar asistencia' }}
				</button>
			</div>
		</template>
	</div>`,
};
