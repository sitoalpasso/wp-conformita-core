<?php
/**
 * Registro delle modifiche: voci automatiche, voci dei componenti, solo in
 * aggiunta.
 *
 * Righe di collaudo C-195..C-213 e C-222..C-224, che dettagliano C-50 e C-51.
 * La schermata di consultazione, C-52, ha le sue prove in
 * `registro-schermata-test.php`.
 *
 * **Come si legge il registro nelle prove.** Ogni prova parte contando le voci
 * che esistono e guarda solo quelle nate dopo: le operazioni di preparazione,
 * per esempio la creazione di un contenuto, scrivono voci anche loro, e una
 * prova che contasse tutto misurerebbe la preparazione e non l'operazione.
 *
 * @package Conformita_Core
 */

/**
 * Prove sul registro delle modifiche.
 */
class Conformita_Core_Registro_Test extends WP_UnitTestCase {

	const SEZIONE       = 'sezione_registro';
	const TIPO          = 'prova_registro';
	const SEZIONE_ALTRA = 'sezione_altra';
	const TIPO_ALTRO    = 'prova_altra';

	/**
	 * Numero dell'ultima voce prima dell'operazione provata.
	 *
	 * @var int
	 */
	private $partenza = 0;

	/**
	 * Due sezioni, due tipi, orologio fissato.
	 */
	public function set_up() {
		parent::set_up();

		Conformita_Core_Sezioni::azzera();
		Conformita_Core_Tipi::azzera();
		delete_option( Conformita_Core_Registro::OPZIONE_MANCATE );

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

		Conformita_Core_Scadenza::fissa_orologio( new DateTimeImmutable( '2026-10-02 09:15:30', new DateTimeZone( 'UTC' ) ) );
	}

	/**
	 * Orologio e agganci rimessi a posto.
	 */
	public function tear_down() {
		update_option( 'timezone_string', '' );
		Conformita_Core_Scadenza::azzera_orologio();
		Conformita_Core_Registro_Automatico::avvia();
		wp_set_current_user( 0 );

		parent::tear_down();
	}

	/**
	 * Segna il punto da cui contare le voci nuove.
	 */
	private function segna() {
		global $wpdb;

		$tabella = Conformita_Core_Registro::tabella();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- prova: si legge la tabella per quello che contiene.
		$this->partenza = (int) $wpdb->get_var( "SELECT COALESCE( MAX( id ), 0 ) FROM {$tabella}" );
	}

	/**
	 * Le voci nate dopo il segno, in ordine di scrittura.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function nuove() {
		return array_values(
			array_filter(
				conformita_core_voci_registro(),
				function ( $voce ) {
					return $voce['id'] > $this->partenza;
				}
			)
		);
	}

	/**
	 * Le azioni delle voci nate dopo il segno, in ordine.
	 *
	 * @return array<int, string>
	 */
	private function azioni_nuove() {
		return array_map(
			function ( $voce ) {
				return $voce['azione'];
			},
			$this->nuove()
		);
	}

	/**
	 * Tutte le righe della tabella, così come sono.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function righe() {
		global $wpdb;

		$tabella = Conformita_Core_Registro::tabella();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- prova: fotografia della tabella.
		return $wpdb->get_results( "SELECT * FROM {$tabella} ORDER BY id", ARRAY_A );
	}

	/**
	 * Un contenuto del tipo gestito, nello stato indicato.
	 *
	 * @param string $stato Stato.
	 * @param string $tipo  Tipo.
	 * @return int
	 */
	private function contenuto( $stato = 'publish', $tipo = self::TIPO ) {
		return self::factory()->post->create(
			array(
				'post_type'   => $tipo,
				'post_status' => $stato,
				'post_title'  => 'Contenuto di prova',
			)
		);
	}

	/**
	 * Un utente con un ruolo.
	 *
	 * @param string $ruolo Ruolo.
	 * @return int
	 */
	private function utente( $ruolo = 'editor' ) {
		return self::factory()->user->create( array( 'role' => $ruolo ) );
	}

	/**
	 * C-195: pubblicare un contenuto gestito scrive una voce, e una sola, con
	 * chi, che cosa e quando.
	 */
	public function test_c195_pubblicazione() {
		update_option( 'timezone_string', 'Europe/Rome' );

		$utente = $this->utente();
		wp_set_current_user( $utente );

		$id = $this->contenuto( 'pending' );

		$this->segna();
		wp_update_post(
			array(
				'ID'          => $id,
				'post_status' => 'publish',
			)
		);

		$voci = $this->nuove();

		$this->assertCount( 1, $voci, 'Una pubblicazione, una voce: ' . wp_json_encode( $voci ) );
		$this->assertSame( 'pubblicazione', $voci[0]['azione'] );
		$this->assertSame( $utente, $voci[0]['utente'] );
		$this->assertSame( $id, $voci[0]['contenuto'] );
		$this->assertSame( self::TIPO, $voci[0]['tipo'] );
		$this->assertSame( self::SEZIONE, $voci[0]['sezione'] );
		$this->assertSame( Conformita_Core_Registro::ORIGINE_AUTOMATICA, $voci[0]['origine'] );
		$this->assertSame( '2026-10-02 09:15:30', $voci[0]['istante']->format( 'Y-m-d H:i:s' ), 'L\'istante e\' quello dell\'orologio di core, conservato in UTC anche se il sito ha un altro fuso.' );
		$this->assertSame( 'UTC', $voci[0]['istante']->getTimezone()->getName() );
		$this->assertSame(
			array(
				'stato_precedente' => 'pending',
				'stato_nuovo'      => 'publish',
			),
			$voci[0]['dettagli']
		);
		$this->assertNull( $voci[0]['motivazione'] );
	}

