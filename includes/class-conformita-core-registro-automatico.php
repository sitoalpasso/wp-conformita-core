<?php
/**
 * Le voci che core scrive da sé, su ogni contenuto di un tipo gestito.
 *
 * **Perché da sé e non su richiesta del componente.** Un componente che
 * dimentica di scrivere una voce lascia un buco che nessuno vede. Le operazioni
 * che WordPress compie su un contenuto passano tutte da pochi agganci, e da lì
 * core le vede qualunque sia la strada: l'editor, l'interfaccia per programmi,
 * la riga di comando, il codice di un altro componente. Il componente aggiunge
 * le proprie voci con il motivo, accanto a queste; non le sostituisce.
 *
 * **Le bozze non si registrano.** Mentre una bozza si scrive, WordPress la
 * salva da solo a intervalli, e una voce per ogni salvataggio renderebbe il
 * registro illeggibile senza dire niente di utile: una bozza non è ancora
 * un'operazione su qualcosa che qualcuno ha visto. Si registrano la nascita
 * del contenuto, ogni suo cambio di stato, la sua eliminazione, e ogni
 * modifica compiuta fuori dalla bozza. Riga C-201.
 *
 * Righe di collaudo C-195..C-203.
 *
 * @package Conformita_Core
 */

defined( 'ABSPATH' ) || exit;

/**
 * Agganci che traducono le operazioni di WordPress in voci di registro.
 */
final class Conformita_Core_Registro_Automatico {

	/**
	 * Stati in cui il contenuto è ancora in lavorazione e non si registra.
	 */
	const STATI_IN_LAVORAZIONE = array( 'new', 'auto-draft', 'draft' );

	/**
	 * I campi del contenuto confrontati per decidere se c'è stata una modifica.
	 *
	 * Mancano di proposito lo stato, che ha le sue voci, e i campi che WordPress
	 * aggiorna da sé a ogni salvataggio, come la data di ultima modifica: con
	 * quelli ogni salvataggio sarebbe una modifica.
	 */
	const CAMPI = array(
		'post_title',
		'post_content',
		'post_excerpt',
		'post_name',
		'post_author',
		'post_date',
		'post_date_gmt',
		'post_parent',
		'menu_order',
		'post_password',
		'comment_status',
		'ping_status',
		'post_mime_type',
		'post_content_filtered',
	);

	/**
	 * Data che WordPress usa per dire "non ancora fissata".
	 */
	const DATA_NON_FISSATA = '0000-00-00 00:00:00';

	/**
	 * Il meccanismo ha già agganciato i propri ascoltatori.
	 *
	 * @var bool
	 */
	private static $avviato = false;

	/**
	 * La scrittura delle voci automatiche, ricevuta dal registro all'avvio.
	 *
	 * @var Closure|null
	 */
	private static $scrittura = null;

	/**
	 * Gli ascoltatori agganciati, uno per voce di `agganci()`, nello stesso ordine.
	 *
	 * Sono chiusure nate qui dentro, che chiamano metodi privati: nessun
	 * componente può chiamare da fuori un ascoltatore per far scrivere a core
	 * una voce su un fatto che non è avvenuto.
	 *
	 * @var array<int, Closure>
	 */
	private static $ascoltatori = array();

	/**
	 * Cosa ha chiesto il salvataggio in corso e cosa ne ha ricavato WordPress,
	 * per contenuto.
	 *
	 * Si legge al filtro dei dati, prima che la banca dati li riceva, e si
	 * consuma all'annuncio della modifica, che segue nello stesso salvataggio.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private static $richieste = array();

	/**
	 * Valori della fine pubblicazione letti prima di una scrittura, per contenuto.
	 *
	 * @var array<int, array<int, string>>
	 */
	private static $fine_prima = array();

	/**
	 * Righe della fine pubblicazione toccate da una scrittura il cui annuncio
	 * non nomina il contenuto giusto, per numero di riga.
	 *
	 * @var array<int, int>
	 */
	private static $righe_fine = array();

	/**
	 * Contenuti in corso di eliminazione, per identificativo.
	 *
	 * WordPress cancella i metadati di un contenuto che elimina: senza questo
	 * elenco la cancellazione della fine pubblicazione produrrebbe una seconda
	 * voce accanto a quella dell'eliminazione, che dice già tutto.
	 *
	 * @var array<int, bool>
	 */
	private static $in_eliminazione = array();

	/**
	 * Allegati in corso di eliminazione, con il contenuto a cui appartengono.
	 *
	 * La voce si scrive a eliminazione avvenuta, quando dell'allegato non
	 * resta niente da cui leggere il padre.
	 *
	 * @var array<int, int>
	 */
	private static $allegati_in_eliminazione = array();

	/**
	 * Contenuti la cui riga WordPress ha provato a cancellare, per
	 * identificativo, in attesa di sapere se ci è riuscito.
	 *
	 * @var array<int, bool>
	 */
	private static $riga_in_cancellazione = array();

	/**
	 * Allegati e figli che WordPress sposterà al padre di un contenuto che
	 * elimina, letti prima dello spostamento, per contenuto eliminato.
	 *
	 * @var array<int, array{padre: int, allegati: array<int, int>, figli: array<int, int>}>
	 */
	private static $spostamenti = array();

	/**
	 * Contenuti gestiti di un utente che si sta eliminando affidandone i
	 * contenuti a un altro, per utente.
	 *
	 * @var array<int, array<int, int>>
	 */
	private static $contenuti_affidati = array();

	/**
	 * Le operazioni che core registra da sé, riservate.
	 *
	 * Un componente non le può usare per le proprie voci: una voce
	 * `pubblicazione` scritta da un componente si confonderebbe con quella che
	 * attesta il fatto. L'origine della voce lo direbbe comunque, ma il nome non
	 * deve prestarsi all'equivoco.
	 *
	 * @return array<int, string>
	 */
	public static function azioni() {
		return array(
			'creazione',
			'pubblicazione',
			'rimozione',
			'cambio_stato',
			'modifica',
			'modifica_fine_pubblicazione',
			'eliminazione',
			'allegato_aggiunto',
			'allegato_eliminato',
		);
	}

