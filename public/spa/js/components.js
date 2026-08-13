/**
 * Piezas de interfaz reutilizables.
 *
 * Se apoyan en las clases de public/css/portales.css para que la app se vea
 * igual que los portales que reemplaza.
 */

import { formatScore } from '@edu/store.js';

/** Estado de carga. */
export const EduLoading = {
	props: { texto: { type: String, default: 'Cargando…' } },
	template: `<div class="edu-spa-loading"><span class="edu-spa-spinner"></span> {{ texto }}</div>`,
};

/** Error legible, con opción de reintentar. */
export const EduError = {
	props: { error: Object },
	emits: [ 'retry' ],
	computed: {
		mensaje() {
			return this.error?.message || 'Ocurrió un error inesperado.';
		},
		expirada() {
			return !! this.error?.isSessionExpired;
		},
	},
	methods: {
		recargar() {
			window.location.reload();
		},
	},
	template: `
		<div class="edu-spa-error">
			<p>{{ mensaje }}</p>
			<p v-if="expirada">
				<button class="edu-btn edu-btn-primary" @click="recargar">Recargar la página</button>
			</p>
			<p v-else>
				<button class="edu-btn" @click="$emit('retry')">Reintentar</button>
			</p>
		</div>`,
};

/** Aviso de que no hay nada que mostrar. */
export const EduEmpty = {
	props: { texto: { type: String, default: 'No hay nada que mostrar todavía.' } },
	template: `<div class="edu-spa-empty">{{ texto }}</div>`,
};

/** Tarjeta con título. */
export const EduCard = {
	props: { titulo: String },
	template: `
		<div class="edu-card">
			<h3 v-if="titulo" class="edu-card-title">{{ titulo }}</h3>
			<slot></slot>
		</div>`,
};

/** Métrica grande de la fila superior. */
export const EduStat = {
	props: {
		label: String,
		value: [ String, Number ],
		sub: String,
		color: { type: String, default: '#1e293b' },
	},
	template: `
		<div class="edu-stat">
			<div class="edu-stat-label">{{ label }}</div>
			<div class="edu-stat-value" :style="{ color }">{{ value }}</div>
			<div v-if="sub" class="edu-stat-sub">{{ sub }}</div>
		</div>`,
};

/**
 * Nota con su equivalencia cualitativa.
 *
 * El código y el color vienen calculados del servidor: la escala del
 * Instructivo 2025 no se reimplementa aquí a propósito.
 */
export const EduNota = {
	props: {
		score: { type: [ Number, String ], default: null },
		cualitativa: { type: Object, default: null },
	},
	computed: {
		texto() {
			return formatScore( this.score );
		},
	},
	template: `
		<span class="edu-nota">
			<strong>{{ texto }}</strong>
			<span v-if="cualitativa"
			      class="edu-cuali"
			      :style="{ background: cualitativa.color }">{{ cualitativa.codigo }}</span>
		</span>`,
};

/** Etiqueta de estado con color. */
export const EduBadge = {
	props: {
		texto: String,
		tono: { type: String, default: 'neutro' },
	},
	template: `<span class="edu-badge" :class="'edu-badge-' + tono">{{ texto }}</span>`,
};

export const componentes = {
	EduLoading,
	EduError,
	EduEmpty,
	EduCard,
	EduStat,
	EduNota,
	EduBadge,
};
