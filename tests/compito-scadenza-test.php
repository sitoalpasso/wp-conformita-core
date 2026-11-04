<?php
/**
 * Compito alla scadenza e battito, righe C-318..C-323 del catalogo.
 *
 * Scheda: `docs/implementazione/compito-alla-scadenza.md`.
 *
 * @package Conformita_Core
 */

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- prove sulla banca dati.

/**
 * Prove sul compito alla scadenza.
 */
class Conformita_Core_Compito_Scadenza_Test extends WP_UnitTestCase {

	const SEZIONE       = 'sezione_compito';
	const SEZIONE_ALTRA = 'sezione_compito_altra';
	const TIPO          = 'tipo_compito';
	const TIPO_ALTRO    = 'tipo_compito_altro';

	/**
	 * I contenuti passati all'azione, in ordine.
	 *
	 * @var array<int, int>
	 */
	private $chiamati = array();

	/**
	 * Due sezioni, due tipi, orologio fissato al 10 ottobre 2026 alle 9 del mattino a Roma.
	 */
	public function set_up() {
		parent::set_up();

		update_option( 'timezone_string', 'Europe/Rome' );
		Conformita_Core_Sezioni::azzera();
		Conformita_Core_Tipi::azzera();
		Conformita_Core_Compito_Scadenza::azzera();
		delete_option( Conformita_Core_Compito_Scadenza::BATTITO );

		foreach ( array(
			self::SEZIONE       => self::TIPO,
			self::SEZIONE_ALTRA => self::TIPO_ALTRO,
		) as $sezione => $tipo ) {
			$this->assertTrue(
				conformita_core_registra_sezione(
					$sezione,
					array(
						'indicizzazione' => 'vietata',
						'scadenza'       => 'irraggiungibile',
					)
				)
			);
			$this->assertTrue(
				conformita_core_registra_tipo(
					$tipo,
					array(
						'sezione'      => $sezione,
						'show_in_rest' => false,
						'argomenti'    => array( 'public' => true ),
					)
				)
			);
		}

		Conformita_Core_Scadenza::fissa_orologio( new DateTimeImmutable( '2026-10-10 07:00:00', new DateTimeZone( 'UTC' ) ) );

		$this->chiamati = array();
	}

	/**
	 * Orologio, sezioni e compito rimessi a posto.
	 */
	public function tear_down() {
		global $wpdb;

		remove_all_filters( 'query' );
		$wpdb->suppress_errors( false );
		Conformita_Core_Scadenza::azzera_orologio();
		Conformita_Core_Compito_Scadenza::azzera();
		Conformita_Core_Sezioni::azzera();
		Conformita_Core_Tipi::azzera();
		update_option( 'timezone_string', '' );
		wp_clear_scheduled_hook( Conformita_Core_Compito_Scadenza::COMPITO );

		parent::tear_down();
	}

	/**
	 * C-318: la registrazione pretende sezione, stati e azione, senza valori
	 * predefiniti; senza registrazione il compito non tocca niente.
	 */
	public function test_c318_registrazione() {
		$scaduto = $this->contenuto( self::TIPO, 'publish', '2026-10-01' );

		$this->assertSame(
			array(
				'contenuti' => 0,
				'falliti'   => 0,
				'completo'  => true,
			),
			conformita_core_esegui_compito_scadenza(),
			'Senza registrazione non si tocca niente.'
		);
		$this->assertSame( 'publish', get_post_status( $scaduto ) );

		$azione = array( $this, 'azione_privata' );
		$casi   = array(
			'sezione non registrata' => array( array( 'sezione_che_non_c_e', array( 'publish' ), $azione ), 'conformita_core_compito_sezione' ),
			'stati vuoti'            => array( array( self::SEZIONE, array(), $azione ), 'conformita_core_compito_stati' ),
			'stati non elenco'       => array( array( self::SEZIONE, 'publish', $azione ), 'conformita_core_compito_stati' ),
			'stato non valido'       => array( array( self::SEZIONE, array( 'Publish Me' ), $azione ), 'conformita_core_compito_stati' ),
			'azione non chiamabile'  => array( array( self::SEZIONE, array( 'publish' ), 'funzione_che_non_esiste' ), 'conformita_core_compito_azione' ),
		);

		foreach ( $casi as $caso => $dati ) {
			$esito = conformita_core_registra_azione_alla_scadenza( ...$dati[0] );

			$this->assertWPError( $esito, $caso );
			$this->assertSame( $dati[1], $esito->get_error_code(), $caso );
		}

		$this->assertTrue( conformita_core_registra_azione_alla_scadenza( self::SEZIONE, array( 'publish' ), $azione ) );

		$doppia = conformita_core_registra_azione_alla_scadenza( self::SEZIONE, array( 'publish', 'private' ), '__return_true' );

		$this->assertWPError( $doppia );
		$this->assertSame( 'conformita_core_compito_duplicato', $doppia->get_error_code(), 'La prima registrazione non si sovrascrive.' );
	}

