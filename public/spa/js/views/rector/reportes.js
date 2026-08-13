/**
 * Boletines y exportes Mineduc.
 *
 * Ningún binario viaja en la respuesta: la API valida el permiso y devuelve una
 * URL firmada que caduca en 5 minutos y solo sirve para quien la pidió. Por eso
 * el enlace se pide en el momento del clic y se abre enseguida.
 */

import { eduApi } from '@edu/api.js';
import { store, moduleActive } from '@edu/store.js';

const EXPORTES = [
	{ tipo: 'acta-consolidada', nombre: 'Acta consolidada', desc: 'Notas por materia T1·T2·T3, promedio y estado.', necesitaGrado: true },
	{ tipo: 'nomina-amie', nombre: 'Nómina AMIE', desc: 'Listado de estudiantes con sus datos y representante.', necesitaGrado: true },
	{ tipo: 'distributivo-docente', nombre: 'Distributivo docente', desc: 'Carga horaria por docente.', necesitaGrado: false },
	{ tipo: 'asistencia-acumulada', nombre: 'Asistencia acumulada', desc: 'Días y porcentaje por estudiante.', necesitaGrado: true },
];

export const VistaRectorReportes = {
	data: () => ( {
		exportes: EXPORTES,
		cargando: true,
		error: null,
		grados: [],
		estudiantes: [],
		gradeId: null,
		periodId: null,
		generando: null,
	} ),

	computed: {
		periodos() {
			return store.me?.active_period ? [ store.me.active_period ] : [];
		},

		hayBoletines() {
			return moduleActive( 'boletines' );
		},

		hayExportes() {
			return moduleActive( 'exportes' );
		},
	},

	async mounted() {
		this.periodId = store.me?.active_period?.id || null;
		await this.cargarGrados();
	},

	methods: {
		async cargarGrados() {
			this.cargando = true;
			this.error = null;

			try {
				this.grados = await eduApi.get( '/grades' );
				this.gradeId = this.grados[ 0 ]?.id || null;
				await this.cargarEstudiantes();
			} catch ( e ) {
				this.error = e;
			} finally {
				this.cargando = false;
			}
		},

		async cargarEstudiantes() {
			if ( ! this.gradeId ) {
				this.estudiantes = [];
				return;
			}

			try {
				this.estudiantes = await eduApi.get( '/students', {
					grade_id: this.gradeId,
					status: 'active',
					per_page: 100,
				} );
			} catch ( e ) {
				this.estudiantes = [];
			}
		},

		async boletin( estudiante ) {
			await this.abrir( 'b' + estudiante.id, '/reports/boletin', {
				student_id: estudiante.id,
				period_id: this.periodId,
			} );
		},

		async exportar( exporte ) {
			await this.abrir( 'e' + exporte.tipo, '/reports/mineduc/' + exporte.tipo, {
				period_id: this.periodId,
				grade_id: exporte.necesitaGrado ? this.gradeId : 0,
			} );
		},

		async abrir( clave, ruta, params ) {
			if ( this.generando ) return;

			this.generando = clave;
			this.error = null;

			try {
				const r = await eduApi.get( ruta, params );
				window.open( r.url, '_blank', 'noopener' );
			} catch ( e ) {
				this.error = e;
			} finally {
				this.generando = null;
			}
		},
	},

	template: `
	<div>
		<div class="edu-content-header">
			<h2 class="edu-page-title">Reportes</h2>
			<p class="edu-page-sub">
				Los enlaces de descarga son personales y caducan a los 5 minutos.
			</p>
		</div>

		<div class="edu-selector-curso">
			<label>
				<span>Período</span>
				<select v-model="periodId" class="edu-select">
					<option v-for="p in periodos" :key="p.id" :value="p.id">{{ p.name }}</option>
				</select>
			</label>
			<label>
				<span>Grado</span>
				<select v-model="gradeId" class="edu-select" @change="cargarEstudiantes">
					<option v-for="g in grados" :key="g.id" :value="g.id">{{ g.display_name }}</option>
				</select>
			</label>
		</div>

		<edu-loading v-if="cargando" texto="Cargando…" />
		<edu-error v-else-if="error" :error="error" @retry="cargarGrados" />

		<template v-else>
			<edu-card v-if="hayExportes" titulo="Exportes Mineduc">
				<div class="edu-table-wrap">
					<table class="edu-table">
						<tbody>
							<tr v-for="e in exportes" :key="e.tipo">
								<td>
									<strong>{{ e.nombre }}</strong>
									<div class="edu-muted edu-small">{{ e.desc }}</div>
								</td>
								<td class="edu-td-right">
									<button class="edu-btn edu-btn-primary"
									        :disabled="generando === 'e' + e.tipo"
									        @click="exportar(e)">
										{{ generando === 'e' + e.tipo ? 'Generando…' : 'Descargar .xlsx' }}
									</button>
								</td>
							</tr>
						</tbody>
					</table>
				</div>
			</edu-card>

			<edu-card v-if="hayBoletines" titulo="Boletines del grado">
				<edu-empty v-if="!estudiantes.length" texto="Este grado no tiene estudiantes activos." />
				<div v-else class="edu-table-wrap">
					<table class="edu-table">
						<tbody>
							<tr v-for="e in estudiantes" :key="e.id">
								<td>{{ e.apellidos }} {{ e.nombres }}</td>
								<td class="edu-td-right">
									<button class="edu-btn"
									        :disabled="generando === 'b' + e.id"
									        @click="boletin(e)">
										{{ generando === 'b' + e.id ? 'Generando…' : 'Boletín PDF' }}
									</button>
								</td>
							</tr>
						</tbody>
					</table>
				</div>
			</edu-card>

			<edu-empty v-if="!hayExportes && !hayBoletines"
			           texto="Los módulos de boletines y exportes están desactivados." />
		</template>
	</div>`,
};
