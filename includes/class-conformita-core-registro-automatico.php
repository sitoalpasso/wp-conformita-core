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
	 * I campi che WordPress riscrive da sé quando lo stato cambia.
	 */
	const CAMPI_DEL_CAMBIO_DI_STATO = array( 'post_name', 'post_date', 'post_date_gmt' );

	/**
	 * Il meccanismo ha già agganciato i propri ascoltatori.
	 *
	 * @var bool
	 */
	private static $avviato = false;

	/**
	 * Valori della fine pubblicazione letti prima di una scrittura, per contenuto.
	 *
	 * @var array<int, array<int, string>>
	 */
	private static $fine_prima = array();

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
	 * @internal Si accende al caricamento di core, come il motore di scadenza.
	 */
	public static function avvia() {
		if ( self::$avviato ) {
			return;
		}

		self::$avviato = true;

		foreach ( self::agganci() as $aggancio ) {
			add_action( $aggancio['aggancio'], array( __CLASS__, $aggancio['metodo'] ), $aggancio['priorita'], $aggancio['argomenti'] );
		}
	}

	/**
	 * Stacca gli ascoltatori.
	 *
	 * @internal Solo per le prove di non vacuità: usa lo stesso elenco
	 *           dell'accensione, così lo spegnimento non può dimenticarne uno.
	 */
	public static function spegni() {
		foreach ( self::agganci() as $aggancio ) {
			remove_action( $aggancio['aggancio'], array( __CLASS__, $aggancio['metodo'] ), $aggancio['priorita'] );
		}

		self::$avviato         = false;
		self::$fine_prima      = array();
		self::$in_eliminazione = array();
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
				'aggancio'  => 'post_updated',
				'metodo'    => 'modifica',
				'priorita'  => PHP_INT_MIN,
				'argomenti' => 3,
			),
			array(
				'aggancio'  => 'before_delete_post',
				'metodo'    => 'eliminazione',
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
	 * @param string  $nuovo      Stato nuovo.
	 * @param string  $precedente Stato precedente.
	 * @param WP_Post $post       Contenuto.
	 */
	public static function stato( $nuovo, $precedente, $post ) {
		if ( ! $post instanceof WP_Post || $nuovo === $precedente || 'auto-draft' === $nuovo ) {
			return;
		}

		$dettagli = array(
			'stato_precedente' => (string) $precedente,
			'stato_nuovo'      => (string) $nuovo,
		);

		if ( in_array( $precedente, array( 'new', 'auto-draft' ), true ) ) {
			self::scrivi( $post, 'creazione', $dettagli );
		}

		if ( 'publish' === $nuovo ) {
			self::scrivi( $post, 'pubblicazione', $dettagli );
		} elseif ( 'publish' === $precedente ) {
			self::scrivi( $post, 'rimozione', $dettagli );
		} elseif ( ! in_array( $precedente, array( 'new', 'auto-draft' ), true ) ) {
			self::scrivi( $post, 'cambio_stato', $dettagli );
		}
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
	public static function modifica( $post_id, $dopo, $prima ) {
		unset( $post_id );

		if ( ! $dopo instanceof WP_Post || ! $prima instanceof WP_Post ) {
			return;
		}

		if ( in_array( $prima->post_status, self::STATI_IN_LAVORAZIONE, true ) ) {
			return;
		}

		$cambiati = array();
		$campi    = self::CAMPI;

		/*
		 * Quando lo stato cambia, WordPress assegna da sé il nome nell'indirizzo
		 * (lo genera alla pubblicazione, gli aggiunge un suffisso nel cestino) e
		 * la data in UTC (la scrive alla pubblicazione). Contarli come modifica
		 * affiancherebbe a ogni cambio di stato una voce che attribuisce a chi ha
		 * agito un cambiamento che non ha fatto. Lo stesso salvataggio registra
		 * comunque ogni altro campo cambiato.
		 */
		if ( $prima->post_status !== $dopo->post_status ) {
			$campi = array_diff( $campi, self::CAMPI_DEL_CAMBIO_DI_STATO );
		}

		foreach ( $campi as $campo ) {
			if ( (string) $prima->$campo !== (string) $dopo->$campo ) {
				$cambiati[] = $campo;
			}
		}

		if ( array() === $cambiati ) {
			return;
		}

		self::scrivi( $dopo, 'modifica', array( 'campi' => $cambiati ) );
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
	 * @param int          $post_id Identificativo del contenuto.
	 * @param WP_Post|null $post    Contenuto.
	 */
	public static function eliminazione( $post_id, $post = null ) {
		$post = $post instanceof WP_Post ? $post : get_post( $post_id );

		if ( ! $post instanceof WP_Post || in_array( $post->post_status, array( 'new', 'auto-draft' ), true ) ) {
			return;
		}

		self::$in_eliminazione[ (int) $post->ID ] = true;

		self::scrivi( $post, 'eliminazione', array( 'stato' => (string) $post->post_status ) );
	}

	/**
	 * Un allegato aggiunto a un contenuto gestito.
	 *
	 * @param int $allegato_id Identificativo dell'allegato.
	 */
	public static function allegato_aggiunto( $allegato_id ) {
		self::allegato( $allegato_id, get_post( $allegato_id ), 'allegato_aggiunto' );
	}

	/**
	 * Un allegato eliminato da un contenuto gestito.
	 *
	 * @param int          $allegato_id Identificativo dell'allegato.
	 * @param WP_Post|null $allegato    Allegato.
	 */
	public static function allegato_eliminato( $allegato_id, $allegato = null ) {
		self::allegato( $allegato_id, $allegato instanceof WP_Post ? $allegato : get_post( $allegato_id ), 'allegato_eliminato' );
	}

	/**
	 * Scrive la voce di un allegato sul contenuto a cui appartiene.
	 *
	 * @param int          $allegato_id Identificativo dell'allegato.
	 * @param WP_Post|null $allegato    Allegato.
	 * @param string       $azione      Operazione.
	 */
	private static function allegato( $allegato_id, $allegato, $azione ) {
		if ( ! $allegato instanceof WP_Post || $allegato->post_parent <= 0 ) {
			return;
		}

		$padre = get_post( $allegato->post_parent );

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
	public static function fine_prima_aggiunta( $post_id, $chiave ) {
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
	 * @param int|array $meta_id Identificativo della riga, o delle righe.
	 * @param int       $post_id Identificativo del contenuto.
	 * @param string    $chiave  Chiave del metadato.
	 */
	public static function fine_prima( $meta_id, $post_id, $chiave ) {
		unset( $meta_id );

		if ( Conformita_Core_Scadenza::CHIAVE === $chiave ) {
			self::$fine_prima[ (int) $post_id ] = self::valori_fine( (int) $post_id );
		}
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
	public static function fine_dopo( $meta_id, $post_id, $chiave ) {
		unset( $meta_id );

		if ( Conformita_Core_Scadenza::CHIAVE !== $chiave ) {
			return;
		}

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
	 * @param WP_Post              $post     Contenuto.
	 * @param string               $azione   Operazione.
	 * @param array<string, mixed> $dettagli Dettagli.
	 */
	private static function scrivi( WP_Post $post, $azione, array $dettagli ) {
		if ( ! Conformita_Core_Tipi::registrato( $post->post_type ) ) {
			return;
		}

		$esito = Conformita_Core_Registro::scrivi(
			array(
				'sezione'   => Conformita_Core_Tipi::sezione( $post->post_type ),
				'azione'    => $azione,
				'contenuto' => (int) $post->ID,
				'dettagli'  => $dettagli,
			),
			Conformita_Core_Registro::ORIGINE_AUTOMATICA
		);

		if ( is_wp_error( $esito ) ) {
			Conformita_Core_Registro::annota_mancata();
		}
	}
}
