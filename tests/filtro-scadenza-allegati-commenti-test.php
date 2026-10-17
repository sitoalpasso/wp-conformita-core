<?php
/**
 * Il filtro di scadenza sulle strade che passano da un allegato o da un
 * commento, e il rifiuto che non si distingue da un indirizzo sbagliato: righe
 * C-259..C-267 del catalogo.
 *
 * **Che cosa avevano in comune i buchi.** Un allegato e un commento non hanno
 * una data di fine propria: la loro scadenza e' quella del contenuto a cui
 * appartengono. Il filtro la guardava sul padre soltanto nell'elenco dei
 * risultati; sulle strade che leggono il singolo allegato (la scheda
 * nell'interfaccia informatica, l'anteprima incorporata, la pubblicazione
 * remota) guardava l'allegato stesso, che non e' di un tipo gestito, e lo
 * lasciava passare. I commenti si leggono con un'interrogazione propria, che
 * il filtro non toccava affatto.
 *
 * **Perche' il rifiuto deve essere identico.** Un 404 con un codice proprio dice
 * a chi prova i numeri uno per uno che dietro quel numero c'era qualcosa. La
 * prova non confronta il rifiuto con un codice scritto qui: lo confronta con la
 * risposta che WordPress da' per un numero che non esiste, in quel momento e
 * con quell'utente, cosi' resta vera anche se WordPress cambia il proprio
 * messaggio.
 *
 * @package Conformita_Core
 */

/**
 * Prove sul filtro di scadenza per allegati e commenti.
 */
class Conformita_Core_Filtro_Scadenza_Allegati_Commenti_Test extends WP_UnitTestCase {

	const SEZIONE = 'sezione_figli';
	const TIPO    = 'prova_figli';
	const UTENTE  = 'utente_dei_figli';
	const PAROLA  = 'parola-di-prova-lunga';

	/**
	 * Il nome del file caricato, che puo' contenere dati personali: e' quello
	 * che la scheda dell'allegato non deve piu' restituire.
	 */
	const FILE = 'graduatoria-mario-rossi.pdf';

	/**
	 * Fuso del sito durante le prove, ripristinato alla fine.
	 *
	 * @var string
	 */
	private $fuso_originale = '';

	/**
	 * Valore delle pagine degli allegati, ripristinato alla fine.
	 *
	 * @var mixed
	 */
	private $pagine_allegati = false;

