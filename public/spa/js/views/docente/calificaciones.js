/**
 * Grilla de calificaciones: estudiantes × componentes evaluables.
 *
 * Reglas del negocio que la pantalla respeta al pie de la letra:
 * - Una celda vacía significa SIN CALIFICAR, no cero. Se excluye del cálculo y
 *   los pesos se renormalizan, así que no hace falta que sumen 1.00.
 * - Solo se envían las celdas que el docente tocó. Enviar una celda vacía no
 *   borra la nota anterior.
 * - Una celda mala no tumba el guardado: el servidor guarda el resto y devuelve
 *   el detalle de lo que rechazó.
 * - Un estudiante con el parcial cerrado no admite cambios: su fila se bloquea.
 */

import { eduApi } from '@edu/api.js';
import { formatScore, formatDate } from '@edu/store.js';
import { EduSelectorCurso } from '@edu/views/docente/selector.js';

export const VistaDocenteCalificaciones = {
	components: { EduSelectorCurso },

	data: () => ( {
		curso: null,
		cargando: false,
		guardando: false,
		error: null,
		datos: null,

		/** Valores editados: "studentId:componentId" → texto del input. */
		editado: {},

		resultado: null,
		nuevoComponente: '',
		creando: false,

		/*
		 * Desglose de una celda. La nota que se ve es el PROMEDIO de todas las
		 * notas de ese componente, así que antes de cerrar un parcial conviene
		 * poder ver de qué está hecha.
		 */
		celdaAbierta: null,
		desgloseCelda: null,
		cargandoCelda: false,
		errorCelda: null,
	} ),

	computed: {
		componentes() {
			return this.datos?.components || [];
		},

		estudiantes() {
			return this.datos?.students || [];
		},

		contexto() {
			return this.datos?.context || null;
		},

		cerrado() {
			return !! this.contexto?.is_closed;
		},

		hayCambios() {
			return Object.keys( this.editado ).length > 0;
		},

		sumaPesos() {
			return this.componentes.reduce( ( t, c ) => t + Number( c.weight || 0 ), 0 );
		},
	},

	methods: {
		async cambioCurso( seleccion ) {
			this.curso = seleccion;
			await this.cargar();
		},

		async cargar() {
			if ( ! this.curso ) return;

			this.cargando = true;
			this.error = null;
			this.editado = {};
			this.resultado = null;

			try {
				this.datos = await eduApi.get( '/gradebook', this.curso );
			} catch ( e ) {
				this.error = e;
				this.datos = null;
			} finally {
				this.cargando = false;
			}
		},

		clave( studentId, componentId ) {
			return studentId + ':' + componentId;
		},

		/** Cuántas notas hay detrás de una celda. */
		cuantas( estudiante, componenteId ) {
			return estudiante.score_counts?.[ String( componenteId ) ] || 0;
		},

		/** Despliega el desglose de una celda; segundo clic lo cierra. */
		async abrirCelda( estudiante, componenteId ) {
			const k = this.clave( estudiante.student_id, componenteId );

			if ( this.celdaAbierta === k ) {
				this.celdaAbierta = null;
				return;
			}

			this.celdaAbierta = k;
			this.desgloseCelda = null;
			this.errorCelda = null;
			this.cargandoCelda = true;

			try {
				const r = await eduApi.get(
					`/students/${ estudiante.student_id }/component-breakdown`,
					{
						subject_id: this.curso.subject_id,
						trimester_id: this.curso.trimester_id,
						parcial_num: this.curso.parcial_num,
					}
				);

				this.desgloseCelda =
					( r.components || [] ).find( ( c ) => c.component_id === Number( componenteId ) ) || null;
			} catch ( e ) {
				this.errorCelda = e;
			} finally {
				this.cargandoCelda = false;
			}
		},

		origenTexto( entrada ) {
			return 'assignment' === entrada.origin
				? entrada.assignment_title || 'Tarea'
				: 'Nota registrada a mano';
		},

		/** Valor a mostrar: lo editado si existe, si no lo guardado. */
		valor( estudiante, componenteId ) {
			const k = this.clave( estudiante.student_id, componenteId );

			if ( k in this.editado ) return this.editado[ k ];

			const nota = estudiante.scores[ String( componenteId ) ];
			return nota === null || nota === undefined ? '' : String( nota );
		},

		editar( estudiante, componenteId, evento ) {
			this.editado[ this.clave( estudiante.student_id, componenteId ) ] = evento.target.value;
		},

		/** ¿El texto escrito es una nota válida de 0 a 10? */
		invalida( estudiante, componenteId ) {
			const k = this.clave( estudiante.student_id, componenteId );
			if ( ! ( k in this.editado ) ) return false;

			const texto = String( this.editado[ k ] ).trim().replace( ',', '.' );
			if ( '' === texto ) return false;

			const n = Number( texto );
			return isNaN( n ) || n < 0 || n > 10;
		},

		hayInvalidas() {
			return this.estudiantes.some( ( e ) =>
				this.componentes.some( ( c ) => this.invalida( e, c.id ) )
			);
		},

		async guardar() {
			if ( ! this.hayCambios || this.guardando ) return;

			this.guardando = true;
			this.error = null;
			this.resultado = null;

			const celdas = Object.entries( this.editado ).map( ( [ k, valor ] ) => {
				const [ studentId, componentId ] = k.split( ':' ).map( Number );
				return {
					student_id: studentId,
					component_id: componentId,
					score: String( valor ).trim(),
				};
			} );

			try {
				const r = await eduApi.post( '/gradebook/scores', { ...this.curso, scores: celdas } );

				this.editado = {};

				// Se recarga primero: cargar() limpia el resultado anterior, así
				// que el aviso se fija después para que quede a la vista.
				await this.cargar();
				this.resultado = r;
			} catch ( e ) {
				this.error = e;
			} finally {
				this.guardando = false;
			}
		},

		descartar() {
			this.editado = {};
			this.resultado = null;
		},

		async crearComponente() {
			const nombre = this.nuevoComponente.trim();
			if ( ! nombre || this.creando ) return;

			this.creando = true;
			this.error = null;

			try {
				await eduApi.post( '/components', {
					subject_id: this.curso.subject_id,
					trimester_id: this.curso.trimester_id,
					parcial_num: this.curso.parcial_num,
					name: nombre,
				} );

				this.nuevoComponente = '';
				await this.cargar();
			} catch ( e ) {
				this.error = e;
			} finally {
				this.creando = false;
			}
		},

		formatScore,
		formatDate,
	},

	template: `
	<div>
		<div class="edu-content-header">
			<h2 class="edu-page-title">Calificaciones</h2>
			<p class="edu-page-sub">
				Deja en blanco lo que aún no has calificado: no cuenta como cero.
			</p>
		</div>

		<edu-selector-curso @cambio="cambioCurso" />

		<edu-loading v-if="cargando" texto="Cargando la grilla…" />
		<edu-error v-else-if="error" :error="error" @retry="cargar" />

		<template v-else-if="datos">
			<div v-if="cerrado" class="edu-aviso edu-aviso-cerrado">
				Este parcial está cerrado. La grilla es de solo lectura.
			</div>

			<edu-empty v-if="!componentes.length"
			           texto="Este parcial todavía no tiene componentes evaluables. Crea el primero abajo." />

			<edu-card v-else>
				<div class="edu-table-wrap">
					<table class="edu-table edu-gradebook">
						<thead>
							<tr>
								<th class="edu-col-alumno">Estudiante</th>
								<th v-for="c in componentes" :key="c.id" class="edu-th-num">
									{{ c.name }}
									<div class="edu-muted edu-small">peso {{ formatScore(c.weight) }}</div>
								</th>
								<th class="edu-th-num">Parcial</th>
							</tr>
						</thead>
						<tbody>
							<template v-for="e in estudiantes" :key="e.student_id">
							<tr :class="{ 'edu-fila-cerrada': e.is_closed }">
								<td class="edu-col-alumno">
									{{ e.apellidos }} {{ e.nombres }}
									<span v-if="e.is_closed" class="edu-muted edu-small"> · cerrado</span>
								</td>
								<td v-for="c in componentes" :key="c.id" class="edu-td-num">
									<input class="edu-nota-input"
									       :class="{ 'edu-input-error': invalida(e, c.id) }"
									       type="text" inputmode="decimal" placeholder="—"
									       :disabled="e.is_closed || cerrado"
									       :value="valor(e, c.id)"
									       @input="editar(e, c.id, $event)">
									<button v-if="cuantas(e, c.id)"
									        class="edu-enlace edu-conteo-celda"
									        :class="{ 'is-open': celdaAbierta === clave(e.student_id, c.id) }"
									        @click="abrirCelda(e, c.id)"
									        :title="'Ver las ' + cuantas(e, c.id) + ' nota(s) que promedian esta celda'">
										{{ cuantas(e, c.id) }} nota<span v-if="cuantas(e, c.id) > 1">s</span>
									</button>
								</td>
								<td class="edu-td-num">
									<edu-nota :score="e.computed_score" :cualitativa="e.cualitativa" />
								</td>
							</tr>

							<tr v-if="celdaAbierta && celdaAbierta.startsWith(e.student_id + ':')">
								<td :colspan="componentes.length + 2">
									<div class="edu-desglose">
										<div v-if="cargandoCelda" class="edu-muted edu-small">Cargando desglose…</div>
										<div v-else-if="errorCelda" class="edu-texto-error edu-small">
											{{ errorCelda.message || 'No se pudo cargar el desglose.' }}
										</div>
										<template v-else-if="desgloseCelda">
											<h4 class="edu-desglose-titulo">
												{{ e.apellidos }} {{ e.nombres }} · {{ desgloseCelda.name }}
											</h4>
											<div class="edu-desglose-comp">
												<div class="edu-desglose-cab">
													<span class="edu-muted edu-small">peso {{ formatScore(desgloseCelda.weight) }}</span>
													<span class="edu-desglose-prom">
														{{ desgloseCelda.average === null ? '—' : formatScore(desgloseCelda.average) }}
														<span class="edu-muted">({{ desgloseCelda.count }})</span>
													</span>
												</div>
												<ul class="edu-lista edu-small">
													<li v-for="x in desgloseCelda.entries" :key="x.id">
														<span>{{ origenTexto(x) }}
															<span class="edu-muted"> · {{ formatDate(x.registered_at) }}</span>
														</span>
														<strong>{{ formatScore(x.score) }}</strong>
													</li>
												</ul>
											</div>
										</template>
									</div>
								</td>
							</tr>
							</template>
						</tbody>
					</table>
				</div>

				<p class="edu-muted edu-small">
					Suma de pesos: {{ formatScore(sumaPesos) }}.
					El cálculo renormaliza sobre los componentes con nota, así que no necesita sumar 1.00.
				</p>
			</edu-card>

			<div v-if="resultado" class="edu-aviso"
			     :class="resultado.errors.length ? 'edu-aviso-parcial' : 'edu-aviso-ok'">
				Se guardaron {{ resultado.saved }} nota(s).
				<template v-if="resultado.errors.length">
					{{ resultado.errors.length }} celda(s) no se pudieron guardar:
					<ul class="edu-lista-errores">
						<li v-for="(x, i) in resultado.errors" :key="i">{{ x.message }}</li>
					</ul>
				</template>
			</div>

			<div v-if="hayCambios && !cerrado" class="edu-barra-guardar">
				<span>{{ Object.keys(editado).length }} cambio(s) sin guardar</span>
				<span v-if="hayInvalidas()" class="edu-texto-error">
					Hay notas fuera del rango 0–10
				</span>
				<button class="edu-btn" @click="descartar">Descartar</button>
				<button class="edu-btn edu-btn-primary" :disabled="guardando" @click="guardar">
					{{ guardando ? 'Guardando…' : 'Guardar notas' }}
				</button>
			</div>

			<edu-card v-if="!cerrado" titulo="Nuevo componente evaluable">
				<p class="edu-muted edu-small">
					Nace con peso 1.00, igual que los demás. Si ya existe uno con ese nombre en
					este parcial, se reutiliza en vez de duplicarlo.
				</p>
				<div class="edu-fila-form">
					<input class="edu-input" type="text" placeholder="Tareas, Lecciones, Prueba…"
					       v-model="nuevoComponente" @keyup.enter="crearComponente">
					<button class="edu-btn edu-btn-primary"
					        :disabled="!nuevoComponente.trim() || creando"
					        @click="crearComponente">
						{{ creando ? 'Creando…' : 'Añadir' }}
					</button>
				</div>
			</edu-card>
		</template>
	</div>`,
};
