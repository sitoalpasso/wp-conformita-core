<?php
/**
 * Cartella protetta, verifica della protezione, deposito e impronta.
 *
 * Righe di collaudo C-115..C-125, C-146, C-147, C-153, C-154..C-158.
 *
 * **Come si simula il server.** La verifica della protezione e' una richiesta
 * HTTP verso il sito stesso. Qui non c'e' nessun server web, quindi la
 * richiesta si intercetta con `pre_http_request` e le si fa dire quello che
 * direbbe un server coperto, uno scoperto, o uno irraggiungibile. E' la stessa
 * sostituzione che la suite di WordPress usa per le richieste in uscita: quello
 * che resta vero e' la lettura della risposta, che e' la parte che decide.
 *
 * Il filtro conta anche **quante** richieste partono, perche' due righe
 * (C-157 e C-140) non verificano un esito ma un'assenza: un deposito normale e
 * una consegna non devono fare nessuna richiesta.
 *
 * @package Conformita_Core
 */

/**
 * Prove sulla cartella protetta, sul deposito e sull'impronta.
 */
class Conformita_Core_Allegati_Test extends WP_UnitTestCase {

	const SEZIONE = 'sezione_allegati';
	const TIPO    = 'prova_allegato';

	/**
	 * Risposta che il server finto dara' alla prossima richiesta all'esca.
	 *
	 * @var mixed
	 */
	private $risposta_finta = null;

	/**
	 * Quante richieste HTTP sono partite da quando la prova e' cominciata.
	 *
	 * @var int
	 */
	private $richieste = 0;

	/**
	 * File temporanei creati dalla prova, da togliere alla fine.
	 *
	 * @var array<int, string>
	 */
	private $temporanei = array();

	/**
	 * Fuso del sito durante le prove, ripristinato alla fine.
	 *
	 * @var string
	 */
	private $fuso_originale = '';