	/**
	 * Una sezione con un tipo aperto all'interfaccia informatica, le pagine degli
	 * allegati accese e le rotte ricostruite dopo la registrazione del tipo.
	 */
	public function set_up() {
		parent::set_up();

		Conformita_Core_Sezioni::azzera();
		Conformita_Core_Tipi::azzera();
		Conformita_Core_Scadenza::azzera_orologio();
		Conformita_Core_Filtro_Scadenza::avvia();

		$this->fuso_originale = get_option( 'timezone_string' );
		update_option( 'timezone_string', 'Europe/Rome' );

		/*
		 * Dalla 6.4 WordPress spegne le pagine degli allegati sui siti nuovi, e
		 * allora l'indirizzo di un allegato porta al file e non alla pagina: la
		 * prova dell'anteprima incorporata partirebbe da un indirizzo che non
		 * porta a nessun contenuto, e sarebbe verde senza misurare niente.
		 */
		$this->pagine_allegati = get_option( 'wp_attachment_pages_enabled' );
		update_option( 'wp_attachment_pages_enabled', 1 );

		// Indirizzi semplici: vedi la stessa scelta nelle prove C-17.
		$this->set_permalink_structure( '' );

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
					'show_in_rest' => true,
					'argomenti'    => array(
						'public'      => true,
						'has_archive' => true,
						'query_var'   => false,
					),
				)
			)
		);

		$GLOBALS['wp_rest_server'] = new WP_REST_Server();
		do_action( 'rest_api_init', $GLOBALS['wp_rest_server'] );
	}

	/**
	 * Server, opzioni e orologio ripristinati.
	 */
	public function tear_down() {
		$GLOBALS['wp_rest_server'] = null;
		remove_filter( 'xmlrpc_enabled', '__return_true' );
		update_option( 'timezone_string', $this->fuso_originale );
		update_option( 'wp_attachment_pages_enabled', $this->pagine_allegati );
		Conformita_Core_Scadenza::azzera_orologio();
		Conformita_Core_Filtro_Scadenza::avvia();

		parent::tear_down();
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
	 * @param string $fine   Data di fine, formato AAAA-MM-GG.
	 * @param string $titolo Titolo del contenuto.
	 * @return int Identificativo del contenuto.
	 */
	private function contenuto( $fine, $titolo = 'Atto di prova' ) {
		$post_id = self::factory()->post->create(
			array(
				'post_type'   => self::TIPO,
				'post_status' => 'publish',
				'post_title'  => $titolo,
			)
		);

		$this->assertTrue( conformita_core_imposta_fine_pubblicazione( $post_id, $fine ) );

		return $post_id;
	}

	/**
	 * Un allegato appeso a un contenuto, con il nome del file caricato.
	 *
	 * @param int $padre Identificativo del contenuto padre.
	 * @return int Identificativo dell'allegato.
	 */
	private function allegato_di( $padre ) {
		$allegato = self::factory()->post->create(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_parent'    => $padre,
				'post_title'     => 'Graduatoria',
				'post_mime_type' => 'application/pdf',
			)
		);

		update_post_meta( $allegato, '_wp_attached_file', '2026/09/' . self::FILE );

		return $allegato;
	}

	/**
	 * Un commento approvato su un contenuto.
	 *
	 * @param int    $contenuto Identificativo del contenuto.
	 * @param string $testo     Testo del commento.
	 * @return int Identificativo del commento.
	 */
	private function commento_su( $contenuto, $testo ) {
		return self::factory()->comment->create(
			array(
				'comment_post_ID'  => $contenuto,
				'comment_content'  => $testo,
				'comment_approved' => '1',
			)
		);
	}

	/**
	 * Un amministratore con tutte le capability del tipo assegnate.
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
		$utente    = get_user_by( 'id', $utente_id );

		foreach ( array_unique( array_values( conformita_core_capacita_tipo( self::TIPO ) ) ) as $capacita ) {
			$utente->add_cap( $capacita );
		}

		return $utente_id;
	}

	/**
	 * Esegue una richiesta sull'interfaccia informatica.
	 *
	 * @param string $percorso  Percorso della rotta, senza parametri.
	 * @param array  $parametri Parametri della richiesta.
	 * @param string $metodo    Metodo della richiesta.
	 * @return WP_REST_Response
	 */
	private function chiedi( $percorso, array $parametri = array(), $metodo = 'GET' ) {
		$richiesta = new WP_REST_Request( $metodo, $percorso );

		foreach ( $parametri as $nome => $valore ) {
			$richiesta->set_param( $nome, $valore );
		}

		return rest_get_server()->dispatch( $richiesta );
	}

	/**
	 * Tutto quello che una risposta lascia vedere: stato, corpo e intestazioni.
	 *
	 * @param WP_REST_Response $risposta Risposta ottenuta.
	 * @return array
	 */
	private function visibile( $risposta ) {
		return array(
			'stato'        => $risposta->get_status(),
			'corpo'        => $risposta->get_data(),
			'intestazioni' => $risposta->get_headers(),
		);
	}

	/**
	 * Un numero che non e' mai stato di nessun contenuto ne' di nessun commento.
	 *
	 * @return int
	 */
	private function numero_inesistente() {
		return PHP_INT_MAX;
	}

	/**
	 * Gli identificativi di una collezione dell'interfaccia informatica.
	 *
	 * @param WP_REST_Response $risposta Risposta ottenuta.
	 * @return array<int, int>
	 */
	private function identificativi( $risposta ) {
		$this->assertSame( 200, $risposta->get_status() );

		return array_map( 'intval', wp_list_pluck( $risposta->get_data(), 'id' ) );
	}

	/**
	 * C-259: la scheda di un allegato di un contenuto scaduto, nell'interfaccia
	 * informatica, risponde come un numero che non esiste.
	 *
	 * Per il visitatore e per chi gestisce il tipo. La scheda dell'allegato di
	 * un contenuto valido risponde, e anche quella di un allegato di un articolo
	 * qualunque: il filtro non deve toccare quello che non governa.
	 */
	public function test_c259_scheda_dell_allegato() {
		$this->oggi_e( '2026-09-09' );

		$valido   = $this->contenuto( '2026-09-30' );
		$scaduto  = $this->contenuto( '2026-09-08' );
		$articolo = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$allegato_buono    = $this->allegato_di( $valido );
		$allegato_morto    = $this->allegato_di( $scaduto );
		$allegato_articolo = $this->allegato_di( $articolo );

		foreach ( array( 0, $this->utente_autorizzato() ) as $utente ) {
			wp_set_current_user( $utente );

			$buona = $this->chiedi( '/wp/v2/media/' . $allegato_buono );
			$this->assertSame( 200, $buona->get_status(), 'La scheda dell\'allegato di un contenuto valido risponde: senza questa asserzione un filtro che nega tutto passerebbe.' );
			$this->assertSame( $allegato_buono, $buona->get_data()['id'] );

			$this->assertSame( 200, $this->chiedi( '/wp/v2/media/' . $allegato_articolo )->get_status(), 'L\'allegato di un articolo non e\' affare del filtro.' );

			$morta = $this->chiedi( '/wp/v2/media/' . $allegato_morto );

			$this->assertSame(
				$this->visibile( $this->chiedi( '/wp/v2/media/' . $this->numero_inesistente() ) ),
				$this->visibile( $morta ),
				'La scheda dell\'allegato di un contenuto scaduto deve rispondere come un numero che non esiste, per l\'utente ' . $utente . '.'
			);
			$this->assertStringNotContainsString( self::FILE, wp_json_encode( $morta->get_data() ), 'Il nome del file non deve uscire.' );
		}
	}

	/**
	 * C-260: l'anteprima incorporata della pagina di un allegato di un
	 * contenuto scaduto non viene servita.
	 */
	public function test_c260_anteprima_dell_allegato() {
		$this->oggi_e( '2026-09-09' );

		$allegato_buono = $this->allegato_di( $this->contenuto( '2026-09-30' ) );
		$allegato_morto = $this->allegato_di( $this->contenuto( '2026-09-08' ) );

		// L'indirizzo deve portare all'allegato: e' il filtro a dover negare.
		$this->assertSame( $allegato_morto, url_to_postid( get_permalink( $allegato_morto ) ) );

		$buona = $this->chiedi( '/oembed/1.0/embed', array( 'url' => get_permalink( $allegato_buono ) ) );
		$this->assertSame( 200, $buona->get_status(), 'L\'anteprima dell\'allegato di un contenuto valido e\' servita.' );

		$negata = $this->chiedi( '/oembed/1.0/embed', array( 'url' => get_permalink( $allegato_morto ) ) );
		$this->assertSame( 404, $negata->get_status(), 'L\'anteprima dell\'allegato di un contenuto scaduto non deve essere servita.' );
		$this->assertSame( 'oembed_invalid_url', $negata->get_data()['code'] );
		$this->assertSame( array(), get_oembed_response_data( $allegato_morto, 600 ), 'Neanche chiamata direttamente.' );
	}

	/**
	 * C-261: il rifiuto del singolo contenuto scaduto e' identico alla risposta
	 * per un numero che non esiste: stato, corpo e intestazioni.
	 */
	public function test_c261_rifiuto_indistinguibile() {
		$this->oggi_e( '2026-09-09' );

		$scaduto = $this->contenuto( '2026-09-08' );

		foreach ( array( 0, $this->utente_autorizzato() ) as $utente ) {
			wp_set_current_user( $utente );

			$this->assertSame(
				$this->visibile( $this->chiedi( '/wp/v2/' . self::TIPO . '/' . $this->numero_inesistente() ) ),
				$this->visibile( $this->chiedi( '/wp/v2/' . self::TIPO . '/' . $scaduto ) ),
				'Il contenuto scaduto deve rispondere come un numero che non esiste, per l\'utente ' . $utente . '.'
			);
		}
	}

	/**
	 * C-262: la richiesta di sole intestazioni sul singolo scaduto risponde come
	 * un numero che non esiste.
	 *
	 * WordPress serve quella richiesta con la stessa funzione della lettura, e
	 * la risposta porta l'indirizzo del contenuto, che contiene il titolo.
	 */
	public function test_c262_richiesta_di_sole_intestazioni() {
		$this->oggi_e( '2026-09-09' );

		$valido  = $this->contenuto( '2026-09-30' );
		$scaduto = $this->contenuto( '2026-09-08' );

		$this->assertSame( 200, $this->chiedi( '/wp/v2/' . self::TIPO . '/' . $valido, array(), 'HEAD' )->get_status(), 'Il contenuto valido risponde anche cosi\'.' );

		$this->assertSame(
			$this->visibile( $this->chiedi( '/wp/v2/' . self::TIPO . '/' . $this->numero_inesistente(), array(), 'HEAD' ) ),
			$this->visibile( $this->chiedi( '/wp/v2/' . self::TIPO . '/' . $scaduto, array(), 'HEAD' ) )
		);

		$allegato_morto = $this->allegato_di( $scaduto );

		$this->assertSame(
			$this->visibile( $this->chiedi( '/wp/v2/media/' . $this->numero_inesistente(), array(), 'HEAD' ) ),
			$this->visibile( $this->chiedi( '/wp/v2/media/' . $allegato_morto, array(), 'HEAD' ) )
		);
	}

	/**
	 * C-263: i commenti di un contenuto scaduto, e dei suoi allegati, non escono
	 * dall'interfaccia informatica: ne' dall'elenco, ne' dall'elenco per
	 * contenuto, ne' dal singolo, che risponde come un numero che non esiste.
	 */
	public function test_c263_commenti_nell_interfaccia() {
		$this->oggi_e( '2026-09-09' );

		$valido  = $this->contenuto( '2026-09-30' );
		$scaduto = $this->contenuto( '2026-09-08' );

		$buono          = $this->commento_su( $valido, 'Commento visibile' );
		$morto          = $this->commento_su( $scaduto, 'Commento nascosto' );
		$morto_allegato = $this->commento_su( $this->allegato_di( $scaduto ), 'Commento sull\'allegato nascosto' );

		foreach ( array( 0, $this->utente_autorizzato() ) as $utente ) {
			wp_set_current_user( $utente );

			$elenco = $this->identificativi( $this->chiedi( '/wp/v2/comments' ) );
			$this->assertContains( $buono, $elenco, 'Il commento di un contenuto valido resta: senza, un filtro che toglie tutto passerebbe.' );
			$this->assertNotContains( $morto, $elenco );
			$this->assertNotContains( $morto_allegato, $elenco );

			$this->assertSame( array(), $this->identificativi( $this->chiedi( '/wp/v2/comments', array( 'post' => array( $scaduto ) ) ) ) );

			$this->assertSame( 200, $this->chiedi( '/wp/v2/comments/' . $buono )->get_status() );

			$inesistente = $this->visibile( $this->chiedi( '/wp/v2/comments/' . $this->numero_inesistente() ) );

			$this->assertSame( $inesistente, $this->visibile( $this->chiedi( '/wp/v2/comments/' . $morto ) ), 'Utente ' . $utente . '.' );
			$this->assertSame( $inesistente, $this->visibile( $this->chiedi( '/wp/v2/comments/' . $morto_allegato ) ), 'Utente ' . $utente . '.' );
		}
	}

	/**
	 * C-264: le letture dei commenti fatte dal sito pubblico, per esempio il
	 * riquadro degli ultimi commenti, non li restituiscono; l'amministrazione si'.
	 */
	public function test_c264_commenti_letti_dal_sito() {
		$this->oggi_e( '2026-09-09' );

		$valido  = $this->contenuto( '2026-09-30' );
		$scaduto = $this->contenuto( '2026-09-08' );

		$buono = $this->commento_su( $valido, 'Commento visibile' );
		$morto = $this->commento_su( $scaduto, 'Commento nascosto' );

		$pubblici = array_map( 'intval', wp_list_pluck( get_comments( array( 'status' => 'approve' ) ), 'comment_ID' ) );

		$this->assertContains( $buono, $pubblici );
		$this->assertNotContains( $morto, $pubblici );

		set_current_screen( 'edit-comments' );

		$amministrazione = array_map( 'intval', wp_list_pluck( get_comments( array( 'status' => 'approve' ) ), 'comment_ID' ) );

		set_current_screen( 'front' );

		$this->assertContains( $morto, $amministrazione, 'Nell\'amministrazione il commento resta, come il contenuto.' );
	}

	/**
	 * C-265: il feed generale dei commenti e il feed della ricerca con i
	 * commenti non portano i commenti di un contenuto scaduto.
	 */
	public function test_c265_feed_dei_commenti() {
		$this->oggi_e( '2026-09-09' );

		$valido  = $this->contenuto( '2026-09-30', 'Atto cercato valido' );
		$scaduto = $this->contenuto( '2026-09-08', 'Atto cercato scaduto' );

		$buono          = $this->commento_su( $valido, 'Commento visibile' );
		$morto          = $this->commento_su( $scaduto, 'Commento nascosto' );
		$morto_allegato = $this->commento_su( $this->allegato_di( $scaduto ), 'Commento sull\'allegato nascosto' );

		foreach ( array( '/?feed=comments-rss2', '/?s=cercato&feed=rss2&withcomments=1' ) as $indirizzo ) {
			$this->go_to( $indirizzo );

			$this->assertTrue( is_comment_feed(), 'Precondizione: ' . $indirizzo . ' e\' un feed dei commenti.' );

			$commenti = array_map( 'intval', wp_list_pluck( $GLOBALS['wp_query']->comments, 'comment_ID' ) );

			$this->assertContains( $buono, $commenti, 'Il commento valido esce da ' . $indirizzo . '.' );
			$this->assertNotContains( $morto, $commenti, 'Il commento dello scaduto non esce da ' . $indirizzo . '.' );
			$this->assertNotContains( $morto_allegato, $commenti, 'Nemmeno quello del suo allegato, da ' . $indirizzo . '.' );
			$this->assertSame( count( $commenti ), $GLOBALS['wp_query']->comment_count, 'Il conteggio segue i commenti rimasti.' );
		}
	}

	/**
	 * C-267: la versione dell'interfaccia sale a 1.6.0.
	 *
	 * E' la prima che garantisce le strade di questo file: un componente che
	 * conta su di esse, per esempio per pretendere nelle proprie prove il
	 * rifiuto identico a un numero inesistente, deve poterla chiedere.
	 */
	public function test_c267_versione_dell_interfaccia() {
		$this->assertSame( '1.6.0', conformita_core_versione_api() );
	}

	/**
	 * C-266: la pubblicazione remota non descrive l'allegato di un contenuto
	 * scaduto, come non descrive il contenuto (C-18).
	 */
	public function test_c266_allegato_nella_pubblicazione_remota() {
		require_once ABSPATH . WPINC . '/class-IXR.php';
		require_once ABSPATH . WPINC . '/class-wp-xmlrpc-server.php';
		add_filter( 'xmlrpc_enabled', '__return_true' );

		$this->oggi_e( '2026-09-09' );

		$allegato_buono = $this->allegato_di( $this->contenuto( '2026-09-30' ) );
		$allegato_morto = $this->allegato_di( $this->contenuto( '2026-09-08' ) );

		$this->utente_autorizzato();

		$server = new wp_xmlrpc_server();

		$buono = $server->wp_getMediaItem( array( 1, self::UTENTE, self::PAROLA, $allegato_buono ) );
		$this->assertIsArray( $buono );
		$this->assertArrayHasKey( 'attachment_id', $buono, 'L\'allegato di un contenuto valido arriva con i suoi dati.' );

		$this->assertSame( array(), $server->wp_getMediaItem( array( 1, self::UTENTE, self::PAROLA, $allegato_morto ) ) );
	}
}