	/**
	 * C-196: la nascita di un contenuto si registra una volta, e una nascita
	 * direttamente pubblicata registra anche la pubblicazione.
	 */
	public function test_c196_creazione() {
		wp_set_current_user( $this->utente() );

		$this->segna();
		$bozza = $this->contenuto( 'draft' );
		$this->assertSame( array( 'creazione' ), $this->azioni_nuove(), 'Una bozza nuova: la nascita e basta.' );

		$this->segna();
		$pubblicato = $this->contenuto( 'publish' );
		$this->assertSame( array( 'creazione', 'pubblicazione' ), $this->azioni_nuove() );

		$this->segna();
		$automatica = wp_insert_post(
			array(
				'post_type'   => self::TIPO,
				'post_status' => 'auto-draft',
				'post_title'  => 'Bozza automatica',
			)
		);
		$this->assertSame( array(), $this->azioni_nuove(), 'La bozza automatica dell\'editor non e\' ancora un contenuto.' );

		wp_update_post(
			array(
				'ID'          => $automatica,
				'post_status' => 'draft',
			)
		);
		$this->assertSame( array( 'creazione' ), $this->azioni_nuove(), 'La nascita e\' il primo salvataggio della bozza automatica.' );

		unset( $bozza, $pubblicato );
	}

	/**
	 * C-197: la modifica dei campi di un contenuto fuori dalla bozza scrive una
	 * voce con i nomi dei campi, e un salvataggio senza modifiche non la scrive.
	 */
	public function test_c197_modifica() {
		$utente = $this->utente();
		wp_set_current_user( $utente );

		foreach ( array( 'publish', 'pending' ) as $stato ) {
			$id = $this->contenuto( $stato );

			$this->segna();
			wp_update_post(
				array(
					'ID'         => $id,
					'post_title' => 'Titolo cambiato ' . $stato,
				)
			);

			$voci = $this->nuove();

			$this->assertCount( 1, $voci, "Stato {$stato}: una modifica, una voce." );
			$this->assertSame( 'modifica', $voci[0]['azione'] );
			$this->assertSame( $utente, $voci[0]['utente'] );
			$this->assertSame( array( 'campi' => array( 'post_title' ) ), $voci[0]['dettagli'] );

			$this->segna();
			wp_update_post( get_post( $id, ARRAY_A ) );
			$this->assertSame( array(), $this->azioni_nuove(), "Stato {$stato}: salvare senza cambiare niente non e' una modifica." );
		}

		$id = $this->contenuto( 'publish' );
		$this->segna();
		wp_update_post(
			array(
				'ID'           => $id,
				'post_content' => 'Testo nuovo',
				'post_excerpt' => 'Riassunto nuovo',
			)
		);
		$voci = $this->nuove();
		$this->assertCount( 1, $voci );
		$this->assertSame( array( 'campi' => array( 'post_content', 'post_excerpt' ) ), $voci[0]['dettagli'] );
		$this->assertStringNotContainsString( 'Testo nuovo', wp_json_encode( $voci[0] ), 'Il registro conserva i nomi dei campi, non i valori.' );
	}

	/**
	 * C-198: l'uscita dallo stato pubblicato, verso qualunque stato, e' una
	 * rimozione.
	 */
	public function test_c198_rimozione() {
		wp_set_current_user( $this->utente() );

		foreach ( array( 'draft', 'pending', 'private', 'trash' ) as $destinazione ) {
			$id = $this->contenuto( 'publish' );

			$this->segna();

			if ( 'trash' === $destinazione ) {
				wp_trash_post( $id );
			} else {
				wp_update_post(
					array(
						'ID'          => $id,
						'post_status' => $destinazione,
					)
				);
			}

			$voci = $this->nuove();

			$this->assertCount( 1, $voci, "Verso {$destinazione}: " . wp_json_encode( $voci ) );
			$this->assertSame( 'rimozione', $voci[0]['azione'] );
			$this->assertSame( $destinazione, $voci[0]['dettagli']['stato_nuovo'] );
			$this->assertSame( 'publish', $voci[0]['dettagli']['stato_precedente'] );
		}
	}

	/**
	 * C-199: gli altri cambi di stato, compresi quelli che non passano dallo
	 * stato pubblicato, si registrano come cambi di stato.
	 */
	public function test_c199_cambio_stato() {
		wp_set_current_user( $this->utente() );

		$id = $this->contenuto( 'draft' );

		$this->segna();
		wp_update_post(
			array(
				'ID'          => $id,
				'post_status' => 'pending',
			)
		);
		wp_update_post(
			array(
				'ID'          => $id,
				'post_status' => 'draft',
			)
		);
		wp_trash_post( $id );
		wp_untrash_post( $id );

		$voci = $this->nuove();

		$this->assertSame( array( 'cambio_stato', 'cambio_stato', 'cambio_stato', 'cambio_stato' ), $this->azioni_nuove() );
		$this->assertSame(
			array( 'draft>pending', 'pending>draft', 'draft>trash', 'trash>draft' ),
			array_map(
				function ( $voce ) {
					return $voce['dettagli']['stato_precedente'] . '>' . $voce['dettagli']['stato_nuovo'];
				},
				$voci
			)
		);
	}

	/**
	 * C-200: l'eliminazione definitiva scrive una voce, e le voci del contenuto
	 * sopravvivono al contenuto.
	 */
	public function test_c200_eliminazione() {
		wp_set_current_user( $this->utente() );

		$id = $this->contenuto( 'publish' );
		$this->assertTrue( conformita_core_imposta_fine_pubblicazione( $id, '2026-10-20' ) );

		$prima = conformita_core_voci_registro( array( 'contenuto' => $id ) );
		$this->assertNotEmpty( $prima, 'Precondizione: il contenuto ha gia\' delle voci.' );

		$this->segna();
		wp_delete_post( $id, true );

		$this->assertNull( get_post( $id ), 'Precondizione: il contenuto non esiste piu\'.' );
		$this->assertSame(
			array( 'eliminazione' ),
			$this->azioni_nuove(),
			'Una eliminazione, una voce: la cancellazione dei metadati che WordPress fa insieme non ne aggiunge altre.'
		);
		$this->assertSame( array( 'stato' => 'publish' ), $this->nuove()[0]['dettagli'], 'La voce dice da quale stato e\' stato eliminato.' );

		$dopo = conformita_core_voci_registro( array( 'contenuto' => $id ) );
		$this->assertSame(
			wp_list_pluck( $prima, 'id' ),
			array_slice( wp_list_pluck( $dopo, 'id' ), 0, count( $prima ) ),
			'Le voci di prima restano tutte.'
		);
	}

