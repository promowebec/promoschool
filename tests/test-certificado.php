<?php
/**
 * Certificado de promoción: quién tiene derecho, quién puede emitirlo y que el
 * PDF salga.
 *
 * Crea su propio colegio (institución, período con 3 trimestres, grado, tres
 * materias, tres estudiantes con nombres inventados) y lo borra al terminar.
 *
 *   A — todo cerrado y aprobado (una materia en supletorio) → certificado.
 *   B — una materia con solo dos trimestres cerrados          → year_open.
 *   C — una materia en supletorio sin rendir                  → not_promoted.
 *
 * La tercera materia no la tiene calificada nadie del grado: no debe bloquear.
 *
 * @package SistemaEducativo
 */

require __DIR__ . '/bootstrap.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

global $wpdb;
$p = $wpdb->prefix . 'edu_';

$admin = edu_test_admin_id();
if ( ! $admin ) {
	edu_test_skip( 'no hay administrador' );
}

/* ── Fixture ─────────────────────────────────────────────────────────────── */

$creado = array( 'users' => array(), 'students' => array(), 'subjects' => array(), 'trims' => array() );

edu_test_cleanup(
	function () use ( $wpdb, $p, &$creado ) {
		$in = function ( $ids ) {
			return implode( ',', array_map( 'intval', $ids ? $ids : array( 0 ) ) );
		};
		$st = $in( $creado['students'] );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- listas de enteros.
		$wpdb->query( "DELETE FROM {$p}trimester_scores WHERE student_id IN ($st)" );
		$wpdb->query( "DELETE FROM {$p}year_scores WHERE student_id IN ($st)" );
		$wpdb->query( "DELETE FROM {$p}audit WHERE entity_type = 'student' AND entity_id IN ($st)" );
		$wpdb->query( "DELETE FROM {$p}students WHERE id IN ($st)" );
		$wpdb->query( "DELETE FROM {$p}grade_subjects WHERE subject_id IN (" . $in( $creado['subjects'] ) . ')' );
		$wpdb->query( "DELETE FROM {$p}subjects WHERE id IN (" . $in( $creado['subjects'] ) . ')' );
		$wpdb->query( "DELETE FROM {$p}trimesters WHERE id IN (" . $in( $creado['trims'] ) . ')' );
		// phpcs:enable
		foreach ( array( 'grade' => 'grades', 'period' => 'periods' ) as $k => $tabla ) {
			if ( ! empty( $creado[ $k ] ) ) {
				$wpdb->delete( $p . $tabla, array( 'id' => $creado[ $k ] ) );
			}
		}
		foreach ( array( 'inst', 'inst2' ) as $k ) {
			if ( ! empty( $creado[ $k ] ) ) {
				$wpdb->query( $wpdb->prepare( "DELETE FROM {$p}audit WHERE entity_type = 'institution' AND entity_id = %d", $creado[ $k ] ) );
				$wpdb->delete( $p . 'institutions', array( 'id' => $creado[ $k ] ) );
				$dir = Edu_File_Service::ensure_protected_dir() . 'certificados/' . (int) $creado[ $k ] . '/';
				foreach ( (array) glob( $dir . '*' ) as $f ) {
					wp_delete_file( $f );
				}
				if ( is_dir( $dir ) ) {
					rmdir( $dir );
				}
			}
		}
		foreach ( $creado['users'] as $uid ) {
			wp_delete_user( $uid );
		}
	}
);

$sufijo = wp_generate_password( 6, false );

$wpdb->insert( $p . 'institutions', array(
	'name'           => 'Unidad Educativa Prueba Certificado ' . $sufijo,
	'regime'         => 'sierra',
	'amie_code'      => '17H99999',
	'city'           => 'Quito',
	'rector_name'    => 'Rector de Prueba',
	'secretary_name' => 'Secretaria de Prueba',
) );
$creado['inst'] = $inst = (int) $wpdb->insert_id;

$wpdb->insert( $p . 'institutions', array( 'name' => 'Otra Institución Prueba ' . $sufijo, 'regime' => 'sierra' ) );
$creado['inst2'] = (int) $wpdb->insert_id;

$wpdb->insert( $p . 'periods', array(
	'institution_id' => $inst,
	'name'           => '2025-2026',
	'regime'         => 'sierra',
	'start_date'     => '2025-09-01',
	'end_date'       => '2026-07-01',
	'is_active'      => 0,
) );
$creado['period'] = $period = (int) $wpdb->insert_id;

