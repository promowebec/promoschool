/**
 * Cierre de parciales y trimestres.
 *
 * Es la pantalla más delicada del sistema: **cerrar no tiene vuelta atrás**
 * desde la aplicación. Un parcial cerrado deja de recalcularse y no admite
 * notas nuevas. Por eso cada botón pide una confirmación explícita en dos
 * pasos, y el trimestre solo se puede cerrar cuando sus dos parciales lo están.
 */

import { eduApi } from '@edu/api.js';
import { store } from '@edu/store.js';

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
							<tr v-for="i in trimestre.items" :key="i.student_id">
								<td class="edu-col-alumno">{{ i.apellidos }} {{ i.nombres }}</td>
								<td class="edu-td-num">{{ i.parcial1_score ?? '—' }}</td>
								<td class="edu-td-num">{{ i.parcial2_score ?? '—' }}</td>
								<td class="edu-td-num">{{ i.final_exam_score ?? '—' }}</td>
								<td class="edu-td-num">
									<edu-nota :score="i.computed_score" :cualitativa="i.cualitativa" />
								</td>
								<td>
									<edu-badge :texto="i.is_closed ? 'Cerrado' : 'Abierto'"
									           :tono="i.is_closed ? 'ok' : 'neutro'" />
								</td>
							</tr>
						</tbody>
					</table>
				</div>
			</edu-card>
		</template>
	</div>`,
};