	/**
	 * C-319: il compito prende solo i contenuti scaduti, della sezione, negli
	 * stati dichiarati, con una data valida; chiama l'azione su ciascuno, e il
	 * cambio di stato lascia la sua voce nel registro.
	 */
	public function test_c319_quali_contenuti() {
		$scaduto_ieri = $this->contenuto( self::TIPO, 'publish', '2026-10-09' );
		$scaduto_vec  = $this->contenuto( self::TIPO, 'publish', '2025-01-31' );
		$oggi         = $this->contenuto( self::TIPO, 'publish', '2026-10-10' );
		$futuro       = $this->contenuto( self::TIPO, 'publish', '2026-12-31' );
		$bozza        = $this->contenuto( self::TIPO, 'draft', '2026-10-01' );
		$altra        = $this->contenuto( self::TIPO_ALTRO, 'publish', '2026-10-01' );
		$senza_data   = $this->contenuto( self::TIPO, 'publish', '' );
		$rotta        = $this->contenuto( self::TIPO, 'publish', '' );
		$articolo     = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		add_post_meta( $rotta, Conformita_Core_Scadenza::chiave(), '2026-02-31' );
		add_post_meta( $articolo, Conformita_Core_Scadenza::chiave(), '2026-10-01' );

		$this->assertTrue( conformita_core_registra_azione_alla_scadenza( self::SEZIONE, array( 'publish' ), array( $this, 'azione_privata' ) ) );

		$esito = conformita_core_esegui_compito_scadenza();

		$this->assertSame(
			array(
				'contenuti' => 2,
				'falliti'   => 0,
				'completo'  => true,
			),
			$esito
		);
		$this->assertEqualsCanonicalizing( array( $scaduto_ieri, $scaduto_vec ), $this->chiamati, 'L\'azione solo sui due scaduti.' );
		$this->assertSame( 'private', get_post_status( $scaduto_ieri ) );
		$this->assertSame( 'private', get_post_status( $scaduto_vec ) );

		foreach ( array(
			'fine oggi, ancora in corso' => $oggi,
			'fine futura'                => $futuro,
			'stato non dichiarato'       => $bozza,
			'altra sezione'              => $altra,
			'senza data'                 => $senza_data,
			'data rotta'                 => $rotta,
			'tipo di nessuna sezione'    => $articolo,
		) as $caso => $id ) {
			$this->assertNotSame( 'private', get_post_status( $id ), $caso );
		}

		// Il cambio di stato fatto dall'azione con WordPress lascia la voce da se'.
		$voci = conformita_core_voci_registro( array( 'contenuto' => $scaduto_ieri ) );

		$this->assertNotWPError( $voci );
		$this->assertContains( 'rimozione', $this->azioni( $voci ), 'Una voce per il contenuto toccato.' );
	}

	/**
	 * C-320: due esecuzioni di fila non fanno niente due volte; un'esecuzione
	 * mentre un'altra tiene il lucchetto rinuncia senza toccare niente.
	 */
	public function test_c320_una_volta_sola() {
		global $wpdb;

		$scaduto = $this->contenuto( self::TIPO, 'publish', '2026-10-01' );

		$this->assertTrue( conformita_core_registra_azione_alla_scadenza( self::SEZIONE, array( 'publish' ), array( $this, 'azione_privata' ) ) );

		// Un'altra esecuzione in corso, da un'altra connessione.
		$altra = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );

