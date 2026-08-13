/**
 * Comunicados del docente: enviar a un grado o a un estudiante, y ver los
 * enviados con cuántos acusaron recibo.
 *
 * El envío por WhatsApp se encola en el servidor, por eso la respuesta es
 * "encolado" y no "enviado".
 */

import { eduApi } from '@edu/api.js';
import { profile, formatDate } from '@edu/store.js';

export const VistaDocenteComunicados = {
	data: () => ( {
		cargando: true,
		enviando: false,
		error: null,
		aviso: '',
		enviados: [],

		gradeId: null,
		alcance: 'grade',
		studentId: null,
		estudiantes: [],
		titulo: '',
		cuerpo: '',
		porEmail: false,
	} ),

	computed: {
		grados() {
			const vistos = new Map();

			( profile.value.assignments || [] ).forEach( ( a ) => {
				if ( ! vistos.has( a.grade_id ) ) {
					vistos.set( a.grade_id, { id: a.grade_id, nombre: a.grade_name } );
				}
			} );

			return [ ...vistos.values() ];
		},

		puedeEnviar() {
			return this.titulo.trim() &&
				this.gradeId &&
				( 'grade' === this.alcance || this.studentId );
		},
	},

	watch: {
		async gradeId() {
			this.studentId = null;
			await this.cargarEstudiantes();
		},
	},

	async mounted() {
		this.gradeId = this.grados[ 0 ]?.id || null;
		await Promise.all( [ this.cargar(), this.cargarEstudiantes() ] );
	},

	methods: {
		async cargar() {
			this.cargando = true;
			this.error = null;

			try {
				this.enviados = await eduApi.get( '/announcements' );
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

		async enviar() {
			if ( ! this.puedeEnviar || this.enviando ) return;

			this.enviando = true;
			this.error = null;
			this.aviso = '';

			try {
				const r = await eduApi.post( '/announcements', {
					scope: this.alcance,
					target_grade_id: this.gradeId,
					target_student_id: 'student' === this.alcance ? this.studentId : 0,
					title: this.titulo,
					body: this.cuerpo,
					send_email: this.porEmail,
				} );

				this.aviso = `Encolado para ${ r.recipients } destinatario(s).`;
				this.titulo = '';
				this.cuerpo = '';
				await this.cargar();
			} catch ( e ) {
				this.error = e;
			} finally {
				this.enviando = false;
			}
		},

		formatDate,
	},

	template: `
	<div>
		<div class="edu-content-header">
			<h2 class="edu-page-title">Comunicados</h2>
			<p class="edu-page-sub">Llegan al estudiante y a sus representantes.</p>
		</div>

		<div v-if="aviso" class="edu-aviso edu-aviso-ok">{{ aviso }}</div>
		<edu-error v-if="error" :error="error" @retry="cargar" />

		<edu-card titulo="Nuevo comunicado">
			<div class="edu-form">
				<div class="edu-form-fila">
					<label>
						<span>Grado</span>
						<select class="edu-select" v-model="gradeId">
							<option v-for="g in grados" :key="g.id" :value="g.id">{{ g.nombre }}</option>
						</select>
					</label>
					<label>
						<span>Para</span>
						<select class="edu-select" v-model="alcance">
							<option value="grade">Todo el grado</option>
							<option value="student">Un estudiante</option>
						</select>
					</label>
				</div>

				<label v-if="alcance === 'student'">
					<span>Estudiante</span>
					<select class="edu-select" v-model="studentId">
						<option :value="null">Elige un estudiante…</option>
						<option v-for="e in estudiantes" :key="e.id" :value="e.id">
							{{ e.apellidos }} {{ e.nombres }}
						</option>
					</select>
				</label>

				<label>
					<span>Asunto</span>
					<input class="edu-input" type="text" v-model="titulo"
					       placeholder="Salida pedagógica del viernes">
				</label>

				<label>
					<span>Mensaje</span>
					<textarea class="edu-input" rows="4" v-model="cuerpo"
					          placeholder="Escribe el comunicado…"></textarea>
				</label>

				<label class="edu-check">
					<input type="checkbox" v-model="porEmail">
					<span>Enviar también por correo</span>
				</label>

				<div class="edu-barra-guardar">
					<button class="edu-btn edu-btn-primary"
					        :disabled="!puedeEnviar || enviando"
					        @click="enviar">
						{{ enviando ? 'Enviando…' : 'Enviar comunicado' }}
					</button>
				</div>
			</div>
		</edu-card>

		<edu-loading v-if="cargando" texto="Cargando enviados…" />

		<edu-card v-else titulo="Enviados">
			<edu-empty v-if="!enviados.length" texto="Todavía no has enviado comunicados." />
			<div v-else class="edu-table-wrap">
				<table class="edu-table">
					<thead>
						<tr>
							<th>Asunto</th>
							<th>Enviado</th>
							<th class="edu-th-num">Leídos</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="c in enviados" :key="c.id">
							<td>{{ c.title }}</td>
							<td class="edu-muted edu-small">{{ formatDate(c.sent_at, true) }}</td>
							<td class="edu-td-num">
								{{ c.recipients_read }} / {{ c.recipients_total }}
							</td>
						</tr>
					</tbody>
				</table>
			</div>
		</edu-card>
	</div>`,
};
