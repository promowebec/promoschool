/**
 * Comunicados institucionales.
 *
 * El rectorado es el único que puede escribir a toda la institución; el resto
 * de alcances (grado y estudiante) también están disponibles.
 */

import { eduApi } from '@edu/api.js';
import { formatDate } from '@edu/store.js';

export const VistaRectorComunicados = {
	data: () => ( {
		cargando: true,
		enviando: false,
		error: null,
		aviso: '',
		enviados: [],
		grados: [],
		estudiantes: [],

		alcance: 'institution',
		gradeId: null,
		studentId: null,
		titulo: '',
		cuerpo: '',
		porEmail: false,
		porWhatsapp: false,
	} ),

	computed: {
		necesitaGrado() {
			return [ 'grade', 'student' ].includes( this.alcance );
		},

		puedeEnviar() {
			if ( ! this.titulo.trim() ) return false;
			if ( this.necesitaGrado && ! this.gradeId ) return false;
			if ( 'student' === this.alcance && ! this.studentId ) return false;
			return true;
		},

		destinatariosAprox() {
			if ( 'student' === this.alcance ) return '1 estudiante y sus representantes';
			if ( 'grade' === this.alcance ) return 'todo el grado y sus representantes';
			return 'toda la institución';
		},
	},

	watch: {
		async gradeId() {
			this.studentId = null;
			await this.cargarEstudiantes();
		},
	},

	async mounted() {
		await Promise.all( [ this.cargar(), this.cargarGrados() ] );
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

		async cargarGrados() {
			try {
				this.grados = await eduApi.get( '/grades' );
				this.gradeId = this.grados[ 0 ]?.id || null;
			} catch ( e ) {
				this.grados = [];
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
					target_grade_id: this.necesitaGrado ? this.gradeId : 0,
					target_student_id: 'student' === this.alcance ? this.studentId : 0,
					title: this.titulo,
					body: this.cuerpo,
					send_email: this.porEmail,
					send_whatsapp: this.porWhatsapp,
				} );

				this.aviso = `Encolado para ${ r.recipients } destinatario(s).`
					+ ( r.emailed ? ` ${ r.emailed } correo(s) enviados.` : '' );
				this.titulo = '';
				this.cuerpo = '';
				await this.cargar();
			} catch ( e ) {
				this.error = e;
			} finally {
				this.enviando = false;
			}
		},

		alcanceTexto( scope ) {
			return { institution: 'Institución', grade: 'Grado', student: 'Estudiante', multi_grade: 'Varios grados' }[ scope ] || scope;
		},

		formatDate,
	},

	template: `
	<div>
		<div class="edu-content-header">
			<h2 class="edu-page-title">Comunicados</h2>
			<p class="edu-page-sub">Llegan a los estudiantes y a sus representantes.</p>
		</div>

		<div v-if="aviso" class="edu-aviso edu-aviso-ok">{{ aviso }}</div>
		<edu-error v-if="error" :error="error" @retry="cargar" />

		<edu-card titulo="Nuevo comunicado">
			<div class="edu-form">
				<div class="edu-form-fila">
					<label>
						<span>Alcance</span>
						<select class="edu-select" v-model="alcance">
							<option value="institution">Toda la institución</option>
							<option value="grade">Un grado</option>
							<option value="student">Un estudiante</option>
						</select>
					</label>
					<label v-if="necesitaGrado">
						<span>Grado</span>
						<select class="edu-select" v-model="gradeId">
							<option v-for="g in grados" :key="g.id" :value="g.id">{{ g.display_name }}</option>
						</select>
					</label>
					<label v-if="alcance === 'student'">
						<span>Estudiante</span>
						<select class="edu-select" v-model="studentId">
							<option :value="null">Elige…</option>
							<option v-for="e in estudiantes" :key="e.id" :value="e.id">
								{{ e.apellidos }} {{ e.nombres }}
							</option>
						</select>
					</label>
				</div>

				<label>
					<span>Asunto</span>
					<input class="edu-input" type="text" v-model="titulo"
					       placeholder="Suspensión de clases del lunes">
				</label>

				<label>
					<span>Mensaje</span>
					<textarea class="edu-input" rows="4" v-model="cuerpo"></textarea>
				</label>

				<div class="edu-form-fila">
					<label class="edu-check">
						<input type="checkbox" v-model="porEmail">
						<span>Enviar también por correo</span>
					</label>
					<label class="edu-check">
						<input type="checkbox" v-model="porWhatsapp">
						<span>Enviar por WhatsApp (si está configurado)</span>
					</label>
				</div>

				<div class="edu-barra-guardar">
					<span class="edu-muted edu-small">Irá a {{ destinatariosAprox }}.</span>
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
			<edu-empty v-if="!enviados.length" texto="Todavía no hay comunicados." />
			<div v-else class="edu-table-wrap">
				<table class="edu-table">
					<thead>
						<tr>
							<th>Asunto</th>
							<th>Alcance</th>
							<th>Enviado</th>
							<th class="edu-th-num">Leídos</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="c in enviados" :key="c.id">
							<td>{{ c.title }}</td>
							<td><edu-badge :texto="alcanceTexto(c.scope)" tono="neutro" /></td>
							<td class="edu-muted edu-small">{{ formatDate(c.sent_at, true) }}</td>
							<td class="edu-td-num">{{ c.recipients_read }} / {{ c.recipients_total }}</td>
						</tr>
					</tbody>
				</table>
			</div>
		</edu-card>
	</div>`,
};