	/**
	 * Aggancia gli ascoltatori.
	 *
	 * Alla prima accensione riceve dal registro la scrittura delle voci
	 * automatiche, che il registro consegna una volta sola.
	 *
	 * @internal Si accende al caricamento di core, come il motore di scadenza.
	 */
	public static function avvia() {
		if ( self::$avviato ) {
			return;
		}

		self::$avviato = true;

		if ( null === self::$scrittura ) {
			self::$scrittura = Conformita_Core_Registro::scrittura_automatica();
		}

		self::$ascoltatori = array();

		foreach ( self::agganci() as $aggancio ) {
			$metodo = $aggancio['metodo'];

			$ascoltatore = static function ( ...$argomenti ) use ( $metodo ) {
				return self::$metodo( ...$argomenti );
			};

			self::$ascoltatori[] = $ascoltatore;

			add_filter( $aggancio['aggancio'], $ascoltatore, $aggancio['priorita'], $aggancio['argomenti'] );
		}
	}

	/**
	 * Stacca gli ascoltatori.
	 *
	 * Privata: un componente che potesse spegnere le voci automatiche
	 * lavorerebbe senza lasciare traccia. Le prove di non vacuità la chiamano
	 * attraverso la riflessione. Usa lo stesso elenco dell'accensione, così lo
	 * spegnimento non può dimenticarne uno.
	 */
	private static function spegni() {
		foreach ( self::agganci() as $indice => $aggancio ) {
			if ( isset( self::$ascoltatori[ $indice ] ) ) {
				remove_filter( $aggancio['aggancio'], self::$ascoltatori[ $indice ], $aggancio['priorita'] );
			}
		}

		self::$ascoltatori     = array();
		self::$avviato         = false;
		self::$richieste       = array();
		self::$fine_prima      = array();
		self::$righe_fine      = array();
		self::$in_eliminazione = array();

		self::$allegati_in_eliminazione = array();
		self::$riga_in_cancellazione    = array();
		self::$spostamenti              = array();
		self::$contenuti_affidati       = array();
	}

	/**
	 * Il meccanismo è agganciato.
	 *
	 * @return bool
	 */
	public static function avviato() {
		return self::$avviato;
	}

	/**
	 * L'elenco degli agganci, in un posto solo.
	 *
	 * La priorità è la più alta possibile sugli agganci che vengono dopo il
	 * fatto: un altro componente che su quegli stessi agganci termina la
	 * richiesta non deve poter impedire la voce.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function agganci() {
		return array(
			array(
				'aggancio'  => 'transition_post_status',
				'metodo'    => 'stato',
				'priorita'  => PHP_INT_MIN,
				'argomenti' => 3,
			),
			array(
				'aggancio'  => 'wp_insert_post_data',
				'metodo'    => 'richiesta',
				'priorita'  => PHP_INT_MIN,
				'argomenti' => 4,
			),
			array(
				'aggancio'  => 'wp_insert_post_data',
				'metodo'    => 'richiesta_finale',
				'priorita'  => PHP_INT_MAX,
				'argomenti' => 4,
			),
			array(
				'aggancio'  => 'post_updated',
				'metodo'    => 'modifica',
				'priorita'  => PHP_INT_MIN,
				'argomenti' => 3,
			),
			array(
				'aggancio'  => 'before_delete_post',
				'metodo'    => 'eliminazione_inizio',
				'priorita'  => PHP_INT_MIN,
				'argomenti' => 2,
			),
			array(
				'aggancio'  => 'delete_post',
				'metodo'    => 'eliminazione_riga',
				'priorita'  => PHP_INT_MIN,
				'argomenti' => 2,
			),
			array(
				'aggancio'  => 'deleted_post',
				'metodo'    => 'eliminazione',
				'priorita'  => PHP_INT_MIN,
				'argomenti' => 2,
			),
			array(
				'aggancio'  => 'delete_user',
				'metodo'    => 'utente_in_eliminazione',
				'priorita'  => PHP_INT_MIN,
				'argomenti' => 2,
			),
			array(
				'aggancio'  => 'deleted_user',
				'metodo'    => 'utente_eliminato',
				'priorita'  => PHP_INT_MIN,
				'argomenti' => 2,
			),
			array(
				'aggancio'  => 'add_attachment',
				'metodo'    => 'allegato_aggiunto',
				'priorita'  => PHP_INT_MIN,
				'argomenti' => 1,
			),
			array(
				'aggancio'  => 'delete_attachment',
				'metodo'    => 'allegato_eliminato',
				'priorita'  => PHP_INT_MIN,
				'argomenti' => 2,
			),
			array(
				'aggancio'  => 'attachment_updated',
				'metodo'    => 'modifica',
				'priorita'  => PHP_INT_MIN,
				'argomenti' => 3,
			),
			array(
				'aggancio'  => 'wp_media_attach_action',
				'metodo'    => 'allegato_collegato',
				'priorita'  => PHP_INT_MIN,
				'argomenti' => 3,
			),
			array(
				'aggancio'  => 'add_post_meta',
				'metodo'    => 'fine_prima_aggiunta',
				'priorita'  => PHP_INT_MIN,
				'argomenti' => 2,
			),
			array(
				'aggancio'  => 'update_post_meta',
				'metodo'    => 'fine_prima',
				'priorita'  => PHP_INT_MIN,
				'argomenti' => 3,
			),
			array(
				'aggancio'  => 'delete_post_meta',
				'metodo'    => 'fine_prima',
				'priorita'  => PHP_INT_MIN,
				'argomenti' => 3,
			),
			array(
				'aggancio'  => 'update_post_metadata_by_mid',
				'metodo'    => 'fine_rinominata',
				'priorita'  => PHP_INT_MIN,
				'argomenti' => 4,
			),
			array(
				'aggancio'  => 'added_post_meta',
				'metodo'    => 'fine_dopo',
				'priorita'  => PHP_INT_MIN,
				'argomenti' => 3,
			),
			array(
				'aggancio'  => 'updated_post_meta',
				'metodo'    => 'fine_dopo',
				'priorita'  => PHP_INT_MIN,
				'argomenti' => 3,
			),
			array(
				'aggancio'  => 'deleted_post_meta',
				'metodo'    => 'fine_dopo',
				'priorita'  => PHP_INT_MIN,
				'argomenti' => 3,
			),
		);
	}

	/**
	 * Nascita, pubblicazione, rimozione e ogni altro cambio di stato.
	 *
	 * La pubblicazione è l'ingresso nello stato pubblicato da qualunque altro
	 * stato, la rimozione è l'uscita da quello stato verso qualunque altro, il
	 * cestino compreso. Gli altri passaggi, per esempio da bozza a in attesa o
	 * verso gli stati propri di un componente, sono cambi di stato.
	 *
	 * La bozza automatica che WordPress crea all'apertura dell'editor non è
	 * ancora un contenuto, e la sua nascita non si registra. Un contenuto che
	 * esiste già e viene rimesso in bozza automatica, invece, esce dal suo
	 * stato come verso qualunque altro.
	 *
	 * @param string  $nuovo      Stato nuovo.
	 * @param string  $precedente Stato precedente.
	 * @param WP_Post $post       Contenuto.
	 */
	private static function stato( $nuovo, $precedente, $post ) {
		$nascita = in_array( $precedente, array( 'new', 'auto-draft' ), true );

		if ( ! $post instanceof WP_Post || $nuovo === $precedente || ( 'auto-draft' === $nuovo && $nascita ) ) {
			return;
		}

		$dettagli = array(
			'stato_precedente' => (string) $precedente,
			'stato_nuovo'      => (string) $nuovo,
		);

		if ( $nascita ) {
			self::scrivi( $post, 'creazione', $dettagli );
		}

		if ( 'publish' === $nuovo ) {
			self::scrivi( $post, 'pubblicazione', $dettagli );
		} elseif ( 'publish' === $precedente ) {
			self::scrivi( $post, 'rimozione', $dettagli );
		} elseif ( ! $nascita ) {
			self::scrivi( $post, 'cambio_stato', $dettagli );
		}
	}

