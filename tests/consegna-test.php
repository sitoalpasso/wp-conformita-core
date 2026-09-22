<?php
/**
 * I due punti di consegna: indirizzi, catena di controlli, intestazioni.
 *
 * Righe di collaudo C-126..C-145, C-159, C-160.
 *
 * **Come si intercetta la risposta.** Il punto pubblico, in esercizio, manda le
 * intestazioni, riversa i byte ed esce. Uscire dentro una prova ucciderebbe il
 * processo, quindi l'ultimo passo e' sostituibile: `fissa_emettitore()` mette
 * al posto dell'emissione una funzione che registra e basta. Tutto quello che
 * viene prima (l'aggancio, le variabili d'interrogazione, la catena di
 * controlli, la costruzione delle intestazioni) gira davvero. E' la stessa
 * idea dell'orologio iniettato di `Conformita_Core_Scadenza`.
 *
 * **Perche' i rifiuti non dicono perche'.** La catena non restituisce nessun
 * motivo: tutti i rifiuti sono la stessa risposta, ed e' quello che la riga
 * C-138 pretende. Che ogni anello della catena sia davvero quello che rifiuta
 * non lo dimostra un codice d'errore, lo dimostrano le prove di non vacuita'
 * di `consegna-non-vacuita-test.php`.
 *
 * @package Conformita_Core
 */

/**
 * Prove sui due punti di consegna.
 */
class Conformita_Core_Consegna_Test extends WP_UnitTestCase {

	const SEZIONE = 'sezione_consegna';
	const TIPO    = 'prova_consegna';

	/**
	 * Le risposte che l'emettitore finto ha raccolto.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private $raccolte = array();

	/**
	 * File temporanei creati dalla prova.
	 *
	 * @var array<int, string>
	 */
	private $temporanei = array();

	/**
	 * Fuso del sito durante le prove.
	 *
	 * @var string
	 */
	private $fuso_originale = '';

	/**
	 * Sezione, tipo, cartella protetta verificata, emettitore finto.
	 */
	public function set_up() {
		parent::set_up();

		Conformita_Core_Sezioni::azzera();
		Conformita_Core_Tipi::azzera();
		Conformita_Core_Scadenza::azzera_orologio();
		Conformita_Core_Filtro_Scadenza::azzera_avvio();
		Conformita_Core_Filtro_Scadenza::avvia();
		Conformita_Core_Consegna::azzera_avvio();
		Conformita_Core_Consegna::avvia();
		Conformita_Core_Allegati::azzera();

		$this->fuso_originale = get_option( 'timezone_string' );
		update_option( 'timezone_string', 'Europe/Rome' );
		$this->set_permalink_structure( '' );

		$this->raccolte = array();

		add_filter( 'pre_http_request', array( $this, 'server_che_nega' ), 10, 3 );

		Conformita_Core_Consegna::fissa_emettitore(
			function ( array $risposta ) {
				$risposta['amministrazione'] = is_admin();
				$this->raccolte[]            = $risposta;
			}
		);

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
	}

	/**
	 * Rimette a posto tutto.
	 */
	public function tear_down() {
		remove_filter( 'pre_http_request', array( $this, 'server_che_nega' ), 10 );
		Conformita_Core_Consegna::azzera_emettitore();

		foreach ( $this->temporanei as $percorso ) {
			if ( file_exists( $percorso ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- prova: si tocca il disco direttamente perche' e' il disco cio' che si sta verificando.
				unlink( $percorso );
			}
		}

		$this->svuota_cartella_protetta();
		Conformita_Core_Allegati::azzera();

		unset( $_SERVER['HTTP_RANGE'] );
		$_GET     = array();
		$_REQUEST = array();

		update_option( 'timezone_string', $this->fuso_originale );

		parent::tear_down();
	}

	/**
	 * Il server finto nega il percorso dell'esca, cosi' i depositi passano.
	 *
	 * @param mixed $esito     Esito gia' deciso.
	 * @param mixed $argomenti Argomenti.
	 * @param mixed $indirizzo Indirizzo.
	 * @return array<string, mixed>
	 */
	public function server_che_nega( $esito, $argomenti = array(), $indirizzo = '' ) {
		unset( $esito, $argomenti, $indirizzo );

		return array(
			'response' => array(
				'code'    => 403,
				'message' => 'Forbidden',
			),
			'body'     => 'Forbidden',
		);
	}

	/**
	 * Toglie di mezzo la cartella protetta.
	 */
	private function svuota_cartella_protetta() {
		$cartella = Conformita_Core_Allegati::cartella();

		if ( ! is_dir( $cartella ) ) {
			return;
		}

		$voci = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $cartella, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);

		/*
		 * Si tolgono i file e si lasciano le cartelle. Non e' pigrizia:
		 * `wp_upload_dir()` tiene una memoria interna dei percorsi che ha gia'
		 * creato, quindi una cartella cancellata fra una prova e l'altra non
		 * verrebbe ricreata e lo spostamento dei byte fallirebbe per un motivo
		 * che non c'entra niente con quello che la prova verifica.
		 */
		foreach ( $voci as $voce ) {
			if ( ! $voce->isDir() ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- prova: si tocca il disco direttamente perche' e' il disco cio' che si sta verificando.
				unlink( $voce->getPathname() );
			}
		}
	}