	/**
	 * C-201: le bozze non si registrano; lo stesso lavoro fuori dalla bozza si.
	 */
	public function test_c201_bozze() {
		wp_set_current_user( $this->utente() );

		$risultati = array();

		foreach ( array( 'draft', 'pending' ) as $stato ) {
			$id = $this->contenuto( $stato );

			$this->segna();
			wp_update_post(
				array(
					'ID'         => $id,
					'post_title' => 'Cambio nella ' . $stato,
				)
			);
			conformita_core_imposta_fine_pubblicazione( $id, '2026-11-01' );
			self::factory()->attachment->create_object(
				array(
					'file'           => 'prova-' . $stato . '.pdf',
					'post_parent'    => $id,
					'post_mime_type' => 'application/pdf',
				)
			);

			$risultati[ $stato ] = $this->azioni_nuove();
		}

		$this->assertSame( array(), $risultati['draft'], 'Nella bozza nessuna voce.' );
		$this->assertSame(
			array( 'modifica', 'modifica_fine_pubblicazione', 'allegato_aggiunto' ),
			$risultati['pending'],
			'Controllo positivo: fuori dalla bozza le stesse tre operazioni scrivono tre voci.'
		);

		/*
		 * L'eccezione ha un confine: l'eliminazione di una bozza si registra,
		 * perche' una bozza puo' avere una storia, e i salvataggi automatici
		 * che giustificano l'eccezione non c'entrano.
		 */
		$storia = $this->contenuto( 'publish' );
		wp_update_post(
			array(
				'ID'          => $storia,
				'post_status' => 'draft',
			)
		);

		$this->segna();
		wp_delete_post( $storia, true );
		$this->assertSame( array( 'eliminazione' ), $this->azioni_nuove() );
		$this->assertSame( array( 'stato' => 'draft' ), $this->nuove()[0]['dettagli'] );
	}

	/**
	 * C-202: ogni cambio della fine della pubblicazione scrive una voce con il
	 * valore di prima e quello di dopo, e scrivere lo stesso valore non la scrive.
	 */
	public function test_c202_fine_pubblicazione() {
		wp_set_current_user( $this->utente() );

		$id = $this->contenuto( 'publish' );

		$this->segna();
		$this->assertTrue( conformita_core_imposta_fine_pubblicazione( $id, '2026-10-16' ) );
		$this->assertTrue( conformita_core_imposta_fine_pubblicazione( $id, '2026-10-16' ) );
		$this->assertTrue( conformita_core_imposta_fine_pubblicazione( $id, '2026-10-01' ) );
		delete_post_meta( $id, conformita_core_chiave_fine_pubblicazione() );

		$voci = $this->nuove();

		$this->assertSame( array( 'modifica_fine_pubblicazione', 'modifica_fine_pubblicazione', 'modifica_fine_pubblicazione' ), $this->azioni_nuove() );
		$this->assertSame(
			array(
				array( array(), array( '2026-10-16' ) ),
				array( array( '2026-10-16' ), array( '2026-10-01' ) ),
				array( array( '2026-10-01' ), array() ),
			),
			array_map(
				function ( $voce ) {
					return array( $voce['dettagli']['valore_precedente'], $voce['dettagli']['valore_nuovo'] );
				},
				$voci
			)
		);

		/*
		 * Due righe per la stessa chiave aggiornate con una sola scrittura:
		 * WordPress segnala una scrittura per riga, e il registro deve scrivere
		 * una voce per l'operazione, non una per riga.
		 */
		add_post_meta( $id, conformita_core_chiave_fine_pubblicazione(), '2026-10-05' );
		add_post_meta( $id, conformita_core_chiave_fine_pubblicazione(), '2026-10-06' );

		$this->segna();
		update_post_meta( $id, conformita_core_chiave_fine_pubblicazione(), '2026-10-09' );

		$voci = $this->nuove();
		$this->assertCount( 1, $voci, 'Una scrittura su due righe, una voce.' );
		$this->assertSame( array( '2026-10-05', '2026-10-06' ), $voci[0]['dettagli']['valore_precedente'] );
		$this->assertSame( array( '2026-10-09', '2026-10-09' ), $voci[0]['dettagli']['valore_nuovo'] );
	}

	/**
	 * C-203: un allegato aggiunto o eliminato scrive una voce sul contenuto a
	 * cui appartiene.
	 */
	public function test_c203_allegati() {
		wp_set_current_user( $this->utente() );

		$id = $this->contenuto( 'publish' );

		$this->segna();
		$allegato = self::factory()->attachment->create_object(
			array(
				'file'           => 'documento.pdf',
				'post_parent'    => $id,
				'post_mime_type' => 'application/pdf',
			)
		);
		wp_delete_attachment( $allegato, true );

		$voci = $this->nuove();

		$this->assertSame( array( 'allegato_aggiunto', 'allegato_eliminato' ), $this->azioni_nuove() );

		foreach ( $voci as $voce ) {
			$this->assertSame( $id, $voce['contenuto'], 'La voce sta sul contenuto padre.' );
			$this->assertSame( array( 'allegato' => $allegato ), $voce['dettagli'] );
		}

		/*
		 * Un allegato gia' esistente che cambia padre: con il salvataggio di
		 * WordPress, e con il collegamento della libreria dei media, che scrive
		 * sulla banca dati direttamente e lo annuncia con un aggancio suo.
		 */
		$altro  = $this->contenuto( 'publish' );
		$libero = self::factory()->attachment->create_object(
			array(
				'file'           => 'libero.pdf',
				'post_mime_type' => 'application/pdf',
			)
		);

		$this->segna();
		wp_update_post(
			array(
				'ID'          => $libero,
				'post_parent' => $id,
			)
		);
		wp_update_post(
			array(
				'ID'          => $libero,
				'post_parent' => $altro,
			)
		);
		$this->assertSame(
			array( array( 'allegato_aggiunto', $id ), array( 'allegato_eliminato', $id ), array( 'allegato_aggiunto', $altro ) ),
			array_map(
				function ( $voce ) {
					return array( $voce['azione'], $voce['contenuto'] );
				},
				$this->nuove()
			),
			'Il padre cambiato col salvataggio: tolto da uno, aggiunto all\'altro.'
		);

		$this->segna();
		do_action( 'wp_media_attach_action', 'detach', $libero, $altro );
		do_action( 'wp_media_attach_action', 'attach', $libero, $id );
		$this->assertSame(
			array( array( 'allegato_eliminato', $altro ), array( 'allegato_aggiunto', $id ) ),
			array_map(
				function ( $voce ) {
					return array( $voce['azione'], $voce['contenuto'] );
				},
				$this->nuove()
			),
			'Il collegamento dalla libreria dei media.'
		);
	}

