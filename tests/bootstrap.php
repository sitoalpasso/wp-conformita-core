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
	echo "Suite di test di WordPress non trovata in {$conformita_core_tests_dir}.\n";
	echo "Avviare l'ambiente con `npx wp-env start` oppure valorizzare WP_TESTS_DIR.\n";
	exit( 1 );
}

require_once $conformita_core_tests_dir . '/includes/functions.php';
require $conformita_core_tests_dir . '/includes/bootstrap.php';
