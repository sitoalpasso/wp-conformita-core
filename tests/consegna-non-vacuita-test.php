<?php
/**
 * Prove di non vacuita' della consegna: righe C-150 e C-162.
 *
 * **Che problema risolvono.** Quasi tutte le righe da C-131 a C-138 verificano
 * che un file *non* esca. Una verifica scritta al negativo e' verde anche
 * quando il file non esce per un motivo che non c'entra niente con la catena di
 * controlli: un aggancio mai registrato, un indirizzo che non porta da nessuna
 * parte, una variabile d'interrogazione che WordPress scarta. Il conteggio dei
 * test non lo rivela, e nemmeno rileggere il codice.
 *
 * **Perche' qui ce ne sono due e non sei.** Le prove di non vacuita' della
 * catena (togliere il controllo di scadenza, quello di appartenenza, quello
 * sullo stato, la lettura dell'esito della verifica) si possono fare in due
 * modi. Il primo e' mettere nel codice di produzione un interruttore che spenga
 * un anello, e chiamarlo dalla prova. Il secondo e' rompere il codice in una
 * copia presa fuori dal controllo di versione e guardare quali righe
 * diventano rosse.
 *
 * **Si e' scelto il secondo, e la ragione e' la stessa per cui la scheda di S4
 * ha rifiutato il parametro `$adesso` nell'interfaccia pubblica**: un
 * interruttore che spegne il controllo di scadenza, per quanto marcato come
 * interno, e' una riga di codice che prima o poi qualcuno chiama per far
 * sembrare non scaduto qualcosa che lo e'. Il costo di questa scelta e' che
 * quelle prove non girano a ogni verifica continua, e vivono nella tabella dei
 * guasti della scheda: e' un costo dichiarato, non nascosto.
 *
 * Restano qui le due che si fanno **da fuori**, senza toccare il codice di
 * produzione: spegnere un aggancio e' esattamente quello che faceva S4.
 *
 * @package Conformita_Core
 */

/**
 * Prove che dimostrano che le prove della consegna non sono vuote.
 */
class Conformita_Core_Consegna_Non_Vacuita_Test extends WP_UnitTestCase {

	const SEZIONE = 'sezione_non_vacua_consegna';
	const TIPO    = 'prova_non_vacua_c';