	/**
	 * C-204: le stesse operazioni su un tipo che core non governa non scrivono
	 * niente.
	 */
	public function test_c204_tipi_non_gestiti() {
		wp_set_current_user( $this->utente() );

		$this->segna();
		$articolo = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		wp_update_post(
			array(
				'ID'         => $articolo,
				'post_title' => 'Cambiato',
			)
		);
		update_post_meta( $articolo, conformita_core_chiave_fine_pubblicazione(), '2026-10-10' );
		self::factory()->attachment->create_object(
			array(
				'file'        => 'articolo.pdf',
				'post_parent' => $articolo,
			)
		);
		wp_trash_post( $articolo );
		wp_delete_post( $articolo, true );

		$this->assertSame( array(), $this->azioni_nuove() );
		$this->assertSame( 0, Conformita_Core_Registro::mancate()['conteggio'], 'Un tipo non gestito non e\' una voce mancata: non doveva esserci.' );

		$gestito = $this->contenuto( 'publish' );
		wp_trash_post( $gestito );
		$this->assertSame( array( 'creazione', 'pubblicazione', 'rimozione' ), $this->azioni_nuove(), 'Controllo positivo sul tipo gestito.' );
	}

	/**
	 * C-205: chi ha agito e' il numero dell'utente della richiesta, zero se non
	 * c'e' nessun utente, e nella voce non c'e' nessun altro dato della persona.
	 */
	public function test_c205_chi() {
		$utente = self::factory()->user->create(
			array(
				'role'         => 'editor',
				'user_login'   => 'maria_rossi_prova',
				'user_email'   => 'maria.rossi@example.org',
				'display_name' => 'Maria Rossi Prova',
			)
		);

		wp_set_current_user( 0 );
		$this->segna();
		$id = $this->contenuto( 'publish' );
		$this->assertSame( array( 0, 0 ), wp_list_pluck( $this->nuove(), 'utente' ), 'Senza utente, per esempio il compito pianificato: zero.' );

		wp_set_current_user( $utente );
		$this->segna();
		wp_trash_post( $id );
		$this->assertSame( array( $utente ), wp_list_pluck( $this->nuove(), 'utente' ) );

		$righe = $this->righe();
		$riga  = end( $righe );

		$this->assertSame(
			array( 'id', 'istante', 'utente', 'sezione', 'contenuto', 'tipo', 'azione', 'origine', 'motivazione', 'dettagli', 'riferimento', 'chiave' ),
			array_keys( $riga ),
			'Le colonne sono queste e nessun\'altra.'
		);

		$testo = wp_json_encode( $riga );

		foreach ( array( 'maria', 'rossi', 'example.org' ) as $dato ) {
			$this->assertStringNotContainsStringIgnoringCase( $dato, $testo );
		}
	}

	/**
	 * C-206: una voce scritta da un componente si rilegge uguale, con l'utente
	 * della richiesta, l'istante di core e l'origine del componente.
	 */
	public function test_c206_voce_del_componente() {
		$utente = $this->utente();
		wp_set_current_user( $utente );

		$id = $this->contenuto( 'publish' );

		$this->segna();
		$voce = conformita_core_registra_voce(
			array(
				'sezione'     => self::SEZIONE,
				'azione'      => 'rimozione_anticipata',
				'contenuto'   => $id,
				'motivazione' => "  Dati personali pubblicati per errore.\nSeconda riga.  ",
				'dettagli'    => array(
					'causa'          => 'dati_non_diffondibili',
					'fine_prevista'  => '2026-10-16',
					'fine_effettiva' => '2026-10-01',
					'giorni'         => 15,
					'confermata'     => true,
					'nota'           => null,
					'stati'          => array( 'publish', 'defisso' ),
				),
			)
		);

		$this->assertIsInt( $voce );
		$this->assertCount( 1, $this->nuove(), 'Una chiamata, una voce: nessuna voce automatica accanto.' );

		$letta = conformita_core_voce_registro( $voce );

		$this->assertSame( $voce, $letta['id'] );
		$this->assertSame( 'rimozione_anticipata', $letta['azione'] );
		$this->assertSame( Conformita_Core_Registro::ORIGINE_COMPONENTE, $letta['origine'] );
		$this->assertSame( $utente, $letta['utente'] );
		$this->assertSame( $id, $letta['contenuto'] );
		$this->assertSame( self::TIPO, $letta['tipo'] );
		$this->assertSame( "Dati personali pubblicati per errore.\nSeconda riga.", $letta['motivazione'] );
		$this->assertSame( '2026-10-02 09:15:30', $letta['istante']->format( 'Y-m-d H:i:s' ) );
		$this->assertSame(
			array(
				'causa'          => 'dati_non_diffondibili',
				'fine_prevista'  => '2026-10-16',
				'fine_effettiva' => '2026-10-01',
				'giorni'         => 15,
				'confermata'     => true,
				'nota'           => null,
				'stati'          => array( 'publish', 'defisso' ),
			),
			$letta['dettagli']
		);
		$this->assertNull( $letta['riferimento'] );
		$this->assertNull( $letta['chiave'] );

		$sezione = conformita_core_registra_voce(
			array(
				'sezione' => self::SEZIONE,
				'azione'  => 'numerazione_dichiarata',
			)
		);
		$this->assertIsInt( $sezione, 'Una voce puo\' riguardare la sezione e nessun contenuto.' );
		$this->assertNull( conformita_core_voce_registro( $sezione )['contenuto'] );
	}

