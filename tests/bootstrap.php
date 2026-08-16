<?php
/**
 * Avvio della suite di test sulla suite di test di WordPress.
 *
 * @package Conformita_Core
 */

$conformita_core_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $conformita_core_tests_dir ) {
	$conformita_core_tests_dir = '/wordpress-phpunit';
}

$conformita_core_tests_dir = rtrim( $conformita_core_tests_dir, '/\\' );

if ( ! file_exists( $conformita_core_tests_dir . '/includes/functions.php' ) ) {
	// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- WP_Filesystem non esiste: qui WordPress non e ancora caricato, ed e proprio la condizione segnalata.
	fwrite( STDERR, 'Suite di test di WordPress non trovata in ' . $conformita_core_tests_dir . ".\n" );
	fwrite( STDERR, "Avviare l'ambiente con `npx wp-env start` oppure valorizzare WP_TESTS_DIR.\n" );
	// phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
	exit( 1 );
}

require_once $conformita_core_tests_dir . '/includes/functions.php';

/**
 * Carica il file principale del plugin dentro l'installazione di prova.
 *
 * Senza questo passaggio la suite gira su un WordPress in cui il plugin non
 * esiste: i test resterebbero verdi non perché il codice funziona, ma perché
 * non c'è niente da verificare. È il difetto che non produce nessun segnale.
 */
function conformita_core_carica_plugin() {
	require dirname( __DIR__ ) . '/conformita-core.php';
}

tests_add_filter( 'muplugins_loaded', 'conformita_core_carica_plugin' );

require $conformita_core_tests_dir . '/includes/bootstrap.php';
