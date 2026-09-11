<?php
/**
 * Il filtro di scadenza nell'interfaccia informatica pubblica: righe C-15, C-16,
 * C-17, C-105 e C-112 del catalogo.
 *
 * I due percorsi sono diversi per costruzione, e questa e' la ragione per cui
 * hanno due righe di collaudo. La **collezione** passa da un'interrogazione, e
 * quindi la coprono i due strati generali del filtro senza bisogno di niente in
 * piu'. Il **singolo identificativo** non passa da nessuna interrogazione:
 * WordPress legge il contenuto e lo prepara, quindi senza un aggancio dedicato
 * risponderebbe 200 con i dati di un atto che non e' piu' pubblicabile.
 *
 * La riga C-105 esiste per un rischio dell'implementazione, non della norma:
 * l'aggancio che serve al singolo e' lo stesso che WordPress usa per preparare
 * ogni elemento di un elenco, quindi se non distinguesse i due casi l'errore
 * finirebbe dentro la collezione e la risposta sarebbe malformata invece che
 * semplicemente priva del contenuto scaduto.
 *
 * @package Conformita_Core
 */

/**
 * Prove sul filtro di scadenza nell'interfaccia informatica pubblica.
 */
class Conformita_Core_Filtro_Scadenza_Rest_Test extends WP_UnitTestCase {

	const SEZIONE = 'sezione_rest';
	const TIPO    = 'prova_rest';

	/**
	 * Fuso del sito durante le prove, ripristinato alla fine.
	 *
	 * @var string
	 */
	private $fuso_originale = '';

	/**
	 * Una sezione con un tipo esposto all'interfaccia informatica, e le rotte
	 * ricostruite dopo la registrazione del tipo.
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
		 * Indirizzi semplici e nessuna variabile d'interrogazione propria per il
		 * tipo. Serve alla prova C-17, che parte da un indirizzo: cosi'
		 * l'indirizzo del contenuto porta l'identificativo, e WordPress risale al
		 * contenuto leggendolo, senza passare dalle regole di riscrittura. Con gli
		 * indirizzi leggibili il percorso dipenderebbe da quelle regole, e una
		 * prova che fallisce perche' una regola non c'e' dice che il filtro non
		 * funziona mentre il filtro non c'entra niente.
		 */
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

