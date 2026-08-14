/**
 * Dashboard institucional del rector.
 *
 * Sale entero de GET /dashboard/rector, que ya devuelve el bloque armado:
 * métricas, rendimiento por grado con su cualitativa, alertas de asistencia y
 * resumen de cobranza. Los bloques de módulos apagados no vienen.
 */

import { eduApi } from '@edu/api.js';
import { store, moduleActive, formatScore } from '@edu/store.js';

export const VistaRectorInicio = {
	data: () => ( {
		cargando: true,
		error: null,
		datos: null,
		trimesterId: 0,
	} ),

	computed: {
		trimestres() {
			return store.me?.active_period?.trimesters || [];
		},

		stats() {
			return this.datos?.stats || {};
		},

		rendimiento() {
			return this.datos?.rendimiento || [];
		},

		alertas() {
			return this.datos?.alertas || [];
		},

		pagos() {
			return this.datos?.pagos || null;
		},

		promedioInstitucional() {
			if ( ! this.rendimiento.length ) return null;

			const suma = this.rendimiento.reduce( ( t, g ) => t + Number( g.promedio || 0 ), 0 );
			return suma / this.rendimiento.length;
		},

		deuda() {
			if ( ! this.pagos ) return 0;
			return Number( this.pagos.pending.amount || 0 ) + Number( this.pagos.overdue.amount || 0 );
		},
	},

	mounted() {
		this.cargar();
	},

	methods: {
		async cargar() {
			this.cargando = true;
			this.error = null;

			try {
				this.datos = await eduApi.get(
					'/dashboard/rector',
					this.trimesterId ? { trimester_id: this.trimesterId } : {}
				);
			} catch ( e ) {
				this.error = e;
			} finally {
				this.cargando = false;
			}
		},

		colorPromedio( n ) {
			if ( n === null || n === undefined ) return '#1e293b';
			return n >= 8 ? '#059669' : n >= 7 ? '#1d4ed8' : '#dc2626';
		},

		dinero( v ) {
			return '$' + Number( v || 0 ).toFixed( 2 );
		},

		hayPagos() {
			return moduleActive( 'pagos' ) && this.pagos;
		},

		formatScore,
	},

	template: `
	<div>
		<div class="edu-content-header">
			<h2 class="edu-page-title">Panel institucional</h2>
			<p class="edu-page-sub">
				{{ store.me?.institution?.name }}
				<span v-if="store.me?.active_period"> · {{ store.me.active_period.name }}</span>
			</p>
		</div>

		<div class="edu-selector-curso">
			<label>
				<span>Trimestre</span>
				<select v-model="trimesterId" class="edu-select" @change="cargar">
					<option :value="0">Todos</option>
					<option v-for="t in trimestres" :key="t.id" :value="t.id">Trimestre {{ t.number }}</option>
				</select>
			</label>
		</div>

		<edu-loading v-if="cargando" texto="Cargando el panel…" />
		<edu-error v-else-if="error" :error="error" @retry="cargar" />

		<template v-else-if="datos">
			<div class="edu-stats-row">
				<edu-stat label="Estudiantes" :value="stats.estudiantes || 0" />
				<edu-stat label="Docentes" :value="stats.docentes || 0" />
				<edu-stat label="Grados" :value="stats.grados || 0" />
				<edu-stat label="Promedio institucional"
				          :value="promedioInstitucional === null ? '—' : formatScore(promedioInstitucional)"
				          :color="colorPromedio(promedioInstitucional)" />
			</div>

			<div class="edu-spa-cols">
				<edu-card titulo="Rendimiento por grado">
					<edu-empty v-if="!rendimiento.length"
					           texto="Todavía no hay notas de trimestre registradas." />
					<div v-else class="edu-table-wrap">
						<table class="edu-table">
							<thead>
								<tr>
									<th>Grado</th>
									<th class="edu-th-num">Estudiantes</th>
									<th class="edu-th-num">Promedio</th>
								</tr>
							</thead>
							<tbody>
								<tr v-for="g in rendimiento" :key="g.grade_id">
									<td>{{ g.grade_name }}</td>
									<td class="edu-td-num">{{ g.estudiantes }}</td>
									<td class="edu-td-num">
										<edu-nota :score="g.promedio" :cualitativa="g.cualitativa" />
									</td>
								</tr>
							</tbody>
						</table>
					</div>
				</edu-card>

				<edu-card titulo="Alertas de asistencia">
					<p class="edu-muted edu-small">
						Estudiantes con 3 o más faltas en los últimos 30 días.
					</p>
					<edu-empty v-if="!alertas.length" texto="Ninguna alerta. Buena señal." />
					<div v-else class="edu-table-wrap">
						<table class="edu-table">
							<thead>
								<tr>
									<th>Estudiante</th>
									<th>Grado</th>
									<th class="edu-th-num">Faltas</th>
									<th class="edu-th-num">Asistencia</th>
								</tr>
							</thead>
							<tbody>
								<tr v-for="a in alertas" :key="a.student_id">
									<td>{{ a.apellidos }} {{ a.nombres }}</td>
									<td class="edu-muted">{{ a.grade_name }}</td>
									<td class="edu-td-num">{{ a.faltas }}</td>
									<td class="edu-td-num">
										<span :class="a.porcentaje < 75 ? 'edu-texto-error' : ''">
											{{ a.porcentaje }}%
										</span>
									</td>
								</tr>
							</tbody>
						</table>
					</div>
				</edu-card>
			</div>

			<edu-card v-if="hayPagos()" titulo="Cobranza del período">
				<div class="edu-stats-row">
					<edu-stat label="Por cobrar" :value="dinero(deuda)"
					          :color="deuda > 0 ? '#d97706' : '#059669'" />
					<edu-stat label="Cobrado" :value="dinero(pagos.paid.amount)" color="#059669"
					          :sub="pagos.paid.count + ' pagos'" />
					<edu-stat label="Vencidos" :value="pagos.overdue.count"
					          :color="pagos.overdue.count ? '#dc2626' : '#1e293b'"
					          :sub="dinero(pagos.overdue.amount)" />
					<edu-stat label="Exonerados" :value="pagos.waived.count" />
				</div>
			</edu-card>
		</template>
	</div>`,

	setup() {
		return { store };
	},
};
