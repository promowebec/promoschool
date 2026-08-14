/**
 * Notas del estudiante en foco: por materia, trimestre a trimestre, con la
 * nota anual y su estado.
 *
 * Sirve igual al estudiante (sus notas) y al representante (las de su hijo):
 * la diferencia es solo qué student_id hay en el store.
 */

import { eduApi } from '@edu/api.js';
import { store, currentStudent, formatScore, formatDate } from '@edu/store.js';

export const VistaNotas = {
	data: () => ( {
		cargando: true,
		error: null,
		datos: null,

		/*
		 * Desglose de un parcial. La celda de un componente es el PROMEDIO de
		 * sus notas, así que sin esto nadie puede explicar de dónde sale.
		 * Se pide al abrir y se cachea por materia-trimestre-parcial.
		 */
		abierto: null,
		desgloses: {},
		cargandoDesglose: false,
		errorDesglose: null,
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

		claveDesglose( materia, t, parcial ) {
			return `${ materia.subject_id }-${ t.trimester_id }-${ parcial }`;
		},

		/** Despliega el desglose de un parcial; segundo clic lo cierra. */
		async abrirDesglose( materia, t, parcial ) {
			const clave = this.claveDesglose( materia, t, parcial );

			if ( this.abierto === clave ) {
				this.abierto = null;
				return;
			}

			this.abierto = clave;
			this.errorDesglose = null;

			if ( this.desgloses[ clave ] ) return; // Ya cacheado.

			this.cargandoDesglose = true;

			try {
				this.desgloses[ clave ] = await eduApi.get(
					`/students/${ store.studentId }/component-breakdown`,
					{
						subject_id: materia.subject_id,
						trimester_id: t.trimester_id,
						parcial_num: parcial,
					}
				);
			} catch ( e ) {
				// Se deja abierto a propósito: si se cierra, el clic parece no
				// haber hecho nada y el usuario no sabe que falló.
				this.errorDesglose = e;
			} finally {
				this.cargandoDesglose = false;
			}
		},

		componentesDe( clave ) {
			return this.desgloses[ clave ]?.components || [];
		},

		origenTexto( entrada ) {
			return 'assignment' === entrada.origin
				? entrada.assignment_title || 'Tarea'
				: 'Nota registrada por el docente';
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
		formatDate,
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
								<template v-for="t in m.trimesters" :key="m.subject_id + '-' + t.trimester_id">
								<tr>
									<td>{{ m.subject_name }}</td>
									<td>{{ t.number }}</td>
									<td v-for="p in [1, 2]" :key="p" class="edu-td-num">
										<button class="edu-enlace edu-celda-parcial"
										        :class="{ 'is-open': abierto === claveDesglose(m, t, p) }"
										        @click="abrirDesglose(m, t, p)"
										        :title="'Ver de dónde sale la nota del parcial ' + p">
											{{ formatScore(p === 1 ? t.parcial1_score : t.parcial2_score) }}
											<span class="edu-caret">▸</span>
										</button>
									</td>
									<td class="edu-td-num">{{ formatScore(t.final_exam_score) }}</td>
									<td v-if="usaProyecto" class="edu-td-num">{{ formatScore(t.proyecto_score) }}</td>
									<td class="edu-td-num">
										<edu-nota :score="t.computed_score" :cualitativa="t.cualitativa" />
									</td>
								</tr>

								<tr v-for="p in [1, 2]" :key="'d-' + p"
								    v-show="abierto === claveDesglose(m, t, p)">
									<td :colspan="usaProyecto ? 7 : 6">
										<div class="edu-desglose">
											<h4 class="edu-desglose-titulo">
												{{ m.subject_name }} · Trimestre {{ t.number }} · Parcial {{ p }}
											</h4>

											<div v-if="cargandoDesglose && !desgloses[claveDesglose(m, t, p)]"
											     class="edu-muted edu-small">Cargando desglose…</div>

											<div v-else-if="errorDesglose" class="edu-texto-error edu-small">
												{{ errorDesglose.message || 'No se pudo cargar el desglose.' }}
											</div>

											<template v-else>
												<p v-if="!componentesDe(claveDesglose(m, t, p)).length"
												   class="edu-muted edu-small">
													Este parcial todavía no tiene componentes evaluables.
												</p>

												<div v-for="c in componentesDe(claveDesglose(m, t, p))"
												     :key="c.component_id" class="edu-desglose-comp">
													<div class="edu-desglose-cab">
														<strong>{{ c.name }}</strong>
														<span class="edu-muted edu-small">peso {{ c.weight }}</span>
														<span class="edu-desglose-prom">
															{{ c.average === null ? '—' : formatScore(c.average) }}
															<span class="edu-muted">({{ c.count }})</span>
														</span>
													</div>

													<ul v-if="c.entries.length" class="edu-lista edu-small">
														<li v-for="e in c.entries" :key="e.id">
															<span>
																{{ origenTexto(e) }}
																<span class="edu-muted"> · {{ formatDate(e.registered_at) }}</span>
															</span>
															<strong>{{ formatScore(e.score) }}</strong>
														</li>
													</ul>
													<p v-else class="edu-muted edu-small">Sin notas todavía.</p>
												</div>

												<p class="edu-nota-formula">
													La nota de cada componente es el promedio de sus notas. Los
													componentes sin calificar no cuentan: sus pesos se reparten
													entre los que sí tienen nota.
												</p>
											</template>
										</div>
									</td>
								</tr>
								</template>
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
