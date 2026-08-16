<?php
/**
 * Versione dell'API di core e guardia dei componenti dipendenti, righe C-72,
 * C-74 e C-75 del catalogo.
 *
 * L'intestazione `Requires Plugins` di WordPress 6.5 accetta solo slug, non
 * vincoli di versione: dichiarare la dipendenza non basta a garantire che la
 * versione trovata sia quella attesa. Il controllo è quindi a runtime, e il suo
 * esito negativo deve essere una disattivazione con messaggio leggibile, non un
 * errore fatale su una chiamata a un metodo che in quella versione non esiste.
 *
 * @package Conformita_Core
 */

/**
 * Prove sulla versione di API e sulla guardia di compatibilità.
 */
class Conformita_Core_Dipendenza_Test extends WP_UnitTestCase {

	/**
	 * Percorso del componente finto usato dalle prove.
	 *
	 * @var string
	 */
	const COMPONENTE = 'componente-di-prova/componente-di-prova.php';

	/**
	 * Nome visibile del componente finto.
	 *
	 * @var string
	 */
	const NOME = 'Componente di prova';

	/**
	 * Il componente finto risulta attivo prima di ogni prova.
	 */
	public function set_up() {
		parent::set_up();
		update_option( 'active_plugins', array( self::COMPONENTE ) );
	}

	/**
	 * Elenco corrente dei plugin attivi.
	 *
	 * @return array<int, string>
	 */
	private function plugin_attivi() {
		return (array) get_option( 'active_plugins', array() );
	}

	/**
	 * Testo degli avvisi in bacheca prodotti finora.
	 *
	 * @return string
	 */
	private function avvisi_in_bacheca() {
		ob_start();
		do_action( 'admin_notices' );
		return (string) ob_get_clean();
	}

	/**
	 * La versione di API è esposta, ed è una cosa diversa dalla versione del
	 * plugin: i componenti dipendenti verificano questa, non quella.
	 */
	public function test_versione_api_esposta() {
		$this->assertTrue( defined( 'CONFORMITA_CORE_VERSIONE_API' ) );
		$this->assertSame( CONFORMITA_CORE_VERSIONE_API, conformita_core_versione_api() );
		$this->assertMatchesRegularExpression( '/^\d+\.\d+\.\d+$/', conformita_core_versione_api() );
	}

	/**
	 * C-72 e C-74: la regola di compatibilità.
	 *
	 * Stesso numero maggiore e versione disponibile non inferiore a quella
	 * richiesta. Un numero maggiore diverso è incompatibile in entrambe le
	 * direzioni: verso il basso perché il metodo richiesto può non esserci
	 * ancora, verso l'alto perché può essere stato tolto.
	 *
	 * @dataProvider casi_di_compatibilita
	 *
	 * @param string      $richiesta   Versione richiesta dal componente.
	 * @param string|null $disponibile Versione esposta da core.
	 * @param bool        $atteso      Esito atteso.
	 */
	public function test_c72_c74_regola_di_compatibilita( $richiesta, $disponibile, $atteso ) {
		$this->assertSame( $atteso, conformita_core_api_compatibile( $richiesta, $disponibile ) );
	}

	/**
	 * Casi della regola di compatibilità.
	 *
	 * @return array<string, array<int, mixed>>
	 */
	public function casi_di_compatibilita() {
		return array(
			'versione esatta'           => array( '1.0.0', '1.0.0', true ),
			'richiesta piu corta'       => array( '1.0', '1.0.0', true ),
			'core piu recente'          => array( '1.0', '1.4.2', true ),
			'core troppo vecchio'       => array( '1.5', '1.0.0', false ),
			'maggiore richiesto avanti' => array( '2.0', '1.0.0', false ),
			'maggiore di core avanti'   => array( '1.0', '2.0.0', false ),
			'richiesta vuota'           => array( '', '1.0.0', false ),
			'disponibile vuota'         => array( '1.0', '', false ),
			'disponibile assente'       => array( '1.0', null, false ),
			'richiesta non numerica'    => array( 'ultima', '1.0.0', false ),
		);
	}

	/**
	 * C-72: componente con versione API incompatibile.
	 *
	 * Si disattiva, dice perché, e non lascia nessun errore fatale dietro di sé.
	 */
	public function test_c72_componente_con_versione_api_incompatibile() {
		$avviato = conformita_core_avvia_componente( self::NOME, self::COMPONENTE, '9.0' );

		$this->assertFalse( $avviato, 'Il componente incompatibile non deve avviarsi.' );
		$this->assertNotContains(
			self::COMPONENTE,
			$this->plugin_attivi(),
			'Il componente incompatibile deve risultare disattivato.'
		);

		$avviso = $this->avvisi_in_bacheca();

		$this->assertStringContainsString( self::NOME, $avviso, 'L\'avviso deve dire quale componente.' );
		$this->assertStringContainsString( '9.0', $avviso, 'L\'avviso deve dire la versione richiesta.' );
		$this->assertStringContainsString(
			conformita_core_versione_api(),
			$avviso,
			'L\'avviso deve dire la versione disponibile.'
		);
	}

	/**
	 * C-74: componente con versione API compatibile.
	 *
	 * Resta attivo e non produce nessun avviso: un avviso qui sarebbe rumore che
	 * insegna a ignorare gli avvisi.
	 */
	public function test_c74_componente_con_versione_api_compatibile() {
		$avviato = conformita_core_avvia_componente( self::NOME, self::COMPONENTE, conformita_core_versione_api() );

		$this->assertTrue( $avviato );
		$this->assertContains( self::COMPONENTE, $this->plugin_attivi() );
		$this->assertStringNotContainsString( self::NOME, $this->avvisi_in_bacheca() );
	}

	/**
	 * C-75: guardia invocata senza versione API disponibile.
	 *
	 * Non è un caso di scuola: è la condizione in cui la guardia gira mentre core
	 * non ha esposto la sua versione. Deve dare un errore leggibile, non un
	 * confronto fra una stringa e il nulla.
	 */
	public function test_c75_guardia_senza_versione_api_disponibile() {
		$esito = Conformita_Core_Dipendenza::verifica_componente( self::NOME, '1.0', null );

		$this->assertWPError( $esito );
		$this->assertSame( 'conformita_core_api_non_disponibile', $esito->get_error_code() );
		$this->assertStringContainsString( self::NOME, $esito->get_error_message() );
	}

	/**
	 * C-72: l'errore della verifica descrive entrambe le versioni.
	 */
	public function test_c72_errore_di_verifica_leggibile() {
		$esito = Conformita_Core_Dipendenza::verifica_componente( self::NOME, '9.0', conformita_core_versione_api() );

		$this->assertWPError( $esito );
		$this->assertSame( 'conformita_core_api_incompatibile', $esito->get_error_code() );
		$this->assertStringContainsString( '9.0', $esito->get_error_message() );
		$this->assertStringContainsString( conformita_core_versione_api(), $esito->get_error_message() );
	}

	/**
	 * C-74: la verifica compatibile non produce errori.
	 */
	public function test_c74_verifica_compatibile() {
		$this->assertTrue(
			Conformita_Core_Dipendenza::verifica_componente( self::NOME, '1.0', '1.2.0' )
		);
	}
}