		// Le rotte si costruiscono a tipo gia' registrato: un server preparato
		// prima non conoscerebbe questo tipo.
		$GLOBALS['wp_rest_server'] = new WP_REST_Server();
		do_action( 'rest_api_init', $GLOBALS['wp_rest_server'] );
	}

	/**
	 * Server, fuso e orologio ripristinati.
	 */
	public function tear_down() {
		$GLOBALS['wp_rest_server'] = null;
		update_option( 'timezone_string', $this->fuso_originale );
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
	 * Un amministratore con tutte le capability del tipo assegnate.
	 *
	 * L'assegnazione e' esplicita perche' core registra le capability e non le
	 * assegna a nessun ruolo: senza, nemmeno un amministratore potrebbe
	 * modificare il tipo, e le prove sul contesto di modifica sarebbero verdi o
	 * rosse per il motivo sbagliato.
	 *
	 * @return int Identificativo dell'utente.
	 */
	private function utente_autorizzato() {
		$utente_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$utente    = get_user_by( 'id', $utente_id );

		foreach ( array_unique( array_values( conformita_core_capacita_tipo( self::TIPO ) ) ) as $capacita ) {
			$utente->add_cap( $capacita );
		}

		return $utente_id;
	}

	/**
	 * Esegue una richiesta sull'interfaccia informatica.
	 *
	 * **I parametri si passano uno per uno, non attaccati al percorso.** Il
	 * percorso di una richiesta costruita a mano viene confrontato per intero con
	 * la rotta: attaccarci la parte dopo il punto interrogativo non la fa
	 * interpretare, fa semplicemente sbagliare il confronto, e la risposta e'
	 * "nessuna rotta corrispondente". Che e' un 404, quindi indistinguibile da un
	 * rifiuto vero se non si guarda il motivo.
	 *
	 * @param string $percorso  Percorso della rotta, senza parametri.
	 * @param array  $parametri Parametri della richiesta.
	 * @return WP_REST_Response
	 */
	private function chiedi( $percorso, array $parametri = array() ) {
		$richiesta = new WP_REST_Request( 'GET', $percorso );

		foreach ( $parametri as $nome => $valore ) {
			$richiesta->set_param( $nome, $valore );
		}

		return rest_get_server()->dispatch( $richiesta );
	}

	/**
	 * Il motivo di una risposta, per i messaggi di fallimento.
	 *
	 * Una prova che fallisce dicendo soltanto "404 invece di 200" non dice se il
	 * rifiuto viene dal filtro o da una rotta sbagliata, e i due casi si
	 * correggono in posti diversi.
	 *
	 * @param WP_REST_Response $risposta Risposta ottenuta.
	 * @return string
	 */
	private function motivo( $risposta ) {
		$dati = $risposta->get_data();

		return isset( $dati['code'] ) ? (string) $dati['code'] : 'nessun codice';
	}

	/**
	 * C-15: il contenuto scaduto non e' nella collezione, il valido si'.
	 */
	public function test_c15_collezione() {
		$this->oggi_e( '2026-09-09' );

		$valido  = $this->contenuto( '2026-09-30' );
		$scaduto = $this->contenuto( '2026-09-08' );

		$risposta = $this->chiedi( '/wp/v2/' . self::TIPO );

		$this->assertSame( 200, $risposta->get_status() );

		$identificativi = wp_list_pluck( $risposta->get_data(), 'id' );

		$this->assertContains( $valido, $identificativi, 'Il contenuto valido resta nella collezione: senza questa asserzione una collezione sempre vuota passerebbe la prova.' );
		$this->assertNotContains( $scaduto, $identificativi, 'Il contenuto scaduto non deve comparire nella collezione.' );
	}

	/**
	 * C-105: la collezione resta ben formata.
	 *
	 * Ogni elemento deve essere un elenco di dati con il proprio identificativo,
	 * non un oggetto d'errore finito li' dentro dal controllo sul singolo.
	 */
	public function test_c105_collezione_ben_formata() {
		$this->oggi_e( '2026-09-09' );

		$this->contenuto( '2026-09-30' );
		$this->contenuto( '2026-09-08' );

		$risposta = $this->chiedi( '/wp/v2/' . self::TIPO );

		$this->assertSame( 200, $risposta->get_status() );
		$this->assertIsArray( $risposta->get_data() );
		$this->assertNotEmpty( $risposta->get_data(), 'La collezione non deve essere vuota, altrimenti la prova non dimostra niente.' );

		foreach ( $risposta->get_data() as $elemento ) {
			$this->assertIsArray( $elemento, 'Ogni elemento della collezione e\' un elenco di dati.' );
			$this->assertArrayHasKey( 'id', $elemento );
			$this->assertArrayNotHasKey( 'code', $elemento, 'Un elemento con una chiave "code" e\' un oggetto d\'errore finito dentro la collezione.' );
		}
	}

	/**
	 * C-16: l'indirizzo del singolo contenuto scaduto risponde "non trovato".
	 *
	 * Non 200 con i dati, e non 401 ne' 403: un codice che distingue "non
	 * esiste" da "non ti e' permesso" e' esso stesso un'informazione.
	 */
	public function test_c16_singolo_identificativo() {
		$this->oggi_e( '2026-09-09' );

		$valido  = $this->contenuto( '2026-09-30' );
		$scaduto = $this->contenuto( '2026-09-08' );

		$buona = $this->chiedi( '/wp/v2/' . self::TIPO . '/' . $valido );

		$this->assertSame( 200, $buona->get_status(), 'Il contenuto valido deve rispondere: senza questa asserzione un filtro che nega tutto passerebbe la prova.' );
		$this->assertSame( $valido, $buona->get_data()['id'] );

		$negata = $this->chiedi( '/wp/v2/' . self::TIPO . '/' . $scaduto );

		$this->assertSame( 404, $negata->get_status(), 'Il contenuto scaduto deve rispondere "non trovato", non 200 e non 403.' );
	}

	/**
	 * C-16: nemmeno l'utente autenticato con i permessi di gestione lo vede.
	 *
	 * L'interfaccia informatica pubblica e' una superficie pubblica, e
	 * l'esenzione dal filtro e' della superficie e non dell'utente.
	 */
	public function test_c16_nemmeno_per_utente_autorizzato() {
		$this->oggi_e( '2026-09-09' );

		$scaduto = $this->contenuto( '2026-09-08' );
		$valido  = $this->contenuto( '2026-09-30' );

		wp_set_current_user( $this->utente_autorizzato() );

		$this->assertSame( 200, $this->chiedi( '/wp/v2/' . self::TIPO . '/' . $valido )->get_status() );
		$this->assertSame(
			404,
			$this->chiedi( '/wp/v2/' . self::TIPO . '/' . $scaduto )->get_status(),
			'I permessi di gestione non aprono una superficie pubblica.'
		);
	}

	/**
	 * C-112: con il contesto di modifica l'atto scaduto resta correggibile.
	 *
	 * **E' il difetto piu' serio trovato in revisione, e non si vedeva dai test.**
	 * L'interfaccia informatica non e' soltanto una superficie pubblica: e' anche
	 * il canale con cui l'editor a blocchi di WordPress apre un contenuto per
	 * modificarlo. Rispondendo "non trovato" a qualunque richiesta del singolo, un
	 * atto con la data sbagliata compariva nell'elenco amministrativo ma non si
	 * apriva, quindi non era correggibile: l'esatto contrario di quello che la
	 * riga C-101 pretende.
	 *
	 * Le tre asserzioni vanno lette insieme. L'anonimo non passa, quindi il
	 * contesto di modifica non e' una porta di servizio. L'autorizzato passa,
	 * quindi l'atto si corregge. Lo stesso autorizzato con il contesto pubblico
	 * **non** passa, quindi l'esenzione e' del contesto e non dell'utente.
	 */
	public function test_c112_contesto_di_modifica() {
		$this->oggi_e( '2026-09-09' );

		$scaduto = $this->contenuto( '2026-09-08' );

		wp_set_current_user( 0 );

		$anonimo = $this->chiedi( '/wp/v2/' . self::TIPO . '/' . $scaduto, array( 'context' => 'edit' ) );

		$this->assertNotSame( 200, $anonimo->get_status(), 'Il contesto di modifica non e\' una porta di servizio per chi non ha permessi.' );
		$this->assertContains(
			$anonimo->get_status(),
			array( 401, 403 ),
			'Il rifiuto e\' quello normale di WordPress sui permessi, e arriva prima che si sappia se il contenuto sia scaduto. Motivo: ' . $this->motivo( $anonimo )
		);

		wp_set_current_user( $this->utente_autorizzato() );

		$modifica = $this->chiedi( '/wp/v2/' . self::TIPO . '/' . $scaduto, array( 'context' => 'edit' ) );

		$this->assertSame(
			200,
			$modifica->get_status(),
			'Chi ha il permesso di modifica deve poter aprire l\'atto scaduto, altrimenti non lo corregge nessuno. Motivo del rifiuto: ' . $this->motivo( $modifica )
		);
		$this->assertSame( $scaduto, $modifica->get_data()['id'] );

		$pubblico = $this->chiedi( '/wp/v2/' . self::TIPO . '/' . $scaduto );

		$this->assertSame(
			404,
			$pubblico->get_status(),
			'Lo stesso utente, con il contesto pubblico, non lo vede: l\'esenzione e\' del contesto e non dell\'utente.'
		);
	}

	/**
	 * C-17: l'anteprima incorporata di un contenuto scaduto non viene servita.
	 *
	 * E' il percorso con cui un altro sito chiede a questo una scheda del
	 * contenuto da mostrare dentro una propria pagina. Conta piu' di quanto
	 * sembri: l'anteprima finisce memorizzata sul sito che la incorpora, cioe'
	 * fuori dal nostro controllo, e li' resterebbe anche dopo la scadenza.
	 */
	public function test_c17_anteprima_incorporata() {
		$this->oggi_e( '2026-09-09' );

		$valido  = $this->contenuto( '2026-09-30' );
		$scaduto = $this->contenuto( '2026-09-08' );

		/*
		 * Prima si verifica che WordPress risalga al contenuto partendo dal suo
		 * indirizzo, per tutti e due. Senza questo controllo la prova sarebbe
		 * vuota: se l'indirizzo dello scaduto non portasse a niente, l'anteprima
		 * mancherebbe lo stesso, e la prova sarebbe verde senza che il filtro
		 * abbia fatto niente.
		 */
		$this->assertSame( $valido, url_to_postid( get_permalink( $valido ) ), 'L\'indirizzo del contenuto valido deve portare al contenuto.' );
		$this->assertSame( $scaduto, url_to_postid( get_permalink( $scaduto ) ), 'Anche l\'indirizzo dello scaduto deve portare al contenuto: e\' il filtro a doverne negare l\'anteprima, non l\'indirizzo a essere irraggiungibile.' );

		$buona = $this->chiedi( '/oembed/1.0/embed', array( 'url' => get_permalink( $valido ) ) );

		$this->assertSame( 200, $buona->get_status(), 'L\'anteprima del contenuto valido deve essere servita. Motivo del rifiuto: ' . $this->motivo( $buona ) );

		$negata = $this->chiedi( '/oembed/1.0/embed', array( 'url' => get_permalink( $scaduto ) ) );

		$this->assertSame( 404, $negata->get_status(), 'L\'anteprima di un contenuto scaduto non deve essere servita.' );
		$this->assertSame(
			'oembed_invalid_url',
			$this->motivo( $negata ),
			'Il rifiuto deve venire dal filtro e non da una rotta che non esiste: sono due 404 che si correggono in posti diversi.'
		);
	}

	/**
	 * C-16: un contenuto senza data di fine risponde "non trovato".
	 *
	 * Stessa direzione sicura del contratto del dato: il valore assente non e'
	 * un permesso implicito.
	 */
	public function test_c16_data_assente() {
		$this->oggi_e( '2026-09-09' );

		$senza_data = self::factory()->post->create(
			array(
				'post_type'   => self::TIPO,
				'post_status' => 'publish',
			)
		);

		$this->assertSame( 404, $this->chiedi( '/wp/v2/' . self::TIPO . '/' . $senza_data )->get_status() );
	}
}