	/**
	 * Un file vero sul disco nella forma di una voce di $_FILES.
	 *
	 * @param string      $nome     Nome del file.
	 * @param string|null $sorgente Percorso da copiare, oppure null per un PDF minimo.
	 * @return array<string, mixed>
	 */
	private function file_da_depositare( $nome = 'atto.pdf', $sorgente = null ) {
		$percorso = wp_tempnam( $nome );

		if ( null === $sorgente ) {
			file_put_contents( $percorso, "%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- prova: serve un file vero sul disco.
		} else {
			copy( $sorgente, $percorso );
		}

		$this->temporanei[] = $percorso;

		return array(
			'name'     => $nome,
			'tmp_name' => $percorso,
			'type'     => '',
			'size'     => filesize( $percorso ),
			'error'    => 0,
		);
	}

	/**
	 * Un atto pubblicato con la fine fra un mese.
	 *
	 * @param string $stato Stato del contenuto.
	 * @param string $fine  Data di fine, oppure vuoto per non scriverla.
	 * @return int
	 */
	private function atto( $stato = 'publish', $fine = null ) {
		$atto = self::factory()->post->create(
			array(
				'post_type'   => self::TIPO,
				'post_status' => $stato,
			)
		);

		if ( null === $fine ) {
			$fine = gmdate( 'Y-m-d', strtotime( '+30 days' ) );
		}

		if ( '' !== $fine ) {
			update_post_meta( $atto, conformita_core_chiave_fine_pubblicazione(), $fine );
		}

		return $atto;
	}

	/**
	 * Deposita un allegato su un atto e restituisce il suo identificativo.
	 *
	 * @param int         $atto     Contenuto padre.
	 * @param string      $nome     Nome del file.
	 * @param string|null $sorgente Percorso da copiare.
	 * @return int
	 */
	private function allegato( $atto, $nome = 'atto.pdf', $sorgente = null ) {
		$allegato = conformita_core_deposita_allegato(
			$atto,
			$this->file_da_depositare( $nome, $sorgente ),
			array( 'origine' => 'percorso_locale' )
		);

		$this->assertIsInt( $allegato, is_wp_error( $allegato ) ? $allegato->get_error_message() : '' );

		return $allegato;
	}

	/**
	 * Chiede il file dal punto pubblico, passando dall'aggancio vero.
	 *
	 * @param int $atto     Identificativo del contenuto dichiarato.
	 * @param int $allegato Identificativo dell'allegato.
	 * @return array<string, mixed>|null La risposta emessa, oppure null.
	 */
	private function chiedi( $atto, $allegato ) {
		$this->raccolte = array();

		$this->go_to(
			add_query_arg(
				array(
					'conformita_core_atto'     => $atto,
					'conformita_core_allegato' => $allegato,
				),
				home_url( '/' )
			)
		);

		return empty( $this->raccolte ) ? null : $this->raccolte[0];
	}

	/**
	 * Chiede il file dal punto amministrativo.
	 *
	 * @param int    $atto     Contenuto dichiarato.
	 * @param int    $allegato Allegato.
	 * @param string $nonce    Nonce da presentare, oppure null per quello giusto.
	 * @return array<string, mixed>|null
	 */
	private function chiedi_da_amministrazione( $atto, $allegato, $nonce = null ) {
		$this->raccolte = array();

		if ( null === $nonce ) {
			$nonce = wp_create_nonce( 'conformita_core_allegato_' . $allegato );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- prova: si sta costruendo la richiesta, nonce compreso, per collaudare proprio la verifica del nonce.
		$_GET = array(
			'action'   => 'conformita_core_allegato',
			'atto'     => $atto,
			'allegato' => $allegato,
			'_wpnonce' => $nonce,
		);
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- prova: si costruisce la richiesta, nonce compreso, per collaudare la verifica del nonce.
		$_REQUEST = $_GET;

		if ( is_user_logged_in() ) {
			do_action( 'admin_post_conformita_core_allegato' );
		} else {
			do_action( 'admin_post_nopriv_conformita_core_allegato' );
		}

		return empty( $this->raccolte ) ? null : $this->raccolte[0];
	}

	/**
	 * Un utente che possiede le capability del tipo.
	 *
	 * @return int
	 */
	private function utente_del_tipo() {
		$capacita = conformita_core_capacita_tipo( self::TIPO );
		$utente   = self::factory()->user->create( array( 'role' => 'editor' ) );
		$oggetto  = new WP_User( $utente );

		foreach ( $capacita as $capacita_wp ) {
			$oggetto->add_cap( $capacita_wp );
		}

		return $utente;
	}

	/**
	 * C-126: l'indirizzo di consegna funziona con e senza struttura dei permalink.
	 *
	 * La stessa forma in tutti e due i casi: non c'e' nessuna regola di
	 * riscrittura da rigenerare, quindi non c'e' nessuno stato memorizzato da
	 * perdere.
	 */
	public function test_c126_indirizzo_indipendente_dai_permalink() {
		$atto     = $this->atto();
		$allegato = $this->allegato( $atto );

		$semplice = conformita_core_indirizzo_consegna( $allegato );

		$this->assertStringContainsString( 'conformita_core_atto=' . $atto, $semplice );
		$this->assertStringContainsString( 'conformita_core_allegato=' . $allegato, $semplice );
		$this->assertNotNull( $this->chiedi( $atto, $allegato ) );

		$this->set_permalink_structure( '/%postname%/' );

		$bello = conformita_core_indirizzo_consegna( $allegato );

		$this->assertSame(
			wp_parse_url( $semplice, PHP_URL_QUERY ),
			wp_parse_url( $bello, PHP_URL_QUERY )
		);

		$risposta = $this->chiedi( $atto, $allegato );

		$this->assertNotNull( $risposta );
		$this->assertSame( 200, $risposta['stato'] );

		$this->set_permalink_structure( '' );
	}

	/**
	 * C-127: l'indirizzo del file che WordPress pubblica e' quello di consegna.
	 */
	public function test_c127_wp_get_attachment_url_da_lindirizzo_di_consegna() {
		$atto     = $this->atto();
		$allegato = $this->allegato( $atto );

		$indirizzo = wp_get_attachment_url( $allegato );

		$this->assertSame( conformita_core_indirizzo_consegna( $allegato ), $indirizzo );
		$this->assertStringNotContainsString( 'conformita-core-protetto', $indirizzo );
		$this->assertStringNotContainsString( '.pdf', $indirizzo );
	}

	/**
	 * C-128: un allegato non depositato attraverso core non si consegna.
	 *
	 * **Due casi, e il secondo e' quello che conta.** Il primo e' un allegato
	 * che sta fuori dalla cartella protetta: lo rifiuta il controllo sul
	 * percorso, e da solo non direbbe niente sulla marca. Il secondo e' un
	 * allegato che punta **dentro** la cartella protetta ma non porta la marca,
	 * cioe' un file arrivato li' da una migrazione o da un altro componente: li'
	 * il controllo sul percorso non basta, e a rifiutare deve essere la marca.
	 *
	 * Il secondo caso e' stato aggiunto dopo la prova con i guasti: togliendo il
	 * controllo della marca dalla catena, nessuna riga diventava rossa, il che
	 * voleva dire che questa prova stava misurando il percorso e non la marca.
	 */
	public function test_c128_allegato_estraneo_non_si_consegna() {
		$atto = $this->atto();

		$fuori = self::factory()->attachment->create_object(
			array(
				'file'           => 'estraneo.pdf',
				'post_parent'    => $atto,
				'post_mime_type' => 'application/pdf',
			)
		);

		$this->assertFalse( conformita_core_allegato_protetto( $fuori ) );
		$this->assertWPError( conformita_core_indirizzo_consegna( $fuori ) );
		$this->assertSame( 404, $this->chiedi( $atto, $fuori )['stato'] );

		$depositato = $this->allegato( $atto, 'vicino.pdf' );
		$percorso   = get_attached_file( $depositato );
		$intruso    = dirname( $percorso ) . '/intruso.pdf';

		copy( $percorso, $intruso );

		$this->temporanei[] = $intruso;

		$caricamenti = wp_get_upload_dir();

		$dentro = self::factory()->attachment->create_object(
			array(
				'file'           => ltrim( str_replace( $caricamenti['basedir'], '', $intruso ), '/' ),
				'post_parent'    => $atto,
				'post_mime_type' => 'application/pdf',
			)
		);

		$this->assertFileExists( get_attached_file( $dentro ) );
		$this->assertStringStartsWith(
			Conformita_Core_Allegati::cartella(),
			get_attached_file( $dentro ),
			'Il secondo caso vale solo se il file sta davvero dentro la cartella protetta.'
		);
		$this->assertFalse( conformita_core_allegato_protetto( $dentro ) );
		$this->assertSame(
			404,
			$this->chiedi( $atto, $dentro )['stato'],
			'Dentro la cartella protetta ma senza la marca: a rifiutare deve essere la marca.'
		);
	}

	/**
	 * C-129: il caso in cui il file deve arrivare.
	 *
	 * **Controllo positivo.** Senza questa riga, tutte le righe sui rifiuti
	 * sarebbero soddisfatte da un punto di consegna che non consegna mai.
	 */
	public function test_c129_atto_valido_il_file_arriva() {
		$atto     = $this->atto();
		$allegato = $this->allegato( $atto );

		$risposta = $this->chiedi( $atto, $allegato );

		$this->assertNotNull( $risposta );
		$this->assertSame( 200, $risposta['stato'] );
		$this->assertSame( realpath( get_attached_file( $allegato ) ), $risposta['percorso'] );
		$this->assertFileExists( $risposta['percorso'] );

		$intestazioni = $risposta['intestazioni'];

		$this->assertSame( 'application/pdf', $intestazioni['Content-Type'] );
		$this->assertSame( (string) filesize( $risposta['percorso'] ), $intestazioni['Content-Length'] );
		$this->assertStringContainsString( 'atto.pdf', $intestazioni['Content-Disposition'] );
	}

	/**
	 * C-130: le intestazioni comuni a ogni risposta del punto pubblico.
	 */
	public function test_c130_intestazioni_contro_la_memoria_di_pagina() {
		$atto     = $this->atto();
		$allegato = $this->allegato( $atto );

		foreach ( array( $this->chiedi( $atto, $allegato ), $this->chiedi( $atto, 999999 ) ) as $risposta ) {
			$this->assertNotNull( $risposta );

			$intestazioni = $risposta['intestazioni'];

			$this->assertStringContainsString( 'no-store', $intestazioni['Cache-Control'] );
			$this->assertStringContainsString( 'private', $intestazioni['Cache-Control'] );
			$this->assertStringContainsString( 'no-cache', $intestazioni['Cache-Control'] );
			$this->assertSame( 'no-cache', $intestazioni['Pragma'] );
			$this->assertSame( 'nosniff', $intestazioni['X-Content-Type-Options'] );
			$this->assertSame( 'none', $intestazioni['Accept-Ranges'] );
			$this->assertArrayNotHasKey( 'ETag', $intestazioni );
			$this->assertArrayNotHasKey( 'Last-Modified', $intestazioni );

			$scadenza = strtotime( $intestazioni['Expires'] );

			$this->assertIsInt( $scadenza );
			$this->assertLessThan( time(), $scadenza );
		}
	}

	/**
	 * C-159: elenco chiuso di tipi serviti dentro la pagina.
	 */
	public function test_c159_pdf_e_immagini_dentro_la_pagina_il_resto_no() {
		$atto = $this->atto();

		$pdf = $this->chiedi( $atto, $this->allegato( $atto, 'atto.pdf' ) );

		$this->assertSame( 200, $pdf['stato'] );
		$this->assertStringStartsWith( 'inline;', $pdf['intestazioni']['Content-Disposition'] );
		$this->assertStringContainsString( 'atto.pdf', $pdf['intestazioni']['Content-Disposition'] );
		$this->assertArrayHasKey( 'Content-Security-Policy', $pdf['intestazioni'] );

		$politica = $pdf['intestazioni']['Content-Security-Policy'];

		$this->assertStringContainsString( "default-src 'none'", $politica );
		$this->assertStringContainsString( "script-src 'none'", $politica );
		$this->assertStringContainsString( "object-src 'none'", $politica );
		$this->assertStringContainsString( "frame-ancestors 'self'", $politica );
		$this->assertStringNotContainsString( 'sandbox', $politica );

		$immagine = $this->chiedi( $atto, $this->allegato( $atto, 'foto.jpg', DIR_TESTDATA . '/images/canola.jpg' ) );

		$this->assertSame( 200, $immagine['stato'] );
		$this->assertSame( 'image/jpeg', $immagine['intestazioni']['Content-Type'] );
		$this->assertStringStartsWith( 'inline;', $immagine['intestazioni']['Content-Disposition'] );

		add_filter( 'upload_mimes', array( $this, 'ammetti_svg' ) );

		$svg = $this->chiedi(
			$atto,
			$this->allegato( $atto, 'disegno.svg', $this->file_svg() )
		);

		remove_filter( 'upload_mimes', array( $this, 'ammetti_svg' ) );

		$this->assertSame( 200, $svg['stato'] );
		$this->assertStringStartsWith( 'attachment;', $svg['intestazioni']['Content-Disposition'] );
		$this->assertArrayNotHasKey( 'Content-Security-Policy', $svg['intestazioni'] );
	}

	/**
	 * Ammette l'SVG fra i tipi caricabili, come fanno alcune installazioni.
	 *
	 * @param array<string, string> $tipi Tipi ammessi.
	 * @return array<string, string>
	 */
	public function ammetti_svg( $tipi ) {
		$tipi['svg'] = 'image/svg+xml';

		return $tipi;
	}

	/**
	 * Un file SVG vero sul disco.
	 *
	 * @return string
	 */
	private function file_svg() {
		$percorso = wp_tempnam( 'sorgente.svg' );

		file_put_contents( $percorso, '<svg xmlns="http://www.w3.org/2000/svg"><rect width="1" height="1"/></svg>' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- prova: serve un file vero sul disco.

		$this->temporanei[] = $percorso;

		return $percorso;
	}

	/**
	 * C-160: tipo dichiarato e tipo dedotto dall'estensione discordi.
	 */
	public function test_c160_tipo_discorde_esce_come_scaricamento_generico() {
		$atto     = $this->atto();
		$allegato = $this->allegato( $atto );

		wp_update_post(
			array(
				'ID'             => $allegato,
				'post_mime_type' => 'image/png',
			)
		);

		$risposta = $this->chiedi( $atto, $allegato );

		$this->assertSame( 200, $risposta['stato'] );
		$this->assertSame( 'application/octet-stream', $risposta['intestazioni']['Content-Type'] );
		$this->assertStringStartsWith( 'attachment;', $risposta['intestazioni']['Content-Disposition'] );
	}

	/**
	 * C-131: atto scaduto, con il compito pianificato mai eseguito.
	 */
	public function test_c131_atto_scaduto_non_trovato() {
		$atto     = $this->atto( 'publish', gmdate( 'Y-m-d', strtotime( '+30 days' ) ) );
		$allegato = $this->allegato( $atto );

		$this->assertSame( 200, $this->chiedi( $atto, $allegato )['stato'] );

		update_post_meta(
			$atto,
			conformita_core_chiave_fine_pubblicazione(),
			gmdate( 'Y-m-d', strtotime( '-1 day' ) )
		);

		$this->assertSame( 404, $this->chiedi( $atto, $allegato )['stato'] );
	}

	/**
	 * C-132: atto scaduto e richiedente con tutte le capability del tipo.
	 *
	 * L'esenzione e' della superficie e non dell'utente: e' la regola del punto
	 * 3 della scheda di S4, e qui vale per il file come vale per la pagina.
	 */
	public function test_c132_le_capability_non_riaprono_il_punto_pubblico() {
		$atto     = $this->atto( 'publish', gmdate( 'Y-m-d', strtotime( '-1 day' ) ) );
		$allegato = $this->allegato( $atto );

		wp_set_current_user( $this->utente_del_tipo() );

		$this->assertSame( 404, $this->chiedi( $atto, $allegato )['stato'] );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->assertSame( 404, $this->chiedi( $atto, $allegato )['stato'] );
	}

	/**
	 * C-133: contenuto inesistente, e allegato inesistente.
	 */
	public function test_c133_contenuto_o_allegato_inesistenti() {
		$atto     = $this->atto();
		$allegato = $this->allegato( $atto );

		$this->assertSame( 404, $this->chiedi( 999999, $allegato )['stato'] );
		$this->assertSame( 404, $this->chiedi( $atto, 999999 )['stato'] );
	}

	/**
	 * C-134: allegato che non appartiene al contenuto dichiarato.
	 */
	public function test_c134_accoppiata_sbagliata() {
		$primo    = $this->atto();
		$secondo  = $this->atto();
		$allegato = $this->allegato( $primo );

		$this->assertSame( 200, $this->chiedi( $primo, $allegato )['stato'] );
		$this->assertSame( 404, $this->chiedi( $secondo, $allegato )['stato'] );
	}

	/**
	 * C-135: contenuto di un tipo non registrato attraverso core.
	 */
	public function test_c135_tipo_non_gestito() {
		$atto     = $this->atto();
		$allegato = $this->allegato( $atto );
		$estraneo = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		wp_update_post(
			array(
				'ID'          => $allegato,
				'post_parent' => $estraneo,
			)
		);

		$this->assertSame( 404, $this->chiedi( $estraneo, $allegato )['stato'] );
	}

	/**
	 * C-136: contenuto non pubblicato.
	 */
	public function test_c136_contenuto_non_pubblicato() {
		foreach ( array( 'draft', 'private', 'pending' ) as $stato ) {
			$atto     = $this->atto( $stato );
			$allegato = $this->allegato( $atto, 'atto-' . $stato . '.pdf' );

			$this->assertSame(
				404,
				$this->chiedi( $atto, $allegato )['stato'],
				'Stato ' . $stato . ': il file non deve uscire.'
			);
		}
	}

	/**
	 * C-137: file mancante sul disco.
	 */
	public function test_c137_file_mancante_sul_disco() {
		$atto     = $this->atto();
		$allegato = $this->allegato( $atto );
		$percorso = get_attached_file( $allegato );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- prova: si tocca il disco direttamente perche' e' il disco cio' che si sta verificando.
		unlink( $percorso );

		$risposta = $this->chiedi( $atto, $allegato );

		$this->assertSame( 404, $risposta['stato'] );
		$this->assertStringNotContainsString( $percorso, $risposta['corpo'] );
		$this->assertStringNotContainsString( 'conformita-core-protetto', $risposta['corpo'] );
	}

	/**
	 * C-138: i rifiuti sono indistinguibili fra loro.
	 *
	 * Un file che esiste ma e' scaduto non deve confessare di esistere: se il
	 * rifiuto per scadenza fosse diverso da quello per contenuto inesistente,
	 * la differenza direbbe "c'e', ma non per te", che e' esattamente cio' che
	 * la politica `irraggiungibile` non vuole dire.
	 *
	 * Il nono caso della tabella della scheda, la sezione senza politica valida,
	 * non e' costruibile attraverso l'API, perche' un tipo si registra solo
	 * dentro una sezione che ha gia' dichiarato la politica, ed e' una guardia
	 * difensiva, come la riga C-94 di S4.
	 */
	public function test_c138_i_rifiuti_sono_tutti_uguali() {
		$atto     = $this->atto();
		$allegato = $this->allegato( $atto );

		$scaduto      = $this->atto( 'publish', gmdate( 'Y-m-d', strtotime( '-1 day' ) ) );
		$alleg_scad   = $this->allegato( $scaduto, 'scaduto.pdf' );
		$bozza        = $this->atto( 'draft' );
		$alleg_bozza  = $this->allegato( $bozza, 'bozza.pdf' );
		$altro        = $this->atto();
		$senza_file   = $this->allegato( $atto, 'senza-file.pdf' );
		$non_gestito  = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$alleg_estran = self::factory()->attachment->create_object(
			array(
				'file'           => 'estraneo.pdf',
				'post_parent'    => $atto,
				'post_mime_type' => 'application/pdf',
			)
		);

		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- prova: si tocca il disco direttamente perche' e' il disco cio' che si sta verificando.
		unlink( get_attached_file( $senza_file ) );

		$rifiuti = array(
			'contenuto inesistente'   => $this->chiedi( 999999, $allegato ),
			'allegato inesistente'    => $this->chiedi( $atto, 999999 ),
			'accoppiata sbagliata'    => $this->chiedi( $altro, $allegato ),
			'tipo non gestito'        => $this->chiedi( $non_gestito, $allegato ),
			'contenuto non pubblico'  => $this->chiedi( $bozza, $alleg_bozza ),
			'contenuto scaduto'       => $this->chiedi( $scaduto, $alleg_scad ),
			'file mancante sul disco' => $this->chiedi( $atto, $senza_file ),
			'allegato non depositato' => $this->chiedi( $atto, $alleg_estran ),
		);

		$riferimento = null;

		foreach ( $rifiuti as $caso => $risposta ) {
			$this->assertNotNull( $risposta, $caso );
			$this->assertSame( 404, $risposta['stato'], $caso );

			$confrontabile = array(
				'stato'        => $risposta['stato'],
				'corpo'        => $risposta['corpo'],
				'intestazioni' => $risposta['intestazioni'],
			);

			if ( null === $riferimento ) {
				$riferimento = $confrontabile;
				continue;
			}

			$this->assertSame( $riferimento, $confrontabile, 'Il rifiuto "' . $caso . '" si distingue dagli altri.' );
		}
	}

	/**
	 * C-139: richiesta parziale, risposta intera.
	 */
	public function test_c139_richiesta_parziale_ignorata() {
		$atto     = $this->atto();
		$allegato = $this->allegato( $atto );

		$_SERVER['HTTP_RANGE'] = 'bytes=0-9';

		$risposta = $this->chiedi( $atto, $allegato );

		$this->assertSame( 200, $risposta['stato'] );
		$this->assertSame( 'none', $risposta['intestazioni']['Accept-Ranges'] );
		$this->assertArrayNotHasKey( 'Content-Range', $risposta['intestazioni'] );
		$this->assertSame(
			(string) filesize( get_attached_file( $allegato ) ),
			$risposta['intestazioni']['Content-Length']
		);
	}

	/**
	 * C-140: il percorso di consegna non scrive niente e non chiede niente.
	 */
	public function test_c140_la_consegna_non_scrive_e_non_chiede() {
		global $wpdb;

		$atto     = $this->atto();
		$allegato = $this->allegato( $atto );

		$richieste = 0;

		$conta = function ( $esito ) use ( &$richieste ) {
			++$richieste;

			return $esito;
		};

		add_filter( 'pre_http_request', $conta, 5 );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- prova: si contano le righe vere, ed e' il conteggio a fare fede. Una memoria intermedia lo falserebbe, che e' il difetto che questa riga cerca.
		$meta_prima = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta}" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- come sopra.
		$opzioni_prima = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options}" );
		$stato_prima   = get_option( 'conformita_core_protezione_allegati' );

		$this->assertSame( 200, $this->chiedi( $atto, $allegato )['stato'] );
		$this->assertSame( 404, $this->chiedi( $atto, 999999 )['stato'] );

		remove_filter( 'pre_http_request', $conta, 5 );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- prova: come sopra.
		$meta_dopo = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta}" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- prova: come sopra.
		$opzioni_dopo = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options}" );