foreach ( array( 1, 2, 3 ) as $n ) {
	$wpdb->insert( $p . 'trimesters', array( 'period_id' => $period, 'number' => $n, 'start_date' => '2025-09-01', 'end_date' => '2026-07-01' ) );
	$creado['trims'][ $n ] = (int) $wpdb->insert_id;
}

$wpdb->insert( $p . 'grades', array(
	'institution_id'  => $inst,
	'level'           => 'basica',
	'sub_level'       => 'media',
	'name'            => '5to EGB',
	'paralelo'        => 'A',
	'next_grade_name' => 'Sexto Grado de Educación General Básica',
) );
$creado['grade'] = $grade = (int) $wpdb->insert_id;

foreach ( array( 'Matemática', 'Lengua y Literatura', 'Acompañamiento integral' ) as $nombre ) {
	$wpdb->insert( $p . 'subjects', array( 'institution_id' => $inst, 'name' => $nombre . ' ' . $sufijo ) );
	$sid                  = (int) $wpdb->insert_id;
	$creado['subjects'][] = $sid;
	$wpdb->insert( $p . 'grade_subjects', array( 'grade_id' => $grade, 'subject_id' => $sid ) );
}
list( $mat, $len ) = $creado['subjects'];

$nuevo_usuario = function ( $login, $rol, $nombre = '', $apellido = '' ) use ( &$creado ) {
	$uid = wp_insert_user( array(
		'user_login' => $login,
		'user_pass'  => wp_generate_password(),
		'user_email' => $login . '@prueba.invalid',
		'role'       => $rol,
		'first_name' => $nombre,
		'last_name'  => $apellido,
	) );
	$creado['users'][] = $uid;
	return $uid;
};

$estudiante = function ( $letra, $sexo ) use ( $wpdb, $p, $grade, $sufijo, $nuevo_usuario, &$creado ) {
	$uid = $nuevo_usuario( 'cert_' . strtolower( $letra ) . '_' . $sufijo, 'edu_estudiante', 'Estudiante' . $letra, 'Prueba' );
	$wpdb->insert( $p . 'students', array( 'user_id' => $uid, 'grade_id' => $grade, 'sexo' => $sexo, 'status' => 'active' ) );
	$id                   = (int) $wpdb->insert_id;
	$creado['students'][] = $id;
	return $id;
};

/** Notas de una materia: trimestres cerrados y fila anual. */
$notas = function ( $student, $subject, $cerrados, $promedio, $status, $supletorio = null ) use ( $wpdb, $p, $period, &$creado ) {
	foreach ( array( 1, 2, 3 ) as $n ) {
		$wpdb->insert( $p . 'trimester_scores', array(
			'student_id'     => $student,
			'subject_id'     => $subject,
			'trimester_id'   => $creado['trims'][ $n ],
			'computed_score' => $promedio,
			'is_closed'      => $n <= $cerrados ? 1 : 0,
		) );
	}
	$wpdb->insert( $p . 'year_scores', array(
		'student_id'       => $student,
		'subject_id'       => $subject,
		'period_id'        => $period,
		'trim1'            => $promedio,
		'trim2'            => $promedio,
		'trim3'            => $promedio,
		'average'          => $promedio,
		'status'           => $status,
		'supletorio_score' => $supletorio,
	) );
};

$a = $estudiante( 'A', 'F' );
$b = $estudiante( 'B', 'M' );
$c = $estudiante( 'C', 'M' );

$notas( $a, $mat, 3, 8.50, 'aprobado' );
$notas( $a, $len, 3, 6.20, 'aprobado', 7.50 ); // Aprobó en supletorio.
$notas( $b, $mat, 3, 9.00, 'aprobado' );
$notas( $b, $len, 2, 8.00, 'aprobado' );       // Falta cerrar el T3.
$notas( $c, $mat, 3, 9.00, 'aprobado' );
$notas( $c, $len, 3, 5.80, 'supletorio' );     // Supletorio sin rendir.

edu_test_as( $admin, $inst );

/* ── Derecho al certificado ──────────────────────────────────────────────── */

edu_test_case( 'Quién tiene derecho al certificado' );

