<?php
/**
 * Registro delle modifiche: una voce per ogni operazione rilevante, solo in
 * aggiunta.
 *
 * **Solo in aggiunta vuol dire che il percorso per modificare non esiste.** In
 * questa classe ci sono una scrittura che aggiunge una riga e delle letture.
 * Nessuna funzione modifica o cancella una voce, e una prova statica verifica
 * che nel codice del componente non compaia nessuna istruzione che lo faccia
 * sulla tabella. Chi ha accesso diretto alla banca dati resta fuori dalla
 * portata di un componente WordPress, ed è detto nella scheda: la garanzia è
 * verso l'interfaccia e verso gli altri componenti, non verso l'amministratore
 * del server.
 *
 * **Chi e quando non si dichiarano, si leggono.** Chi scrive una voce passa la
 * sezione, l'operazione, il contenuto, il motivo e i dettagli. L'utente è
 * sempre quello della richiesta in corso e l'istante è sempre quello
 * dell'orologio di core: se si potessero passare, un componente potrebbe
 * scrivere una voce a nome di un altro o con una data a scelta, e il registro
 * non attesterebbe più niente.
 *
 * **Dell'utente si conserva solo il numero.** Nessun nome, nessuna email,
 * nessun indirizzo: il registro dura più dei contenuti, e un dato personale che
 * non c'è non va protetto né cancellato.
 *
 * Righe di collaudo C-50..C-52, dettagliate in C-195..C-224.
 *
 * @package Conformita_Core
 */

defined( 'ABSPATH' ) || exit;

/**
 * Tabella del registro, scrittura in aggiunta e letture.
 */
final class Conformita_Core_Registro {

	/**
	 * Nome della tabella senza il prefisso del sito.
	 */
	const TABELLA = 'conformita_core_registro';

	/**
	 * Versione dello schema della tabella.
	 *
	 * Cambia solo quando cambia la struttura della tabella: all'avvio, se
	 * l'opzione dice un numero diverso, la tabella si crea o si aggiorna.
	 */
	const VERSIONE_SCHEMA = '1';

	/**
	 * Opzione che conserva la versione dello schema installata.
	 */
	const OPZIONE_SCHEMA = 'conformita_core_registro_schema';

	/**
	 * Opzione che conta le voci automatiche che non si sono potute scrivere.
	 */
	const OPZIONE_MANCATE = 'conformita_core_registro_mancate';

	/**
	 * La capability che apre la schermata di consultazione.
	 *
	 * Core non la assegna a nessun ruolo, nemmeno all'amministratore, come per
	 * le capability dei tipi: chi può leggere il registro lo decide il
	 * componente, che conosce i propri ruoli.
	 */
	const CAPACITA = 'conformita_core_leggere_registro';

	/**
	 * Origine di una voce scritta da core per conto proprio.
	 */
	const ORIGINE_AUTOMATICA = 'automatica';

	/**
	 * Origine di una voce scritta da un componente con l'API.
	 */
	const ORIGINE_COMPONENTE = 'componente';

	/**
	 * Le chiavi ammesse nella descrizione di una voce.
	 *
	 * Una chiave sconosciuta è un errore e non viene ignorata: `motivo` al posto
	 * di `motivazione` produrrebbe altrimenti una voce senza motivo, e chi l'ha
	 * scritta crederebbe di averlo registrato. `utente` e `istante` non ci sono
	 * di proposito.
	 */
	const CHIAVI_VOCE = array( 'sezione', 'azione', 'contenuto', 'motivazione', 'dettagli', 'riferimento', 'chiave' );

	/**
	 * Le chiavi ammesse nei filtri di lettura.
	 */
	const CHIAVI_FILTRO = array( 'sezione', 'contenuto', 'azione', 'utente', 'origine', 'riferimento', 'dal', 'al', 'ordine', 'per_pagina', 'pagina' );

