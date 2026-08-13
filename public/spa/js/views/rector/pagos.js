/**
 * Pensiones y matrículas desde el rectorado.
 *
 * Invariante que la pantalla respeta: **un pago solo se marca como pagado con
 * confirmación del servidor**. El cobro en línea sigue el flujo de Payphone;
 * aquí solo está el registro manual, que exige permiso de rectorado y queda
 * auditado.
 */

import { eduApi } from '@edu/api.js';
import { store } from '@edu/store.js';

export const VistaRectorPagos = {
	data: () => ( {
		cargando: true,
		trabajando: null,
		error: null,
		aviso: '',
		pagos: [],
		periodId: null,
		estado: '',

		/** Pago en proceso de registro manual. */
		registrando: null,
		metodo: 'transfer',
		referencia: '',

		enlace: '',
	} ),

	computed: {
		periodos() {
			return store.me?.active_period ? [ store.me.active_period ] : [];
		},

		visibles() {
			return this.estado ? this.pagos.filter( ( p ) => p.status === this.estado ) : this.pagos;
		},

		resumen() {
			const r = { pending: 0, paid: 0, overdue: 0, waived: 0 };
			this.pagos.forEach( ( p ) => {
				if ( r[ p.status ] !== undefined ) r[ p.status ]++;
			} );
			return r;
		},

		porCobrar() {
			return this.pagos
				.filter( ( p ) => [ 'pending', 'overdue' ].includes( p.status ) )
				.reduce( ( t, p ) => t + Number( p.amount || 0 ), 0 );
		},
	},

	mounted() {
		this.periodId = store.me?.active_period?.id || null;
		this.cargar();
	},

	methods: {
		async cargar() {
			this.cargando = true;
			this.error = null;

			try {
				this.pagos = await eduApi.get(
					'/payments',
					this.periodId ? { period_id: this.periodId } : {}
				);
			} catch ( e ) {
				this.error = e;
			} finally {
				this.cargando = false;
			}
		},

		async generarMes() {
			await this.accion( 'generar', () => eduApi.post( '/payments/generate-monthly', { period_id: this.periodId } ),
				( r ) => `Se generaron ${ r.created } cuota(s).` );
		},

		abrirRegistro( pago ) {
			this.registrando = pago.id;
			this.metodo = 'transfer';
			this.referencia = '';
			this.aviso = '';
		},

		async registrarManual( pago ) {
			await this.accion( 'm' + pago.id,
				() => eduApi.post( `/payments/${ pago.id }/manual`, {
					payment_method: this.metodo,
					payment_ref: this.referencia,
				} ),
				() => {
					this.registrando = null;
					return 'Pago registrado.';
				}
			);
		},

		async exonerar( pago ) {
			await this.accion( 'w' + pago.id,
				() => eduApi.post( `/payments/${ pago.id }/waive` ),
				() => 'Pago exonerado.' );
		},

		async generarEnlace( pago ) {
			this.enlace = '';
			await this.accion( 'l' + pago.id,
				() => eduApi.post( `/payments/${ pago.id }/link` ),
				( r ) => {
					this.enlace = r.url;
					return 'Enlace de pago generado.';
				},
				false
			);
		},

		async accion( clave, fn, mensaje, recargar = true ) {
			if ( this.trabajando ) return;

			this.trabajando = clave;
			this.error = null;
			this.aviso = '';

			try {
				const r = await fn();
				if ( recargar ) await this.cargar();
				this.aviso = mensaje( r );
			} catch ( e ) {
				this.error = e;
			} finally {
				this.trabajando = null;
			}
		},

		etiqueta( estado ) {
			return { pending: 'Pendiente', paid: 'Pagado', overdue: 'Vencido', waived: 'Exonerado' }[ estado ] || estado;
		},

		tono( estado ) {
			return { pending: 'aviso', paid: 'ok', overdue: 'malo', waived: 'info' }[ estado ] || 'neutro';
		},

		dinero( v ) {
			return '$' + Number( v || 0 ).toFixed( 2 );
		},

		editable( pago ) {
			return [ 'pending', 'overdue' ].includes( pago.status );
		},
	},

	template: `
	<div>
		<div class="edu-content-header">
			<h2 class="edu-page-title">Pagos</h2>
			<p class="edu-page-sub">Pensiones y matrículas del período.</p>
		</div>

		<div class="edu-selector-curso">
			<label>
				<span>Período</span>
				<select v-model="periodId" class="edu-select" @change="cargar">
					<option v-for="p in periodos" :key="p.id" :value="p.id">{{ p.name }}</option>
				</select>
			</label>
			<label>
				<span>Estado</span>
				<select v-model="estado" class="edu-select">
					<option value="">Todos</option>
					<option value="pending">Pendientes</option>
					<option value="overdue">Vencidos</option>
					<option value="paid">Pagados</option>
					<option value="waived">Exonerados</option>
				</select>
			</label>
			<button class="edu-btn" :disabled="trabajando === 'generar'" @click="generarMes">
				{{ trabajando === 'generar' ? 'Generando…' : 'Generar cuotas del mes' }}
			</button>
		</div>

		<div v-if="aviso" class="edu-aviso edu-aviso-ok">{{ aviso }}</div>

		<div v-if="enlace" class="edu-aviso edu-aviso-parcial">
			<p><strong>Enlace de pago</strong> — compártelo con el representante:</p>
			<p><code class="edu-enlace">{{ enlace }}</code></p>
		</div>

		<edu-error v-if="error" :error="error" @retry="cargar" />
		<edu-loading v-if="cargando" texto="Cargando pagos…" />

		<template v-else>
			<div class="edu-stats-row">
				<edu-stat label="Por cobrar" :value="dinero(porCobrar)"
				          :color="porCobrar > 0 ? '#d97706' : '#059669'" />
				<edu-stat label="Pendientes" :value="resumen.pending" />
				<edu-stat label="Vencidos" :value="resumen.overdue"
				          :color="resumen.overdue ? '#dc2626' : '#1e293b'" />
				<edu-stat label="Pagados" :value="resumen.paid" color="#059669" />
			</div>

			<edu-card>
				<edu-empty v-if="!visibles.length" texto="No hay pagos con este filtro." />
				<div v-else class="edu-table-wrap">
					<table class="edu-table">
						<thead>
							<tr>
								<th class="edu-col-alumno">Estudiante</th>
								<th>Grado</th>
								<th>Concepto</th>
								<th>Mes</th>
								<th class="edu-th-num">Valor</th>
								<th>Estado</th>
								<th></th>
							</tr>
						</thead>
						<tbody>
							<template v-for="p in visibles" :key="p.id">
								<tr>
									<td class="edu-col-alumno">{{ p.apellidos }} {{ p.nombres }}</td>
									<td class="edu-muted">{{ p.grade_name }}</td>
									<td>{{ p.concept === 'matricula' ? 'Matrícula' : 'Pensión' }}</td>
									<td>{{ p.month }}</td>
									<td class="edu-td-num">{{ dinero(p.amount) }}</td>
									<td><edu-badge :texto="etiqueta(p.status)" :tono="tono(p.status)" /></td>
									<td class="edu-td-right edu-acciones">
										<template v-if="editable(p)">
											<button class="edu-btn" @click="abrirRegistro(p)">Registrar pago</button>
											<button class="edu-btn" :disabled="trabajando === 'l' + p.id"
											        @click="generarEnlace(p)">Enlace</button>
											<button class="edu-btn" :disabled="trabajando === 'w' + p.id"
											        @click="exonerar(p)">Exonerar</button>
										</template>
										<span v-else class="edu-muted edu-small">{{ p.payment_method || '—' }}</span>
									</td>
								</tr>

								<tr v-if="registrando === p.id">
									<td colspan="7">
										<div class="edu-fila-form">
											<select class="edu-select edu-auto" v-model="metodo">
												<option value="transfer">Transferencia</option>
												<option value="manual">Efectivo</option>
												<option value="check">Cheque</option>
											</select>
											<input class="edu-input" type="text" placeholder="Referencia o comprobante"
											       v-model="referencia">
											<button class="edu-btn" @click="registrando = null">Cancelar</button>
											<button class="edu-btn edu-btn-primary"
											        :disabled="trabajando === 'm' + p.id"
											        @click="registrarManual(p)">
												{{ trabajando === 'm' + p.id ? 'Registrando…' : 'Confirmar pago' }}
											</button>
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