	/**
	 * Legge cosa chiede il salvataggio, prima di ogni altro filtro.
	 *
	 * Serve a `riscritto_da_wordpress()`: un campo cambiato al cambio di stato
	 * è di WordPress solo se nessuno l'ha chiesto. I dati non si toccano.
	 *
	 * @param array $dati           Dati del contenuto, come WordPress li ha preparati.
	 * @param array $richiesta      Dati ricevuti, ripuliti.
	 * @param array $non_ripuliti   Dati ricevuti, come sono arrivati.
	 * @param bool  $aggiornamento  Il contenuto esiste già.
	 * @return array
	 */
	private static function richiesta( $dati, $richiesta, $non_ripuliti, $aggiornamento ) {
		unset( $richiesta );

		if ( ! $aggiornamento || ! is_array( $dati ) || ! is_array( $non_ripuliti ) || empty( $non_ripuliti['ID'] ) ) {
			return $dati;
		}

		$data_gmt = isset( $non_ripuliti['post_date_gmt'] ) ? (string) $non_ripuliti['post_date_gmt'] : null;

		self::$richieste[ (int) $non_ripuliti['ID'] ] = array(
			'nome'           => isset( $non_ripuliti['post_name'] ) ? (string) $non_ripuliti['post_name'] : null,
			'data'           => isset( $non_ripuliti['post_date'] ) ? (string) $non_ripuliti['post_date'] : '',
			'data_gmt'       => $data_gmt,
			'data_esplicita' => ! empty( $non_ripuliti['edit_date'] ) || ( null !== $data_gmt && '' !== $data_gmt && self::DATA_NON_FISSATA !== $data_gmt ),
			'adesso'         => current_time( 'mysql' ),
			'nome_wordpress' => isset( $dati['post_name'] ) ? (string) $dati['post_name'] : '',
			'nome_finale'    => null,
			'date_wordpress' => array(
				'post_date'     => isset( $dati['post_date'] ) ? (string) $dati['post_date'] : '',
				'post_date_gmt' => isset( $dati['post_date_gmt'] ) ? (string) $dati['post_date_gmt'] : '',
			),
		);

		return $dati;
	}

	/**
	 * Legge il nome nell'indirizzo dopo ogni altro filtro.
	 *
	 * Se dopo tutti i filtri il nome è ancora vuoto, WordPress lo genera dal
	 * titolo dopo aver salvato; se un altro componente l'ha cambiato, il
	 * cambiamento è suo e non di WordPress.
	 *
	 * @param array $dati          Dati del contenuto.
	 * @param array $richiesta     Dati ricevuti, ripuliti.
	 * @param array $non_ripuliti  Dati ricevuti, come sono arrivati.
	 * @param bool  $aggiornamento Il contenuto esiste già.
	 * @return array
	 */
	private static function richiesta_finale( $dati, $richiesta, $non_ripuliti, $aggiornamento ) {
		unset( $richiesta );

		if ( $aggiornamento && is_array( $dati ) && is_array( $non_ripuliti ) && isset( $non_ripuliti['ID'], self::$richieste[ (int) $non_ripuliti['ID'] ] ) ) {
			self::$richieste[ (int) $non_ripuliti['ID'] ]['nome_finale'] = isset( $dati['post_name'] ) ? (string) $dati['post_name'] : '';
		}

		return $dati;
	}

