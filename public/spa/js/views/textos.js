/**
 * Mis libros.
 *
 * Los textos de cada persona no los decide la app: los decide el shortcode
 * [mis_textos] del sitio, que ya sabe qué le toca a quién. Esta vista pide el
 * HTML ya montado a GET /me/texts y lo pinta tal cual.
 *
 * Es la única vista que inserta HTML del servidor, y es a propósito. La
 * alternativa —que la app reinterprete el criterio de asignación— haría que
 * tarde o temprano el portal y la app le mostraran cosas distintas al mismo
 * docente. El HTML viene de nuestro propio sitio, no lleva scripts, y cada
 * tarjeta es un enlace a la página de WordPress donde vive el flipbook con su
 * material: la app lleva hasta allí, no reimplementa nada.
 *
 * **El HTML va suelto, sin `<edu-card>` alrededor.** Esa tarjeta define
 * `.edu-card h3`, que con dos componentes de especificidad le gana a la clase
 * `.g22-seccion` del proveedor y le cambiaría el tamaño y los márgenes a los
 * títulos de sección. Además el proveedor ya trae su propio espaciado lateral,
 * así que envolverlo lo duplicaba. Así queda igual que en el portal, que es lo
 * que se busca: la misma pantalla en los dos sitios.
 */

import { eduApi } from '@edu/api.js';

export const VistaTextos = {
	data: () => ( {
		cargando: true,
		error: null,
		html: '',
		vacio: false,
	} ),

	mounted() {
		this.cargar();
	},

	methods: {
		async cargar() {
			this.cargando = true;
			this.error = null;

			try {
				const r = await eduApi.get( '/me/texts' );

				this.html = r.html || '';
				this.vacio = !! r.vacio;
			} catch ( e ) {
				this.error = e;
			} finally {
				this.cargando = false;
			}
		},
	},

	template: `
	<div>
		<div class="edu-content-header">
			<h2 class="edu-page-title">Mis libros</h2>
			<p class="edu-page-sub">Los textos que tienes asignados, con su material.</p>
		</div>

		<edu-loading v-if="cargando" texto="Cargando tus libros…" />
		<edu-error v-else-if="error" :error="error" @retry="cargar" />
		<edu-empty v-else-if="vacio" texto="Todavía no tienes textos asignados." />

		<div v-else class="edu-textos" v-html="html"></div>
	</div>`,
};
