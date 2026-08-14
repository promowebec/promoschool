/**
 * Asistencia del estudiante en foco, con su resumen del rango consultado.
 */

import { eduApi } from '@edu/api.js';
import { store, currentStudent, formatDate } from '@edu/store.js';

export const VistaAsistencia = {
	data() {
		const hoy = new Date();
		const inicio = new Date( hoy.getFullYear(), hoy.getMonth(), 1 );

		return {
			cargando: true,
			error: null,
			datos: null,
			desde: inicio.toISOString().slice( 0, 10 ),
			hasta: hoy.toISOString().slice( 0, 10 ),
		};
	},

	computed: {
		estudiante() {
			return currentStudent.value;
		},

		resumen() {
			return this.datos?.summary || {};
		},

		porcentaje() {
			return this.datos?.percentage;
		},

		colorPorcentaje() {
			const p = this.porcentaje;
			if ( p === null || p === undefined ) return '#1e293b';
			return p >= 90 ? '#059669' : p >= 75 ? '#d97706' : '#dc2626';
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
				this.datos = await eduApi.get( `/students/${ store.studentId }/attendance`, {
					from: this.desde,
					to: this.hasta,
				} );
			} catch ( e ) {
				this.error = e;
			} finally {
				this.cargando = false;
			}
		},

		etiqueta( estado ) {
			return {
				presente: 'Presente',
				atraso: 'Atraso',
				falta_justificada: 'Falta justificada',
				falta_injustificada: 'Falta injustificada',
			}[ estado ] || estado;
		},

		tono( estado ) {
			return {
				presente: 'ok',
				atraso: 'aviso',
				falta_justificada: 'info',
				falta_injustificada: 'malo',
			}[ estado ] || 'neutro';
		},

		formatDate,
	},

	template: `
	<div>
		<div class="edu-content-header">
			<h2 class="edu-page-title">Asistencia</h2>
			<p class="edu-page-sub" v-if="estudiante">
				{{ estudiante.nombres }} {{ estudiante.apellidos }}
			</p>
		</div>

		<div class="edu-spa-filtros">
			<label>Desde <input type="date" v-model="desde" @change="cargar"></label>
			<label>Hasta <input type="date" v-model="hasta" @change="cargar"></label>
		</div>

		<edu-loading v-if="cargando" texto="Cargando asistencia…" />
		<edu-error v-else-if="error" :error="error" @retry="cargar" />

		<template v-else>
			<div class="edu-stats-row">
				<edu-stat label="Asistencia"
				          :value="porcentaje === null || porcentaje === undefined ? '—' : porcentaje + '%'"
				          :color="colorPorcentaje"
				          :sub="datos.total_days + ' días registrados'" />
				<edu-stat label="Presente" :value="resumen.presente || 0" color="#059669" />
				<edu-stat label="Atrasos" :value="resumen.atraso || 0" color="#d97706" />
				<edu-stat label="Faltas"
				          :value="(resumen.falta_justificada || 0) + (resumen.falta_injustificada || 0)"
				          color="#dc2626"
				          :sub="(resumen.falta_justificada || 0) + ' justificadas'" />
			</div>

			<edu-card titulo="Detalle por día">
				<edu-empty v-if="!datos.days.length"
				           texto="No hay registros de asistencia en este rango." />
				<div v-else class="edu-table-wrap">
					<table class="edu-table">
						<thead>
							<tr><th>Fecha</th><th>Estado</th><th>Justificación</th></tr>
						</thead>
						<tbody>
							<tr v-for="d in datos.days" :key="d.date">
								<td>{{ formatDate(d.date) }}</td>
								<td><edu-badge :texto="etiqueta(d.status)" :tono="tono(d.status)" /></td>
								<td class="edu-muted">{{ d.justification || '—' }}</td>
							</tr>
						</tbody>
					</table>
				</div>
			</edu-card>
		</template>
	</div>`,
};