	/**
	 * Descrizioni sbagliate, ciascuna con il codice d'errore atteso.
	 *
	 * @return array<string, array{0: mixed, 1: string}>
	 */
	private function descrizioni_sbagliate() {
		$gestito  = $this->contenuto( 'publish' );
		$altro    = $this->contenuto( 'publish', self::TIPO_ALTRO );
		$post     = self::factory()->post->create();
		$fratello = $this->contenuto( 'publish' );

		$di_altro   = conformita_core_registra_voce(
			array(
				'sezione'   => self::SEZIONE,
				'azione'    => 'prima',
				'contenuto' => $fratello,
			)
		);
		$di_sezione = conformita_core_registra_voce(
			array(
				'sezione' => self::SEZIONE_ALTRA,
				'azione'  => 'prima',
			)
		);

		$base = array(
			'sezione'   => self::SEZIONE,
			'azione'    => 'prova',
			'contenuto' => $gestito,
		);

		$casi = array(
			'non un elenco'                     => array( 'testo', 'conformita_core_registro_voce_non_valida' ),
			'senza sezione'                     => array( array( 'azione' => 'prova' ), 'conformita_core_registro_sezione_sconosciuta' ),
			'sezione non registrata'            => array( array_merge( $base, array( 'sezione' => 'mai_registrata' ) ), 'conformita_core_registro_sezione_sconosciuta' ),
			'sezione non testo'                 => array( array_merge( $base, array( 'sezione' => array( self::SEZIONE ) ) ), 'conformita_core_registro_sezione_sconosciuta' ),
			'senza azione'                      => array( array( 'sezione' => self::SEZIONE ), 'conformita_core_registro_azione_non_valida' ),
			'azione vuota'                      => array( array_merge( $base, array( 'azione' => '' ) ), 'conformita_core_registro_azione_non_valida' ),
			'azione maiuscola'                  => array( array_merge( $base, array( 'azione' => 'Prova' ) ), 'conformita_core_registro_azione_non_valida' ),
			'azione con trattino'               => array( array_merge( $base, array( 'azione' => 'rimando-in-bozza' ) ), 'conformita_core_registro_azione_non_valida' ),
			'azione con a capo finale'          => array( array_merge( $base, array( 'azione' => "prova\n" ) ), 'conformita_core_registro_azione_non_valida' ),
			'azione lunga 65'                   => array( array_merge( $base, array( 'azione' => str_repeat( 'a', 65 ) ) ), 'conformita_core_registro_azione_non_valida' ),
			'contenuto inesistente'             => array( array_merge( $base, array( 'contenuto' => 999999 ) ), 'conformita_core_registro_contenuto_sconosciuto' ),
			'contenuto zero'                    => array( array_merge( $base, array( 'contenuto' => 0 ) ), 'conformita_core_registro_contenuto_sconosciuto' ),
			'contenuto non numero'              => array( array_merge( $base, array( 'contenuto' => '12abc' ) ), 'conformita_core_registro_contenuto_sconosciuto' ),
			'contenuto non gestito'             => array( array_merge( $base, array( 'contenuto' => $post ) ), 'conformita_core_registro_contenuto_estraneo' ),
			'contenuto di un\'altra sezione'    => array( array_merge( $base, array( 'contenuto' => $altro ) ), 'conformita_core_registro_contenuto_estraneo' ),
			'motivazione vuota'                 => array( array_merge( $base, array( 'motivazione' => '' ) ), 'conformita_core_registro_motivazione_vuota' ),
			'motivazione di soli spazi'         => array( array_merge( $base, array( 'motivazione' => " \n\t " ) ), 'conformita_core_registro_motivazione_vuota' ),
			'motivazione non testo'             => array( array_merge( $base, array( 'motivazione' => 12 ) ), 'conformita_core_registro_motivazione_vuota' ),
			'motivazione non UTF-8'             => array( array_merge( $base, array( 'motivazione' => "caf\xe9" ) ), 'conformita_core_registro_motivazione_vuota' ),
			'dettagli non elenco'               => array( array_merge( $base, array( 'dettagli' => 'testo' ) ), 'conformita_core_registro_dettagli_non_validi' ),
			'dettagli con nome numerico'        => array( array_merge( $base, array( 'dettagli' => array( 'uno' ) ) ), 'conformita_core_registro_dettagli_non_validi' ),
			'dettagli con nome maiuscolo'       => array( array_merge( $base, array( 'dettagli' => array( 'Causa' => 'x' ) ) ), 'conformita_core_registro_dettagli_non_validi' ),
			'dettagli annidati'                 => array( array_merge( $base, array( 'dettagli' => array( 'a' => array( array( 'b' ) ) ) ) ), 'conformita_core_registro_dettagli_non_validi' ),
			'dettagli con elenco a chiavi'      => array( array_merge( $base, array( 'dettagli' => array( 'a' => array( 'x' => 'y' ) ) ) ), 'conformita_core_registro_dettagli_non_validi' ),
			'dettagli con oggetto'              => array( array_merge( $base, array( 'dettagli' => array( 'a' => new stdClass() ) ) ), 'conformita_core_registro_dettagli_non_validi' ),
			'dettagli con infinito'             => array( array_merge( $base, array( 'dettagli' => array( 'a' => INF ) ) ), 'conformita_core_registro_dettagli_non_validi' ),
			'dettagli non UTF-8'                => array( array_merge( $base, array( 'dettagli' => array( 'a' => "caf\xe9" ) ) ), 'conformita_core_registro_dettagli_non_validi' ),
			'riferimento inesistente'           => array( array_merge( $base, array( 'riferimento' => 99999999 ) ), 'conformita_core_registro_riferimento_sconosciuto' ),
			'riferimento di un altro contenuto' => array( array_merge( $base, array( 'riferimento' => $di_altro ) ), 'conformita_core_registro_riferimento_estraneo' ),
			'riferimento di un\'altra sezione'  => array(
				array(
					'sezione'     => self::SEZIONE,
					'azione'      => 'prova',
					'riferimento' => $di_sezione,
				),
				'conformita_core_registro_riferimento_estraneo',
			),
			'chiave con spazio'                 => array( array_merge( $base, array( 'chiave' => 'una chiave' ) ), 'conformita_core_registro_chiave_non_valida' ),
			'chiave vuota'                      => array( array_merge( $base, array( 'chiave' => '' ) ), 'conformita_core_registro_chiave_non_valida' ),
			'chiave lunga 192'                  => array( array_merge( $base, array( 'chiave' => str_repeat( 'k', 192 ) ) ), 'conformita_core_registro_chiave_non_valida' ),
			'chiave non testo'                  => array( array_merge( $base, array( 'chiave' => 7 ) ), 'conformita_core_registro_chiave_non_valida' ),
			'chiave sconosciuta motivo'         => array( array_merge( $base, array( 'motivo' => 'errore' ) ), 'conformita_core_registro_chiave_sconosciuta' ),
			'chi dichiarato'                    => array( array_merge( $base, array( 'utente' => 1 ) ), 'conformita_core_registro_chiave_sconosciuta' ),
			'quando dichiarato'                 => array( array_merge( $base, array( 'istante' => '2020-01-01 00:00:00' ) ), 'conformita_core_registro_chiave_sconosciuta' ),
			'origine dichiarata'                => array( array_merge( $base, array( 'origine' => 'automatica' ) ), 'conformita_core_registro_chiave_sconosciuta' ),
		);

		foreach ( Conformita_Core_Registro_Automatico::azioni() as $riservata ) {
			$casi[ 'azione riservata ' . $riservata ] = array( array_merge( $base, array( 'azione' => $riservata ) ), 'conformita_core_registro_azione_riservata' );
		}

		return $casi;
	}