	/**
	 * La modifica dei campi di un contenuto che non è più una bozza.
	 *
	 * Si registrano i nomi dei campi cambiati e non i loro valori: il titolo o
	 * il testo di un atto possono contenere dati personali, e il registro non
	 * ne conserva nessuno oltre al numero dell'utente.
	 *
	 * @param int     $post_id Identificativo del contenuto.
	 * @param WP_Post $dopo    Contenuto dopo la modifica.
	 * @param WP_Post $prima   Contenuto prima della modifica.
	 */
	private static function modifica( $post_id, $dopo, $prima ) {
		$post_id   = (int) $post_id;
		$richiesta = self::$richieste[ $post_id ] ?? null;
		unset( self::$richieste[ $post_id ] );

		if ( ! $dopo instanceof WP_Post || ! $prima instanceof WP_Post ) {
			return;
		}

		/*
		 * Un allegato che cambia padre esce da un contenuto ed entra in un
		 * altro: per ciascuno dei due è un allegato tolto o aggiunto, anche se
		 * nessun file è stato caricato o cancellato. Per gli allegati WordPress
		 * non chiama `post_updated` ma `attachment_updated`, con gli stessi
		 * argomenti: per questo lo stesso metodo ascolta tutti e due.
		 */
		if ( 'attachment' === $dopo->post_type ) {
			if ( (int) $prima->post_parent !== (int) $dopo->post_parent ) {
				self::allegato( $dopo->ID, (int) $prima->post_parent, 'allegato_eliminato' );
				self::allegato( $dopo->ID, (int) $dopo->post_parent, 'allegato_aggiunto' );
			}

			return;
		}

		if ( in_array( $prima->post_status, self::STATI_IN_LAVORAZIONE, true ) ) {
			return;
		}

		$cambiati = array();

		foreach ( self::CAMPI as $campo ) {
			if ( (string) $prima->$campo !== (string) $dopo->$campo && ! self::riscritto_da_wordpress( $campo, $prima, $dopo, $richiesta ) ) {
				$cambiati[] = $campo;
			}
		}

		if ( array() === $cambiati ) {
			return;
		}

		self::scrivi( $dopo, 'modifica', array( 'campi' => $cambiati ) );
	}

	/**
	 * Il campo è cambiato perché WordPress lo riscrive da sé al cambio di stato.
	 *
	 * Contare questi cambiamenti come modifica affiancherebbe a ogni cambio di
	 * stato una voce che attribuisce a chi ha agito un cambiamento che non ha
	 * fatto. Ma il cambio di stato da solo non basta a dirlo, e nemmeno il
	 * valore di prima: chi pubblica o programma può, nello stesso salvataggio,
	 * scegliere lui l'indirizzo o la data, anche di un contenuto che non li
	 * aveva ancora, e quella è una modifica. Il criterio è chi ha chiesto il
	 * valore di dopo: il campo è di WordPress solo se il salvataggio non l'ha
	 * chiesto e il valore è quello che WordPress stesso ha preparato.
	 *
	 * - Il nome nell'indirizzo: non chiesto vuol dire assente dalla richiesta o
	 *   uguale a quello di prima. WordPress lo genera dal titolo quando dopo
	 *   tutti i filtri è ancora vuoto, gli aggiunge il suffisso del cestino
	 *   quando il contenuto ci entra e lo toglie quando ne esce, e lo svuota
	 *   quando il contenuto passa in attesa di revisione per mano di chi non
	 *   può pubblicarlo.
	 * - Le due date: WordPress le fissa, o le rimette a oggi, solo quando la
	 *   data non era ancora fissata, cioè quando quella in UTC era la data
	 *   nulla. Chiesta vuol dire con la richiesta esplicita di cambiare data,
	 *   o con una data in UTC vera, o con una data locale diversa da quella di
	 *   prima, qualunque sia la data in UTC che l'accompagna: assente, vuota o
	 *   nulla. Una sola eccezione: la data locale di adesso, accompagnata dalla
	 *   data in UTC vuota, è la forma con cui la funzione di aggiornamento di
	 *   WordPress rimette a oggi da sé la data di un contenuto che non l'aveva
	 *   fissata. Si riconosce entro due secondi, il tempo fra quella funzione
	 *   e questo filtro; chi indica da sé proprio l'ora di adesso chiede
	 *   quello che WordPress avrebbe fatto comunque.
	 *
	 * Senza la richiesta, per esempio se l'annuncio della modifica arriva da
	 * un'altra strada, ogni campo cambiato è una modifica.
	 *
	 * @param string     $campo     Nome del campo.
	 * @param WP_Post    $prima     Contenuto prima.
	 * @param WP_Post    $dopo      Contenuto dopo.
	 * @param array|null $richiesta Cosa ha chiesto il salvataggio.
	 * @return bool
	 */
	private static function riscritto_da_wordpress( $campo, WP_Post $prima, WP_Post $dopo, $richiesta ) {
		if ( $prima->post_status === $dopo->post_status || ! is_array( $richiesta ) ) {
			return false;
		}

		if ( 'post_name' === $campo ) {
			$chiesto = null !== $richiesta['nome'] && (string) $prima->post_name !== $richiesta['nome'];

			if ( $chiesto ) {
				return false;
			}

			return (string) $dopo->post_name === $richiesta['nome_wordpress']
				|| ( '' === $richiesta['nome_wordpress'] && '' === $richiesta['nome_finale'] );
		}

		if ( 'post_date' === $campo || 'post_date_gmt' === $campo ) {
			$data           = $richiesta['data'];
			$rimessa_a_oggi = '' === $richiesta['data_gmt'] && self::appena_prima( $data, $richiesta['adesso'] );
			$chiesta        = $richiesta['data_esplicita']
				|| ( '' !== $data && (string) $prima->post_date !== $data && ! $rimessa_a_oggi );

			return self::DATA_NON_FISSATA === (string) $prima->post_date_gmt
				&& ! $chiesta
				&& (string) $dopo->$campo === $richiesta['date_wordpress'][ $campo ];
		}

		return false;
	}