	/**
	 * Le risposte raccolte dall'emettitore finto.
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
	 * Sezione, tipo, protezione verificata, emettitore finto.
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
				$this->raccolte[] = $risposta;
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
	 * Rimette gli agganci e pulisce.
	 */
	public function tear_down() {
		remove_filter( 'pre_http_request', array( $this, 'server_che_nega' ), 10 );
		Conformita_Core_Consegna::azzera_emettitore();
		Conformita_Core_Consegna::azzera_avvio();
		Conformita_Core_Consegna::avvia();

		foreach ( $this->temporanei as $percorso ) {
			if ( file_exists( $percorso ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- prova: si tocca il disco direttamente perche' e' il disco cio' che si sta verificando.
				unlink( $percorso );
			}
		}

		$this->svuota_cartella_protetta();
		Conformita_Core_Allegati::azzera();

		update_option( 'timezone_string', $this->fuso_originale );

		parent::tear_down();
	}

	/**
	 * Il server finto nega il percorso dell'esca.
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
	 * Un atto pubblicato con un allegato depositato.
	 *
	 * @return array<int, int> Contenuto e allegato.
	 */
	private function atto_con_allegato() {
		$atto = self::factory()->post->create(
			array(
				'post_type'   => self::TIPO,
				'post_status' => 'publish',
			)
		);

		update_post_meta(
			$atto,
			conformita_core_chiave_fine_pubblicazione(),
			gmdate( 'Y-m-d', strtotime( '+30 days' ) )
		);

		$percorso = wp_tempnam( 'atto.pdf' );

		file_put_contents( $percorso, "%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- prova: serve un file vero sul disco.

		$this->temporanei[] = $percorso;

		$allegato = conformita_core_deposita_allegato(
			$atto,
			array(
				'name'     => 'atto.pdf',
				'tmp_name' => $percorso,
				'type'     => '',
				'size'     => filesize( $percorso ),
				'error'    => 0,
			),
			array( 'origine' => 'percorso_locale' )
		);

		$this->assertIsInt( $allegato, is_wp_error( $allegato ) ? $allegato->get_error_message() : '' );

		return array( $atto, $allegato );
	}

	/**
	 * Chiede il file dal punto pubblico.
	 *
	 * @param int $atto     Contenuto dichiarato.
	 * @param int $allegato Allegato.
	 * @return array<string, mixed>|null
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
	 * Spegne un solo aggancio del meccanismo, e verifica di averlo spento.
	 *
	 * Prima si controlla che l'aggancio ci fosse e dopo che non ci sia piu':
	 * con un nome sbagliato lo spegnimento non farebbe niente e la prova
	 * fallirebbe accusando la catena di controlli. E' la precauzione che la
	 * scheda di S4 ha imparato sulle proprie prove di non vacuita'.
	 *
	 * @param string $aggancio Nome dell'aggancio.
	 * @param string $metodo   Nome del metodo agganciato.
	 */
	private function spegni_aggancio( $aggancio, $metodo ) {
		$this->assertNotFalse(
			has_filter( $aggancio, array( 'Conformita_Core_Consegna', $metodo ) ),
			'L\'aggancio ' . $aggancio . ' non c\'era: la prova starebbe misurando altro.'
		);

		remove_filter( $aggancio, array( 'Conformita_Core_Consegna', $metodo ), 10 );

		$this->assertFalse(
			has_filter( $aggancio, array( 'Conformita_Core_Consegna', $metodo ) ),
			'L\'aggancio ' . $aggancio . ' non si e\' spento.'
		);
	}

	/**
	 * C-150: tolgo il filtro sull'indirizzo dell'allegato, e ricompare il
	 * percorso diretto del file.
	 *
	 * Se non ricomparisse, la riga C-127 starebbe misurando qualcos'altro: per
	 * esempio un indirizzo che e' gia' quello di consegna per un motivo suo.
	 */
	public function test_c150_senza_il_filtro_ricompare_il_percorso_del_file() {
		list( , $allegato ) = $this->atto_con_allegato();

		$this->assertSame(
			conformita_core_indirizzo_consegna( $allegato ),
			wp_get_attachment_url( $allegato )
		);

		$this->spegni_aggancio( 'wp_get_attachment_url', 'filtra_indirizzo_allegato' );

		$nudo = wp_get_attachment_url( $allegato );

		$this->assertStringContainsString( 'conformita-core-protetto', $nudo );
		$this->assertStringEndsWith( '.pdf', $nudo );
		$this->assertNotSame( conformita_core_indirizzo_consegna( $allegato ), $nudo );
	}

	/**
	 * C-162: tolgo l'aggancio del punto pubblico, e la richiesta non produce
	 * piu' nessuna risposta.
	 *
	 * E' la riga che dimostra che tutte le altre passavano davvero
	 * dall'aggancio. Senza, una variabile d'interrogazione scartata da
	 * WordPress produrrebbe zero risposte e ogni verifica scritta al negativo
	 * resterebbe verde.
	 */
	public function test_c162_senza_laggancio_il_punto_pubblico_non_risponde() {
		list( $atto, $allegato ) = $this->atto_con_allegato();

		$prima = $this->chiedi( $atto, $allegato );

		$this->assertNotNull( $prima, 'Con l\'aggancio acceso la richiesta deve produrre una risposta.' );
		$this->assertSame( 200, $prima['stato'] );

		$this->spegni_aggancio( 'parse_request', 'serve_pubblica' );

		$this->assertNull(
			$this->chiedi( $atto, $allegato ),
			'Spento l\'aggancio, nessuna risposta: le altre prove passavano di li\'.'
		);
	}
}
