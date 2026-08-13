/**
 * Panel de supervisión de docentes.
 *
 * Por cada asignación muestra cuánto ha avanzado: componentes definidos,
 * tareas creadas, notas registradas y cuándo fue la última.
 */

import { eduApi } from '@edu/api.js';
import { store, formatDate } from '@edu/store.js';

export const VistaRectorDocentes = {
	data: () => ( {
		cargando: true,
		error: null,
		filas: [],
		periodId: 0,
		orden: 'docente',
	} ),

	computed: {
		periodos() {
			return store.me?.active_period ? [ store.me.active_period ] : [];
		},

		ordenadas() {
			const copia = [ ...this.filas ];

			if ( 'avance' === this.orden ) {
				return copia.sort( ( a, b ) => a.notas - b.notas );
			}

			return copia.sort( ( a, b ) => a.teacher_name.localeCompare( b.teacher_name ) );
		},

		sinActividad() {
			return this.filas.filter( ( f ) => 0 === f.notas ).length;
		},

		docentes() {
			return new Set( this.filas.map( ( f ) => f.teacher_id ) ).size;
		},
	},

	mounted() {
		this.periodId = store.me?.active_period?.id || 0;
		this.cargar();
	},

	methods: {
		async cargar() {
			this.cargando = true;
			this.error = null;

			try {
				this.filas = await eduApi.get(
					'/dashboard/teacher-panel',
					this.periodId ? { period_id: this.periodId } : {}
				);
			} catch ( e ) {
				this.error = e;
			} finally {
				this.cargando = false;
			}
		},

		tonoAvance( fila ) {
			if ( 0 === fila.notas ) return 'malo';
			if ( 0 === fila.tareas ) return 'aviso';
			return 'ok';
		},

		textoAvance( fila ) {
			if ( 0 === fila.notas ) return 'Sin notas';
			if ( 0 === fila.tareas ) return 'Solo notas';
			return 'Al día';
		},

		formatDate,
	},

	template: `
	<div>
		<div class="edu-content-header">
			<h2 class="edu-page-title">Panel de docentes</h2>
			<p class="edu-page-sub">Avance de cada asignación académica.</p>
		</div>

		<div class="edu-selector-curso">
			<label>
				<span>Ordenar por</span>
				<select v-model="orden" class="edu-select">
					<option value="docente">Docente</option>
					<option value="avance">Menor avance primero</option>
				</select>
			</label>
		</div>

		<edu-loading v-if="cargando" texto="Cargando el panel…" />
		<edu-error v-else-if="error" :error="error" @retry="cargar" />
		<edu-empty v-else-if="!filas.length" texto="No hay asignaciones activas en el período." />

		<template v-else>
			<div class="edu-stats-row">
				<edu-stat label="Docentes" :value="docentes" />
				<edu-stat label="Asignaciones" :value="filas.length" />
				<edu-stat label="Sin notas registradas" :value="sinActividad"
				          :color="sinActividad ? '#dc2626' : '#059669'" />
			</div>

			<edu-card>
				<div class="edu-table-wrap">
					<table class="edu-table">
						<thead>
							<tr>
								<th>Docente</th>
								<th>Grado</th>
								<th>Materia</th>
								<th class="edu-th-num">Componentes</th>
								<th class="edu-th-num">Tareas</th>
								<th class="edu-th-num">Notas</th>
								<th>Última nota</th>
								<th>Avance</th>
							</tr>
						</thead>
						<tbody>
							<tr v-for="f in ordenadas" :key="f.assignment_id">
								<td>{{ f.teacher_name }}</td>
								<td>{{ f.grade_name }}</td>
								<td>{{ f.subject_name }}</td>
								<td class="edu-td-num">{{ f.componentes }}</td>
								<td class="edu-td-num">{{ f.tareas }}</td>
								<td class="edu-td-num">{{ f.notas }}</td>
								<td class="edu-muted edu-small">{{ formatDate(f.ultima_nota) }}</td>
								<td><edu-badge :texto="textoAvance(f)" :tono="tonoAvance(f)" /></td>
							</tr>
						</tbody>
					</table>
				</div>
			</edu-card>
		</template>
	</div>`,
};
