/**
 * Descarga de boletines.
 *
 * El PDF no viaja en la respuesta: la API valida el permiso y devuelve una URL
 * firmada que caduca en 5 minutos y solo sirve para quien la pidió. Por eso el
 * enlace se pide en el momento del clic y se abre enseguida.
 */

import { eduApi } from '@edu/api.js';
import { store, currentStudent } from '@edu/store.js';

export const VistaBoletines = {
	data: () => ( {
		cargando: true,
		error: null,
		periodos: [],
		generando: null,
	} ),

	computed: {
		estudiante() {
			return currentStudent.value;
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
				this.periodos = await eduApi.get( '/periods' );
			} catch ( e ) {
				this.error = e;
			} finally {
				this.cargando = false;
			}
		},

		async descargar( periodo ) {
			if ( this.generando ) return;

			this.generando = periodo.id;
			this.error = null;

			try {
				const r = await eduApi.get( '/reports/boletin', {
					student_id: store.studentId,
					period_id: periodo.id,
				} );

				// La URL firmada dura poco: se abre de inmediato.
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
			<h2 class="edu-page-title">Boletines</h2>
			<p class="edu-page-sub" v-if="estudiante">
				{{ estudiante.nombres }} {{ estudiante.apellidos }}
			</p>
		</div>

		<edu-loading v-if="cargando" texto="Cargando períodos…" />
		<edu-error v-else-if="error" :error="error" @retry="cargar" />
		<edu-empty v-else-if="!periodos.length" texto="No hay períodos lectivos registrados." />

		<edu-card v-else>
			<div class="edu-table-wrap">
				<table class="edu-table">
					<thead>
						<tr><th>Período</th><th>Fechas</th><th></th></tr>
					</thead>
					<tbody>
						<tr v-for="p in periodos" :key="p.id">
							<td>
								<strong>{{ p.name }}</strong>
								<edu-badge v-if="p.is_active" texto="Activo" tono="ok" />
							</td>
							<td class="edu-muted">{{ p.start_date }} — {{ p.end_date }}</td>
							<td class="edu-td-right">
								<button class="edu-btn edu-btn-primary"
								        :disabled="generando === p.id"
								        @click="descargar(p)">
									{{ generando === p.id ? 'Generando…' : 'Descargar PDF' }}
								</button>
							</td>
						</tr>
					</tbody>
				</table>
			</div>
			<p class="edu-muted edu-small">
				El enlace de descarga es personal y caduca a los 5 minutos.
			</p>
		</edu-card>
	</div>`,
};