	/**
	 * C-207: ogni descrizione sbagliata e' rifiutata con il suo errore, e la
	 * tabella non cambia.
	 */
	public function test_c207_rifiuti() {
		wp_set_current_user( $this->utente() );

		$casi = $this->descrizioni_sbagliate();

		$valida = conformita_core_registra_voce(
			array(
				'sezione'     => self::SEZIONE,
				'azione'      => 'prova',
				'motivazione' => 'Controllo positivo',
			)
		);
		$this->assertIsInt( $valida, 'Controllo positivo: la stessa forma, corretta, riesce.' );

		$fotografia = $this->righe();

		foreach ( $casi as $nome => $caso ) {
			$esito = conformita_core_registra_voce( $caso[0] );

			$this->assertWPError( $esito, "Caso {$nome}: doveva essere rifiutato." );
			$this->assertSame( $caso[1], $esito->get_error_code(), "Caso {$nome}: codice d'errore." );
		}

		$this->assertSame( $fotografia, $this->righe(), 'Nessun rifiuto ha lasciato traccia nella tabella.' );
	}

	/**
	 * C-208: una voce successiva rimanda a una precedente senza toccarla, e si
	 * ritrovano insieme leggendo il contenuto.
	 */
	public function test_c208_riferimento() {
		$primo = $this->utente();
		wp_set_current_user( $primo );

		$id = $this->contenuto( 'publish' );

		$rimozione = conformita_core_registra_voce(
			array(
				'sezione'     => self::SEZIONE,
				'azione'      => 'rimozione_anticipata',
				'contenuto'   => $id,
				'motivazione' => 'Causa dell\'elenco',
			)
		);
		$originale = $this->righe();

		$secondo = $this->utente();
		wp_set_current_user( $secondo );
		Conformita_Core_Scadenza::fissa_orologio( new DateTimeImmutable( '2026-10-06 14:00:00', new DateTimeZone( 'UTC' ) ) );

		$risposta = conformita_core_registra_voce(
			array(
				'sezione'     => self::SEZIONE,
				'azione'      => 'effetto_sul_periodo',
				'contenuto'   => $id,
				'riferimento' => $rimozione,
				'dettagli'    => array( 'effetto' => 'vale_per_il_periodo_trascorso' ),
			)
		);

		$this->assertIsInt( $risposta );
		$this->assertSame( $originale, array_slice( $this->righe(), 0, count( $originale ) ), 'La voce di prima resta com\'era.' );

		$letta = conformita_core_voce_registro( $risposta );
		$this->assertSame( $rimozione, $letta['riferimento'] );
		$this->assertSame( $secondo, $letta['utente'], 'Chi risponde dopo e\' chi ha risposto, non chi aveva rimosso.' );
		$this->assertSame( '2026-10-06 14:00:00', $letta['istante']->format( 'Y-m-d H:i:s' ) );

		$del_contenuto = wp_list_pluck( conformita_core_voci_registro( array( 'contenuto' => $id ) ), 'id' );
		$this->assertSame( array( $rimozione, $risposta ), array_slice( $del_contenuto, -2 ), 'In ordine di scrittura, la risposta dopo la rimozione.' );
		$this->assertSame( array( $risposta ), wp_list_pluck( conformita_core_voci_registro( array( 'riferimento' => $rimozione ) ), 'id' ) );
	}

