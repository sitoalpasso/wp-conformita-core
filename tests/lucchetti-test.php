<?php
/**
 * Lucchetti della banca dati, comuni ai componenti, righe C-311..C-316 del
 * catalogo.
 *
 * Le prove sulle banche dati che tengono un solo lucchetto per connessione
 * accendono l'imitazione di `class-conformita-core-lucchetto-unico.php`. Per
 * vedere che cosa vede un'altra richiesta aprono una seconda connessione e la
 * mettono al posto di quella del sito per il tempo di una chiamata: il
 * componente comune la tratta come la connessione di un'altra richiesta, con
 * il suo stato.
 *
 * @package Conformita_Core
 */

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- lucchetti della banca dati nelle prove.

/**
 * Prove sui lucchetti.
 */
class Conformita_Core_Lucchetti_Test extends WP_UnitTestCase {

	/**
	 * Il lucchetto di un componente.
	 */
	const PRIMO = 'prova_componente_uno_turno';

	/**
	 * Il lucchetto di un altro componente.
	 */
	const SECONDO = 'prova_componente_due_salvataggio';

	/**
	 * La seconda connessione, se aperta.
	 *
	 * @var wpdb|null
	 */
	private $altra = null;

	/**
	 * I filtri sulle istruzioni messi dalla prova.
	 *
	 * @var array<int, callable>
	 */
	private $filtri = array();

	/**
	 * Ogni prova parte senza lucchetti e senza stato ricordato.
	 */
	public function set_up() {
		parent::set_up();

		Conformita_Core_Lucchetto_Unico::rilascia_tutti( $GLOBALS['wpdb'] );
		Conformita_Core_Lucchetto_Unico::dimentica_stato();
	}

	/**
	 * Spegne l'imitazione e lascia i lucchetti, su tutte e due le connessioni.
	 */
	public function tear_down() {
		$this->togli_filtri();

		if ( null !== $this->altra ) {
			Conformita_Core_Lucchetto_Unico::rilascia_tutti( $this->altra );
			$this->altra->close();
			$this->altra = null;
		}

		Conformita_Core_Lucchetto_Unico::disattiva();
		Conformita_Core_Lucchetto_Unico::rilascia_tutti( $GLOBALS['wpdb'] );
		Conformita_Core_Lucchetto_Unico::dimentica_stato();

		parent::tear_down();
	}

