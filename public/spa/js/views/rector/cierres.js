/**
 * Cierre de parciales y trimestres.
 *
 * Es la pantalla más delicada del sistema: **cerrar no tiene vuelta atrás**
 * desde la aplicación. Un parcial cerrado deja de recalcularse y no admite
 * notas nuevas. Por eso cada botón pide una confirmación explícita en dos
 * pasos, y el trimestre solo se puede cerrar cuando sus dos parciales lo están.
 */

import { eduApi } from '@edu/api.js';
import { store, formatDate } from '@edu/store.js';

export const VistaRectorCierres = {
	data: () => ( {
		grados: [],
		materias: [],
		gradeId: null,
		subjectId: null,
		trimesterId: null,

		cargando: false,
		trabajando: false,
		error: null,
		aviso: '',

		/** Estado por parcial: { 1: {...}, 2: {...} } */
		parciales: {},
		trimestre: null,

		/** Acción esperando confirmación. */
		confirmando: null,

		/*
		 * Desglose de un parcial de un estudiante. Cerrar es irreversible sin
		 * auditoría, así que conviene poder ver de qué está hecha cada nota
		 * antes de hacerlo.
		 */
		desgloseAbierto: null,
		desglose: null,
		cargandoDesglose: false,
		errorDesglose: null,
	} ),

	computed: {
		trimestres() {
			return store.me?.active_period?.trimesters || [];
		},

		completo() {
			return !! ( this.gradeId && this.subjectId && this.trimesterId );
		},

		ambosCerrados() {
			return this.parcialCerrado( 1 ) && this.parcialCerrado( 2 );
		},

		trimestreCerrado() {
			if ( ! this.trimestre || ! this.trimestre.items.length ) return false;
			return this.trimestre.items.every( ( i ) => i.is_closed );
		},
	},

	async mounted() {
		this.trimesterId = ( this.trimestres.find( ( t ) => ! t.is_closed ) || this.trimestres[ 0 ] )?.id || null;
		await this.cargarCatalogos();
	},

	methods: {
		async cargarCatalogos() {
			this.error = null;

			try {
				const [ grados, materias ] = await Promise.all( [
					eduApi.get( '/grades' ),
					eduApi.get( '/subjects' ),
				] );

				this.grados = grados;
				this.materias = materias;
				this.gradeId = grados[ 0 ]?.id || null;
				this.subjectId = materias[ 0 ]?.id || null;

				await this.cargar();
			} catch ( e ) {
				this.error = e;
			}
		},

		async cargar() {
			if ( ! this.completo ) return;

			this.cargando = true;
			this.error = null;
			this.confirmando = null;

			const base = {
				grade_id: this.gradeId,
				subject_id: this.subjectId,
				trimester_id: this.trimesterId,
			};

			try {
				const [ p1, p2, trim ] = await Promise.all( [
					eduApi.get( '/gradebook', { ...base, parcial_num: 1 } ),
					eduApi.get( '/gradebook', { ...base, parcial_num: 2 } ),
					eduApi.get( '/trimester-scores', base ),
				] );

				this.parciales = { 1: p1, 2: p2 };
				this.trimestre = trim;
			} catch ( e ) {
				this.error = e;
				this.parciales = {};
				this.trimestre = null;
			} finally {
				this.cargando = false;
			}
		},

		estudiantes( n ) {
			return this.parciales[ n ]?.students || [];
		},

		claveDesglose( studentId, parcial ) {
			return studentId + ':' + parcial;
		},

		/** Despliega el desglose de un parcial de un estudiante. */
		async abrirDesglose( studentId, parcial ) {
			const k = this.claveDesglose( studentId, parcial );

			if ( this.desgloseAbierto === k ) {
				this.desgloseAbierto = null;
				return;
			}

			this.desgloseAbierto = k;
			this.desglose = null;
			this.errorDesglose = null;
			this.cargandoDesglose = true;

			try {
				this.desglose = await eduApi.get( `/students/${ studentId }/component-breakdown`, {
					subject_id: this.subjectId,
					trimester_id: this.trimesterId,
					parcial_num: parcial,
				} );
			} catch ( e ) {
				this.errorDesglose = e;
			} finally {
				this.cargandoDesglose = false;
			}
		},

		origenTexto( entrada ) {
			return 'assignment' === entrada.origin
				? entrada.assignment_title || 'Tarea'
				: 'Nota registrada a mano';
		},

		formatDate,

		cerrados( n ) {
			return this.estudiantes( n ).filter( ( e ) => e.is_closed ).length;
		},

		parcialCerrado( n ) {
			const lista = this.estudiantes( n );
			return lista.length > 0 && lista.every( ( e ) => e.is_closed );
		},

		/** Estudiantes sin ninguna nota: cerrar los dejaría en cero. */
		sinNotas( n ) {
			return this.estudiantes( n ).filter(
				( e ) => e.computed_score === null || e.computed_score === undefined
			).length;
		},

		pedirConfirmacion( accion ) {
			this.confirmando = accion;
			this.aviso = '';
		},

		cancelar() {
			this.confirmando = null;
		},

		async cerrarParcial( n ) {
			await this.ejecutar( '/trimester-scores/close-parcial', {
				grade_id: this.gradeId,
				subject_id: this.subjectId,
				trimester_id: this.trimesterId,
				parcial_num: n,
			}, `Parcial ${ n } cerrado.` );
		},

		async cerrarTrimestre() {
			await this.ejecutar( '/trimester-scores/close-trimester', {
				grade_id: this.gradeId,
				subject_id: this.subjectId,
				trimester_id: this.trimesterId,
			}, 'Trimestre cerrado.' );
		},

		async ejecutar( ruta, cuerpo, mensaje ) {
			if ( this.trabajando ) return;

			this.trabajando = true;
			this.error = null;
			this.confirmando = null;

			try {
				const r = await eduApi.post( ruta, cuerpo );
				await this.cargar();
				this.aviso = mensaje + ' ' + r.students + ' estudiante(s) afectados.';
			} catch ( e ) {
				this.error = e;
			} finally {
				this.trabajando = false;
			}
		},
	},

	template: `
	<div>
		<div class="edu-content-header">
			<h2 class="edu-page-title">Cierres</h2>
			<p class="edu-page-sub">
				Cerrar congela las notas: dejan de recalcularse y no admiten cambios.
			</p>
		</div>

		<div class="edu-selector-curso">
			<label>
				<span>Grado</span>
				<select v-model="gradeId" class="edu-select" @change="cargar">
					<option v-for="g in grados" :key="g.id" :value="g.id">{{ g.display_name }}</option>
				</select>
			</label>
			<label>
				<span>Materia</span>
				<select v-model="subjectId" class="edu-select" @change="cargar">
					<option v-for="m in materias" :key="m.id" :value="m.id">{{ m.name }}</option>
				</select>
			</label>
			<label>
				<span>Trimestre</span>
				<select v-model="trimesterId" class="edu-select" @change="cargar">
					<option v-for="t in trimestres" :key="t.id" :value="t.id">Trimestre {{ t.number }}</option>
				</select>
			</label>
		</div>

		<div v-if="aviso" class="edu-aviso edu-aviso-ok">{{ aviso }}</div>
		<edu-error v-if="error" :error="error" @retry="cargar" />

		<edu-loading v-if="cargando" texto="Consultando el estado…" />

		<template v-else-if="trimestre">
			<div class="edu-spa-cols">
				<edu-card v-for="n in [1, 2]" :key="n" :titulo="'Parcial ' + n">
					<div class="edu-stats-row">
						<edu-stat label="Estudiantes" :value="estudiantes(n).length" />
						<edu-stat label="Cerrados" :value="cerrados(n)"
						          :color="parcialCerrado(n) ? '#059669' : '#d97706'" />
						<edu-stat label="Sin nota" :value="sinNotas(n)"
						          :color="sinNotas(n) ? '#dc2626' : '#1e293b'" />
					</div>

					<edu-badge v-if="parcialCerrado(n)" texto="Cerrado" tono="ok" />

					<template v-else>
						<p v-if="sinNotas(n)" class="edu-texto-error edu-small">
							Hay {{ sinNotas(n) }} estudiante(s) sin ninguna nota. Al cerrar quedarán en cero.
						</p>

						<div v-if="confirmando === 'p' + n" class="edu-aviso edu-aviso-parcial">
							<p><strong>¿Cerrar el parcial {{ n }}?</strong></p>
							<p>Desde la aplicación no se puede reabrir.</p>
							<div class="edu-barra-guardar">
								<button class="edu-btn" @click="cancelar">Cancelar</button>
								<button class="edu-btn edu-btn-primary" :disabled="trabajando"
								        @click="cerrarParcial(n)">
									{{ trabajando ? 'Cerrando…' : 'Sí, cerrar' }}
								</button>
							</div>
						</div>

						<p v-else>
							<button class="edu-btn" :disabled="!estudiantes(n).length"
							        @click="pedirConfirmacion('p' + n)">
								Cerrar parcial {{ n }}
							</button>
						</p>
					</template>
				</edu-card>
			</div>

			<edu-card titulo="Trimestre">
				<p class="edu-muted edu-small">
					Solo se puede cerrar cuando los dos parciales están cerrados.
					Al cerrar se recalcula la nota anual.
				</p>

				<edu-badge v-if="trimestreCerrado" texto="Trimestre cerrado" tono="ok" />

				<template v-else>
					<div v-if="confirmando === 'trim'" class="edu-aviso edu-aviso-parcial">
						<p><strong>¿Cerrar el trimestre?</strong></p>
						<p>Se congelan las notas y se recalcula el año. No se puede reabrir desde la aplicación.</p>
						<div class="edu-barra-guardar">
							<button class="edu-btn" @click="cancelar">Cancelar</button>
							<button class="edu-btn edu-btn-primary" :disabled="trabajando"
							        @click="cerrarTrimestre">
								{{ trabajando ? 'Cerrando…' : 'Sí, cerrar el trimestre' }}
							</button>
						</div>
					</div>

					<p v-else>
						<button class="edu-btn" :disabled="!ambosCerrados"
						        @click="pedirConfirmacion('trim')">
							Cerrar trimestre
						</button>
						<span v-if="!ambosCerrados" class="edu-muted edu-small">
							 · faltan parciales por cerrar
						</span>
					</p>
				</template>

				<div class="edu-table-wrap" v-if="trimestre.items.length">
					<table class="edu-table">
						<thead>
							<tr>
								<th class="edu-col-alumno">Estudiante</th>
								<th class="edu-th-num">P1</th>
								<th class="edu-th-num">P2</th>
								<th class="edu-th-num">Examen</th>
								<th class="edu-th-num">Nota</th>
								<th>Estado</th>
							</tr>
						</thead>
						<tbody>
							<template v-for="i in trimestre.items" :key="i.student_id">
							<tr>
								<td class="edu-col-alumno">{{ i.apellidos }} {{ i.nombres }}</td>
								<td v-for="p in [1, 2]" :key="p" class="edu-td-num">
									<button class="edu-enlace edu-celda-parcial"
									        :class="{ 'is-open': desgloseAbierto === claveDesglose(i.student_id, p) }"
									        @click="abrirDesglose(i.student_id, p)"
									        :title="'Ver de dónde sale la nota del parcial ' + p">
										{{ (p === 1 ? i.parcial1_score : i.parcial2_score) ?? '—' }}
										<span class="edu-caret">▸</span>
									</button>
								</td>
								<td class="edu-td-num">{{ i.final_exam_score ?? '—' }}</td>
								<td class="edu-td-num">
									<edu-nota :score="i.computed_score" :cualitativa="i.cualitativa" />
								</td>
								<td>
									<edu-badge :texto="i.is_closed ? 'Cerrado' : 'Abierto'"
									           :tono="i.is_closed ? 'ok' : 'neutro'" />
								</td>
							</tr>

							<tr v-if="desgloseAbierto && desgloseAbierto.startsWith(i.student_id + ':')">
								<td colspan="6">
									<div class="edu-desglose">
										<div v-if="cargandoDesglose" class="edu-muted edu-small">Cargando desglose…</div>
										<div v-else-if="errorDesglose" class="edu-texto-error edu-small">
											{{ errorDesglose.message || 'No se pudo cargar el desglose.' }}
										</div>
										<template v-else-if="desglose">
											<h4 class="edu-desglose-titulo">
												{{ i.apellidos }} {{ i.nombres }} · Parcial {{ desglose.parcial_num }}
											</h4>
											<p v-if="!desglose.components.length" class="edu-muted edu-small">
												Este parcial no tiene componentes evaluables.
											</p>
											<div v-for="c in desglose.components" :key="c.component_id"
											     class="edu-desglose-comp">
												<div class="edu-desglose-cab">
													<strong>{{ c.name }}</strong>
													<span class="edu-muted edu-small">peso {{ c.weight }}</span>
													<span class="edu-desglose-prom">
														{{ c.average === null ? '—' : c.average }}
														<span class="edu-muted">({{ c.count }})</span>
													</span>
												</div>
												<ul v-if="c.entries.length" class="edu-lista edu-small">
													<li v-for="x in c.entries" :key="x.id">
														<span>{{ origenTexto(x) }}
															<span class="edu-muted"> · {{ formatDate(x.registered_at) }}</span>
														</span>
														<strong>{{ x.score }}</strong>
													</li>
												</ul>
												<p v-else class="edu-muted edu-small">Sin notas todavía.</p>
											</div>
										</template>
									</div>
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
