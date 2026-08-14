/**
 * Bandeja de comunicados del usuario, con acuse de recibo.
 *
 * El cuerpo llega ya pasado por wp_kses_post() en el servidor, así que se
 * inserta con v-html sin volver a sanear en el cliente.
 */

import { eduApi } from '@edu/api.js';
import { formatDate } from '@edu/store.js';

export const VistaComunicados = {
	data: () => ( {
		cargando: true,
		error: null,
		lista: [],
		abierto: null,
		confirmando: null,
	} ),

	computed: {
		sinLeer() {
			return this.lista.filter( ( c ) => ! c.is_read ).length;
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
				this.lista = await eduApi.get( '/me/announcements' );
			} catch ( e ) {
				this.error = e;
			} finally {
				this.cargando = false;
			}
		},

		abrir( c ) {
			this.abierto = this.abierto === c.id ? null : c.id;
		},

		async acusar( c ) {
			if ( c.is_read || this.confirmando ) return;

			this.confirmando = c.id;

			try {
				await eduApi.post( `/announcements/${ c.id }/acknowledge` );
				c.is_read = true;
				c.read_at = new Date().toISOString();
			} catch ( e ) {
				this.error = e;
			} finally {
				this.confirmando = null;
			}
		},

		formatDate,
	},

	template: `
	<div>
		<div class="edu-content-header">
			<h2 class="edu-page-title">Comunicados</h2>
			<p class="edu-page-sub">
				<template v-if="sinLeer">{{ sinLeer }} sin leer</template>
				<template v-else>Todo al día</template>
			</p>
		</div>

		<edu-loading v-if="cargando" texto="Cargando comunicados…" />
		<edu-error v-else-if="error" :error="error" @retry="cargar" />
		<edu-empty v-else-if="!lista.length" texto="No tienes comunicados." />

		<div v-else class="edu-spa-comunicados">
			<div v-for="c in lista" :key="c.id"
			     class="edu-card edu-comunicado"
			     :class="{ 'no-leido': !c.is_read }">

				<div class="edu-comunicado-head" @click="abrir(c)">
					<div>
						<strong>{{ c.title }}</strong>
						<div class="edu-muted edu-small">{{ formatDate(c.sent_at, true) }}</div>
					</div>
					<edu-badge v-if="!c.is_read" texto="Nuevo" tono="info" />
				</div>

				<div v-if="abierto === c.id" class="edu-comunicado-body">
					<div v-html="c.body"></div>

					<p v-if="!c.is_read">
						<button class="edu-btn edu-btn-primary"
						        :disabled="confirmando === c.id"
						        @click.stop="acusar(c)">
							{{ confirmando === c.id ? 'Confirmando…' : 'Confirmar que lo leí' }}
						</button>
					</p>
					<p v-else class="edu-muted edu-small">
						Confirmado el {{ formatDate(c.read_at, true) }}
					</p>
				</div>
			</div>
		</div>
	</div>`,
};