$da = Edu_Certificate_Service::datos( $a, $period );
edu_assert_ok( 'A: todo cerrado y aprobado → certificado', $da );

if ( ! is_wp_error( $da ) ) {
	edu_assert( 'A: grado siguiente del grado', 'Sexto Grado de Educación General Básica' === $da['grado_siguiente'] );
	edu_assert( 'A: solo materias calificadas (2 de 3)', 2 === count( $da['materias'] ), count( $da['materias'] ) . ' materias' );
	edu_assert( 'A: promedio general (8.50 + 6.20) / 2', 7.35 === $da['promedio'], (string) $da['promedio'] );
	$len_fila = array_values( array_filter( $da['materias'], fn( $m ) => 6.2 === $m['nota'] ) );
	edu_assert( 'A: anota que aprobó en supletorio', $len_fila && 'Supletorio: 7.50' === $len_fila[0]['observacion'], $len_fila ? $len_fila[0]['observacion'] : '' );
	edu_assert( 'A: cualitativa calculada en el servidor', Edu_Qualitativa_Helper::codigo( 7.35 ) === $da['promedio_cualitativa'], $da['promedio_cualitativa'] );
	edu_assert( 'A: firmantes y AMIE de la institución', 'Rector de Prueba' === $da['rector'] && 'Secretaria de Prueba' === $da['secretario'] && '17H99999' === $da['amie'] );
}

edu_assert_error( 'B: un trimestre abierto → year_open', Edu_Certificate_Service::datos( $b, $period ), 'year_open' );
edu_assert_error( 'C: supletorio sin rendir → not_promoted', Edu_Certificate_Service::datos( $c, $period ), 'not_promoted' );

$wpdb->update( $p . 'grades', array( 'next_grade_name' => null ), array( 'id' => $grade ) );
edu_assert_error( 'sin grado siguiente → no_next_grade', Edu_Certificate_Service::datos( $a, $period ), 'no_next_grade' );
$wpdb->update( $p . 'grades', array( 'next_grade_name' => 'Sexto Grado de Educación General Básica' ), array( 'id' => $grade ) );

$estado = Edu_Certificate_Service::estado_grado( $grade, $period );
$elegibles = is_wp_error( $estado ) ? array() : array_column( array_filter( $estado, fn( $f ) => $f['elegible'] ), 'student_id' );
edu_assert( 'estado del grado: 3 estudiantes, solo A elegible', ! is_wp_error( $estado ) && 3 === count( $estado ) && array( $a ) === $elegibles );

/* ── Quién puede emitirlo ────────────────────────────────────────────────── */

edu_test_case( 'Quién puede emitirlo' );

$docente = $nuevo_usuario( 'cert_doc_' . $sufijo, 'edu_docente' );
update_user_meta( $docente, 'edu_institution_id', $inst );
edu_test_as( $docente, $inst );
edu_assert_error( 'docente: sin permiso', Edu_Certificate_Service::datos( $a, $period ), 'forbidden' );
edu_assert_error( 'docente: tampoco por la API', Edu_Certificate_Service::url( $a, $period ), 'forbidden' );

$rector_otro = $nuevo_usuario( 'cert_rec_' . $sufijo, 'edu_rector' );
update_user_meta( $rector_otro, 'edu_institution_id', $creado['inst2'] );
edu_test_as( $rector_otro, $creado['inst2'] );
edu_assert_error( 'rector de otra institución: fuera de alcance', Edu_Certificate_Service::datos( $a, $period ), 'invalid_scope' );
edu_assert_error( 'rector de otra institución: ni el estado del grado', Edu_Certificate_Service::estado_grado( $grade, $period ), 'invalid_scope' );

$rector = $nuevo_usuario( 'cert_rec2_' . $sufijo, 'edu_rector' );
update_user_meta( $rector, 'edu_institution_id', $inst );
edu_test_as( $rector, $inst );
edu_assert_ok( 'rector de la institución: sí', Edu_Certificate_Service::datos( $a, $period ) );