	/**
	 * C-209: una chiave di unicita' gia' usata ferma la seconda voce, e il
	 * vincolo sta nella banca dati, non solo nel controllo che la precede.
	 */
	public function test_c209_chiave_unica() {
		wp_set_current_user( $this->utente() );

		$id = $this->contenuto( 'publish' );

		$prima = conformita_core_registra_voce(
			array(
				'sezione'   => self::SEZIONE,
				'azione'    => 'effetto_sul_periodo',
				'contenuto' => $id,
				'dettagli'  => array( 'effetto' => 'va_rifatta' ),
				'chiave'    => 'effetto_sul_periodo:' . $id,
			)
		);
		$this->assertIsInt( $prima );

		$fotografia = $this->righe();

		$seconda = conformita_core_registra_voce(
			array(
				'sezione'   => self::SEZIONE,
				'azione'    => 'effetto_sul_periodo',
				'contenuto' => $id,
				'dettagli'  => array( 'effetto' => 'vale_per_il_periodo_trascorso' ),
				'chiave'    => 'effetto_sul_periodo:' . $id,
			)
		);

		$this->assertWPError( $seconda );
		$this->assertSame( 'conformita_core_registro_chiave_esistente', $seconda->get_error_code() );
		$this->assertSame( array( 'voce' => $prima ), $seconda->get_error_data() );
		$this->assertSame( $fotografia, $this->righe(), 'La prima resta com\'era e non se ne aggiunge un\'altra.' );

		$maiuscola = conformita_core_registra_voce(
			array(
				'sezione'   => self::SEZIONE,
				'azione'    => 'effetto_sul_periodo',
				'contenuto' => $id,
				'chiave'    => 'EFFETTO_SUL_PERIODO:' . $id,
			)
		);
		$this->assertIsInt( $maiuscola, 'La chiave si confronta byte per byte: maiuscole e minuscole sono chiavi diverse.' );

		global $wpdb;
		$tabella = Conformita_Core_Registro::tabella();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- prova: struttura della tabella.
		$indici = $wpdb->get_results( "SHOW INDEX FROM {$tabella} WHERE Column_name = 'chiave'", ARRAY_A );
		$this->assertCount( 1, $indici );
		$this->assertSame( '0', (string) $indici[0]['Non_unique'], 'Due scritture contemporanee le ferma il vincolo di unicita\' della banca dati.' );
	}

	/**
	 * Rompe la prossima scrittura nel registro.
	 *
	 * @param string $sql Istruzione.
	 * @return string
	 */
	public function rompi_inserimento( $sql ) {
		if ( 0 === strpos( $sql, 'INSERT INTO `' . Conformita_Core_Registro::tabella() . '`' ) ) {
			return 'INSERT INTO tabella_che_non_esiste_mai VALUES (1)';
		}

		return $sql;
	}

	/**
	 * C-210: se la scrittura fallisce, il componente riceve un errore, e una
	 * voce automatica mancata resta annotata.
	 */
	public function test_c210_scrittura_fallita() {
		wp_set_current_user( $this->utente() );

		$id = $this->contenuto( 'pending' );

		$fotografia = $this->righe();
		$this->assertSame( 0, Conformita_Core_Registro::mancate()['conteggio'], 'Precondizione: nessuna voce mancata.' );

		add_filter( 'query', array( $this, 'rompi_inserimento' ) );

		$esito = conformita_core_registra_voce(
			array(
				'sezione' => self::SEZIONE,
				'azione'  => 'prova',
			)
		);

		wp_update_post(
			array(
				'ID'          => $id,
				'post_status' => 'publish',
			)
		);

		remove_filter( 'query', array( $this, 'rompi_inserimento' ) );

		$this->assertWPError( $esito );
		$this->assertSame( 'conformita_core_registro_non_scritto', $esito->get_error_code() );
		$this->assertSame( 'publish', get_post_status( $id ), 'L\'operazione e\' avvenuta: il registro non la disfa.' );
		$this->assertSame( $fotografia, $this->righe(), 'Nessuna voce scritta.' );

		$mancate = Conformita_Core_Registro::mancate();
		$this->assertSame( 1, $mancate['conteggio'], 'La pubblicazione senza voce e\' annotata.' );
		$this->assertSame( '2026-10-02 09:15:30', $mancate['prima'] );
		$this->assertSame( '2026-10-02 09:15:30', $mancate['ultima'] );
	}

	/**
	 * Cambia il testo della motivazione mentre si scrive.
	 *
	 * @param string $sql Istruzione.
	 * @return string
	 */
	public function altera_inserimento( $sql ) {
		if ( 0 === strpos( $sql, 'INSERT INTO `' . Conformita_Core_Registro::tabella() . '`' ) ) {
			return str_replace( 'Motivo dichiarato', 'Motivo diverso', $sql );
		}

		return $sql;
	}

	/**
	 * C-211: una scrittura che riesce ma scrive altro da quanto dichiarato
	 * restituisce errore, non il numero della voce.
	 */
	public function test_c211_rilettura() {
		wp_set_current_user( $this->utente() );

		add_filter( 'query', array( $this, 'altera_inserimento' ) );

		$esito = conformita_core_registra_voce(
			array(
				'sezione'     => self::SEZIONE,
				'azione'      => 'prova',
				'motivazione' => 'Motivo dichiarato',
			)
		);

		remove_filter( 'query', array( $this, 'altera_inserimento' ) );

		$this->assertWPError( $esito );
		$this->assertSame( 'conformita_core_registro_non_conforme', $esito->get_error_code() );

		$controllo = conformita_core_registra_voce(
			array(
				'sezione'     => self::SEZIONE,
				'azione'      => 'prova',
				'motivazione' => 'Motivo dichiarato',
			)
		);
		$this->assertIsInt( $controllo, 'Controllo positivo: senza alterazione la stessa voce riesce.' );
	}

	/**
	 * C-212: nel codice non c'e' nessuna strada per modificare o cancellare una
	 * voce.
	 */
	public function test_c212_nessuna_strada_per_modificare() {
		$cartella = dirname( __DIR__ ) . '/includes';
		$tabella  = Conformita_Core_Registro::TABELLA;
		$propri   = array(
			'class-conformita-core-registro.php',
			'class-conformita-core-registro-automatico.php',
			'class-conformita-core-registro-schermata.php',
		);

		$trovati = array();

		foreach ( glob( $cartella . '/*.php' ) as $file ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- prova statica: si legge il sorgente.
			$sorgente = file_get_contents( $file );
			$nome     = basename( $file );

			if ( in_array( $nome, $propri, true ) ) {
				if ( preg_match( '/->\s*(update|delete|replace)\s*\(|\b(UPDATE|DELETE|REPLACE|TRUNCATE|DROP|ALTER|RENAME)\b/', $sorgente, $corrispondenza ) ) {
					$trovati[] = $nome . ': ' . $corrispondenza[0];
				}
			} elseif ( false !== strpos( $sorgente, $tabella ) || false !== strpos( $sorgente, 'Conformita_Core_Registro::tabella' ) ) {
				$trovati[] = $nome . ': nomina la tabella del registro';
			}
		}

		$this->assertSame( array(), $trovati, 'Solo le classi del registro toccano la tabella, e nessuna la modifica.' );

		$funzioni = array_filter(
			get_defined_functions()['user'],
			function ( $funzione ) {
				return 0 === strpos( $funzione, 'conformita_core_' ) && preg_match( '/(registro|voce)/', $funzione );
			}
		);
		sort( $funzioni );

		$this->assertSame(
			array( 'conformita_core_capacita_registro', 'conformita_core_registra_voce', 'conformita_core_voce_registro', 'conformita_core_voci_registro' ),
			array_values( $funzioni ),
			'Le funzioni pubbliche del registro sono queste quattro: una aggiunge, due leggono, una nomina il permesso.'
		);

		$metodi = get_class_methods( 'Conformita_Core_Registro' );
		$this->assertSame( array(), preg_grep( '/(modific|cancell|elimin|aggiorn|rimuov|sostitu)/i', $metodi ) );
	}

