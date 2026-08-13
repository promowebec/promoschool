/**
 * Notas del estudiante en foco: por materia, trimestre a trimestre, con la
 * nota anual y su estado.
 *
 * Sirve igual al estudiante (sus notas) y al representante (las de su hijo):
 * la diferencia es solo qué student_id hay en el store.
 */

import { eduApi } from '@edu/api.js';
import { store, currentStudent, formatScore } from '@edu/store.js';

export const VistaNotas = {
	data: () => ( {
		cargando: true,
		error: null,
		datos: null,
	} ),

	computed: {
		estudiante() {
			return currentStudent.value;
		},

		materias() {
			return this.datos?.subjects || [];
		},

		/** Trimestres presentes, para armar las columnas. */
		trimestres() {
			const nums = new Set();
			this.materias.forEach( ( m ) =>
				( m.trimesters || [] ).forEach( ( t ) => nums.add( t.number ) )
			);
			return [ ...nums ].sort( ( a, b ) => a - b );
		},

		usaProyecto() {
			return this.datos?.formula === 'sumativa_proyecto';
		},

		promedioGeneral() {
			const notas = this.materias
				.map( ( m ) => m.year?.average )
				.filter( ( n ) => n !== null && n !== undefined );

			if ( ! notas.length ) return null;

			return notas.reduce( ( a, b ) => a + Number( b ), 0 ) / notas.length;
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

			try {
				this.datos = await eduApi.get( `/students/${ store.studentId }/scores` );
			} catch ( e ) {
				this.error = e;
			} finally {
				this.cargando = false;
			}
		},

		/** Nota de un trimestre concreto de una materia. */
		trimestre( materia, numero ) {
			return ( materia.trimesters || [] ).find( ( t ) => t.number === numero ) || null;
		},

		estadoTono( estado ) {
			return {
				aprobado: 'ok',
				supletorio: 'aviso',
				remedial: 'aviso',
				gracia: 'aviso',
				reprobado: 'malo',
				en_curso: 'neutro',
			}[ estado ] || 'neutro';
		},

		estadoTexto( estado ) {
			return {
				aprobado: 'Aprobado',
				supletorio: 'Supletorio',
				remedial: 'Remedial',
				gracia: 'Gracia',
				reprobado: 'Reprobado',
				en_curso: 'En curso',
			}[ estado ] || estado;
		},

		formatScore,
	},

	template: `
	<div>
		<div class="edu-content-header">
			<h2 class="edu-page-title">Calificaciones</h2>
			<p class="edu-page-sub" v-if="estudiante">
				{{ estudiante.nombres }} {{ estudiante.apellidos }}
				<span v-if="estudiante.grade"> · {{ estudiante.grade.name }} {{ estudiante.grade.paralelo }}</span>
			</p>
		</div>

		<edu-loading v-if="cargando" texto="Cargando calificaciones…" />
		<edu-error v-else-if="error" :error="error" @retry="cargar" />
		<edu-empty v-else-if="!materias.length"
		           texto="Todavía no hay calificaciones registradas para este período." />

		<template v-else>
			<div class="edu-stats-row">
				<edu-stat label="Materias" :value="materias.length" />
				<edu-stat label="Promedio general"
				          :value="promedioGeneral === null ? '—' : formatScore(promedioGeneral)"
				          color="#1d4ed8" />
				<edu-stat label="Fórmula"
				          :value="usaProyecto ? 'Examen + Proyecto' : 'Examen'"
				          sub="30% sumativo" />
			</div>

			<edu-card>
				<div class="edu-table-wrap">
					<table class="edu-table">
						<thead>
							<tr>
								<th>Materia</th>
								<th v-for="n in trimestres" :key="n" class="edu-th-num">Trim {{ n }}</th>
								<th class="edu-th-num">Promedio</th>
								<th>Estado</th>
							</tr>
						</thead>
						<tbody>
							<tr v-for="m in materias" :key="m.subject_id">
								<td>{{ m.subject_name }}</td>
								<td v-for="n in trimestres" :key="n" class="edu-td-num">
									<edu-nota v-if="trimestre(m, n)"
									          :score="trimestre(m, n).computed_score"
									          :cualitativa="trimestre(m, n).cualitativa" />
									<span v-else class="edu-muted">—</span>
								</td>
								<td class="edu-td-num">
									<edu-nota v-if="m.year" :score="m.year.average" :cualitativa="m.year.cualitativa" />
									<span v-else class="edu-muted">—</span>
								</td>
								<td>
									<edu-badge v-if="m.year"
									           :texto="estadoTexto(m.year.status)"
									           :tono="estadoTono(m.year.status)" />
									<span v-else class="edu-muted">—</span>
								</td>
							</tr>
						</tbody>
					</table>
				</div>
			</edu-card>

			<edu-card titulo="Detalle por trimestre">
				<div class="edu-table-wrap">
					<table class="edu-table">
						<thead>
							<tr>
								<th>Materia</th>
								<th>Trim</th>
								<th class="edu-th-num">Parcial 1</th>
								<th class="edu-th-num">Parcial 2</th>
								<th class="edu-th-num">Examen</th>
								<th v-if="usaProyecto" class="edu-th-num">Proyecto</th>
								<th class="edu-th-num">Nota</th>
							</tr>
						</thead>
						<tbody>
							<template v-for="m in materias" :key="m.subject_id">
								<tr v-for="t in m.trimesters" :key="m.subject_id + '-' + t.trimester_id">
									<td>{{ m.subject_name }}</td>
									<td>{{ t.number }}</td>
									<td class="edu-td-num">{{ formatScore(t.parcial1_score) }}</td>
									<td class="edu-td-num">{{ formatScore(t.parcial2_score) }}</td>
									<td class="edu-td-num">{{ formatScore(t.final_exam_score) }}</td>
									<td v-if="usaProyecto" class="edu-td-num">{{ formatScore(t.proyecto_score) }}</td>
									<td class="edu-td-num">
										<edu-nota :score="t.computed_score" :cualitativa="t.cualitativa" />
									</td>
								</tr>
							</template>
						</tbody>
					</table>
				</div>
				<p class="edu-nota-formula">
					<template v-if="usaProyecto">
						Nota del trimestre = ((P1 + P2) ÷ 2) × 70% + ((Examen + Proyecto) ÷ 2) × 30%
					</template>
					<template v-else>
						Nota del trimestre = ((P1 + P2) ÷ 2) × 70% + Examen × 30%
					</template>
				</p>
			</edu-card>
		</template>
	</div>`,
};