	/**
	 * Il riferimento orario in cui la tabella conserva gli istanti.
	 *
	 * Non è il fuso di un ente e non sostituisce `wp_timezone()`: è il formato
	 * di conservazione, come la data `post_date_gmt` di WordPress. Un istante
	 * scritto nell'ora civile sarebbe ambiguo nell'ora che si ripete al ritorno
	 * dell'ora solare, e cambierebbe significato se il sito cambiasse fuso.
	 * Tutto ciò che si mostra o si filtra per giorno passa da `wp_timezone()`.
	 *
	 * @return DateTimeZone
	 */
	public static function utc() {
		return new DateTimeZone( 'UTC' );
	}

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
	 * Crea o aggiorna la tabella se la versione installata non è quella attesa.
	 *
	 * Gira al caricamento di core e non solo all'attivazione: WordPress non
	 * chiama l'aggancio di attivazione quando un plugin si aggiorna, e un
	 * registro che dipendesse da quell'aggancio non esisterebbe su un sito che
	 * ha aggiornato core invece di attivarlo.
	 */
	public static function assicura_tabella() {
		if ( self::VERSIONE_SCHEMA === get_option( self::OPZIONE_SCHEMA ) ) {
			return;
		}

		self::installa();
	}

	/**
	 * Crea la tabella, e registra la versione solo se la tabella c'è davvero.
	 *
	 * La versione si scrive dopo aver riletto l'elenco delle tabelle: scriverla
	 * sulla fiducia farebbe credere installato un registro che non esiste, e il
	 * controllo all'avvio non riproverebbe più.
	 *
	 * @return bool Vero se al termine la tabella esiste.
	 */
	public static function installa() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$tabella = self::tabella();
		$collate = $wpdb->get_charset_collate();