	/**
	 * La data è al massimo di due secondi prima di adesso.
	 *
	 * @param string $data   Data nella forma `AAAA-MM-GG HH:MM:SS`.
	 * @param string $adesso Adesso, nella stessa forma e nello stesso fuso.
	 * @return bool
	 */
	private static function appena_prima( $data, $adesso ) {
		$data   = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', (string) $data, new DateTimeZone( 'UTC' ) );
		$adesso = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', (string) $adesso, new DateTimeZone( 'UTC' ) );

		if ( ! $data || ! $adesso ) {
			return false;
		}

		$scarto = $adesso->getTimestamp() - $data->getTimestamp();

		return $scarto >= 0 && $scarto <= 2;
	}

	/**
	 * Segna il contenuto che WordPress sta per eliminare, e legge ciò che
	 * l'eliminazione sposterà.
	 *
	 * WordPress cancella i metadati del contenuto prima della sua riga: il
	 * segno tiene fuori dal registro la cancellazione della fine
	 * pubblicazione, che l'eliminazione comprende. La voce si scrive dopo, in
	 * `eliminazione()`, quando la riga non c'è più.
	 *
	 * Prima di cancellare, WordPress sposta anche gli allegati del contenuto,
	 * e i suoi figli se il tipo è gerarchico, sul padre del contenuto, con
	 * un'istruzione diretta che nessun aggancio annuncia. Qui si legge chi
	 * verrà spostato, qualunque sia il tipo del contenuto eliminato: il padre
	 * che riceve gli allegati può essere un contenuto gestito anche quando il
	 * contenuto eliminato non lo è.
	 *
	 * @param int          $post_id Identificativo del contenuto.
	 * @param WP_Post|null $post    Contenuto.
	 */
	private static function eliminazione_inizio( $post_id, $post = null ) {
		$post = $post instanceof WP_Post ? $post : get_post( $post_id );

		if ( ! $post instanceof WP_Post ) {
			return;
		}

		self::leggi_spostamenti( $post );

		if ( ! Conformita_Core_Tipi::registrato( $post->post_type ) || in_array( $post->post_status, array( 'new', 'auto-draft' ), true ) ) {
			return;
		}

		self::$in_eliminazione[ (int) $post->ID ] = true;
	}