	/**
	 * Una sezione con un tipo gestito, e il server finto agganciato.
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

		$this->risposta_finta = $this->rifiuto_del_server();
		$this->richieste      = 0;

		add_filter( 'pre_http_request', array( $this, 'server_finto' ), 10, 3 );

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
	 * Smonta tutto quello che la prova ha lasciato in giro.
	 */
	public function tear_down() {
		remove_filter( 'pre_http_request', array( $this, 'server_finto' ), 10 );

		foreach ( $this->temporanei as $percorso ) {
			if ( file_exists( $percorso ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- prova: si tocca il disco direttamente perche' e' il disco cio' che si sta verificando.
				unlink( $percorso );
			}
		}

		$this->svuota_cartella_protetta();
		Conformita_Core_Allegati::azzera();
		Conformita_Core_Consegna::azzera_avvio();
		Conformita_Core_Consegna::avvia();

		update_option( 'timezone_string', $this->fuso_originale );

		parent::tear_down();
	}

	/**
	 * Il server finto: risponde quello che la prova ha deciso, e si conta.
	 *
	 * @param mixed $esito      Esito gia' deciso da qualcun altro.
	 * @param mixed $argomenti  Argomenti della richiesta.
	 * @param mixed $indirizzo  Indirizzo richiesto.
	 * @return mixed
	 */
	public function server_finto( $esito, $argomenti = array(), $indirizzo = '' ) {
		unset( $esito, $argomenti, $indirizzo );

		++$this->richieste;

		return $this->risposta_finta;
	}

	/**
	 * La risposta di un server che nega il percorso.
	 *
	 * @return array<string, mixed>
	 */
	private function rifiuto_del_server() {
		return array(
			'response' => array(
				'code'    => 403,
				'message' => 'Forbidden',
			),
			'body'     => '<html><body>Forbidden</body></html>',
		);
	}

	/**
	 * La risposta di un server che serve l'esca, gettone compreso.
	 *
	 * @return array<string, mixed>
	 */
	private function esca_servita() {
		return array(
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'body'     => Conformita_Core_Allegati::contenuto_esca(),
		);
	}

	/**
	 * Un file vero sul disco, con il contenuto e l'estensione indicati.
	 *
	 * Il PDF comincia davvero con la firma di un PDF: la verifica del tipo di
	 * WordPress guarda il contenuto e non solo l'estensione, quindi un file
	 * finto verrebbe rifiutato per il motivo sbagliato.
	 *
	 * @param string $nome      Nome del file.
	 * @param string $contenuto Contenuto del file.
	 * @return array<string, mixed> Voce nella forma di $_FILES.
	 */
	private function file_da_depositare( $nome = 'atto.pdf', $contenuto = null ) {
		if ( null === $contenuto ) {
			$contenuto = "%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF\n";
		}

		$percorso = wp_tempnam( $nome );

		file_put_contents( $percorso, $contenuto ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- prova: il file deve esistere sul disco prima che WP_Filesystem sia inizializzato.

		$this->temporanei[] = $percorso;

		return array(
			'name'     => $nome,
			'tmp_name' => $percorso,
			'type'     => '',
			'size'     => strlen( $contenuto ),
			'error'    => 0,
		);
	}

	/**
	 * Un contenuto pubblicato del tipo gestito, con la fine fra un mese.
	 *
	 * @return int
	 */
	private function atto_valido() {
		$atto = self::factory()->post->create(
			array(
				'post_type'   => self::TIPO,
				'post_status' => 'publish',
			)
		);

		$this->assertTrue(
			conformita_core_imposta_fine_pubblicazione(
				$atto,
				gmdate( 'Y-m-d', strtotime( '+30 days' ) )
			)
		);

		return $atto;
	}

	/**
	 * Toglie di mezzo la cartella protetta fra una prova e l'altra.
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
	 * C-115: il file non finisce nella cartella pubblica dei caricamenti, e il
	 * percorso memorizzato porta il segmento protetto per intero.
	 *
	 * Il percorso memorizzato **deve** contenere il segmento: se contenesse solo
	 * l'anno e il mese, riletto a filtro spento punterebbe a un file pubblico
	 * con lo stesso nome, e la consegna servirebbe il documento sbagliato senza
	 * nessun errore.
	 */
	public function test_c115_il_file_sta_nella_cartella_protetta() {
		$atto = $this->atto_valido();

		$allegato = conformita_core_deposita_allegato(
			$atto,
			$this->file_da_depositare(),
			array( 'origine' => 'percorso_locale' )
		);

		$this->assertIsInt( $allegato );

		$relativo = get_post_meta( $allegato, '_wp_attached_file', true );

		$this->assertStringStartsWith( 'conformita-core-protetto/', $relativo );

		$caricamenti = wp_get_upload_dir();
		$assoluto    = $caricamenti['basedir'] . '/' . $relativo;

		$this->assertFileExists( $assoluto );
		$this->assertStringStartsWith( Conformita_Core_Allegati::cartella() . '/', $assoluto );
	}

	/**
	 * C-116: la cartella nasce con i tre file di regole e con l'esca, e l'esca
	 * contiene il gettone conservato accanto all'esito.
	 */
	public function test_c116_la_cartella_nasce_con_le_regole_e_con_lesca() {
		$this->assertTrue( Conformita_Core_Allegati::prepara() );

		$cartella = Conformita_Core_Allegati::cartella();

		$this->assertFileExists( $cartella . '/.htaccess' );
		$this->assertFileExists( $cartella . '/web.config' );
		$this->assertFileExists( $cartella . '/index.php' );
		$this->assertFileExists( $cartella . '/prova-accesso-diretto.txt' );

		$htaccess = file_get_contents( $cartella . '/.htaccess' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- prova: si legge un file locale appena scritto, non un indirizzo remoto.

		$this->assertStringContainsString( 'Require all denied', $htaccess );
		$this->assertStringContainsString( 'deny from all', $htaccess );

		$esca = file_get_contents( $cartella . '/prova-accesso-diretto.txt' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- prova: come sopra.

		$this->assertSame( Conformita_Core_Allegati::contenuto_esca(), $esca );
		$this->assertNotSame( '', Conformita_Core_Allegati::gettone() );
		$this->assertStringContainsString( Conformita_Core_Allegati::gettone(), $esca );
	}

	/**
	 * C-117: regole cancellate a mano, il deposito successivo le riscrive prima
	 * di muovere i byte.
	 */
	public function test_c117_le_regole_si_riscrivono_prima_di_depositare() {
		$atto = $this->atto_valido();

		$this->assertTrue( Conformita_Core_Allegati::prepara() );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- prova: si tocca il disco direttamente perche' e' il disco cio' che si sta verificando.
		unlink( Conformita_Core_Allegati::cartella() . '/.htaccess' );

		$allegato = conformita_core_deposita_allegato(
			$atto,
			$this->file_da_depositare(),
			array( 'origine' => 'percorso_locale' )
		);

		$this->assertIsInt( $allegato );
		$this->assertFileExists( Conformita_Core_Allegati::cartella() . '/.htaccess' );
	}

	/**
	 * C-118: cartella non creabile, il deposito fallisce e non lascia dietro
	 * ne file ne allegato.
	 *
	 * La cartella si rende impossibile spostando i caricamenti sotto un percorso
	 * che nessuno puo' creare, invece di togliere i permessi di scrittura: le
	 * prove girano spesso come amministratore di sistema, e in quel caso i
	 * permessi non fermerebbero niente e la prova sarebbe verde per il motivo
	 * sbagliato.
	 */
	public function test_c118_cartella_non_creabile_niente_deposito() {
		$atto = $this->atto_valido();

		$prima = wp_count_posts( 'attachment' );

		add_filter( 'upload_dir', array( $this, 'caricamenti_impossibili' ), 1 );

		$esito = conformita_core_deposita_allegato(
			$atto,
			$this->file_da_depositare(),
			array( 'origine' => 'percorso_locale' )
		);

		remove_filter( 'upload_dir', array( $this, 'caricamenti_impossibili' ), 1 );

		$this->assertWPError( $esito );
		$this->assertSame( 'conformita_core_cartella_non_protetta', $esito->get_error_code() );

		$dopo = wp_count_posts( 'attachment' );

		$this->assertSame( $prima->inherit, $dopo->inherit );
	}

	/**
	 * Sposta i caricamenti sotto un percorso che non si puo' creare.
	 *
	 * @param array<string, string> $caricamenti Cartella dei caricamenti.
	 * @return array<string, string>
	 */
	public function caricamenti_impossibili( $caricamenti ) {
		$caricamenti['basedir'] = '/proc/conformita-core-non-creabile';
		$caricamenti['path']    = $caricamenti['basedir'];

		return $caricamenti;
	}

	/**
	 * C-119: copertura `non_coperta`, deposito rifiutato, e l'errore riporta
	 * esito, istante e stato osservato.
	 */
	public function test_c119_copertura_non_coperta_deposito_rifiutato() {
		$atto = $this->atto_valido();

		$this->risposta_finta = $this->esca_servita();

		$stato = conformita_core_verifica_protezione_allegati();

		$this->assertSame( 'non_coperta', $stato['copertura'] );

		$esito = conformita_core_deposita_allegato(
			$atto,
			$this->file_da_depositare(),
			array( 'origine' => 'percorso_locale' )
		);

		$this->assertWPError( $esito );
		$this->assertSame( 'conformita_core_protezione_non_verificata', $esito->get_error_code() );

		$dati = $esito->get_error_data();

		$this->assertSame( 'non_coperta', $dati['copertura'] );
		$this->assertSame( 200, $dati['stato_http'] );
		$this->assertNotSame( '', $dati['istante'] );
	}

	/**
	 * C-120: copertura `ignota`, deposito rifiutato; con lo scavalcamento
	 * dichiarato il deposito procede e lo stato continua a dire la verita'.
	 */
	public function test_c120_copertura_ignota_e_scavalcamento() {
		$atto = $this->atto_valido();

		$this->risposta_finta = new WP_Error( 'http_request_failed', 'Nessuna risposta.' );

		$stato = conformita_core_verifica_protezione_allegati();

		$this->assertSame( 'ignota', $stato['copertura'] );
		$this->assertFalse( $stato['scavalcata'] );

		$esito = conformita_core_deposita_allegato(
			$atto,
			$this->file_da_depositare(),
			array( 'origine' => 'percorso_locale' )
		);

		$this->assertWPError( $esito );
		$this->assertSame( 'conformita_core_protezione_non_verificata', $esito->get_error_code() );

		Conformita_Core_Allegati::fissa_scavalcamento( true );

		$allegato = conformita_core_deposita_allegato(
			$atto,
			$this->file_da_depositare( 'secondo.pdf' ),
			array( 'origine' => 'percorso_locale' )
		);

		$this->assertIsInt( $allegato );

		$stato = conformita_core_stato_protezione_allegati();

		$this->assertSame( 'ignota', $stato['copertura'], 'Lo scavalcamento cambia cosa fa il deposito, non cosa dice lo stato.' );
		$this->assertTrue( $stato['scavalcata'] );
	}

	/**
	 * C-121: deposito su un contenuto di tipo non registrato attraverso core.
	 */
	public function test_c121_tipo_non_gestito_rifiutato() {
		$estraneo = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$esito = conformita_core_deposita_allegato(
			$estraneo,
			$this->file_da_depositare(),
			array( 'origine' => 'percorso_locale' )
		);

		$this->assertWPError( $esito );
		$this->assertSame( 'conformita_core_tipo_non_gestito', $esito->get_error_code() );
	}

	/**
	 * C-122: origine non dichiarata, e origine `caricamento` senza un vero
	 * caricamento.
	 *
	 * Il secondo caso e' la ragione per cui `origine` esiste: senza la
	 * dichiarazione, un percorso ricevuto dall'esterno diventerebbe una lettura
	 * di file arbitrari travestita da caricamento.
	 */
	public function test_c122_origine_obbligatoria_e_coerente() {
		$atto = $this->atto_valido();

		$senza = conformita_core_deposita_allegato( $atto, $this->file_da_depositare(), array() );

		$this->assertWPError( $senza );
		$this->assertSame( 'conformita_core_origine_non_dichiarata', $senza->get_error_code() );

		$sbagliata = conformita_core_deposita_allegato(
			$atto,
			$this->file_da_depositare(),
			array( 'origine' => 'caricamento' )
		);

		$this->assertWPError( $sbagliata );
		$this->assertSame( 'conformita_core_origine_incoerente', $sbagliata->get_error_code() );

		$inventata = conformita_core_deposita_allegato(
			$atto,
			$this->file_da_depositare(),
			array( 'origine' => 'qualunque_cosa' )
		);

		$this->assertWPError( $inventata );
		$this->assertSame( 'conformita_core_origine_non_dichiarata', $inventata->get_error_code() );
	}

	/**
	 * C-123: tipo di file non ammesso dall'installazione.
	 */
	public function test_c123_tipo_di_file_non_ammesso() {
		$atto = $this->atto_valido();

		$esito = conformita_core_deposita_allegato(
			$atto,
			$this->file_da_depositare( 'programma.php', "<?php echo 'ciao';" ),
			array( 'origine' => 'percorso_locale' )
		);

		$this->assertWPError( $esito );
		$this->assertSame( 'conformita_core_tipo_file_non_ammesso', $esito->get_error_code() );
	}

	/**
	 * C-124: impronta al deposito, con dimensione e istante nel fuso del sito.
	 */
	public function test_c124_impronta_al_deposito() {
		$atto      = $this->atto_valido();
		$contenuto = "%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF\n";

		$allegato = conformita_core_deposita_allegato(
			$atto,
			$this->file_da_depositare( 'atto.pdf', $contenuto ),
			array( 'origine' => 'percorso_locale' )
		);

		$this->assertIsInt( $allegato );

		$impronta = conformita_core_impronta_allegato( $allegato );

		$this->assertSame( 'sha256', $impronta['algoritmo'] );
		$this->assertSame( hash( 'sha256', $contenuto ), $impronta['valore'] );
		$this->assertSame( strlen( $contenuto ), $impronta['dimensione'] );

		$memorizzato = get_post_meta( $allegato, '_conformita_core_impronta', true );

		$this->assertSame( 'sha256:' . hash( 'sha256', $contenuto ), $memorizzato );

		$deposito = new DateTimeImmutable( $impronta['deposito'] );

		$this->assertSame(
			wp_timezone()->getOffset( $deposito ),
			$deposito->getOffset(),
			"L'istante di deposito porta lo scostamento del fuso del sito, non quello del server."
		);
	}

	/**
	 * C-125: file sostituito sul disco dopo il deposito.
	 *
	 * **Documentazione eseguibile di un limite, non prova di conformita'.** La
	 * consegna non ricalcola l'impronta, quindi non si accorge della
	 * sostituzione: questa prova lo mette nero su bianco perche' il limite resti
	 * visibile invece di essere scoperto da qualcuno che conta sul contrario.
	 */
	public function test_c125_limite_il_file_sostituito_non_si_nota() {
		$atto = $this->atto_valido();

		$allegato = conformita_core_deposita_allegato(
			$atto,
			$this->file_da_depositare(),
			array( 'origine' => 'percorso_locale' )
		);

		$impronta = conformita_core_impronta_allegato( $allegato );
		$percorso = get_attached_file( $allegato );

		file_put_contents( $percorso, "%PDF-1.4\naltro contenuto\n%%EOF\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- prova: la sostituzione del file e' proprio cio' che si sta simulando.

		$dopo = conformita_core_impronta_allegato( $allegato );

		$this->assertSame( $impronta['valore'], $dopo['valore'], 'Limite dichiarato: l\'impronta e\' quella del deposito.' );
		$this->assertNotSame( hash_file( 'sha256', $percorso ), $dopo['valore'] );
	}

	/**
	 * C-146: lo stato si rilegge con il suo istante, e leggerlo non costa una
	 * richiesta.
	 */
	public function test_c146_lo_stato_si_rilegge_senza_richieste() {
		$verificato = conformita_core_verifica_protezione_allegati();

		$this->assertSame( 'verificata', $verificato['copertura'] );
		$this->assertSame( 1, $this->richieste );

		$riletto = conformita_core_stato_protezione_allegati();

		$this->assertSame( 'verificata', $riletto['copertura'] );
		$this->assertSame( $verificato['istante'], $riletto['istante'] );
		$this->assertSame( 1, $this->richieste, 'Leggere lo stato non deve fare nessuna richiesta.' );
	}

	/**
	 * C-147: la sezione si registra anche a consegna spenta; il deposito no.
	 */
	public function test_c147_la_sezione_non_dipende_dalla_consegna() {
		Conformita_Core_Consegna::azzera_avvio();

		$this->assertFalse( Conformita_Core_Consegna::avviato() );

		Conformita_Core_Sezioni::azzera();

		$this->assertTrue(
			conformita_core_registra_sezione(
				'sezione_senza_consegna',
				array(
					'indicizzazione' => 'vietata',
					'scadenza'       => 'irraggiungibile',
				)
			),
			'La consegna spenta chiude, non apre: non c\'e\' ragione di bloccare la registrazione.'
		);
	}

	/**
	 * C-153: la versione dell'interfaccia e le funzioni nuove.
	 */
	public function test_c153_contratto_pubblico() {
		$this->assertSame( '1.3.0', CONFORMITA_CORE_VERSIONE_API );
		$this->assertSame( '1.3.0', conformita_core_versione_api() );

		foreach (
			array(
				'conformita_core_deposita_allegato',
				'conformita_core_indirizzo_consegna',
				'conformita_core_indirizzo_consegna_amministrativa',
				'conformita_core_allegato_protetto',
				'conformita_core_impronta_allegato',
				'conformita_core_stato_protezione_allegati',
				'conformita_core_verifica_protezione_allegati',
			) as $funzione
		) {
			$this->assertTrue( function_exists( $funzione ), $funzione . ' non esiste.' );
		}
	}

	/**
	 * C-154: l'esca riceve un rifiuto, quindi la protezione e' verificata.
	 */
	public function test_c154_rifiuto_dellesca_vale_verificata() {
		$this->risposta_finta = $this->rifiuto_del_server();

		$stato = conformita_core_verifica_protezione_allegati();

		$this->assertSame( 'verificata', $stato['copertura'] );
		$this->assertSame( 403, $stato['stato_http'] );
		$this->assertNotSame( '', $stato['istante'] );
	}

	/**
	 * C-155: l'esca torna con il suo gettone, quindi la cartella e' aperta.
	 */
	public function test_c155_esca_servita_vale_non_coperta() {
		$this->risposta_finta = $this->esca_servita();

		$stato = conformita_core_verifica_protezione_allegati();

		$this->assertSame( 'non_coperta', $stato['copertura'] );
		$this->assertSame( 200, $stato['stato_http'] );
	}

	/**
	 * C-156: richiesta fallita, e 200 senza il gettone: `ignota` tutt'e due, con
	 * motivi distinti.
	 *
	 * Il secondo caso e' quello che il gettone serve a distinguere: una pagina
	 * di accesso o un catch-all rispondono 200 e non sono un rifiuto. Trattarlo
	 * come protezione attiva sarebbe la direzione sbagliata.
	 */
	public function test_c156_richiesta_fallita_o_risposta_estranea_vale_ignota() {
		$this->risposta_finta = new WP_Error( 'http_request_failed', 'Nessuna risposta.' );

		$fallita = conformita_core_verifica_protezione_allegati();

		$this->assertSame( 'ignota', $fallita['copertura'] );
		$this->assertSame( 0, $fallita['stato_http'] );

		$this->risposta_finta = array(
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'body'     => '<html><body>Accedi</body></html>',
		);

		$estranea = conformita_core_verifica_protezione_allegati();

		$this->assertSame( 'ignota', $estranea['copertura'] );
		$this->assertSame( 200, $estranea['stato_http'] );
		$this->assertNotSame( $fallita['motivo'], $estranea['motivo'] );
	}

	/**
	 * C-157: la verifica si fa su richiesta e alla riscrittura delle regole; un
	 * deposito normale non fa nessuna richiesta.
	 */
	public function test_c157_quando_la_verifica_si_fa_e_quando_no() {
		$atto = $this->atto_valido();

		$this->assertSame( 0, $this->richieste );

		$primo = conformita_core_deposita_allegato(
			$atto,
			$this->file_da_depositare( 'primo.pdf' ),
			array( 'origine' => 'percorso_locale' )
		);

		$this->assertIsInt( $primo );
		$this->assertSame( 1, $this->richieste, 'Il primo deposito scrive le regole, quindi verifica.' );

		$secondo = conformita_core_deposita_allegato(
			$atto,
			$this->file_da_depositare( 'secondo.pdf' ),
			array( 'origine' => 'percorso_locale' )
		);

		$this->assertIsInt( $secondo );
		$this->assertSame( 1, $this->richieste, 'Un deposito che non riscrive niente non deve fare richieste.' );

		conformita_core_verifica_protezione_allegati();

		$this->assertSame( 2, $this->richieste, 'La richiesta esplicita, invece, la fa.' );
	}

	/**
	 * C-158: regole cancellate a mano, il deposito successivo le riscrive e
	 * rifa' la verifica, decidendo con l'esito nuovo.
	 *
	 * E' la riga che chiude l'unico modo di guasto che sbagliava aprendo: un
	 * esito conservato da prima direbbe ancora `verificata` su una cartella che
	 * nel frattempo e' stata riaperta.
	 */
	public function test_c158_regole_cancellate_la_verifica_si_rifa() {
		$atto = $this->atto_valido();

		$primo = conformita_core_deposita_allegato(
			$atto,
			$this->file_da_depositare( 'primo.pdf' ),
			array( 'origine' => 'percorso_locale' )
		);

		$this->assertIsInt( $primo );
		$this->assertSame( 'verificata', conformita_core_stato_protezione_allegati()['copertura'] );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- prova: si tocca il disco direttamente perche' e' il disco cio' che si sta verificando.
		unlink( Conformita_Core_Allegati::cartella() . '/.htaccess' );

		$this->risposta_finta = $this->esca_servita();

		$esito = conformita_core_deposita_allegato(
			$atto,
			$this->file_da_depositare( 'secondo.pdf' ),
			array( 'origine' => 'percorso_locale' )
		);

		$this->assertSame( 2, $this->richieste, 'La riscrittura delle regole rifa\' la verifica.' );
		$this->assertWPError( $esito );
		$this->assertSame( 'non_coperta', conformita_core_stato_protezione_allegati()['copertura'] );
	}
}