		/*
		 * Il formato è quello che dbDelta pretende: un campo per riga, due spazi
		 * dopo PRIMARY KEY. La chiave unica ammette più righe con valore nullo,
		 * quindi vincola solo le voci che una chiave la dichiarano.
		 */
		dbDelta(
			"CREATE TABLE {$tabella} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
istante datetime NOT NULL,
utente bigint(20) unsigned NOT NULL DEFAULT 0,
sezione varchar(64) NOT NULL,
contenuto bigint(20) unsigned DEFAULT NULL,
tipo varchar(20) DEFAULT NULL,
azione varchar(64) NOT NULL,
origine varchar(20) NOT NULL,
motivazione longtext DEFAULT NULL,
dettagli longtext DEFAULT NULL,
riferimento bigint(20) unsigned DEFAULT NULL,
chiave varbinary(191) DEFAULT NULL,
PRIMARY KEY  (id),
UNIQUE KEY chiave (chiave),
KEY contenuto (contenuto,id),
KEY sezione (sezione,id),
KEY azione (azione),
KEY utente (utente),
KEY istante (istante),
KEY riferimento (riferimento)
) {$collate};"
		);

		if ( ! self::tabella_presente() ) {
			return false;
		}

		update_option( self::OPZIONE_SCHEMA, self::VERSIONE_SCHEMA, true );

		return true;
	}

	/**
	 * La tabella esiste nella banca dati.
	 *
	 * @return bool
	 */
	public static function tabella_presente() {
		global $wpdb;

		$tabella = self::tabella();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Verifica di struttura: il risultato non si mette in memoria, deve dire com'è la banca dati adesso.
		return $tabella === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $tabella ) ) );
	}

	/**
	 * Scrive una voce per conto di un componente.
	 *
	 * @param array<string, mixed> $voce Descrizione della voce.
	 * @return int|WP_Error Identificativo della voce scritta, oppure errore.
	 */
	public static function registra( $voce ) {
		if ( ! is_array( $voce ) ) {
			return self::errore( 'conformita_core_registro_voce_non_valida', __( 'Registro: la voce va descritta con un elenco di chiavi e valori.', 'conformita-core' ) );
		}

		$azione = isset( $voce['azione'] ) ? $voce['azione'] : null;

		if ( is_string( $azione ) && in_array( $azione, Conformita_Core_Registro_Automatico::azioni(), true ) ) {
			return self::errore(
				'conformita_core_registro_azione_riservata',
				sprintf(
					/* translators: %s: nome dell'operazione. */
					__( 'Registro: l\'operazione %s è riservata alle voci che core scrive da sé. Un componente registra le proprie operazioni con un nome proprio.', 'conformita-core' ),
					$azione
				)
			);
		}

		return self::scrivi( $voce, self::ORIGINE_COMPONENTE );
	}

	/**
	 * Valida e scrive una voce, poi la rilegge.
	 *
	 * @internal Le voci automatiche passano da qui senza il controllo sui nomi
	 *           riservati; i componenti passano da `registra()`.
	 *
	 * @param array<string, mixed> $voce    Descrizione della voce.
	 * @param string               $origine Una delle due costanti di origine.
	 * @return int|WP_Error
	 */
	public static function scrivi( array $voce, $origine ) {
		global $wpdb;

		$riga = self::valida( $voce );

		if ( is_wp_error( $riga ) ) {
			return $riga;
		}

		$riga['origine'] = $origine;
		$riga['utente']  = get_current_user_id();
		$riga['istante'] = Conformita_Core_Scadenza::ora()->setTimezone( self::utc() )->format( 'Y-m-d H:i:s' );

		if ( null !== $riga['chiave'] ) {
			$esistente = self::per_chiave( $riga['chiave'] );

			if ( null !== $esistente ) {
				return self::errore_chiave( $esistente );
			}
		}

		$formati = array();

		foreach ( $riga as $campo => $valore ) {
			$formati[] = in_array( $campo, array( 'utente', 'contenuto', 'riferimento' ), true ) ? '%d' : '%s';
		}

		/*
		 * Chi guarda la banca dati quando la scrittura fallisce non deve vedere
		 * l'errore stampato nella pagina: l'esito arriva a chi ha chiamato come
		 * errore, e la pagina resta quella che era.
		 */
		$errori = $wpdb->suppress_errors( true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- La tabella è propria di core: non c'è un'API di WordPress per scriverci.
		$esito = $wpdb->insert( self::tabella(), $riga, $formati );
		$wpdb->suppress_errors( $errori );

		if ( false === $esito ) {
			/*
			 * Due scritture con la stessa chiave possono arrivare insieme e
			 * superare entrambe il controllo di sopra: la seconda la ferma il
			 * vincolo di unicità, e qui si distingue quel rifiuto da un guasto.
			 */
			if ( null !== $riga['chiave'] ) {
				$esistente = self::per_chiave( $riga['chiave'] );

				if ( null !== $esistente ) {
					return self::errore_chiave( $esistente );
				}
			}

			return self::errore( 'conformita_core_registro_non_scritto', __( 'Registro: la voce non è stata scritta nella banca dati.', 'conformita-core' ) );
		}

		$id = (int) $wpdb->insert_id;

		/*
		 * Rilettura di controllo: la scrittura può riferire successo e aver
		 * scritto altro, per esempio un testo troncato da una colonna o da un
		 * insieme di caratteri che non lo contiene. Una voce che dice una cosa
		 * diversa da quella dichiarata è peggio di una voce mancante, perché
		 * nessuno la va a verificare.
		 */
		$scritta = $id > 0 ? self::riga( $id ) : null;

		if ( null === $scritta ) {
			return self::errore( 'conformita_core_registro_non_scritto', __( 'Registro: la voce risulta scritta ma non si rilegge.', 'conformita-core' ) );
		}

		foreach ( $riga as $campo => $valore ) {
			$letto = $scritta[ $campo ];

			if ( null === $valore ? null !== $letto : (string) $valore !== (string) $letto ) {
				return self::errore(
					'conformita_core_registro_non_conforme',
					sprintf(
						/* translators: 1: numero della voce, 2: nome del campo. */
						__( 'Registro: la voce %1$d è stata scritta ma il campo %2$s non corrisponde a quello dichiarato.', 'conformita-core' ),
						$id,
						$campo
					)
				);
			}
		}

		return $id;
	}

	/**
	 * Controlla la descrizione di una voce e la porta nella forma della riga.
	 *
	 * @param array<string, mixed> $voce Descrizione della voce.
	 * @return array<string, mixed>|WP_Error
	 */
	private static function valida( array $voce ) {
		$estranee = array_diff( array_keys( $voce ), self::CHIAVI_VOCE );

		if ( array() !== $estranee ) {
			return self::errore(
				'conformita_core_registro_chiave_sconosciuta',
				sprintf(
					/* translators: 1: chiavi non riconosciute, 2: chiavi ammesse. */
					__( 'Registro: chiavi non riconosciute nella voce (%1$s). Le chiavi ammesse sono: %2$s. Chi agisce e quando non si dichiarano: li legge core.', 'conformita-core' ),
					implode( ', ', array_map( 'strval', $estranee ) ),
					implode( ', ', self::CHIAVI_VOCE )
				)
			);
		}

		$sezione = isset( $voce['sezione'] ) ? $voce['sezione'] : null;

		if ( ! is_string( $sezione ) || ! Conformita_Core_Sezioni::registrata( $sezione ) ) {
			return self::errore( 'conformita_core_registro_sezione_sconosciuta', __( 'Registro: la voce deve indicare una sezione registrata.', 'conformita-core' ) );
		}

		$azione = isset( $voce['azione'] ) ? $voce['azione'] : null;

		if ( ! is_string( $azione ) || 1 !== preg_match( '/\A[a-z0-9_]{1,64}\z/', $azione ) ) {
			return self::errore( 'conformita_core_registro_azione_non_valida', __( 'Registro: il nome dell\'operazione è obbligatorio e ammette da 1 a 64 fra lettere minuscole, cifre e trattino basso.', 'conformita-core' ) );
		}

		$riga = array(
			'sezione'     => $sezione,
			'contenuto'   => null,
			'tipo'        => null,
			'azione'      => $azione,
			'motivazione' => null,
			'dettagli'    => null,
			'riferimento' => null,
			'chiave'      => null,
		);

		if ( array_key_exists( 'contenuto', $voce ) ) {
			$contenuto = self::intero_positivo( $voce['contenuto'] );
			$post      = $contenuto ? get_post( $contenuto ) : null;

			if ( ! $post instanceof WP_Post ) {
				return self::errore( 'conformita_core_registro_contenuto_sconosciuto', __( 'Registro: il contenuto indicato non esiste.', 'conformita-core' ) );
			}

			if ( ! Conformita_Core_Tipi::registrato( $post->post_type ) || Conformita_Core_Tipi::sezione( $post->post_type ) !== $sezione ) {
				return self::errore( 'conformita_core_registro_contenuto_estraneo', __( 'Registro: il contenuto indicato non è di un tipo governato dalla sezione della voce.', 'conformita-core' ) );
			}

			$riga['contenuto'] = $contenuto;
			$riga['tipo']      = $post->post_type;
		}

		if ( array_key_exists( 'motivazione', $voce ) ) {
			$motivazione = $voce['motivazione'];

			if ( ! is_string( $motivazione ) || '' === trim( $motivazione ) || '' === wp_check_invalid_utf8( $motivazione ) ) {
				return self::errore( 'conformita_core_registro_motivazione_vuota', __( 'Registro: la motivazione, quando c\'è, deve essere un testo non vuoto. Una voce senza motivo si scrive senza la chiave.', 'conformita-core' ) );
			}

			$riga['motivazione'] = trim( $motivazione );
		}

		if ( array_key_exists( 'dettagli', $voce ) ) {
			$dettagli = self::dettagli( $voce['dettagli'] );

			if ( is_wp_error( $dettagli ) ) {
				return $dettagli;
			}

			$riga['dettagli'] = $dettagli;
		}

		if ( array_key_exists( 'riferimento', $voce ) ) {
			$riferimento = self::intero_positivo( $voce['riferimento'] );
			$precedente  = $riferimento ? self::riga( $riferimento ) : null;

			if ( null === $precedente ) {
				return self::errore( 'conformita_core_registro_riferimento_sconosciuto', __( 'Registro: la voce a cui si rimanda non esiste.', 'conformita-core' ) );
			}

			$stesso_contenuto = null === $riga['contenuto']
				? null === $precedente['contenuto']
				: (string) $riga['contenuto'] === (string) $precedente['contenuto'];

			if ( $precedente['sezione'] !== $sezione || ! $stesso_contenuto ) {
				return self::errore( 'conformita_core_registro_riferimento_estraneo', __( 'Registro: si può rimandare solo a una voce della stessa sezione e dello stesso contenuto.', 'conformita-core' ) );
			}

			$riga['riferimento'] = $riferimento;
		}

		if ( array_key_exists( 'chiave', $voce ) ) {
			$chiave = $voce['chiave'];

			if ( ! is_string( $chiave ) || 1 !== preg_match( '/\A[\x21-\x7e]{1,191}\z/', $chiave ) ) {
				return self::errore( 'conformita_core_registro_chiave_non_valida', __( 'Registro: la chiave di unicità ammette da 1 a 191 caratteri stampabili, senza spazi.', 'conformita-core' ) );
			}

			$riga['chiave'] = $chiave;
		}

		return $riga;
	}

	/**
	 * I dettagli di una voce, controllati e codificati.
	 *
	 * Nomi in minuscolo con cifre e trattino basso; valori testo, numero,
	 * vero o falso, nullo, oppure un elenco di questi. Un livello solo: i
	 * dettagli sono una descrizione dell'operazione, non un archivio di dati.
	 *
	 * @param mixed $dettagli Dettagli dichiarati.
	 * @return string|null|WP_Error Testo codificato, nullo se vuoti, errore.
	 */
	private static function dettagli( $dettagli ) {
		$errore = self::errore( 'conformita_core_registro_dettagli_non_validi', __( 'Registro: i dettagli sono coppie di nome e valore; il nome ammette lettere minuscole, cifre e trattino basso, il valore è un testo, un numero, vero o falso, nullo, oppure un elenco di questi.', 'conformita-core' ) );

		if ( ! is_array( $dettagli ) ) {
			return $errore;
		}

		if ( array() === $dettagli ) {
			return null;
		}

		foreach ( $dettagli as $nome => $valore ) {
			if ( ! is_string( $nome ) || 1 !== preg_match( '/\A[a-z0-9_]{1,64}\z/', $nome ) ) {
				return $errore;
			}

			$valori = is_array( $valore ) ? $valore : array( $valore );

			if ( is_array( $valore ) && array_values( $valore ) !== $valore ) {
				return $errore;
			}

			foreach ( $valori as $singolo ) {
				if ( ! ( null === $singolo || is_scalar( $singolo ) ) ) {
					return $errore;
				}

				if ( is_float( $singolo ) && ! is_finite( $singolo ) ) {
					return $errore;
				}

				if ( is_string( $singolo ) && '' !== $singolo && '' === wp_check_invalid_utf8( $singolo ) ) {
					return $errore;
				}
			}
		}

		$testo = wp_json_encode( $dettagli, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

		return false === $testo ? $errore : $testo;
	}

	/**
	 * Una voce, nella forma in cui la leggono i componenti.
	 *
	 * @param int $id Identificativo della voce.
	 * @return array<string, mixed>|null
	 */
	public static function voce( $id ) {
		$id   = self::intero_positivo( $id );
		$riga = $id ? self::riga( $id ) : null;

		return null === $riga ? null : self::forma( $riga );
	}

	/**
	 * Le voci che rispondono ai filtri.
	 *
	 * @param array<string, mixed> $filtri Filtri: vedi `CHIAVI_FILTRO`.
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	public static function voci( $filtri = array() ) {
		global $wpdb;

		$condizioni = self::condizioni( $filtri );

		if ( is_wp_error( $condizioni ) ) {
			return $condizioni;
		}

		list( $dove, $valori ) = $condizioni;

		$ordine = array_key_exists( 'ordine', $filtri ) && 'decrescente' === $filtri['ordine'] ? 'DESC' : 'ASC';
		$limite = '';

		if ( array_key_exists( 'per_pagina', $filtri ) ) {
			$per_pagina = self::intero_positivo( $filtri['per_pagina'] );
			$pagina     = array_key_exists( 'pagina', $filtri ) ? self::intero_positivo( $filtri['pagina'] ) : 1;
			$limite     = $wpdb->prepare( ' LIMIT %d OFFSET %d', $per_pagina, ( $pagina - 1 ) * $per_pagina );
		}

		$tabella = self::tabella();
		$sql     = "SELECT * FROM {$tabella}{$dove} ORDER BY id {$ordine}{$limite}";

		if ( array() !== $valori ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Le condizioni sono costruite qui con segnaposto e i valori arrivano separati.
			$sql = $wpdb->prepare( $sql, $valori );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Tabella propria; il registro non si legge da una memoria che potrebbe essere vecchia.
		$righe = $wpdb->get_results( $sql, ARRAY_A );

		return array_map( array( __CLASS__, 'forma' ), is_array( $righe ) ? $righe : array() );
	}

	/**
	 * Quante voci rispondono ai filtri, senza tener conto delle pagine.
	 *
	 * @param array<string, mixed> $filtri Filtri: vedi `CHIAVI_FILTRO`.
	 * @return int|WP_Error
	 */
	public static function conta( $filtri = array() ) {
		global $wpdb;

		$condizioni = self::condizioni( $filtri );

		if ( is_wp_error( $condizioni ) ) {
			return $condizioni;
		}

		list( $dove, $valori ) = $condizioni;

		$tabella = self::tabella();
		$sql     = "SELECT COUNT(*) FROM {$tabella}{$dove}";

		if ( array() !== $valori ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Le condizioni sono costruite qui con segnaposto e i valori arrivano separati.
			$sql = $wpdb->prepare( $sql, $valori );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Tabella propria, conteggio del momento.
		return (int) $wpdb->get_var( $sql );
	}

	/**
	 * Traduce i filtri in condizioni con segnaposto.
	 *
	 * Un filtro sconosciuto o malformato è un errore e non viene ignorato: un
	 * filtro ignorato restituirebbe tutte le voci, e chi cercava quelle di un
	 * solo contenuto le leggerebbe come se fossero sue. Per questo conta la
	 * presenza della chiave e non il valore: un filtro dichiarato con valore
	 * nullo, per esempio il numero di un contenuto che chi chiama non ha
	 * trovato, è malformato come uno con valore sbagliato. Lo stesso vale per
	 * la pagina senza la misura della pagina, che da sola non limiterebbe
	 * niente.
	 *
	 * @param mixed $filtri Filtri dichiarati.
	 * @return array{0: string, 1: array<int, mixed>}|WP_Error
	 */
	private static function condizioni( $filtri ) {
		$errore = self::errore( 'conformita_core_registro_filtro_non_valido', __( 'Registro: filtro non riconosciuto o con un valore non valido.', 'conformita-core' ) );

		if ( ! is_array( $filtri ) || array() !== array_diff( array_keys( $filtri ), self::CHIAVI_FILTRO ) ) {
			return $errore;
		}

		$condizioni = array();
		$valori     = array();

		foreach ( array( 'sezione', 'azione', 'origine' ) as $campo ) {
			if ( array_key_exists( $campo, $filtri ) ) {
				if ( ! is_string( $filtri[ $campo ] ) || '' === $filtri[ $campo ] ) {
					return $errore;
				}

				$condizioni[] = "{$campo} = %s";
				$valori[]     = $filtri[ $campo ];
			}
		}

		foreach ( array( 'contenuto', 'riferimento' ) as $campo ) {
			if ( array_key_exists( $campo, $filtri ) ) {
				$numero = self::intero_positivo( $filtri[ $campo ] );

				if ( ! $numero ) {
					return $errore;
				}

				$condizioni[] = "{$campo} = %d";
				$valori[]     = $numero;
			}
		}

		if ( array_key_exists( 'utente', $filtri ) ) {
			$utente = $filtri['utente'];

			if ( ! ( ( is_int( $utente ) && $utente >= 0 ) || ( is_string( $utente ) && 1 === preg_match( '/\A[0-9]{1,19}\z/', $utente ) ) ) ) {
				return $errore;
			}

			$condizioni[] = 'utente = %d';
			$valori[]     = (int) $utente;
		}

		foreach ( array(
			'dal' => '>=',
			'al'  => '<',
		) as $campo => $confronto ) {
			if ( array_key_exists( $campo, $filtri ) ) {
				$giorno = self::giorno( $filtri[ $campo ] );

				if ( null === $giorno ) {
					return $errore;
				}

				if ( 'al' === $campo ) {
					$giorno = $giorno->modify( '+1 day' );
				}

				$condizioni[] = "istante {$confronto} %s";
				$valori[]     = $giorno->setTimezone( self::utc() )->format( 'Y-m-d H:i:s' );
			}
		}

		foreach ( array( 'per_pagina', 'pagina' ) as $campo ) {
			if ( array_key_exists( $campo, $filtri ) && ! self::intero_positivo( $filtri[ $campo ] ) ) {
				return $errore;
			}
		}

		if ( array_key_exists( 'pagina', $filtri ) && ! array_key_exists( 'per_pagina', $filtri ) ) {
			return $errore;
		}

		if ( array_key_exists( 'ordine', $filtri ) && ! in_array( $filtri['ordine'], array( 'crescente', 'decrescente' ), true ) ) {
			return $errore;
		}

		$dove = array() === $condizioni ? '' : ' WHERE ' . implode( ' AND ', $condizioni );

		return array( $dove, $valori );
	}

	/**
	 * La mezzanotte di un giorno civile nel fuso del sito.
	 *
	 * @param mixed $valore Data nel formato AAAA-MM-GG.
	 * @return DateTimeImmutable|null
	 */
	private static function giorno( $valore ) {
		if ( ! is_string( $valore ) || 1 !== preg_match( '/\A\d{4}-\d{2}-\d{2}\z/', $valore ) ) {
			return null;
		}

		$giorno = DateTimeImmutable::createFromFormat( '!Y-m-d', $valore, wp_timezone() );

		if ( false === $giorno || $giorno->format( 'Y-m-d' ) !== $valore ) {
			return null;
		}

		return $giorno;
	}

	/**
	 * Una riga della tabella, così com'è.
	 *
	 * @param int $id Identificativo della voce.
	 * @return array<string, mixed>|null
	 */
	private static function riga( $id ) {
		global $wpdb;

		$tabella = self::tabella();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Tabella propria; il nome viene dal prefisso del sito.
		$riga = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tabella} WHERE id = %d", $id ), ARRAY_A );

		return is_array( $riga ) ? $riga : null;
	}

	/**
	 * La voce che porta una chiave di unicità, se c'è.
	 *
	 * @param string $chiave Chiave di unicità.
	 * @return array<string, mixed>|null
	 */
	private static function per_chiave( $chiave ) {
		global $wpdb;

		$tabella = self::tabella();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Tabella propria; il nome viene dal prefisso del sito.
		$riga = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tabella} WHERE chiave = %s", $chiave ), ARRAY_A );

		return is_array( $riga ) ? $riga : null;
	}

	/**
	 * Porta una riga nella forma pubblica: numeri come numeri, dettagli decodificati.
	 *
	 * @param array<string, mixed> $riga Riga della tabella.
	 * @return array<string, mixed>
	 */
	private static function forma( array $riga ) {
		$dettagli = null === $riga['dettagli'] ? array() : json_decode( (string) $riga['dettagli'], true );

		return array(
			'id'          => (int) $riga['id'],
			'istante'     => new DateTimeImmutable( $riga['istante'], self::utc() ),
			'utente'      => (int) $riga['utente'],
			'sezione'     => (string) $riga['sezione'],
			'contenuto'   => null === $riga['contenuto'] ? null : (int) $riga['contenuto'],
			'tipo'        => $riga['tipo'],
			'azione'      => (string) $riga['azione'],
			'origine'     => (string) $riga['origine'],
			'motivazione' => $riga['motivazione'],
			'dettagli'    => is_array( $dettagli ) ? $dettagli : array(),
			'riferimento' => null === $riga['riferimento'] ? null : (int) $riga['riferimento'],
			'chiave'      => $riga['chiave'],
		);
	}

	/**
	 * Annota una voce automatica che non si è potuta scrivere.
	 *
	 * Una voce mancante è un buco nel registro e non si ripara dopo: si può
	 * solo sapere che c'è. Il conteggio lo mostra la schermata di
	 * consultazione, con la data della prima e dell'ultima.
	 *
	 * @internal Chiamata solo dalle voci automatiche.
	 */
	public static function annota_mancata() {
		$adesso  = Conformita_Core_Scadenza::ora()->setTimezone( self::utc() )->format( 'Y-m-d H:i:s' );
		$mancate = self::mancate();

		update_option(
			self::OPZIONE_MANCATE,
			array(
				'conteggio' => $mancate['conteggio'] + 1,
				'prima'     => '' === $mancate['prima'] ? $adesso : $mancate['prima'],
				'ultima'    => $adesso,
			),
			false
		);
	}

	/**
	 * Le voci automatiche che non si sono potute scrivere.
	 *
	 * @return array{conteggio: int, prima: string, ultima: string}
	 */
	public static function mancate() {
		$valore = get_option( self::OPZIONE_MANCATE, array() );
		$valore = is_array( $valore ) ? $valore : array();

		return array(
			'conteggio' => isset( $valore['conteggio'] ) ? max( 0, (int) $valore['conteggio'] ) : 0,
			'prima'     => isset( $valore['prima'] ) && is_string( $valore['prima'] ) ? $valore['prima'] : '',
			'ultima'    => isset( $valore['ultima'] ) && is_string( $valore['ultima'] ) ? $valore['ultima'] : '',
		);
	}

	/**
	 * Un intero strettamente positivo, da numero o da testo di sole cifre.
	 *
	 * @param mixed $valore Valore da interpretare.
	 * @return int Il numero, oppure zero se il valore non lo è.
	 */
	private static function intero_positivo( $valore ) {
		if ( is_int( $valore ) ) {
			return $valore > 0 ? $valore : 0;
		}

		if ( is_string( $valore ) && 1 === preg_match( '/\A[1-9][0-9]{0,18}\z/', $valore ) ) {
			return (int) $valore;
		}

		return 0;
	}

	/**
	 * Il rifiuto di una chiave già usata, con la voce che la porta.
	 *
	 * @param array<string, mixed> $esistente Riga che porta la chiave.
	 * @return WP_Error
	 */
	private static function errore_chiave( array $esistente ) {
		return new WP_Error(
			'conformita_core_registro_chiave_esistente',
			sprintf(
				/* translators: %d: numero della voce che porta già la chiave. */
				__( 'Registro: la chiave di unicità è già della voce %d, che resta com\'era.', 'conformita-core' ),
				(int) $esistente['id']
			),
			array( 'voce' => (int) $esistente['id'] )
		);
	}

	/**
	 * Un errore del registro.
	 *
	 * @param string $codice    Codice dell'errore.
	 * @param string $messaggio Messaggio leggibile.
	 * @return WP_Error
	 */
	private static function errore( $codice, $messaggio ) {
		return new WP_Error( $codice, $messaggio );
	}
}
