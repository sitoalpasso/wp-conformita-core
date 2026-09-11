<?php
/**
 * Il filtro di scadenza nell'interfaccia di pubblicazione remota: riga C-18.
 *
 * La prova chiama davvero il server, non si limita a verificare che l'aggancio
 * risponda come deve: un aggancio giusto su un percorso che non ci passa e' un
 * test verde su un percorso scoperto, ed e' esattamente il difetto che non
 * produce nessun segnale.
 *
 * **Qui si e' scelta fra due letture, ed e' scritto nel diario di cantiere.**
 * Le chiamate che leggono un contenuto da questa interfaccia sono autenticate e
 * pretendono il permesso di modifica, quindi si potrebbe sostenere che meritino
 * l'esenzione dell'amministrazione, dove il contenuto scaduto resta visibile
 * perche' resti correggibile. Si e' scelta la lettura prudente: il costo delle
 * due direzioni non e' simmetrico, perche' chi deve correggere una data va
 * nell'amministrazione, che e' dove andrebbe comunque, mentre non filtrando un
 * atto scaduto resterebbe leggibile da una porta remota che quasi nessuno
 * presidia.
 *
 * @package Conformita_Core
 */

/**
 * Prove sul filtro di scadenza nell'interfaccia di pubblicazione remota.
 */
class Conformita_Core_Filtro_Scadenza_Xmlrpc_Test extends WP_UnitTestCase {

	const SEZIONE = 'sezione_remota';
	const TIPO    = 'prova_remota';
	const UTENTE  = 'utente_di_prova';
	const PAROLA  = 'parola-di-prova-lunga';

	/**
	 * Fuso del sito durante le prove, ripristinato alla fine.
	 *
	 * @var string
	 */
	private $fuso_originale = '';

	/**
	 * Server di pubblicazione remota.
	 *
	 * @var wp_xmlrpc_server
	 */
	private $server;

	/**
	 * Una sezione con un tipo, un utente autorizzato e il server acceso.
	 */
	public function set_up() {
		parent::set_up();

		// Il server di pubblicazione remota non e' caricato in una richiesta
		// normale: lo carica solo il file che serve quell'interfaccia.
		require_once ABSPATH . WPINC . '/class-IXR.php';
		require_once ABSPATH . WPINC . '/class-wp-xmlrpc-server.php';

		Conformita_Core_Sezioni::azzera();
		Conformita_Core_Tipi::azzera();
		Conformita_Core_Scadenza::azzera_orologio();
		Conformita_Core_Filtro_Scadenza::avvia();

		$this->fuso_originale = get_option( 'timezone_string' );
		update_option( 'timezone_string', 'Europe/Rome' );

		$this->assertTrue(
			conformita_core_registra_sezione(
				self::SEZIONE,
				array(
					'indicizzazione' => 'vietata',
					'scadenza'       => 'irraggiungibile',
				)
			)
		);

		$this->assertTrue(
			conformita_core_registra_tipo(
				self::TIPO,
				array(
					'sezione'      => self::SEZIONE,
					'show_in_rest' => false,
					'argomenti'    => array( 'public' => true ),
				)
			)
		);

		add_filter( 'xmlrpc_enabled', '__return_true' );

		$this->server = new wp_xmlrpc_server();
	}

	/**
	 * Fuso e orologio ripristinati.
	 */
	public function tear_down() {
		remove_filter( 'xmlrpc_enabled', '__return_true' );
		update_option( 'timezone_string', $this->fuso_originale );
		Conformita_Core_Scadenza::azzera_orologio();
		Conformita_Core_Filtro_Scadenza::avvia();

		parent::tear_down();
	}