// Representante: solo de su propio hijo.
$padre = $nuevo_usuario( 'cert_pad_' . $sufijo, 'edu_padre' );
update_user_meta( $padre, 'edu_institution_id', $inst );
$wpdb->insert( $p . 'parents', array( 'user_id' => $padre ) );
$parent_id = (int) $wpdb->insert_id;
$wpdb->insert( $p . 'parent_student', array( 'parent_id' => $parent_id, 'student_id' => $a, 'relationship' => 'madre' ) );
edu_test_cleanup(
	function () use ( $wpdb, $p, $parent_id ) {
		$wpdb->delete( $p . 'parent_student', array( 'parent_id' => $parent_id ) );
		$wpdb->delete( $p . 'parents', array( 'id' => $parent_id ) );
	}
);
edu_test_as( $padre, $inst );
edu_assert_ok( 'representante: el certificado de su hijo', Edu_Certificate_Service::url( $a, $period ) );
edu_assert_error( 'representante: no el de otro estudiante', Edu_Certificate_Service::datos( $b, $period ), 'forbidden' );
edu_assert_error( 'representante: no la lista del grado', Edu_Certificate_Service::estado_grado( $grade, $period ), 'forbidden' );

$est_a = (int) $wpdb->get_var( $wpdb->prepare( "SELECT user_id FROM {$p}students WHERE id = %d", $a ) );
edu_test_as( $est_a, $inst );
edu_assert_error( 'estudiante: ni el suyo', Edu_Certificate_Service::datos( $a, $period ), 'forbidden' );

edu_test_as( $rector, $inst );
$url = Edu_Certificate_Service::url( $a, $period );
edu_assert( 'la API entrega una URL firmada', ! is_wp_error( $url ) && false !== strpos( $url['url'], '/files/download' ) );

add_filter( 'edu_module_active', $apaga = fn( $on, $m ) => 'boletines' === $m ? false : $on, 10, 2 );
edu_assert_error( 'módulo boletines apagado → module_disabled', Edu_Certificate_Service::datos( $a, $period ), 'module_disabled' );
remove_filter( 'edu_module_active', $apaga, 10 );

/* ── Firmas, sello y PDF ─────────────────────────────────────────────────── */

edu_test_case( 'Firmas, sello y PDF' );

$falso = wp_tempnam( 'cert' );
file_put_contents( $falso, '<?php echo "no soy una imagen";' );
edu_assert_error(
	'una imagen falsa se rechaza',
	Edu_Certificate_Service::guardar_imagen( $inst, 'sello', array( 'tmp_name' => $falso, 'error' => UPLOAD_ERR_OK, 'size' => filesize( $falso ) ) ),
	'invalid_image'
);
wp_delete_file( $falso );

edu_test_as( $docente, $inst );
edu_assert_error(
	'el docente no puede subir firmas',
	Edu_Certificate_Service::guardar_imagen( $inst, 'sello', array( 'tmp_name' => 'x', 'error' => UPLOAD_ERR_OK, 'size' => 1 ) ),
	'forbidden'
);
edu_test_as( $rector, $inst );

// Las imágenes se colocan a mano: move_uploaded_file() solo acepta subidas reales.
$png = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==' );
$dir = Edu_File_Service::ensure_protected_dir() . 'certificados/' . $inst . '/';
wp_mkdir_p( $dir );
foreach ( Edu_Certificate_Service::IMAGENES as $tipo ) {
	file_put_contents( $dir . $tipo . '.png', $png );
}
edu_assert( 'la vista previa es data URI, no URL', 0 === strpos( Edu_Certificate_Service::vista_previa( $inst, 'sello' ), 'data:image/png;base64,' ) );

$d = Edu_Certificate_Service::emitir( $a, $period );
edu_assert( 'emitir trae las tres imágenes por ruta de disco', ! is_wp_error( $d ) && $d['sello'] && file_exists( $d['firma_rector'] ) && file_exists( $d['firma_secretario'] ) );

$auditado = (int) $wpdb->get_var( $wpdb->prepare(
	"SELECT COUNT(*) FROM {$p}audit WHERE action = %s AND entity_type = 'student' AND entity_id = %d",
	Edu_Audit::CERTIFICADO_EMITIDO,
	$a
) );
edu_assert( 'la emisión queda en la auditoría', 1 === $auditado, "$auditado filas" );

if ( ! is_wp_error( $d ) ) {
	require_once EDU_PLUGIN_DIR . 'modules/boletines/class-edu-certificado-generator.php';
	$ruta = ( new Edu_Certificado_Generator() )->save( $d );
	$pdf  = (string) file_get_contents( $ruta );
	edu_assert( 'el PDF se genera', 0 === strpos( $pdf, '%PDF' ) && strlen( $pdf ) > 5000, strlen( $pdf ) . ' bytes' );
	wp_delete_file( $ruta );
}