	/**
	 * Legge gli allegati e i figli che l'eliminazione sposterà sul padre.
	 *
	 * Si leggono dalla banca dati e non dalla memoria: lo spostamento è
	 * un'istruzione diretta, e la memoria non lo vede.
	 *
	 * @param WP_Post $post Contenuto in eliminazione.
	 */
	private static function leggi_spostamenti( WP_Post $post ) {
		global $wpdb;

		$post_id  = (int) $post->ID;
		$padre    = (int) $post->post_parent;
		$allegati = array();
		$figli    = array();

		if ( $padre > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Lettura dello stato prima di un'istruzione diretta di WordPress.
			$allegati = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_parent = %d AND post_type = 'attachment'", $post_id ) );
		}

		if ( is_post_type_hierarchical( $post->post_type ) && Conformita_Core_Tipi::registrato( $post->post_type ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Come sopra.
			$figli = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_parent = %d AND post_type = %s", $post_id, $post->post_type ) );
		}

		unset( self::$spostamenti[ $post_id ] );

		if ( array() === $allegati && array() === $figli ) {
			return;
		}

		self::$spostamenti[ $post_id ] = array(
			'padre'    => $padre,
			'allegati' => array_map( 'intval', $allegati ),
			'figli'    => array_map( 'intval', $figli ),
		);
	}

	/**
	 * WordPress sta per cancellare la riga del contenuto: gli spostamenti
	 * sono avvenuti e i metadati sono già cancellati.
	 *
	 * Qui finisce il tratto in cui la cancellazione dei metadati va tenuta
	 * fuori dal registro: il segno si toglie adesso, e non a eliminazione
	 * riuscita, così un'eliminazione che fallisce non zittisce ciò che segue
	 * nella stessa richiesta. Resta solo l'attesa della riga, che serve a
	 * `eliminazione()`.
	 *
	 * Gli spostamenti si registrano qui, prima della cancellazione della
	 * riga, perché sono già avvenuti e restano anche se la cancellazione
	 * fallisce. Si confrontano con la banca dati: si registra solo lo
	 * spostamento avvenuto davvero.
	 *
	 * @param int          $post_id Identificativo del contenuto.
	 * @param WP_Post|null $post    Contenuto.
	 */
	private static function eliminazione_riga( $post_id, $post = null ) {
		unset( $post );

		$post_id = (int) $post_id;

		if ( isset( self::$in_eliminazione[ $post_id ] ) ) {
			unset( self::$in_eliminazione[ $post_id ], self::$fine_prima[ $post_id ] );
			self::$riga_in_cancellazione[ $post_id ] = true;
		}

		if ( ! isset( self::$spostamenti[ $post_id ] ) ) {
			return;
		}

		$spostamento = self::$spostamenti[ $post_id ];
		unset( self::$spostamenti[ $post_id ] );

		$padri = self::padri_attuali( array_merge( $spostamento['allegati'], $spostamento['figli'] ) );

		foreach ( $spostamento['allegati'] as $allegato_id ) {
			if ( isset( $padri[ $allegato_id ] ) && $padri[ $allegato_id ] === $spostamento['padre'] ) {
				self::allegato( $allegato_id, $spostamento['padre'], 'allegato_aggiunto' );
			}
		}

		foreach ( $spostamento['figli'] as $figlio_id ) {
			if ( ! isset( $padri[ $figlio_id ] ) || $padri[ $figlio_id ] === $post_id ) {
				continue;
			}

			$figlio = get_post( $figlio_id );

			if ( $figlio instanceof WP_Post && ! in_array( $figlio->post_status, self::STATI_IN_LAVORAZIONE, true ) ) {
				self::scrivi( $figlio, 'modifica', array( 'campi' => array( 'post_parent' ) ) );
			}
		}
	}

	/**
	 * Il padre attuale di alcuni contenuti, letto dalla banca dati.
	 *
	 * @param array<int, int> $ids Identificativi.
	 * @return array<int, int> Padre per identificativo.
	 */
	private static function padri_attuali( array $ids ) {
		global $wpdb;

		if ( array() === $ids ) {
			return array();
		}

		$segnaposto = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Lettura dopo un'istruzione diretta di WordPress; i segnaposto sono uno per numero.
		$righe = $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_parent FROM {$wpdb->posts} WHERE ID IN ( {$segnaposto} )", $ids ) );

		$padri = array();

		foreach ( (array) $righe as $riga ) {
			$padri[ (int) $riga->ID ] = (int) $riga->post_parent;
		}

		return $padri;
	}

	/**
	 * L'eliminazione definitiva di un contenuto, bozze comprese.
	 *
	 * Le voci del contenuto restano: il registro serve proprio quando il
	 * contenuto non c'è più. Qui la bozza non fa eccezione, perché la ragione
	 * che la esclude dalle modifiche, i salvataggi automatici, non vale per
	 * un'eliminazione, e una bozza può avere una storia: un contenuto
	 * pubblicato, riportato in bozza e poi eliminato sparirebbe senza traccia
	 * proprio nell'ultimo passo. Resta fuori solo la bozza automatica, che non
	 * è mai diventata un contenuto.
	 *
	 * La voce si scrive dopo che la banca dati ha cancellato la riga, non
	 * prima: attesta un fatto avvenuto, e se la cancellazione fallisce il
	 * contenuto c'è ancora. Si scrive solo per i contenuti segnati da
	 * `eliminazione_inizio()`: WordPress annuncia così anche l'eliminazione
	 * degli allegati, che hanno le loro voci. Tipo e sezione si controllano
	 * sulla copia del contenuto che WordPress passa, letta prima della
	 * cancellazione: la riga non c'è più, e una copia in memoria può non
	 * esserci.
	 *
	 * @param int          $post_id Identificativo del contenuto.
	 * @param WP_Post|null $post    Contenuto, com'era prima dell'eliminazione.
	 */
	private static function eliminazione( $post_id, $post = null ) {
		$post_id = (int) $post_id;

		if ( isset( self::$allegati_in_eliminazione[ $post_id ] ) ) {
			$padre = self::$allegati_in_eliminazione[ $post_id ];
			unset( self::$allegati_in_eliminazione[ $post_id ] );
			self::allegato( $post_id, $padre, 'allegato_eliminato' );
			return;
		}

		if ( ! isset( self::$riga_in_cancellazione[ $post_id ] ) ) {
			return;
		}

		unset( self::$riga_in_cancellazione[ $post_id ] );

		if ( ! $post instanceof WP_Post || (int) $post->ID !== $post_id ) {
			Conformita_Core_Registro::annota_mancata();
			return;
		}

		self::scrivi( $post, 'eliminazione', array( 'stato' => (string) $post->post_status ), true );
	}

	/**
	 * Legge i contenuti gestiti di un utente che si sta eliminando.
	 *
	 * Se l'utente affida i suoi contenuti a un altro, WordPress ne cambia
	 * l'autore con un'istruzione diretta, senza passare dal salvataggio: nessun
	 * aggancio del contenuto lo annuncia. Se non li affida, i contenuti si
	 * eliminano uno per uno e hanno le loro voci.
	 *
	 * @param int      $utente_id Utente in eliminazione.
	 * @param int|null $affidati  Utente a cui vanno i contenuti, o nulla.
	 */
	private static function utente_in_eliminazione( $utente_id, $affidati = null ) {
		global $wpdb;

		$utente_id = (int) $utente_id;
		unset( self::$contenuti_affidati[ $utente_id ] );

		$tipi = Conformita_Core_Tipi::identificativi();

		if ( null === $affidati || array() === $tipi ) {
			return;
		}

		$segnaposto = implode( ', ', array_fill( 0, count( $tipi ), '%s' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Lettura prima di un'istruzione diretta di WordPress; i segnaposto sono uno per tipo.
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_author = %d AND post_type IN ( {$segnaposto} )", array_merge( array( $utente_id ), $tipi ) ) );

		if ( array() !== $ids ) {
			self::$contenuti_affidati[ $utente_id ] = array_map( 'intval', $ids );
		}
	}

	/**
	 * Registra il cambio di autore dei contenuti affidati, se è avvenuto.
	 *
	 * L'autore si rilegge dalla banca dati: si registra solo il cambio
	 * avvenuto davvero, fuori dalla bozza come ogni modifica.
	 *
	 * @param int      $utente_id Utente eliminato.
	 * @param int|null $affidati  Utente a cui vanno i contenuti, o nulla.
	 */
	private static function utente_eliminato( $utente_id, $affidati = null ) {
		global $wpdb;

		unset( $affidati );

		$utente_id = (int) $utente_id;

		if ( ! isset( self::$contenuti_affidati[ $utente_id ] ) ) {
			return;
		}

		$ids = self::$contenuti_affidati[ $utente_id ];
		unset( self::$contenuti_affidati[ $utente_id ] );

		$segnaposto = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Lettura dopo un'istruzione diretta di WordPress; i segnaposto sono uno per numero.
		$righe = $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_author FROM {$wpdb->posts} WHERE ID IN ( {$segnaposto} )", $ids ) );

		foreach ( (array) $righe as $riga ) {
			if ( (int) $riga->post_author === $utente_id ) {
				continue;
			}

			$post = get_post( (int) $riga->ID );

			if ( $post instanceof WP_Post && ! in_array( $post->post_status, self::STATI_IN_LAVORAZIONE, true ) ) {
				self::scrivi( $post, 'modifica', array( 'campi' => array( 'post_author' ) ) );
			}
		}
	}

	/**
	 * Un allegato aggiunto a un contenuto gestito.
	 *
	 * @param int $allegato_id Identificativo dell'allegato.
	 */
	private static function allegato_aggiunto( $allegato_id ) {
		$allegato = get_post( $allegato_id );

		self::allegato( $allegato_id, $allegato instanceof WP_Post ? (int) $allegato->post_parent : 0, 'allegato_aggiunto' );
	}

	/**
	 * Segna un allegato che WordPress sta per eliminare.
	 *
	 * WordPress annuncia l'eliminazione di un allegato prima di cancellarne la
	 * riga. La voce attesta un fatto avvenuto, e se la cancellazione fallisce
	 * l'allegato c'è ancora: qui si annota solo il padre, e la voce la scrive
	 * `eliminazione()` quando la riga non c'è più. Ogni tentativo riparte da
	 * zero: un tentativo fallito prima non lascia il suo padre a questo.
	 *
	 * @param int          $allegato_id Identificativo dell'allegato.
	 * @param WP_Post|null $allegato    Allegato.
	 */
	private static function allegato_eliminato( $allegato_id, $allegato = null ) {
		$allegato = $allegato instanceof WP_Post ? $allegato : get_post( $allegato_id );

		// Un tentativo precedente fallito non deve lasciare il suo padre a questo.
		unset( self::$allegati_in_eliminazione[ (int) $allegato_id ] );

		if ( $allegato instanceof WP_Post && (int) $allegato->post_parent > 0 ) {
			self::$allegati_in_eliminazione[ (int) $allegato_id ] = (int) $allegato->post_parent;
		}
	}

	/**
	 * Un allegato collegato o scollegato dalla libreria dei media.
	 *
	 * La libreria cambia il padre con un'istruzione diretta sulla banca dati,
	 * senza passare dal salvataggio di WordPress, e lo annuncia solo con questo
	 * aggancio. Nello scollegamento il padre passato è quello di prima; nel
	 * collegamento è quello nuovo, e il padre di prima non si conosce più.
	 *
	 * @param string $azione      `attach` oppure `detach`.
	 * @param int    $allegato_id Identificativo dell'allegato.
	 * @param int    $padre       Contenuto padre.
	 */
	private static function allegato_collegato( $azione, $allegato_id, $padre ) {
		self::allegato( $allegato_id, (int) $padre, 'detach' === $azione ? 'allegato_eliminato' : 'allegato_aggiunto' );
	}

	/**
	 * Scrive la voce di un allegato sul contenuto a cui appartiene.
	 *
	 * @param int    $allegato_id Identificativo dell'allegato.
	 * @param int    $padre_id    Contenuto padre.
	 * @param string $azione      Operazione.
	 */
	private static function allegato( $allegato_id, $padre_id, $azione ) {
		if ( $padre_id <= 0 ) {
			return;
		}

		$padre = get_post( $padre_id );

		if ( ! $padre instanceof WP_Post || in_array( $padre->post_status, self::STATI_IN_LAVORAZIONE, true ) ) {
			return;
		}

		self::scrivi( $padre, $azione, array( 'allegato' => (int) $allegato_id ) );
	}

	/**
	 * Legge la fine della pubblicazione prima che un'aggiunta la tocchi.
	 *
	 * @param int    $post_id Identificativo del contenuto.
	 * @param string $chiave  Chiave del metadato.
	 */
	private static function fine_prima_aggiunta( $post_id, $chiave ) {
		self::fine_prima( 0, $post_id, $chiave );
	}

	/**
	 * Legge la fine della pubblicazione prima che una scrittura la tocchi.
	 *
	 * Si usano gli agganci che WordPress chiama prima della scrittura su tutte
	 * le strade, compresa quella per numero di riga che la cancellazione di un
	 * contenuto percorre: un filtro che solo alcune strade chiamano lascerebbe
	 * in memoria un valore vecchio, e la voce successiva lo confronterebbe con
	 * un dato che non c'entra.
	 *
	 * Quando la scrittura è una cancellazione, WordPress passa l'elenco delle
	 * righe, e il contenuto che nomina non è detto sia quello delle righe: la
	 * cancellazione per chiave su tutti i contenuti non ne nomina nessuno, o
	 * nomina quello che ha passato chi l'ha chiesta. Il contenuto si legge
	 * allora da ciascuna riga, finché le righe esistono, e si ricorda per
	 * l'annuncio che segue la scrittura.
	 *
	 * @param int|array $meta_id Identificativo della riga, o delle righe.
	 * @param int       $post_id Identificativo del contenuto.
	 * @param string    $chiave  Chiave del metadato.
	 */
	private static function fine_prima( $meta_id, $post_id, $chiave ) {
		if ( Conformita_Core_Scadenza::CHIAVE !== $chiave ) {
			return;
		}

		if ( ! is_array( $meta_id ) ) {
			self::$fine_prima[ (int) $post_id ] = self::valori_fine( (int) $post_id );
			return;
		}

		/*
		 * Più righe dello stesso contenuto nella stessa scrittura: i valori di
		 * prima si leggono alla prima riga, quando nessuna è ancora cambiata.
		 */
		$letti = array();

		foreach ( $meta_id as $riga_id ) {
			$riga = get_metadata_by_mid( 'post', (int) $riga_id );

			if ( ! $riga ) {
				continue;
			}

			$contenuto = (int) $riga->post_id;

			self::$righe_fine[ (int) $riga_id ] = $contenuto;

			if ( ! isset( $letti[ $contenuto ] ) ) {
				$letti[ $contenuto ]            = true;
				self::$fine_prima[ $contenuto ] = self::valori_fine( $contenuto );
			}
		}
	}

	/**
	 * Legge la fine della pubblicazione prima che una riga cambi chiave.
	 *
	 * Una riga della fine che prende un'altra chiave toglie la fine al suo
	 * contenuto, ma WordPress annuncia la scrittura con la chiave nuova, prima
	 * e dopo. Solo questo filtro, che WordPress chiama prima di cambiare una
	 * riga per numero, vede la chiave di prima. Il filtro non cambia niente:
	 * restituisce quello che ha ricevuto.
	 *
	 * @param mixed        $risposta Risposta di un filtro precedente, nulla se nessuno ha risposto.
	 * @param int          $meta_id  Identificativo della riga.
	 * @param mixed        $valore   Valore nuovo.
	 * @param string|false $chiave   Chiave nuova, falso se non cambia.
	 * @return mixed
	 */
	private static function fine_rinominata( $risposta, $meta_id, $valore, $chiave ) {
		unset( $valore );

		if ( null !== $risposta || false === $chiave || Conformita_Core_Scadenza::CHIAVE === $chiave ) {
			return $risposta;
		}

		$riga = get_metadata_by_mid( 'post', (int) $meta_id );

		if ( $riga && Conformita_Core_Scadenza::CHIAVE === $riga->meta_key ) {
			self::$righe_fine[ (int) $meta_id ]       = (int) $riga->post_id;
			self::$fine_prima[ (int) $riga->post_id ] = self::valori_fine( (int) $riga->post_id );
		}

		return $risposta;
	}

	/**
	 * Registra il cambio della fine della pubblicazione, se c'è stato.
	 *
	 * Si confrontano tutti i valori prima e dopo, non solo il primo: la chiave
	 * può avere più righe, e WordPress segnala una scrittura per ciascuna. Dopo
	 * la voce il valore di partenza diventa quello appena registrato, così la
	 * seconda segnalazione della stessa scrittura non produce una seconda voce.
	 *
	 * @param int|array $meta_id Identificativo della riga, o delle righe.
	 * @param int       $post_id Identificativo del contenuto.
	 * @param string    $chiave  Chiave del metadato.
	 */
	private static function fine_dopo( $meta_id, $post_id, $chiave ) {
		$contenuti = array();

		foreach ( (array) $meta_id as $riga_id ) {
			if ( isset( self::$righe_fine[ (int) $riga_id ] ) ) {
				$contenuti[] = self::$righe_fine[ (int) $riga_id ];
				unset( self::$righe_fine[ (int) $riga_id ] );
			}
		}

		if ( array() === $contenuti && Conformita_Core_Scadenza::CHIAVE === $chiave ) {
			$contenuti[] = (int) $post_id;
		}

		foreach ( array_unique( $contenuti ) as $contenuto ) {
			self::confronta_fine( $contenuto );
		}
	}

	/**
	 * Confronta la fine della pubblicazione di un contenuto con quella di
	 * prima, e scrive la voce se è cambiata.
	 *
	 * @param int $post_id Identificativo del contenuto.
	 */
	private static function confronta_fine( $post_id ) {
		$post_id = (int) $post_id;
		$prima   = isset( self::$fine_prima[ $post_id ] ) ? self::$fine_prima[ $post_id ] : array();
		$dopo    = self::valori_fine( $post_id );

		self::$fine_prima[ $post_id ] = $dopo;

		if ( $prima === $dopo || isset( self::$in_eliminazione[ $post_id ] ) ) {
			return;
		}

		$post = get_post( $post_id );

		if ( ! $post instanceof WP_Post || in_array( $post->post_status, self::STATI_IN_LAVORAZIONE, true ) ) {
			return;
		}

		self::scrivi(
			$post,
			'modifica_fine_pubblicazione',
			array(
				'valore_precedente' => $prima,
				'valore_nuovo'      => $dopo,
			)
		);
	}

	/**
	 * Tutti i valori registrati della fine pubblicazione, letti dalla banca dati.
	 *
	 * @param int $post_id Identificativo del contenuto.
	 * @return array<int, string>
	 */
	private static function valori_fine( $post_id ) {
		$valori = get_post_meta( $post_id, Conformita_Core_Scadenza::CHIAVE, false );

		return is_array( $valori ) ? array_values( array_map( 'strval', $valori ) ) : array();
	}

	/**
	 * Scrive una voce automatica, se il contenuto è di un tipo gestito.
	 *
	 * Se la scrittura fallisce l'operazione è già avvenuta e non si disfa: si
	 * annota il buco, che la schermata di consultazione mostra.
	 *
	 * @param WP_Post              $post      Contenuto.
	 * @param string               $azione    Operazione.
	 * @param array<string, mixed> $dettagli  Dettagli.
	 * @param bool                 $eliminato Il contenuto non c'è più: tipo
	 *                                        e sezione si controllano sulla
	 *                                        copia passata.
	 */
	private static function scrivi( WP_Post $post, $azione, array $dettagli, $eliminato = false ) {
		if ( ! Conformita_Core_Tipi::registrato( $post->post_type ) ) {
			return;
		}

		$scrittura = self::$scrittura;

		$esito = $scrittura instanceof Closure
			? $scrittura(
				array(
					'sezione'   => Conformita_Core_Tipi::sezione( $post->post_type ),
					'azione'    => $azione,
					'contenuto' => (int) $post->ID,
					'dettagli'  => $dettagli,
				),
				$eliminato ? $post : null
			)
			: null;

		if ( ! is_int( $esito ) ) {
			Conformita_Core_Registro::annota_mancata();
		}
	}
}