		$this->assertSame( '1', (string) $altra->get_var( $altra->prepare( 'SELECT GET_LOCK( %s, 0 )', conformita_core_nome_lucchetto( Conformita_Core_Compito_Scadenza::nome_lucchetto() ) ) ) );

		try {
			$occupato = conformita_core_esegui_compito_scadenza();

			$this->assertWPError( $occupato );
			$this->assertSame( 'conformita_core_compito_occupato', $occupato->get_error_code() );
			$this->assertSame( array(), $this->chiamati, 'Nessuna azione.' );
			$this->assertNull( conformita_core_battito_compito_scadenza(), 'Nessun battito.' );
		} finally {
			$altra->query( 'SELECT RELEASE_ALL_LOCKS()' );
			$altra->close();
		}

		$this->assertSame( 1, conformita_core_esegui_compito_scadenza()['contenuti'] );

		$voci = count( conformita_core_voci_registro( array( 'contenuto' => $scaduto ) ) );

		$this->assertSame(
			array(
				'contenuti' => 0,
				'falliti'   => 0,
				'completo'  => true,
			),
			conformita_core_esegui_compito_scadenza(),
			'La seconda esecuzione non trova niente.'
		);
		$this->assertSame( array( $scaduto ), $this->chiamati, 'L\'azione una volta sola.' );
		$this->assertCount( $voci, conformita_core_voci_registro( array( 'contenuto' => $scaduto ) ), 'Nessuna voce in piu\'.' );

