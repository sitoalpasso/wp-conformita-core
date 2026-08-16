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

	/**
	 * La cartella in cui il plugin è montato corrisponde allo slug dichiarato.
	 *
	 * Dentro il contenitore questo è il percorso di montaggio, non il nome del
	 * clone: se il montaggio tornasse a seguire il nome del repository, il
	 * plugin girerebbe con uno slug diverso da quello pubblicato e la cosa non
	 * darebbe nessun altro segnale.
	 */
	public function test_cartella_del_plugin_corrisponde_allo_slug() {
		$this->assertSame( 'conformita-core', basename( dirname( __DIR__ ) ) );
	}

	/**
	 * Il file principale del plugin è stato caricato dall'avvio della suite.
	 *
	 * Chiude l'altro modo di avere test verdi e vuoti: una suite che gira su un
	 * WordPress dove il plugin non è mai stato incluso.
	 */
	public function test_file_principale_del_plugin_caricato() {
		$principale = realpath( dirname( __DIR__ ) . '/conformita-core.php' );

		$this->assertNotFalse( $principale, 'File principale del plugin non trovato.' );
		$this->assertContains(
			$principale,
			get_included_files(),
			'Il file principale non risulta caricato: la suite girerebbe senza il plugin.'
		);
	}
}