		$this->assertSame( $meta_prima, $meta_dopo );
		$this->assertSame( $opzioni_prima, $opzioni_dopo );
		$this->assertSame( $stato_prima, get_option( 'conformita_core_protezione_allegati' ) );
		$this->assertSame( 0, $richieste, 'La consegna non deve fare nessuna richiesta HTTP.' );
	}

	/**
	 * C-141: la consegna amministrativa serve anche l'allegato di un atto scaduto.
	 */
	public function test_c141_amministrazione_serve_anche_lo_scaduto() {
		$atto     = $this->atto( 'publish', gmdate( 'Y-m-d', strtotime( '-1 day' ) ) );
		$allegato = $this->allegato( $atto );

		wp_set_current_user( $this->utente_del_tipo() );

		$risposta = $this->chiedi_da_amministrazione( $atto, $allegato );

		$this->assertNotNull( $risposta );
		$this->assertSame( 200, $risposta['stato'] );
		$this->assertSame( realpath( get_attached_file( $allegato ) ), $risposta['percorso'] );
	}

	/**
	 * C-142: nonce mancante, non valido, o di un altro allegato.
	 */
	public function test_c142_il_nonce_e_obbligatorio_e_legato_allallegato() {
		$atto  = $this->atto();
		$primo = $this->allegato( $atto, 'primo.pdf' );
		$altro = $this->allegato( $atto, 'altro.pdf' );

		wp_set_current_user( $this->utente_del_tipo() );

		$this->assertSame( 404, $this->chiedi_da_amministrazione( $atto, $primo, '' )['stato'] );
		$this->assertSame( 404, $this->chiedi_da_amministrazione( $atto, $primo, 'inventato' )['stato'] );
		$this->assertSame(
			404,
			$this->chiedi_da_amministrazione(
				$atto,
				$primo,
				wp_create_nonce( 'conformita_core_allegato_' . $altro )
			)['stato'],
			'Il nonce di un altro allegato non deve valere per questo.'
		);
		$this->assertSame( 200, $this->chiedi_da_amministrazione( $atto, $primo )['stato'] );
	}

	/**
	 * C-143: senza la capability del tipo, e da anonimo.
	 */
	public function test_c143_amministrazione_chiusa_a_chi_non_ha_titolo() {
		$atto     = $this->atto();
		$allegato = $this->allegato( $atto );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$this->assertSame( 404, $this->chiedi_da_amministrazione( $atto, $allegato )['stato'] );

		wp_set_current_user( 0 );

		$this->assertSame( 404, $this->chiedi_da_amministrazione( $atto, $allegato )['stato'] );
	}

	/**
	 * C-144: il punto pubblico non e' una superficie di amministrazione.
	 *
	 * E' la riga che impedisce di spostare un domani il punto pubblico su
	 * `admin-post.php`: la' `is_admin()` sarebbe vero anche per un anonimo, e
	 * la superficie risulterebbe esente dal filtro di scadenza di S4 senza che
	 * nessuno lo abbia deciso.
	 */
	public function test_c144_il_punto_pubblico_non_e_amministrazione() {
		$atto     = $this->atto();
		$allegato = $this->allegato( $atto );

		$risposta = $this->chiedi( $atto, $allegato );

		$this->assertFalse( $risposta['amministrazione'] );
		$this->assertFalse( Conformita_Core_Consegna::superficie_amministrativa_in_corso() );
	}

	/**
	 * C-145: alla scadenza il file resta sul disco e l'impronta resta leggibile.
	 */
	public function test_c145_alla_scadenza_il_file_resta() {
		$atto     = $this->atto();
		$allegato = $this->allegato( $atto );
		$percorso = get_attached_file( $allegato );
		$impronta = conformita_core_impronta_allegato( $allegato );

		update_post_meta(
			$atto,
			conformita_core_chiave_fine_pubblicazione(),
			gmdate( 'Y-m-d', strtotime( '-1 day' ) )
		);

		$this->assertSame( 404, $this->chiedi( $atto, $allegato )['stato'] );
		$this->assertFileExists( $percorso );
		$this->assertSame( $impronta, conformita_core_impronta_allegato( $allegato ) );
	}
}