	/**
	 * C-311: su una banca dati a lucchetto unico, la presa di un componente
	 * dentro quella di un altro non apre la prima alle altre richieste.
	 */
	public function test_c311_prese_annidate_di_due_componenti() {
		global $wpdb;

		Conformita_Core_Lucchetto_Unico::attiva();

		// Precondizione: l'imitazione si comporta come la banca dati vecchia, senza il componente comune in mezzo.
		$this->assertSame( '1', (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK( %s, 0 )', self::PRIMO ) ) );
		$this->assertSame( '1', (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK( %s, 0 )', self::SECONDO ) ) );
		$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK( %s )', self::SECONDO ) );
		$this->assertTrue( $this->da_un_altra_richiesta( fn() => $this->presa_grezza( self::PRIMO ) ), 'Precondizione: con le istruzioni dirette il primo lucchetto si apre.' );
		Conformita_Core_Lucchetto_Unico::rilascia_tutti( $this->altra );
		Conformita_Core_Lucchetto_Unico::dimentica_stato();

		$this->assertTrue( conformita_core_lucchetto_unico(), 'La banca dati imitata tiene un lucchetto solo.' );

		$prese = $this->conta_prese();

		$this->assertTrue( conformita_core_prendi_lucchetto( self::PRIMO, 0 ) );
		$this->assertTrue( conformita_core_prendi_lucchetto( self::SECONDO, 0 ), 'La presa annidata riesce subito.' );
		$this->assertSame( 1, $prese->numero, 'Alla banca dati arriva una presa sola.' );
		$this->assertTrue( conformita_core_lucchetto_tenuto_qui( 1 ), 'Chi ha preso il secondo vede che il lucchetto lo tiene anche un altro.' );

		conformita_core_lascia_lucchetto( self::SECONDO );

		$this->assertFalse( $this->da_un_altra_richiesta( static fn() => conformita_core_prendi_lucchetto( self::PRIMO, 0 ) ), 'Lasciato il secondo, il primo resta chiuso.' );
		$this->assertFalse( $this->da_un_altra_richiesta( static fn() => conformita_core_prendi_lucchetto( self::SECONDO, 0 ) ), 'E anche il secondo, perche\' e\' lo stesso lucchetto.' );
		$this->assertTrue( conformita_core_lucchetto_tenuto_qui( 0 ) );
		$this->assertFalse( conformita_core_lucchetto_tenuto_qui( 1 ), 'Resta solo la presa di chi chiede.' );

		conformita_core_lascia_lucchetto( self::PRIMO );

		$this->assertFalse( conformita_core_lucchetto_tenuto_qui( 0 ) );
		$this->assertTrue(
			$this->da_un_altra_richiesta(
				static function () {
					$preso = conformita_core_prendi_lucchetto( self::SECONDO, 0 );
					conformita_core_lascia_lucchetto( self::SECONDO );

					return $preso;
				}
			),
			'Lasciate tutte e due, il lucchetto e\' libero.'
		);
	}

	/**
	 * C-312: la presa trattenuta resta fino alla fine della connessione, e la
	 * presa chiusa senza rilascio non chiede niente alla banca dati.
	 */
	public function test_c312_presa_trattenuta_e_presa_chiusa() {
		global $wpdb;

		Conformita_Core_Lucchetto_Unico::attiva();

		$this->assertTrue( conformita_core_prendi_lucchetto( self::PRIMO, 0 ) );
		conformita_core_trattieni_lucchetto( self::PRIMO );

		$this->assertTrue( conformita_core_lucchetto_tenuto_qui( 0 ), 'Trattenuto, il lucchetto e\' tenuto.' );
		$this->assertFalse( $this->da_un_altra_richiesta( static fn() => conformita_core_prendi_lucchetto( self::PRIMO, 0 ) ) );

		// Un'operazione successiva della stessa richiesta, lasciata, non lo libera.
		$this->assertTrue( conformita_core_prendi_lucchetto( self::SECONDO, 0 ) );
		conformita_core_lascia_lucchetto( self::SECONDO );

		$this->assertFalse( $this->da_un_altra_richiesta( static fn() => conformita_core_prendi_lucchetto( self::SECONDO, 0 ) ), 'Neanche dopo un\'altra operazione della stessa richiesta.' );

		// La presa chiusa da chi ha lasciato il lucchetto con un'istruzione propria.
		Conformita_Core_Lucchetto_Unico::rilascia_tutti( $wpdb );
		Conformita_Core_Lucchetto_Unico::dimentica_stato();

		$this->assertTrue( conformita_core_prendi_lucchetto( self::PRIMO, 0 ) );

		$nome = conformita_core_nome_lucchetto( self::PRIMO );

		$this->assertSame( substr( $wpdb->prefix, 0, 40 ) . 'conformita_lucchetto', $nome, 'Il nome comune, distinto per sito.' );
		$this->assertSame( $nome, conformita_core_nome_lucchetto( self::SECONDO ) );

		$this->assertSame( '1', (string) $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK( %s )', $nome ) ) );

		$prese = $this->conta_prese();

		conformita_core_lucchetto_chiuso_senza_rilascio();

		$this->assertSame( 0, $prese->rilasci, 'Chiudere la presa non chiede niente alla banca dati.' );
		$this->assertFalse( conformita_core_lucchetto_tenuto_qui( 0 ) );
		$this->assertTrue( conformita_core_prendi_lucchetto( self::SECONDO, 0 ) );
		$this->assertSame( 1, $prese->numero, 'La presa dopo e\' di nuovo una presa vera.' );
		conformita_core_lascia_lucchetto( self::SECONDO );
	}

	/**
	 * C-317: se la banca dati ha lasciato il lucchetto unico mentre la
	 * richiesta lo contava come preso, per esempio con la connessione caduta e
	 * riaperta, la presa annidata si rifiuta.
	 */
	public function test_c317_lucchetto_perso_sotto_una_presa() {
		global $wpdb;

		Conformita_Core_Lucchetto_Unico::attiva();

		$this->assertTrue( conformita_core_prendi_lucchetto( self::PRIMO, 0 ) );

		// La banca dati lascia il lucchetto, come alla caduta della connessione, e un'altra richiesta lo prende.
		$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK( %s )', conformita_core_nome_lucchetto( self::PRIMO ) ) );
		$this->assertTrue( $this->da_un_altra_richiesta( static fn() => conformita_core_prendi_lucchetto( self::PRIMO, 0 ) ) );

		$this->assertFalse( conformita_core_prendi_lucchetto( self::SECONDO, 0 ), 'La presa annidata non conta su un lucchetto che non c\'e\' piu\'.' );

		// Lasciata la presa di fuori, la richiesta torna a chiedere il lucchetto alla banca dati, che e' occupato.
		conformita_core_lascia_lucchetto( self::PRIMO );

		$this->assertFalse( conformita_core_prendi_lucchetto( self::PRIMO, 0 ) );

		// L'altra richiesta lascia: il lucchetto si riprende.
		$this->da_un_altra_richiesta( static fn() => conformita_core_lascia_lucchetto( self::PRIMO ) );

		$this->assertTrue( conformita_core_prendi_lucchetto( self::PRIMO, 0 ) );
		$this->assertTrue( conformita_core_prendi_lucchetto( self::SECONDO, 0 ), 'Con il lucchetto di nuovo suo, la presa annidata riesce.' );
		conformita_core_lascia_lucchetto( self::SECONDO );
		conformita_core_lascia_lucchetto( self::PRIMO );
	}

	/**
	 * C-313: il tipo di banca dati si legge dalla versione, e nel dubbio vale il lucchetto unico.
	 */
	public function test_c313_tipo_dalla_versione() {
		$casi = array(
			'5.5.64-MariaDB'            => true,
			'10.0.1-MariaDB'            => true,
			'10.0.2-MariaDB'            => false,
			'10.11.6-MariaDB-0+deb12u1' => false,
			'5.5.5-10.6.12-MariaDB'     => false,
			'5.5.5-10.0.1-MariaDB'      => true,
			'5.6.51'                    => true,
			'5.7.4-m14'                 => true,
			'5.7.5-m15'                 => false,
			'8.0.36'                    => false,
			'8.4.0-log'                 => false,
			''                          => true,
			'versione sconosciuta'      => true,
		);

		foreach ( $casi as $versione => $unico ) {
			$this->assertSame( $unico, conformita_core_versione_a_lucchetto_unico( (string) $versione ), (string) $versione );
		}
	}

	/**
	 * C-314: senza risposta sulla versione nessuna presa, e la volta dopo si chiede di nuovo.
	 */
	public function test_c314_versione_che_non_si_legge() {
		global $wpdb;

		Conformita_Core_Lucchetto_Unico::attiva();

		$guasta    = true;
		$domande   = 0;
		$lucchetti = 0;

		$this->filtra(
			static function ( $istruzione ) use ( &$guasta, &$domande, &$lucchetti ) {
				if ( ! is_string( $istruzione ) ) {
					return $istruzione;
				}

				if ( false !== strpos( $istruzione, 'GET_LOCK' ) ) {
					++$lucchetti;
				}

				if ( 1 === preg_match( '/^\s*SELECT\s+VERSION\(\)\s*$/i', $istruzione ) ) {
					++$domande;

					return $guasta ? 'SELECT NON_ESISTE()' : $istruzione;
				}

				return $istruzione;
			}
		);

		$zitto = $wpdb->suppress_errors( true );

		try {
			$this->assertFalse( conformita_core_prendi_lucchetto( self::PRIMO, 0 ), 'Senza risposta, la presa si rifiuta.' );
			$this->assertSame( 0, $lucchetti, 'E non si chiede nessun lucchetto alla banca dati.' );
			$this->assertFalse( conformita_core_prendi_lucchetto( self::PRIMO, 0 ) );
			$this->assertSame( 2, $domande, 'La risposta mancata non si ricorda: si chiede di nuovo.' );

			// Neanche quando una presa si chiude o si trattiene senza risposta.
			conformita_core_lucchetto_chiuso_senza_rilascio();
			conformita_core_trattieni_lucchetto( self::PRIMO );
			$this->assertSame( 4, $domande );
		} finally {
			$wpdb->suppress_errors( $zitto );
		}

		// La banca dati risponde di nuovo: la presa riesce, e la risposta si ricorda.
		$guasta = false;

		$this->assertTrue( conformita_core_prendi_lucchetto( self::PRIMO, 0 ) );
		conformita_core_lascia_lucchetto( self::PRIMO );
		$this->assertSame( 5, $domande );
		$this->assertTrue( conformita_core_lucchetto_unico(), 'La banca dati imitata e\' a lucchetto unico.' );
		$this->assertSame( 5, $domande, 'Letta una volta, la versione non si chiede piu\'.' );
		$this->assertFalse( conformita_core_lucchetto_tenuto_qui( 0 ), 'Il trattenuto senza risposta non e\' rimasto.' );
	}

	/**
	 * C-315: sulla banca dati vera, quale che sia il suo tipo, la presa
	 * annidata non apre quella di fuori; dove i lucchetti si tengono insieme,
	 * restano distinti.
	 *
	 * Gira senza imitazione: in locale e su MySQL 8 prova i lucchetti
	 * distinti, nella CI su MariaDB 5.5 il lucchetto unico vero.
	 */
	public function test_c315_banca_dati_vera() {
		global $wpdb;

		$unico = conformita_core_versione_a_lucchetto_unico( (string) $wpdb->get_var( 'SELECT VERSION()' ) );

		$this->assertSame( $unico, conformita_core_lucchetto_unico() );

		$this->assertTrue( conformita_core_prendi_lucchetto( self::PRIMO, 0 ) );
		$this->assertTrue( conformita_core_prendi_lucchetto( self::SECONDO, 0 ) );
		conformita_core_lascia_lucchetto( self::SECONDO );

		$this->assertFalse( $this->da_un_altra_richiesta( static fn() => conformita_core_prendi_lucchetto( self::PRIMO, 0 ) ), 'La presa di fuori resta chiusa.' );

		if ( $unico ) {
			$this->assertSame( conformita_core_nome_lucchetto( self::PRIMO ), conformita_core_nome_lucchetto( self::SECONDO ) );
		} else {
			$this->assertSame( self::PRIMO, conformita_core_nome_lucchetto( self::PRIMO ), 'Il nome resta quello del componente.' );
			$this->assertFalse( conformita_core_lucchetto_tenuto_qui( 0 ), 'Dove i lucchetti sono distinti, nessuno lo tiene per altri.' );
			$this->assertTrue(
				$this->da_un_altra_richiesta(
					static function () {
						$preso = conformita_core_prendi_lucchetto( self::SECONDO, 0 );
						conformita_core_lascia_lucchetto( self::SECONDO );

						return $preso;
					}
				),
				'Il secondo lucchetto, lasciato, e\' libero anche mentre il primo e\' tenuto.'
			);
		}

		conformita_core_lascia_lucchetto( self::PRIMO );

		$this->assertTrue(
			$this->da_un_altra_richiesta(
				static function () {
					$preso = conformita_core_prendi_lucchetto( self::PRIMO, 0 );
					conformita_core_lascia_lucchetto( self::PRIMO );

					return $preso;
				}
			),
			'Lasciato il primo, si riprende da un\'altra richiesta.'
		);
	}

	/**
	 * C-316: l'interfaccia che porta i lucchetti e' la 1.14.0.
	 */
	public function test_c316_versione_dell_interfaccia() {
		$this->assertTrue( version_compare( conformita_core_versione_api(), '1.14.0', '>=' ) );
		$this->assertTrue( conformita_core_api_compatibile( '1.13.0', conformita_core_versione_api() ), 'Chi chiedeva 1.13.0 resta compatibile.' );
	}

	/**
	 * Prende un lucchetto con l'istruzione diretta.
	 *
	 * @param string $nome Nome.
	 * @return bool
	 */
	private function presa_grezza( $nome ) {
		global $wpdb;

		return '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK( %s, 0 )', $nome ) );
	}

	/**
	 * Conta le prese e i rilasci che arrivano alla banca dati dalla connessione del sito.
	 *
	 * @return object Con `numero` e `rilasci`.
	 */
	private function conta_prese() {
		$conto          = new stdClass();
		$conto->numero  = 0;
		$conto->rilasci = 0;
		$sito           = $GLOBALS['wpdb'];

		$this->filtra(
			static function ( $istruzione ) use ( $conto, $sito ) {
				if ( is_string( $istruzione ) && $GLOBALS['wpdb'] === $sito ) {
					$conto->numero  += substr_count( $istruzione, 'GET_LOCK' );
					$conto->rilasci += substr_count( $istruzione, 'RELEASE_LOCK' );
				}

				return $istruzione;
			}
		);

		return $conto;
	}

	/**
	 * Esegue una chiamata con la connessione di un'altra richiesta.
	 *
	 * @param callable $chiamata Chiamata.
	 * @return mixed
	 */
	private function da_un_altra_richiesta( callable $chiamata ) {
		if ( null === $this->altra ) {
			$this->altra = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
			$this->altra->set_prefix( $GLOBALS['wpdb']->prefix );
		}

		$principale      = $GLOBALS['wpdb'];
		$GLOBALS['wpdb'] = $this->altra; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- la connessione di un'altra richiesta.

		try {
			return $chiamata();
		} finally {
			$GLOBALS['wpdb'] = $principale; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- si rimette quella del sito.
		}
	}

	/**
	 * Mette un filtro sulle istruzioni, prima dell'imitazione.
	 *
	 * @param callable $filtro Filtro.
	 */
	private function filtra( callable $filtro ) {
		$this->filtri[] = $filtro;
		add_filter( 'query', $filtro, 1 );
	}

	/**
	 * Toglie i filtri messi dalla prova.
	 */
	private function togli_filtri() {
		foreach ( $this->filtri as $filtro ) {
			remove_filter( 'query', $filtro, 1 );
		}

		$this->filtri = array();
	}
}
