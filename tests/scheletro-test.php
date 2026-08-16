<?php
/**
 * Verifiche sullo scheletro: la suite gira e le versioni minime dichiarate sono
 * rispettate. Sono i controlli che rendono rosso un ambiente di prova sbagliato,
 * prima che il codice dei meccanismi venga scritto.
 *
 * @package Conformita_Core
 */

/**
 * Classe di prova dello scheletro.
 */
class Conformita_Core_Scheletro_Test extends WP_UnitTestCase {

	/**
	 * La suite di test di WordPress è caricata.
	 */
	public function test_suite_di_wordpress_caricata() {
		$this->assertTrue( defined( 'ABSPATH' ), 'ABSPATH non definita: WordPress non è caricato.' );
		$this->assertTrue( function_exists( 'do_action' ), 'API degli hook non disponibile.' );
	}

	/**
	 * La versione di WordPress non è inferiore al minimo dichiarato.
	 */
	public function test_versione_minima_di_wordpress() {
		$this->assertTrue(
			version_compare( get_bloginfo( 'version' ), '6.5', '>=' ),
			'Versione minima dichiarata di WordPress: 6.5.'
		);
	}

	/**
	 * La versione di PHP non è inferiore al minimo dichiarato.
	 */
	public function test_versione_minima_di_php() {
		$this->assertTrue(
			version_compare( PHP_VERSION, '8.1', '>=' ),
			'Versione minima dichiarata di PHP: 8.1.'
		);
	}

	/**
	 * Il fuso orario si legge dalla configurazione del sito, mai da un valore cablato.
	 */
	public function test_fuso_orario_dal_sito() {
		$this->assertInstanceOf( DateTimeZone::class, wp_timezone() );
	}
}
