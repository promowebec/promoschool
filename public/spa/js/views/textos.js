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
		<edu-loading v-if="cargando" texto="Cargando tus libros…" />
		<edu-error v-else-if="error" :error="error" @retry="cargar" />
		<edu-empty v-else-if="vacio" texto="Todavía no tienes textos asignados." />

		<edu-card v-else>
			<div class="edu-textos" v-html="html"></div>
		</edu-card>
	</div>`,
};
