/**
 * Selector de curso para las pantallas del docente: grado, materia, trimestre
 * y parcial.
 *
 * No hace ninguna llamada a la API: las asignaciones del docente y los
 * trimestres del período activo ya vienen en GET /me. Al elegir un grado, la
 * lista de materias se reduce a las que ese docente dicta ahí.
 */

import { store, profile } from '@edu/store.js';

export const EduSelectorCurso = {
	props: {
		conParcial: { type: Boolean, default: true },
		conMateria: { type: Boolean, default: true },
	},

	emits: [ 'cambio' ],

	data: () => ( {
		gradeId: null,
		subjectId: null,
		trimesterId: null,
		parcialNum: 1,
	} ),

	computed: {
		asignaciones() {
			return profile.value.assignments || [];
		},

		/** Grados donde el docente tiene asignación, sin repetir. */
		grados() {
			const vistos = new Map();

			this.asignaciones.forEach( ( a ) => {
				if ( ! vistos.has( a.grade_id ) ) {
					vistos.set( a.grade_id, { id: a.grade_id, nombre: a.grade_name } );
				}
			} );

			return [ ...vistos.values() ];
		},

		/** Materias que dicta en el grado elegido. */
		materias() {
			return this.asignaciones
				.filter( ( a ) => a.grade_id === this.gradeId )
				.map( ( a ) => ( { id: a.subject_id, nombre: a.subject_name } ) );
		},

		trimestres() {
			return store.me?.active_period?.trimesters || [];
		},

		completo() {
			return !! this.gradeId &&
				( ! this.conMateria || !! this.subjectId ) &&
				!! this.trimesterId;
		},

		seleccion() {
			return {
				grade_id: this.gradeId,
				subject_id: this.subjectId,
				trimester_id: this.trimesterId,
				parcial_num: this.parcialNum,
			};
		},
	},

	watch: {
		gradeId() {
			// Al cambiar de grado, la materia anterior puede no dictarse ahí.
			if ( ! this.materias.some( ( m ) => m.id === this.subjectId ) ) {
				this.subjectId = this.materias[ 0 ]?.id || null;
			}
			this.emitir();
		},
		subjectId() {
			this.emitir();
		},
		trimesterId() {
			this.emitir();
		},
		parcialNum() {
			this.emitir();
		},
	},

	mounted() {
		this.gradeId = this.grados[ 0 ]?.id || null;
		this.subjectId = this.materias[ 0 ]?.id || null;

		// Se preselecciona el primer trimestre que siga abierto.
		const abierto = this.trimestres.find( ( t ) => ! t.is_closed );
		this.trimesterId = ( abierto || this.trimestres[ 0 ] )?.id || null;

		this.emitir();
	},

	methods: {
		emitir() {
			if ( this.completo ) {
				this.$emit( 'cambio', this.seleccion );
			}
		},
	},

	template: `
	<div class="edu-selector-curso">
		<label>
			<span>Grado</span>
			<select v-model="gradeId" class="edu-select">
				<option v-for="g in grados" :key="g.id" :value="g.id">{{ g.nombre }}</option>
			</select>
		</label>

		<label v-if="conMateria">
			<span>Materia</span>
			<select v-model="subjectId" class="edu-select">
				<option v-for="m in materias" :key="m.id" :value="m.id">{{ m.nombre }}</option>
			</select>
		</label>

		<label>
			<span>Trimestre</span>
			<select v-model="trimesterId" class="edu-select">
				<option v-for="t in trimestres" :key="t.id" :value="t.id">
					Trimestre {{ t.number }}{{ t.is_closed ? ' (cerrado)' : '' }}
				</option>
			</select>
		</label>

		<label v-if="conParcial">
			<span>Parcial</span>
			<select v-model="parcialNum" class="edu-select">
				<option :value="1">Parcial 1</option>
				<option :value="2">Parcial 2</option>
			</select>
		</label>

		<div v-if="!grados.length" class="edu-spa-empty">
			No tienes asignaciones activas en este período.
		</div>
	</div>`,
};