edu_assert_ok( 'quitar una firma', Edu_Certificate_Service::borrar_imagen( $inst, 'firma_secretario' ) );
edu_assert( 'la firma quitada ya no se usa', '' === Edu_Certificate_Service::imagen( $inst, 'firma_secretario' ) );

/* ── Plantilla editable ──────────────────────────────────────────────────── */

edu_test_case( 'Plantilla editable' );

edu_test_cleanup(
	function () use ( &$creado ) {
		delete_option( Edu_Certificate_Service::OPCION_PLANTILLA . (int) $creado['inst'] );
	}
);

edu_assert( 'sin guardar, la plantilla es la de fábrica', Edu_Certificate_Service::plantilla_defecto() === Edu_Certificate_Service::plantilla( $inst ) );

$pl = Edu_Certificate_Service::guardar_plantilla(
	$inst,
	array(
		'titulo'       => 'CERTIFICADO DE PROMOCIÓN ANUAL',
		'introduccion' => "La {institucion} certifica que {estudiante} aprobó.\n<script>alert(1)</script>",
		'promocion'    => '',
		'cargo_rector' => 'DIRECTORA',
		'color'        => 'rojo',
		'mostrar_letras' => '1',
	)
);
edu_assert( 'el título se guarda', ! is_wp_error( $pl ) && 'CERTIFICADO DE PROMOCIÓN ANUAL' === $pl['titulo'] );
edu_assert( 'un texto vacío vuelve al de fábrica', ! is_wp_error( $pl ) && Edu_Certificate_Service::plantilla_defecto()['promocion'] === $pl['promocion'] );
edu_assert( 'el HTML no se guarda', ! is_wp_error( $pl ) && false === strpos( $pl['introduccion'], '<script' ) );
edu_assert( 'un color inválido vuelve al de fábrica', ! is_wp_error( $pl ) && '#1e3a8a' === $pl['color'] );
edu_assert( 'casilla desmarcada = columna oculta', ! is_wp_error( $pl ) && true === $pl['mostrar_letras'] && false === $pl['mostrar_cualitativa'] );

edu_test_as( $docente, $inst );
edu_assert_error( 'el docente no edita la plantilla', Edu_Certificate_Service::guardar_plantilla( $inst, array() ), 'forbidden' );
edu_assert_error( 'ni ve la muestra', Edu_Certificate_Service::muestra( $inst ), 'forbidden' );
edu_test_as( $rector, $inst );

$m = Edu_Certificate_Service::muestra( $inst );
edu_assert( 'la muestra usa la plantilla guardada', ! is_wp_error( $m ) && ! empty( $m['muestra'] ) && 'DIRECTORA' === $m['plantilla']['cargo_rector'] );

$d2 = Edu_Certificate_Service::datos( $a, $period );
edu_assert( 'el certificado real también', ! is_wp_error( $d2 ) && 'CERTIFICADO DE PROMOCIÓN ANUAL' === $d2['plantilla']['titulo'] );

if ( ! is_wp_error( $m ) ) {
	require_once EDU_PLUGIN_DIR . 'modules/boletines/class-edu-certificado-generator.php';
	$ruta = ( new Edu_Certificado_Generator() )->save( $m );
	$pdf  = (string) file_get_contents( $ruta );
	edu_assert( 'la muestra genera PDF', 0 === strpos( $pdf, '%PDF' ), strlen( $pdf ) . ' bytes' );
	wp_delete_file( $ruta );
}

/* ── Nota en letras ──────────────────────────────────────────────────────── */

edu_test_case( 'Nota en letras' );

foreach ( array(
	'8.50' => 'OCHO COMA CINCUENTA',
	'7.05' => 'SIETE COMA CERO CINCO',
	'10'   => 'DIEZ',
	'9.99' => 'NUEVE COMA NOVENTA Y NUEVE',
	'7.2'  => 'SIETE COMA VEINTE',
	'6.16' => 'SEIS COMA DIECISÉIS',
) as $n => $esperado ) {
	$obtenido = Edu_Certificate_Service::en_letras( (float) $n );
	edu_assert( "$n → $esperado", $esperado === $obtenido, $obtenido );
}

edu_test_finish();
