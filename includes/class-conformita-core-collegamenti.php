<?php
/**
 * Verificatore dei collegamenti: un componente registra l'indirizzo di un altro
 * sito, il verificatore ne controlla la risposta e ne conserva l'esito con la
 * data.
 *
 * **L'intervallo e la politica.** Ogni quanti giorni ricontrollare lo dice il
 * componente che registra il collegamento, e non c'e un valore predefinito:
 * un rinvio a una banca dati nazionale e un collegamento a una pagina interna
 * hanno esigenze diverse, e il meccanismo non sa quali.
 *
 * **Il tempo massimo, i rinvii e la quantita letta non sono politiche.** Sono
 * protezioni del sito che controlla: un altro sito lento, o che rimanda a se
 * stesso, non deve bloccare il nostro.
 *
 * Righe di collaudo C-301..C-306, che dettagliano C-60..C-63. Scheda:
 * `docs/implementazione/verificatore-collegamenti.md`.
 *
 * @package Conformita_Core
 */

defined( 'ABSPATH' ) || exit;

/**
 * Tabella dei collegamenti, registrazione, lettura e verifica.
 */
final class Conformita_Core_Collegamenti {

	/**
	 * Nome della tabella senza il prefisso del sito.
	 */
	const TABELLA = 'conformita_core_collegamenti';

	/**
	 * Opzione con la versione dello schema installata.
	 */
	const OPZIONE_SCHEMA = 'conformita_core_collegamenti_schema';

	/**
	 * Versione dello schema della tabella.
	 */
	const VERSIONE_SCHEMA = '1';

	/**
	 * Tempo massimo di attesa della risposta, in secondi.
	 */
	const TEMPO_MASSIMO = 10;

	/**
	 * Rinvii seguiti al massimo.
	 */
	const RINVII = 5;

	/**
	 * Byte letti al massimo dalla risposta.
	 */
	const LETTI = 65536;

	/**
	 * Intervallo minimo e massimo, in giorni.
	 */
	const INTERVALLO_MINIMO  = 1;
	const INTERVALLO_MASSIMO = 90;

	/**
	 * Lunghezza massima di indirizzo, chiave e motivo.
	 */
	const LUNGHEZZA_INDIRIZZO = 2048;
	const LUNGHEZZA_CHIAVE    = 100;
	const LUNGHEZZA_MOTIVO    = 255;

	/**
	 * Nome del compito pianificato del controllo periodico.
	 */
	const COMPITO = 'conformita_core_collegamenti_orario';

	/**
	 * Collegamenti controllati al massimo a ogni giro del compito.
	 */
	const LOTTO = 5;

	/**
	 * Giorni di tolleranza oltre l'intervallo prima che un collegamento risulti in ritardo.
	 */
	const TOLLERANZA = 1;

	/**
	 * Nome completo della tabella, con il prefisso del sito.
	 *
	 * @return string
	 */
	public static function tabella() {
		global $wpdb;

		return $wpdb->prefix . self::TABELLA;
	}

	/**
	 * Crea o aggiorna la tabella se la versione installata non e quella attesa.
	 *
	 * Gira al caricamento di core, come per il registro: WordPress non chiama
	 * l'aggancio di attivazione quando un plugin si aggiorna.
	 */
	public static function assicura_tabella() {
		if ( self::VERSIONE_SCHEMA === get_option( self::OPZIONE_SCHEMA ) ) {
			return;
		}

		self::installa();
	}

	/**
	 * Crea la tabella, e registra la versione solo se la tabella c'e con la sua chiave unica.
	 *
	 * @return bool
	 */
	public static function installa() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$tabella = self::tabella();
		$collate = $wpdb->get_charset_collate();