		// Lasciato il lucchetto: un'altra connessione lo prende.
		$dopo = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );

		$this->assertSame( '1', (string) $dopo->get_var( $dopo->prepare( 'SELECT GET_LOCK( %s, 0 )', conformita_core_nome_lucchetto( Conformita_Core_Compito_Scadenza::nome_lucchetto() ) ) ), 'Il lucchetto e\' lasciato.' );
		$dopo->query( 'SELECT RELEASE_ALL_LOCKS()' );
		$dopo->close();
	}

	/**
	 * C-321: un'azione che fallisce o si guasta non ferma gli altri; i
	 * contenuti oltre il primo lotto si prendono nella stessa esecuzione.
	 */
	public function test_c321_guasti_e_lotti() {
		$fallisce = $this->contenuto( self::TIPO, 'publish', '2026-10-01' );
		$si_rompe = $this->contenuto( self::TIPO, 'publish', '2026-10-01' );
		$altri    = array();

		for ( $i = 0; $i < Conformita_Core_Compito_Scadenza::LOTTO + 10; ++$i ) {
			$altri[] = $this->contenuto( self::TIPO, 'publish', '2026-10-0' . ( 1 + $i % 9 ) );
		}

		$this->assertTrue(
			conformita_core_registra_azione_alla_scadenza(
				self::SEZIONE,
				array( 'publish' ),
				function ( $id ) use ( $fallisce, $si_rompe ) {
					if ( $fallisce === $id ) {
						return new WP_Error( 'prova', 'non riesce' );
					}

					if ( $si_rompe === $id ) {
						throw new RuntimeException( 'guasto' );
					}

					return $this->azione_privata( $id );
				}
			)
		);

		$esito = conformita_core_esegui_compito_scadenza();

		$this->assertSame(
			array(
				'contenuti' => count( $altri ),
				'falliti'   => 2,
				'completo'  => true,
			),
			$esito
		);

		foreach ( $altri as $id ) {
			$this->assertSame( 'private', get_post_status( $id ) );
		}

		$this->assertSame( 'publish', get_post_status( $fallisce ) );
		$this->assertSame( 'publish', get_post_status( $si_rompe ) );

		// L'esecuzione dopo riprova solo i due rimasti.
		$this->chiamati = array();

		$this->assertSame(
			array(
				'contenuti' => 0,
				'falliti'   => 2,
				'completo'  => true,
			),
			conformita_core_esegui_compito_scadenza()
		);
	}

	/**
	 * C-321, seguito: un contenuto che cambia stato mentre l'esecuzione lavora
	 * non riceve l'azione; un'esecuzione si ferma al massimo e lo dice, e la
	 * successiva prende il resto.
	 */
	public function test_c321_cambiato_nel_frattempo_e_massimo() {
		global $wpdb;

		$primo   = $this->contenuto( self::TIPO, 'publish', '2026-10-01' );
		$secondo = $this->contenuto( self::TIPO, 'publish', '2026-10-01' );

		$this->assertTrue(
			conformita_core_registra_azione_alla_scadenza(
				self::SEZIONE,
				array( 'publish' ),
				function ( $id ) use ( $primo, $secondo ) {
					$this->chiamati[] = $id;

					if ( $primo === $id ) {
						// Mentre si lavora sul primo, qualcuno riporta il secondo in bozza.
						$wpdb_locale = $GLOBALS['wpdb'];
						$wpdb_locale->update( $wpdb_locale->posts, array( 'post_status' => 'draft' ), array( 'ID' => $secondo ) );
					}

					$GLOBALS['wpdb']->update( $GLOBALS['wpdb']->posts, array( 'post_status' => 'private' ), array( 'ID' => $id ) );

					return true;
				}
			)
		);

		$this->assertSame(
			array(
				'contenuti' => 1,
				'falliti'   => 0,
				'completo'  => true,
			),
			conformita_core_esegui_compito_scadenza()
		);
		$this->assertSame( array( $primo ), $this->chiamati, 'Il secondo, non piu\' pubblicato, non riceve l\'azione.' );

		// Il massimo: uno in piu' di quelli che un'esecuzione prende.
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->posts} SET post_status = 'trash' WHERE ID IN ( %d, %d )", $primo, $secondo ) );

		$molti = Conformita_Core_Compito_Scadenza::MASSIMO + 1;

		for ( $i = 0; $i < $molti; ++$i ) {
			$this->contenuto( self::TIPO, 'publish', '2026-10-01' );
		}

		$this->chiamati = array();

		$this->assertSame(
			array(
				'contenuti' => Conformita_Core_Compito_Scadenza::MASSIMO,
				'falliti'   => 0,
				'completo'  => false,
			),
			conformita_core_esegui_compito_scadenza()
		);
		$this->assertFalse( conformita_core_battito_compito_scadenza()['completo'], 'Il battito dice che ne restano.' );
		$this->assertSame(
			array(
				'contenuti' => 1,
				'falliti'   => 0,
				'completo'  => true,
			),
			conformita_core_esegui_compito_scadenza(),
			'La successiva prende il resto.'
		);
	}

	/**
	 * C-322: il battito dice quando e' finita l'ultima esecuzione e che cosa ha
	 * fatto; una lettura fallita non lo aggiorna.
	 */
	public function test_c322_battito() {
		global $wpdb;

		$this->assertNull( conformita_core_battito_compito_scadenza(), 'Mai eseguito: nessun battito.' );

		$this->contenuto( self::TIPO, 'publish', '2026-10-01' );
		$this->assertTrue( conformita_core_registra_azione_alla_scadenza( self::SEZIONE, array( 'publish' ), array( $this, 'azione_privata' ) ) );

		conformita_core_esegui_compito_scadenza();

		$this->assertSame(
			array(
				'istante'   => '2026-10-10 07:00:00',
				'contenuti' => 1,
				'falliti'   => 0,
				'completo'  => true,
			),
			conformita_core_battito_compito_scadenza()
		);

		// La lettura dei contenuti scaduti fallisce: errore, e il battito resta quello di prima.
		Conformita_Core_Scadenza::fissa_orologio( new DateTimeImmutable( '2026-10-11 07:00:00', new DateTimeZone( 'UTC' ) ) );
		add_filter(
			'query',
			static function ( $istruzione ) {
				return is_string( $istruzione ) && false !== strpos( $istruzione, 'SELECT DISTINCT p.ID' ) ? 'SELECT * FROM tabella_che_non_esiste' : $istruzione;
			}
		);
		$wpdb->suppress_errors( true );

		$errore = conformita_core_esegui_compito_scadenza();

		$this->assertWPError( $errore );
		$this->assertSame( 'conformita_core_compito_lettura', $errore->get_error_code() );
		$this->assertSame( '2026-10-10 07:00:00', conformita_core_battito_compito_scadenza()['istante'] );
	}

	/**
	 * C-323: il compito si programma ogni ora all'avvio, una volta sola; il
	 * compito di WordPress esegue il lavoro anche con il cron interno spento,
	 * cioe' quando lo chiama il cron di sistema; alla disattivazione si toglie.
	 * Interfaccia 1.15.0.
	 */
	public function test_c323_compito_di_wordpress() {
		wp_clear_scheduled_hook( Conformita_Core_Compito_Scadenza::COMPITO );

		// Agganciato all'avvio; chiamato qui direttamente, perche' ripetere tutto l'avvio di WordPress sporcherebbe le prove dopo.
		$this->assertNotFalse( has_action( 'init', array( 'Conformita_Core_Compito_Scadenza', 'pianifica' ) ) );

		Conformita_Core_Compito_Scadenza::pianifica();
		$prima = wp_next_scheduled( Conformita_Core_Compito_Scadenza::COMPITO );

		$this->assertNotFalse( $prima );
		$this->assertSame( 'hourly', wp_get_schedule( Conformita_Core_Compito_Scadenza::COMPITO ) );

		Conformita_Core_Compito_Scadenza::pianifica();
		$this->assertSame( $prima, wp_next_scheduled( Conformita_Core_Compito_Scadenza::COMPITO ), 'Una volta sola.' );

		$scaduto = $this->contenuto( self::TIPO, 'publish', '2026-10-01' );

		$this->assertTrue( conformita_core_registra_azione_alla_scadenza( self::SEZIONE, array( 'publish' ), array( $this, 'azione_privata' ) ) );

		// Come lo chiama wp-cron.php, quando il cron di sistema lo richiede.
		do_action( Conformita_Core_Compito_Scadenza::COMPITO );

		$this->assertSame( 'private', get_post_status( $scaduto ) );
		$this->assertSame( 1, conformita_core_battito_compito_scadenza()['contenuti'] );

		Conformita_Core_Compito_Scadenza::togli_compito();
		$this->assertFalse( wp_next_scheduled( Conformita_Core_Compito_Scadenza::COMPITO ) );

		$this->assertTrue( version_compare( conformita_core_versione_api(), '1.15.0', '>=' ) );
		$this->assertTrue( conformita_core_api_compatibile( '1.14.0', conformita_core_versione_api() ) );
	}

	/**
	 * L'azione delle prove: rende privato il contenuto con WordPress.
	 *
	 * @param int $id Contenuto.
	 * @return true|WP_Error
	 */
	public function azione_privata( $id ) {
		$this->chiamati[] = $id;

		$esito = wp_update_post(
			array(
				'ID'          => $id,
				'post_status' => 'private',
			),
			true
		);

		return is_wp_error( $esito ) ? $esito : true;
	}

	/**
	 * Un contenuto con la sua data di fine.
	 *
	 * @param string $tipo  Tipo.
	 * @param string $stato Stato.
	 * @param string $fine  Data di fine, vuota per nessuna.
	 * @return int
	 */
	private function contenuto( $tipo, $stato, $fine ) {
		$id = self::factory()->post->create(
			array(
				'post_type'   => $tipo,
				'post_status' => $stato,
			)
		);

		if ( '' !== $fine ) {
			add_post_meta( $id, Conformita_Core_Scadenza::chiave(), $fine );
		}

		return $id;
	}

	/**
	 * Le azioni delle voci.
	 *
	 * @param array<int, array<string, mixed>> $voci Voci.
	 * @return array<int, string>
	 */
	private function azioni( $voci ) {
		return array_map(
			static function ( $voce ) {
				return (string) $voce['azione'];
			},
			$voci
		);
	}
}