	/**
	 * Un utente con tutte le capability del tipo, e la sua parola d'accesso.
	 *
	 * Le capability si assegnano esplicitamente perche' core le registra e non
	 * le assegna a nessun ruolo: senza, nemmeno un amministratore potrebbe
	 * leggere il tipo da questa interfaccia, e la prova sarebbe verde per il
	 * motivo sbagliato.
	 *
	 * @return int Identificativo dell'utente.
	 */
	private function utente_autorizzato() {
		$utente_id = self::factory()->user->create(
			array(
				'user_login' => self::UTENTE,
				'user_pass'  => self::PAROLA,
				'role'       => 'administrator',
			)
		);

		$utente = get_user_by( 'id', $utente_id );

		foreach ( array_unique( array_values( conformita_core_capacita_tipo( self::TIPO ) ) ) as $capacita ) {
			$utente->add_cap( $capacita );
		}

		return $utente_id;
	}

	/**
	 * Fissa l'orologio a mezzogiorno di un giorno noto, fuso del sito.
	 *
	 * @param string $giorno Giorno nel formato AAAA-MM-GG.
	 */
	private function oggi_e( $giorno ) {
		Conformita_Core_Scadenza::fissa_orologio(
			new DateTimeImmutable( $giorno . ' 12:00:00', wp_timezone() )
		);
	}

	/**
	 * Un contenuto pubblicato con una data di fine.
	 *
	 * @param string $fine Data di fine, formato AAAA-MM-GG.
	 * @return int Identificativo del contenuto.
	 */
	private function contenuto( $fine ) {
		$post_id = self::factory()->post->create(
			array(
				'post_type'   => self::TIPO,
				'post_status' => 'publish',
				'post_title'  => 'Atto di prova',
			)
		);

		$this->assertTrue( conformita_core_imposta_fine_pubblicazione( $post_id, $fine ) );

		return $post_id;
	}

	/**
	 * C-18: la lettura remota di un contenuto scaduto non restituisce dati.
	 */
	public function test_c18_lettura_remota() {
		$this->oggi_e( '2026-09-09' );

		$valido  = $this->contenuto( '2026-09-30' );
		$scaduto = $this->contenuto( '2026-09-08' );

		$this->utente_autorizzato();

		$buono = $this->server->wp_getPost( array( 1, self::UTENTE, self::PAROLA, $valido ) );

		$this->assertNotInstanceOf( IXR_Error::class, $buono, 'La lettura del contenuto valido deve riuscire: senza questa asserzione una prova che nega tutto sarebbe verde.' );
		$this->assertIsArray( $buono );
		$this->assertArrayHasKey( 'post_id', $buono, 'Il contenuto valido arriva con i suoi dati.' );

		$negato = $this->server->wp_getPost( array( 1, self::UTENTE, self::PAROLA, $scaduto ) );

		$this->assertIsArray( $negato );
		$this->assertSame( array(), $negato, 'Il contenuto scaduto non deve restituire nessun dato.' );
	}

	/**
	 * C-18: un contenuto senza data di fine non restituisce dati.
	 *
	 * Stessa direzione sicura del contratto del dato: il valore assente non e'
	 * un permesso implicito.
	 */
	public function test_c18_data_assente() {
		$this->oggi_e( '2026-09-09' );

		$senza_data = self::factory()->post->create(
			array(
				'post_type'   => self::TIPO,
				'post_status' => 'publish',
			)
		);

		$this->utente_autorizzato();

		$negato = $this->server->wp_getPost( array( 1, self::UTENTE, self::PAROLA, $senza_data ) );

		$this->assertIsArray( $negato );
		$this->assertSame( array(), $negato );
	}

	/**
	 * C-18: i contenuti di tipi non gestiti restano intatti.
	 *
	 * Il filtro si occupa dei tipi che core governa. Un articolo normale non ha
	 * la data di fine, e trattarlo come scaduto lo farebbe sparire dai programmi
	 * di scrittura di chi usa questa interfaccia per lavorare.
	 */
	public function test_c18_tipo_non_gestito_intatto() {
		$this->oggi_e( '2026-09-09' );

		$articolo = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$this->utente_autorizzato();

		$letto = $this->server->wp_getPost( array( 1, self::UTENTE, self::PAROLA, $articolo ) );

		$this->assertIsArray( $letto );
		$this->assertArrayHasKey( 'post_id', $letto, 'Un articolo normale non lo tocca nessuno.' );
	}
}