		/*
		 * Sezione e chiave in varbinary: insieme stanno dentro i 767 byte che
		 * le banche dati piu vecchie ammettono per un indice. L'indirizzo in
		 * blob, perche si confronta byte per byte: il percorso di un
		 * indirizzo distingue maiuscole e minuscole.
		 */
		dbDelta(
			"CREATE TABLE {$tabella} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
sezione varbinary(64) NOT NULL,
chiave varbinary(100) NOT NULL,
indirizzo blob NOT NULL,
intervallo smallint(5) unsigned NOT NULL,
esito varchar(16) NOT NULL,
codice smallint(5) unsigned DEFAULT NULL,
motivo varchar(255) DEFAULT NULL,
verificato datetime DEFAULT NULL,
registrato datetime NOT NULL,
PRIMARY KEY  (id),
UNIQUE KEY collegamento (sezione,chiave),
KEY verificato (verificato)
) {$collate};"
		);

		if ( ! self::vincolo_presente() ) {
			delete_option( self::OPZIONE_SCHEMA );
			return false;
		}

		update_option( self::OPZIONE_SCHEMA, self::VERSIONE_SCHEMA, true );

		return true;
	}

	/**
	 * La chiave unica c'e, su sezione e chiave, per intero.
	 *
	 * @return bool
	 */
	private static function vincolo_presente() {
		global $wpdb;

		$tabella = self::tabella();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Verifica di struttura sulla tabella propria; il nome viene dal prefisso di WordPress.
		$indici = $wpdb->get_results( "SHOW INDEX FROM `{$tabella}` WHERE Key_name = 'collegamento'", ARRAY_A );

		return is_array( $indici )
			&& 2 === count( $indici )
			&& array( 'sezione', 'chiave' ) === array_column( $indici, 'Column_name' )
			&& array( null, null ) === array_column( $indici, 'Sub_part' )
			&& array( '0', '0' ) === array_map( 'strval', array_column( $indici, 'Non_unique' ) );
	}

	/**
	 * Registra o aggiorna un collegamento.
	 *
	 * Stesso indirizzo: l'esito resta. Indirizzo diverso: l'esito torna "mai",
	 * perche era di un'altra pagina. La riga si rilegge prima di dire riuscito.
	 *
	 * @param mixed $sezione    Sezione registrata.
	 * @param mixed $chiave     Chiave scelta dal componente.
	 * @param mixed $indirizzo  Indirizzo da controllare.
	 * @param mixed $intervallo Ogni quanti giorni ricontrollare: la politica, senza valore predefinito.
	 * @return true|WP_Error
	 */
	public static function registra( $sezione, $chiave, $indirizzo, $intervallo ) {
		$errore = self::controlla_sezione_e_chiave( $sezione, $chiave );

		if ( is_wp_error( $errore ) ) {
			return $errore;
		}

		$errore = self::controlla_indirizzo( $indirizzo );

		if ( is_wp_error( $errore ) ) {
			return $errore;
		}

		if ( ! is_int( $intervallo ) || $intervallo < self::INTERVALLO_MINIMO || $intervallo > self::INTERVALLO_MASSIMO ) {
			return self::errore(
				'conformita_core_collegamento_intervallo',
				/* translators: 1: minimo, 2: massimo. */
				sprintf( __( 'Collegamento: l\'intervallo di verifica va dichiarato in giorni, da %1$d a %2$d. Non ha un valore predefinito.', 'conformita-core' ), self::INTERVALLO_MINIMO, self::INTERVALLO_MASSIMO )
			);
		}

		$esistente = self::leggi( $sezione, $chiave );

		if ( is_wp_error( $esistente ) ) {
			return $esistente;
		}

		global $wpdb;

		$tabella = self::tabella();

		if ( null === $esistente ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Tabella propria.
			$scritto = $wpdb->insert(
				$tabella,
				array(
					'sezione'    => $sezione,
					'chiave'     => $chiave,
					'indirizzo'  => $indirizzo,
					'intervallo' => $intervallo,
					'esito'      => 'mai',
					'registrato' => self::adesso(),
				),
				array( '%s', '%s', '%s', '%d', '%s', '%s' )
			);
		} elseif ( $esistente['indirizzo'] === $indirizzo ) {
			if ( $esistente['intervallo'] === $intervallo ) {
				return true;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Tabella propria.
			$scritto = $wpdb->update( $tabella, array( 'intervallo' => $intervallo ), array( 'id' => $esistente['id'] ), array( '%d' ), array( '%d' ) );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Tabella propria; i valori passano da prepare.
			$scritto = $wpdb->query( $wpdb->prepare( "UPDATE `{$tabella}` SET indirizzo = %s, intervallo = %d, esito = 'mai', codice = NULL, motivo = NULL, verificato = NULL WHERE id = %d", $indirizzo, $intervallo, $esistente['id'] ) );
		}

		$riletto = self::leggi( $sezione, $chiave );

		/*
		 * Conta la riga riletta: una scrittura fallita la lascia com'era, e
		 * una registrazione uguale arrivata nello stesso istante da un'altra
		 * richiesta la lascia come voluta.
		 */
		unset( $scritto );

		if ( ! is_array( $riletto ) || $riletto['indirizzo'] !== $indirizzo || $riletto['intervallo'] !== $intervallo ) {
			return self::errore( 'conformita_core_collegamento_non_scritto', __( 'Collegamento: la registrazione non si è potuta scrivere nella banca dati.', 'conformita-core' ) );
		}

		return true;
	}

	/**
	 * Toglie un collegamento; vero anche se non c'era.
	 *
	 * @param mixed $sezione Sezione registrata.
	 * @param mixed $chiave  Chiave.
	 * @return true|WP_Error
	 */
	public static function togli( $sezione, $chiave ) {
		$errore = self::controlla_sezione_e_chiave( $sezione, $chiave );

		if ( is_wp_error( $errore ) ) {
			return $errore;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Tabella propria.
		$tolto = $wpdb->delete(
			self::tabella(),
			array(
				'sezione' => $sezione,
				'chiave'  => $chiave,
			),
			array( '%s', '%s' )
		);

		if ( false === $tolto || '' !== $wpdb->last_error ) {
			return self::errore( 'conformita_core_collegamento_non_tolto', __( 'Collegamento: non si è potuto togliere dalla banca dati.', 'conformita-core' ) );
		}

		return true;
	}

	/**
	 * Il collegamento con l'esito, nullo se non c'e, errore se la lettura fallisce.
	 *
	 * @param mixed $sezione Sezione registrata.
	 * @param mixed $chiave  Chiave.
	 * @return array<string, mixed>|null|WP_Error
	 */
	public static function collegamento( $sezione, $chiave ) {
		$errore = self::controlla_sezione_e_chiave( $sezione, $chiave );

		if ( is_wp_error( $errore ) ) {
			return $errore;
		}

		$letto = self::leggi( $sezione, $chiave );

		if ( is_array( $letto ) ) {
			unset( $letto['id'] );
		}

		return $letto;
	}

	/**
	 * Controlla adesso il collegamento e ne scrive l'esito.
	 *
	 * @param mixed $sezione Sezione registrata.
	 * @param mixed $chiave  Chiave.
	 * @return array<string, mixed>|WP_Error Il collegamento con l'esito nuovo.
	 */
	public static function verifica( $sezione, $chiave ) {
		$letto = self::collegamento( $sezione, $chiave );

		if ( is_wp_error( $letto ) ) {
			return $letto;
		}

		if ( null === $letto ) {
			return self::errore( 'conformita_core_collegamento_assente', __( 'Collegamento: non è registrato.', 'conformita-core' ) );
		}

		$risposta = wp_safe_remote_get(
			$letto['indirizzo'],
			array(
				'timeout'             => self::TEMPO_MASSIMO,
				'redirection'         => self::RINVII,
				'limit_response_size' => self::LETTI,
			)
		);

		$codice = null;

		if ( is_wp_error( $risposta ) ) {
			$esito  = 'rotto';
			$motivo = $risposta->get_error_message();
		} else {
			$codice = (int) wp_remote_retrieve_response_code( $risposta );
			$esito  = $codice >= 200 && $codice <= 299 ? 'raggiungibile' : 'rotto';
			/* translators: %d: codice della risposta. */
			$motivo = 'raggiungibile' === $esito ? '' : sprintf( __( 'Risposta con codice %d.', 'conformita-core' ), $codice );
		}

		global $wpdb;

		$tabella = self::tabella();
		$motivo  = mb_substr( wp_strip_all_tags( (string) $motivo ), 0, self::LUNGHEZZA_MOTIVO );

		/*
		 * L'esito si scrive solo se l'indirizzo e ancora quello controllato:
		 * se il componente l'ha cambiato nel frattempo, l'esito era di
		 * un'altra pagina.
		 */
		// Un codice nullo non passa da prepare, che lo scriverebbe come testo vuoto.
		$sql    = 'UPDATE `' . $tabella . '` SET esito = %s, codice = ' . ( null === $codice ? 'NULL' : '%d' ) . ', motivo = %s, verificato = %s WHERE sezione = %s AND chiave = %s AND indirizzo = %s';
		$valori = null === $codice
			? array( $esito, $motivo, self::adesso(), $sezione, $chiave, $letto['indirizzo'] )
			: array( $esito, $codice, $motivo, self::adesso(), $sezione, $chiave, $letto['indirizzo'] );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Tabella propria; i valori passano da prepare.
		$scritto = $wpdb->query( $wpdb->prepare( $sql, $valori ) );

		if ( false === $scritto || '' !== $wpdb->last_error ) {
			return self::errore( 'conformita_core_collegamento_esito_non_scritto', __( 'Collegamento: l\'esito della verifica non si è potuto scrivere nella banca dati.', 'conformita-core' ) );
		}

		return self::collegamento( $sezione, $chiave );
	}

	/**
	 * Controlla un lotto di collegamenti da controllare.
	 *
	 * Prima quelli mai controllati, poi quelli controllati da piu tempo il cui
	 * intervallo e passato; solo delle sezioni registrate in questa richiesta.
	 * Il guasto di un collegamento non ferma gli altri.
	 *
	 * @return int|WP_Error Collegamenti controllati con l'esito scritto, errore se il lotto non si e potuto leggere.
	 */
	public static function lotto() {
		global $wpdb;

		$sezioni = Conformita_Core_Sezioni::identificativi();

		if ( array() === $sezioni ) {
			return 0;
		}

		$tabella    = self::tabella();
		$segnaposti = implode( ', ', array_fill( 0, count( $sezioni ), '%s' ) );
		$sql        = 'SELECT sezione, chiave FROM `' . $tabella . '` WHERE sezione IN (' . $segnaposti . ') AND ( verificato IS NULL OR verificato <= DATE_SUB( %s, INTERVAL intervallo DAY ) ) ORDER BY verificato IS NOT NULL, verificato, id LIMIT %d';
		$valori     = array_merge( $sezioni, array( self::adesso(), self::LOTTO ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Tabella propria; i valori passano da prepare.
		$righe = $wpdb->get_results( $wpdb->prepare( $sql, $valori ), ARRAY_A );

		if ( '' !== $wpdb->last_error || ! is_array( $righe ) ) {
			return self::errore( 'conformita_core_collegamenti_lotto', __( 'Collegamenti: il lotto da controllare non si è potuto leggere dalla banca dati.', 'conformita-core' ) );
		}

		$controllati = 0;

		foreach ( $righe as $riga ) {
			if ( ! is_wp_error( self::verifica( (string) $riga['sezione'], (string) $riga['chiave'] ) ) ) {
				++$controllati;
			}
		}

		return $controllati;
	}

	/**
	 * Programma il compito orario, se non e gia programmato.
	 */
	public static function pianifica() {
		if ( false === wp_next_scheduled( self::COMPITO ) ) {
			wp_schedule_event( time(), 'hourly', self::COMPITO );
		}
	}

	/**
	 * Toglie il compito orario.
	 */
	public static function togli_compito() {
		wp_clear_scheduled_hook( self::COMPITO );
	}

	/**
	 * Il collegamento e in ritardo: l'ultimo controllo, o la registrazione se
	 * non e mai stato controllato, e piu vecchio dell'intervallo piu la
	 * tolleranza. Si calcola sul dato letto, senza contare sul compito.
	 *
	 * @param string $verificato Ultimo controllo in UTC, vuoto se mai.
	 * @param string $registrato Registrazione in UTC.
	 * @param int    $intervallo Intervallo in giorni.
	 * @return bool
	 */
	private static function in_ritardo( $verificato, $registrato, $intervallo ) {
		$riferimento = '' !== $verificato ? $verificato : $registrato;
		$istante     = strtotime( $riferimento . ' UTC' );

		if ( false === $istante ) {
			return true;
		}

		return $istante + ( $intervallo + self::TOLLERANZA ) * DAY_IN_SECONDS < time();
	}

	/**
	 * La riga dalla banca dati, nella sua forma.
	 *
	 * @param string $sezione Sezione.
	 * @param string $chiave  Chiave.
	 * @return array<string, mixed>|null|WP_Error
	 */
	private static function leggi( $sezione, $chiave ) {
		global $wpdb;

		$tabella = self::tabella();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Tabella propria; una lettura che deve dire se e fallita.
		$riga = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$tabella}` WHERE sezione = %s AND chiave = %s", $sezione, $chiave ), ARRAY_A );

		if ( '' !== $wpdb->last_error ) {
			return self::errore( 'conformita_core_collegamento_lettura', __( 'Collegamento: la lettura dalla banca dati non è riuscita.', 'conformita-core' ) );
		}

		if ( ! is_array( $riga ) ) {
			return null;
		}

		return array(
			'id'         => (int) $riga['id'],
			'indirizzo'  => (string) $riga['indirizzo'],
			'intervallo' => (int) $riga['intervallo'],
			'esito'      => (string) $riga['esito'],
			'codice'     => null === $riga['codice'] ? null : (int) $riga['codice'],
			'motivo'     => (string) $riga['motivo'],
			'verificato' => (string) $riga['verificato'],
			'registrato' => (string) $riga['registrato'],
			'in_ritardo' => self::in_ritardo( (string) $riga['verificato'], (string) $riga['registrato'], (int) $riga['intervallo'] ),
		);
	}

	/**
	 * La sezione e registrata e la chiave ha la forma ammessa.
	 *
	 * @param mixed $sezione Sezione.
	 * @param mixed $chiave  Chiave.
	 * @return true|WP_Error
	 */
	private static function controlla_sezione_e_chiave( $sezione, $chiave ) {
		if ( ! is_string( $sezione ) || ! Conformita_Core_Sezioni::registrata( $sezione ) ) {
			return self::errore( 'conformita_core_collegamento_sezione', __( 'Collegamento: la sezione non è registrata.', 'conformita-core' ) );
		}

		if ( ! is_string( $chiave ) || 1 !== preg_match( '/\A[a-z0-9_-]{1,' . self::LUNGHEZZA_CHIAVE . '}\z/', $chiave ) ) {
			return self::errore(
				'conformita_core_collegamento_chiave',
				/* translators: %d: lunghezza massima. */
				sprintf( __( 'Collegamento: la chiave va scritta con lettere minuscole, cifre, trattino e trattino basso, al più %d caratteri.', 'conformita-core' ), self::LUNGHEZZA_CHIAVE )
			);
		}

		return true;
	}

	/**
	 * L'indirizzo e uno che si puo controllare.
	 *
	 * @param mixed $indirizzo Indirizzo.
	 * @return true|WP_Error
	 */
	private static function controlla_indirizzo( $indirizzo ) {
		$valido = is_string( $indirizzo )
			&& strlen( $indirizzo ) <= self::LUNGHEZZA_INDIRIZZO
			&& 1 === preg_match( '#\Ahttps?://[^\s\x00-\x1f\x7f]+\z#i', $indirizzo )
			&& esc_url_raw( $indirizzo, array( 'http', 'https' ) ) === $indirizzo
			&& ! self::interno( (string) wp_parse_url( $indirizzo, PHP_URL_HOST ) );

		if ( ! $valido ) {
			return self::errore(
				'conformita_core_collegamento_indirizzo',
				/* translators: %d: lunghezza massima. */
				sprintf( __( 'Collegamento: l\'indirizzo va scritto per intero con http o https, senza spazi, al più %d caratteri, e non può puntare alla rete interna.', 'conformita-core' ), self::LUNGHEZZA_INDIRIZZO )
			);
		}

		return true;
	}

	/**
	 * L'host e scritto come un indirizzo della rete interna.
	 *
	 * Senza chiedere al servizio dei nomi: la registrazione non deve dipendere
	 * dalla rete, e un dominio che non esiste va registrato e risultare rotto.
	 * Un nome che porta alla rete interna lo ferma la verifica, che usa la
	 * richiesta sicura di WordPress.
	 *
	 * @param string $host Host.
	 * @return bool
	 */
	private static function interno( $host ) {
		$host = strtolower( trim( $host, '[]' ) );

		if ( '' === $host || 'localhost' === $host || str_ends_with( $host, '.localhost' ) ) {
			return true;
		}

		if ( false === filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return false;
		}

		return false === filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
	}

	/**
	 * L'istante di adesso, in UTC, nel formato della tabella.
	 *
	 * Il formato di conservazione e UTC come nel registro; chi mostra la data
	 * la porta nel fuso del sito con `wp_timezone()`.
	 *
	 * @return string
	 */
	private static function adesso() {
		return gmdate( 'Y-m-d H:i:s' );
	}

	/**
	 * Un errore con il suo codice.
	 *
	 * @param string $codice    Codice dell'errore.
	 * @param string $messaggio Messaggio leggibile.
	 * @return WP_Error
	 */
	private static function errore( $codice, $messaggio ) {
		return new WP_Error( $codice, $messaggio );
	}
}