	/**
	 * C-213: nessuna superficie esterna scrive o legge il registro.
	 */
	public function test_c213_nessuna_superficie_esterna() {
		global $wp_filter;

		$agganci = array_filter(
			array_keys( $wp_filter ),
			function ( $nome ) {
				return ( 0 === strpos( $nome, 'admin_post' ) || 0 === strpos( $nome, 'wp_ajax' ) ) && false !== strpos( $nome, 'registro' );
			}
		);
		$this->assertSame( array(), array_values( $agganci ), 'Nessuna azione di amministrazione o chiamata asincrona sul registro.' );

		$rotte = array_filter(
			array_keys( rest_get_server()->get_routes() ),
			function ( $rotta ) {
				return false !== strpos( $rotta, 'registro' );
			}
		);
		$this->assertSame( array(), array_values( $rotte ), 'Il registro non e\' esposto all\'interfaccia per programmi.' );
	}

	/**
	 * C-222: la tabella esiste con la versione dello schema, il controllo
	 * all'avvio non la ricrea ogni volta, e la versione non si scrive se la
	 * tabella non c'e'.
	 */
	public function test_c222_installazione() {
		$this->assertTrue( Conformita_Core_Registro::tabella_presente() );
		$this->assertSame( Conformita_Core_Registro::VERSIONE_SCHEMA, get_option( Conformita_Core_Registro::OPZIONE_SCHEMA ) );

		$istruzioni = array();
		$conta      = function ( $sql ) use ( &$istruzioni ) {
			$istruzioni[] = $sql;
			return $sql;
		};

		add_filter( 'query', $conta );
		Conformita_Core_Registro::assicura_tabella();
		remove_filter( 'query', $conta );

		$this->assertSame( array(), $istruzioni, 'Con la versione giusta il controllo all\'avvio non tocca la banca dati.' );

		delete_option( Conformita_Core_Registro::OPZIONE_SCHEMA );

		$nascondi = function ( $sql ) {
			if ( 0 === strpos( $sql, 'SHOW TABLES LIKE' ) ) {
				return "SHOW TABLES LIKE 'nessuna_tabella_si_chiama_cosi'";
			}
			return $sql;
		};

		add_filter( 'query', $nascondi );
		$esito = Conformita_Core_Registro::installa();
		remove_filter( 'query', $nascondi );

		$this->assertFalse( $esito );
		$this->assertFalse( get_option( Conformita_Core_Registro::OPZIONE_SCHEMA ), 'Senza tabella la versione non si scrive, e il prossimo avvio riprova.' );

		$istruzioni = array();
		add_filter( 'query', $conta );
		$this->assertTrue( Conformita_Core_Registro::installa(), 'Controllo positivo.' );
		remove_filter( 'query', $conta );

		$this->assertSame(
			array(),
			preg_grep( '/^\s*(ALTER|CREATE|DROP)\b/i', $istruzioni ),
			'Su una tabella gia\' allineata l\'installazione non cambia la struttura: ripeterla a ogni aggiornamento non costa niente.'
		);
		$this->assertSame( Conformita_Core_Registro::VERSIONE_SCHEMA, get_option( Conformita_Core_Registro::OPZIONE_SCHEMA ) );
	}

	/**
	 * C-223: la versione dell'interfaccia sale a 1.4.0 e le quattro funzioni
	 * nuove esistono.
	 */
	public function test_c223_contratto_pubblico() {
		$this->assertSame( '1.4.0', conformita_core_versione_api() );
		$this->assertTrue( conformita_core_api_compatibile( '1.3.0', conformita_core_versione_api() ), 'Chi chiedeva 1.3.0 resta compatibile.' );
		$this->assertSame( Conformita_Core_Registro::CAPACITA, conformita_core_capacita_registro() );
	}

	/**
	 * C-224: a voci automatiche spente le operazioni delle righe C-195..C-203
	 * non scrivono niente. Se scrivessero lo stesso, quelle righe starebbero
	 * misurando altro.
	 */
	public function test_c224_non_vacuita_voci_automatiche() {
		wp_set_current_user( $this->utente() );

		$id = $this->contenuto( 'pending' );

		Conformita_Core_Registro_Automatico::spegni();
		$this->assertFalse( Conformita_Core_Registro_Automatico::avviato() );

		foreach ( Conformita_Core_Registro_Automatico::agganci() as $aggancio ) {
			$this->assertFalse(
				has_action( $aggancio['aggancio'], array( 'Conformita_Core_Registro_Automatico', $aggancio['metodo'] ) ),
				'Spento vuol dire spento: ' . $aggancio['aggancio']
			);
		}

		$this->segna();
		wp_update_post(
			array(
				'ID'          => $id,
				'post_status' => 'publish',
			)
		);
		wp_update_post(
			array(
				'ID'         => $id,
				'post_title' => 'Cambiato',
			)
		);
		conformita_core_imposta_fine_pubblicazione( $id, '2026-10-30' );
		wp_trash_post( $id );

		$this->assertSame( array(), $this->azioni_nuove() );

		Conformita_Core_Registro_Automatico::avvia();

		wp_untrash_post( $id );
		$this->assertNotSame( array(), $this->azioni_nuove(), 'Riaccese, le voci tornano.' );
	}
}
