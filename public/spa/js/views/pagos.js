/**
 * Pagos del hijo en foco (portal del representante).
 *
 * Solo consulta: el cobro en línea sigue pasando por el flujo de Payphone del
 * plugin, que exige confirmación del servidor antes de dar nada por pagado.
 */

import { eduApi } from '@edu/api.js';
import { store, currentStudent } from '@edu/store.js';

export const VistaPagos = {
	data: () => ( {
		cargando: true,
		error: null,
		lista: [],
	} ),

	computed: {
		estudiante() {
			return currentStudent.value;
		},

		pendientes() {
			return this.lista.filter( ( p ) => p.status === 'pending' );
		},

		vencidos() {
			return this.lista.filter( ( p ) => p.status === 'overdue' );
		},

		deuda() {
			return [ ...this.pendientes, ...this.vencidos ]
				.reduce( ( total, p ) => total + Number( p.amount || 0 ), 0 );
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
				this.lista = await eduApi.get( '/payments', { student_id: store.studentId } );
			} catch ( e ) {
				this.error = e;
			} finally {
				this.cargando = false;
			}
		},

		etiqueta( estado ) {
			return {
				pending: 'Pendiente',
				paid: 'Pagado',
				overdue: 'Vencido',
				waived: 'Exonerado',
			}[ estado ] || estado;
		},

		tono( estado ) {
			return {
				pending: 'aviso',
				paid: 'ok',
				overdue: 'malo',
				waived: 'info',
			}[ estado ] || 'neutro';
		},

		concepto( c ) {
			return c === 'matricula' ? 'Matrícula' : 'Pensión';
		},

		dinero( v ) {
			return '$' + Number( v || 0 ).toFixed( 2 );
		},
	},

	template: `
	<div>
		<div class="edu-content-header">
			<h2 class="edu-page-title">Pagos</h2>
			<p class="edu-page-sub" v-if="estudiante">
				{{ estudiante.nombres }} {{ estudiante.apellidos }}
			</p>
		</div>

		<edu-loading v-if="cargando" texto="Cargando pagos…" />
		<edu-error v-else-if="error" :error="error" @retry="cargar" />
		<edu-empty v-else-if="!lista.length" texto="No hay pagos registrados." />

		<template v-else>
			<div class="edu-stats-row">
				<edu-stat label="Por pagar" :value="dinero(deuda)"
				          :color="deuda > 0 ? '#d97706' : '#059669'" />
				<edu-stat label="Pendientes" :value="pendientes.length" />
				<edu-stat label="Vencidos" :value="vencidos.length"
				          :color="vencidos.length ? '#dc2626' : '#1e293b'" />
			</div>

			<edu-card>
				<div class="edu-table-wrap">
					<table class="edu-table">
						<thead>
							<tr>
								<th>Concepto</th>
								<th>Mes</th>
								<th>Vence</th>
								<th class="edu-th-num">Valor</th>
								<th>Estado</th>
							</tr>
						</thead>
						<tbody>
							<tr v-for="p in lista" :key="p.id">
								<td>{{ concepto(p.concept) }}</td>
								<td>{{ p.month }}</td>
								<td>{{ p.due_date }}</td>
								<td class="edu-td-num">{{ dinero(p.amount) }}</td>
								<td><edu-badge :texto="etiqueta(p.status)" :tono="tono(p.status)" /></td>
							</tr>
						</tbody>
					</table>
				</div>
			</edu-card>
		</template>
	</div>`,
};
