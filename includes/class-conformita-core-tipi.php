<?php
/**
 * Registrazione dei tipi di contenuto governati da una sezione.
 *
 * Un tipo di contenuto non si registra mai direttamente con WordPress: passa da
 * qui, e qui passa solo se dichiara una sezione già registrata. La ragione è la
 * stessa che regge il registro delle sezioni: la politica sta sulla sezione,
 * quindi un tipo registrato fuori da una sezione sarebbe un tipo attivo senza
 * politica, cioè un contenuto pubblicato che nessuna regola governa. Il rifiuto
 * è esplicito e arriva prima della chiamata a WordPress, così un tipo respinto
 * non lascia dietro di sé niente da smontare.
 *
 * Core impone due cose e delega tutto il resto: le capability, che sono la
 * garanzia di isolamento del tipo, e la scelta su REST, che il componente deve
 * dichiarare invece di ereditare. Etichette, supporti, visibilità e riscrittura
 * restano parametri del componente.
 *
 * @package Conformita_Core
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registro dei tipi di contenuto e delle sezioni che li governano.
 */
final class Conformita_Core_Tipi {

	/**
	 * Sezione e capability per identificativo di tipo.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private static $tipi = array();

	/**
	 * Argomenti di registrazione che core impone e non accetta dal componente.
	 *
	 * Le capability sono la garanzia che un tipo non sia governato dai permessi
	 * degli articoli: se il componente potesse riscriverle, la garanzia varrebbe
	 * solo finché nessuno prova a toglierla. La scelta su REST è riservata per il
	 * motivo opposto: va dichiarata nella definizione, dove è visibile, e non
	 * nascosta fra gli argomenti passati a WordPress.
	 *
	 * @return array<int, string>
	 */
	public static function argomenti_riservati() {
		return array( 'capabilities', 'capability_type', 'map_meta_cap', 'show_in_rest' );
	}

	/**
	 * Le due radici da cui WordPress deriva le capability del tipo.
	 *
	 * Sono derivate dall'identificativo del tipo e non configurabili: un nome di
	 * capability sbagliato di una lettera è una capability che nessuno possiede e
	 * di cui nessuno si accorge. Il suffisso del plurale serve solo a tenere
	 * distinte le capability sul singolo contenuto da quelle sull'insieme, come
	 * WordPress si aspetta.
	 *
	 * La derivazione è l'identità, e deve restare tale. La prima stesura
	 * sostituiva il trattino con il trattino basso: siccome WordPress accetta
	 * entrambi nel nome di un tipo, `atto-albo` e `atto_albo` sono due tipi
	 * distinti che quella sostituzione portava alla stessa radice, quindi alle
	 * stesse capability. Chi era autorizzato sull'uno lo era anche sull'altro, e
	 * l'isolamento promesso dalla riga di collaudo C-71 cadeva proprio fra i tipi
	 * di core, che è il caso che conta. Una funzione che deriva un permesso da un
	 * identificativo deve essere iniettiva: qui lo è per costruzione, perché non
	 * trasforma niente, e il trattino è escluso a monte dalla verifica
	 * dell'identificativo. Riga di collaudo C-86.
	 *
	 * @param string $tipo Identificativo del tipo.
	 * @return array<int, string> Radice singolare e radice plurale.
	 */
	public static function radici_capacita( $tipo ) {
		return array( $tipo, $tipo . '_multipli' );
	}

	/**
	 * Registra un tipo di contenuto dentro una sezione.
	 *
	 * @param string $tipo        Identificativo del tipo.
	 * @param array  $definizione Definizione: `sezione`, `show_in_rest` e gli
	 *                            `argomenti` da passare a WordPress.
	 * @return true|WP_Error Vero se il tipo è registrato, errore altrimenti.
	 */
	public static function registra( $tipo, array $definizione ) {
		$tipo = is_string( $tipo ) ? trim( $tipo ) : '';

		$esito = self::verifica_identificativo( $tipo );

		if ( is_wp_error( $esito ) ) {
			return $esito;
		}

		$sezione = self::sezione_dichiarata( $definizione );

		if ( is_wp_error( $sezione ) ) {
			return $sezione;
		}

		if ( ! array_key_exists( 'show_in_rest', $definizione ) || ! is_bool( $definizione['show_in_rest'] ) ) {
			return new WP_Error(
				'conformita_core_rest_non_dichiarata',
				sprintf(
					/* translators: %s: identificativo del tipo di contenuto. */
					__( 'Tipo %s: show_in_rest va dichiarato esplicitamente come valore booleano. La scelta è obbligatoria in entrambi i sensi e non ha un valore predefinito.', 'conformita-core' ),
					$tipo
				)
			);
		}

		$argomenti = self::argomenti_dichiarati( $definizione );

		if ( is_wp_error( $argomenti ) ) {
			return $argomenti;
		}

		$oggetto = register_post_type(
			$tipo,
			array_merge(
				$argomenti,
				array(
					'show_in_rest'    => $definizione['show_in_rest'],
					'capability_type' => self::radici_capacita( $tipo ),
					'map_meta_cap'    => true,
				)
			)
		);

		if ( is_wp_error( $oggetto ) ) {
			return $oggetto;
		}

		self::$tipi[ $tipo ] = array(
			'sezione'  => $sezione,
			'capacita' => (array) $oggetto->cap,
		);

		return true;
	}

	/**
	 * L'identificativo è utilizzabile e non è già di qualcun altro.
	 *
	 * Il limite di venti caratteri e l'insieme di caratteri ammessi sono vincoli
	 * di WordPress: si verificano qui perché `register_post_type` li segnala come
	 * uso scorretto della funzione, mentre un componente merita un errore
	 * leggibile. Il controllo su un tipo già esistente è più importante di
	 * quanto sembri: senza, `register_post_type` rimpiazzerebbe in silenzio il
	 * tipo esistente, capability comprese.
	 *
	 * Il trattino è escluso pur essendo accettato da WordPress, ed è una
	 * restrizione nostra: le capability si derivano dall'identificativo, e
	 * ammettere due grafie che WordPress distingue mentre la derivazione le
	 * confonde produce due tipi diversi governati dagli stessi permessi. Non
	 * costa niente, perché il nome del tipo è interno: l'indirizzo pubblico
	 * arriva dal parametro di riscrittura, che resta libero. Riga C-86.
	 *
	 * @param string $tipo Identificativo del tipo.
	 * @return true|WP_Error
	 */
	private static function verifica_identificativo( $tipo ) {
		if ( ! preg_match( '/^[a-z0-9_]{1,20}$/', $tipo ) ) {
			return new WP_Error(
				'conformita_core_tipo_non_valido',
				__( 'Identificativo di tipo non valido: da uno a venti caratteri fra lettere minuscole, cifre e trattino basso.', 'conformita-core' )
				. ' ' . __( 'Il trattino non è ammesso: renderebbe ambigua la derivazione delle capability.', 'conformita-core' )
			);
		}

		if ( isset( self::$tipi[ $tipo ] ) ) {
			return new WP_Error(
				'conformita_core_tipo_duplicato',
				sprintf(
					/* translators: %s: identificativo del tipo di contenuto. */
					__( 'Tipo %s già registrato: la definizione di un tipo registrato non si sovrascrive.', 'conformita-core' ),
					$tipo
				)
			);
		}

		if ( post_type_exists( $tipo ) ) {
			return new WP_Error(
				'conformita_core_tipo_gia_in_uso',
				sprintf(
					/* translators: %s: identificativo del tipo di contenuto. */
					__( 'Tipo %s già in uso in WordPress: registrarlo sostituirebbe il tipo esistente e le sue capability.', 'conformita-core' ),
					$tipo
				)
			);
		}

		return true;
	}

	/**
	 * La sezione dichiarata dalla definizione, se è una sezione registrata.
	 *
	 * @param array $definizione Definizione del tipo.
	 * @return string|WP_Error Identificativo della sezione, oppure errore.
	 */
	private static function sezione_dichiarata( array $definizione ) {
		$sezione = isset( $definizione['sezione'] ) && is_string( $definizione['sezione'] )
			? trim( $definizione['sezione'] )
			: '';

		if ( '' === $sezione || ! Conformita_Core_Sezioni::registrata( $sezione ) ) {
			return new WP_Error(
				'conformita_core_tipo_senza_sezione',
				sprintf(
					/* translators: %s: identificativo della sezione dichiarata, oppure una descrizione della sua assenza. */
					__( 'Sezione %s non registrata: un tipo di contenuto si registra solo dentro una sezione che ha già dichiarato la sua politica.', 'conformita-core' ),
					'' === $sezione ? __( 'non dichiarata', 'conformita-core' ) : $sezione
				),
				array( 'sezione' => $sezione )
			);
		}

		return $sezione;
	}

	/**
	 * Gli argomenti che il componente passa a WordPress, se sono suoi da passare.
	 *
	 * @param array $definizione Definizione del tipo.
	 * @return array|WP_Error Argomenti validati, oppure errore.
	 */
	private static function argomenti_dichiarati( array $definizione ) {
		if ( ! array_key_exists( 'argomenti', $definizione ) ) {
			return array();
		}

		if ( ! is_array( $definizione['argomenti'] ) ) {
			return new WP_Error(
				'conformita_core_argomenti_non_validi',
				__( 'Gli argomenti di registrazione del tipo devono essere un array associativo.', 'conformita-core' )
			);
		}

		$riservati = array_values(
			array_intersect( self::argomenti_riservati(), array_keys( $definizione['argomenti'] ) )
		);

		if ( ! empty( $riservati ) ) {
			return new WP_Error(
				'conformita_core_argomenti_riservati',
				sprintf(
					/* translators: %s: elenco degli argomenti riservati, separati da virgola. */
					__( 'Argomenti riservati a Conformita Core: %s. Le capability sono un meccanismo di core, e la scelta su REST si dichiara nella definizione del tipo.', 'conformita-core' ),
					implode( ', ', $riservati )
				),
				array( 'riservati' => $riservati )
			);
		}

		return $definizione['argomenti'];
	}

	/**
	 * Il tipo è registrato attraverso core.
	 *
	 * Il nome si riconosce con il criterio della banca dati: vedi `canonico()`.
	 *
	 * @param string $tipo Identificativo del tipo.
	 * @return bool
	 */
	public static function registrato( $tipo ) {
		return null !== self::canonico( $tipo );
	}

	/**
	 * Il tipo gestito che la banca dati riconosce in un nome.
	 *
	 * **Perché non basta il confronto in PHP.** Il tipo di un contenuto è una
	 * colonna della banca dati, e le interrogazioni di WordPress lo cercano con
	 * il confronto della banca dati, che di norma non distingue le maiuscole, né
	 * gli spazi in coda, e in molte configurazioni nemmeno le lettere accentate.
	 * Il salvataggio ordinario riduce il tipo in minuscolo, ma una scrittura
	 * diretta nella banca dati, come un'importazione, o un componente che toglie
	 * quella riduzione, può lasciare `PROVA_ATTO` al posto di `prova_atto`. Una
	 * ricerca per il tipo gestito trova quel contenuto, e un confronto lettera per lettera in PHP
	 * direbbe che non è gestito: il filtro della scadenza lo lascerebbe passare,
	 * e il registro non ne scriverebbe le voci. Due criteri per la stessa
	 * domanda sono due risposte, e fra le due vince la più permissiva.
	 *
	 * Il criterio è quindi uno solo, quello della banca dati, usato ovunque core
	 * chiede se un tipo è gestito: questa funzione è l'unica che risponde.
	 *
	 * **Due scorciatoie, tutte e due senza banca dati.** Il nome esatto di un
	 * tipo gestito è gestito; lo è anche con spazi attorno, come prima di
	 * questa correzione, che per gli spazi in testa è più largo della banca
	 * dati e quindi sbaglia nella direzione che trattiene. Un nome scritto
	 * soltanto con minuscole, cifre, trattino e trattino basso, diverso da ogni
	 * tipo gestito, non è gestito: i tipi gestiti usano lo stesso alfabeto
	 * senza il trattino, e due nomi diversi scritti solo con quei caratteri non
	 * sono uguali per nessun confronto della banca dati. È l'alfabeto a cui
	 * WordPress riduce i nomi dei tipi, quindi un sito sano non fa mai la
	 * domanda alla banca dati. La scorciatoia guarda i caratteri del nome e non
	 * il fatto che il tipo sia registrato: un componente che cambia quella
	 * riduzione può registrare un tipo con un nome qualsiasi, e quel nome va
	 * chiesto alla banca dati come gli altri.
	 *
	 * Tutti gli altri nomi si chiedono alla banca dati a ogni riconoscimento,
	 * senza ricordare la risposta: la tabella, e le sue regole, possono
	 * cambiare nella stessa richiesta. Vedi `riconduci()`. Righe C-248..C-250,
	 * C-255 e C-256.
	 *
	 * @internal Pubblica solo per le altre classi di core.
	 *
	 * @param mixed $tipo Nome del tipo, come arriva.
	 * @return string|null Identificativo del tipo gestito, oppure null.
	 */
	public static function canonico( $tipo ) {
		if ( ! is_string( $tipo ) || empty( self::$tipi ) ) {
			return null;
		}

		$pulito = trim( $tipo );

		if ( isset( self::$tipi[ $pulito ] ) ) {
			return $pulito;
		}

		if ( '' === $pulito || self::normalizzato( $tipo ) ) {
			return null;
		}

		return self::riconduci( $tipo, self::identificativi() );
	}

	/**
	 * Il nome è, per la banca dati, quello di un tipo di WordPress indicato.
	 *
	 * Serve dove core riconosce un tipo di WordPress che non è suo, come
	 * `attachment`, con lo stesso criterio con cui riconosce i propri. Riga
	 * C-250.
	 *
	 * @internal Pubblica solo per le altre classi di core.
	 *
	 * @param mixed  $tipo        Nome del tipo, come arriva.
	 * @param string $riferimento Nome registrato in WordPress.
	 * @return bool
	 */
	public static function uguale( $tipo, $riferimento ) {
		if ( ! is_string( $tipo ) ) {
			return false;
		}

		if ( $tipo === $riferimento ) {
			return true;
		}

		if ( '' === trim( $tipo ) || ( self::normalizzato( $tipo ) && self::normalizzato( $riferimento ) ) ) {
			return false;
		}

		return self::riconduci( $tipo, array( $riferimento ) ) === $riferimento;
	}

	/**
	 * Il nome è scritto soltanto con i caratteri a cui WordPress riduce i nomi
	 * dei tipi: minuscole, cifre, trattino e trattino basso.
	 *
	 * Due nomi diversi scritti così sono diversi anche per la banca dati: è la
	 * condizione delle scorciatoie di `canonico()` e `uguale()`. Riga C-256.
	 *
	 * @param string $tipo Nome del tipo.
	 * @return bool
	 */
	private static function normalizzato( $tipo ) {
		return 1 === preg_match( '/^[a-z0-9_-]+$/D', $tipo );
	}

	/**
	 * Quale dei nomi indicati la banca dati considera uguale al nome dato.
	 *
	 * Il confronto si fa con le regole della colonna del tipo nella tabella dei
	 * contenuti, che sono quelle con cui WordPress cerca i contenuti per tipo:
	 * i due nomi si convertono nell'insieme di caratteri della colonna e si
	 * confrontano con il suo ordinamento. Se le regole della colonna non si
	 * possono leggere, il ripiego confronta senza badare alle maiuscole e agli
	 * spazi in coda, che sono le differenze che ogni ordinamento comune ignora:
	 * sbaglia al più trattenendo meno di quanto la banca dati troverebbe, e
	 * soltanto su una banca dati che non risponde alla domanda sulle sue regole.
	 *
	 * @param string             $tipo      Nome da ricondurre.
	 * @param array<int, string> $candidati Nomi fra cui cercare.
	 * @return string|null Il nome trovato, oppure null.
	 */
	private static function riconduci( $tipo, array $candidati ) {
		global $wpdb;

		$regole = self::regole_della_colonna();

		if ( null === $regole ) {
			$ridotto = strtolower( rtrim( $tipo, ' ' ) );

			foreach ( $candidati as $candidato ) {
				if ( strtolower( $candidato ) === $ridotto ) {
					return $candidato;
				}
			}

			return null;
		}

		$lato      = "CONVERT( %s USING {$regole['insieme']} ) COLLATE {$regole['ordinamento']}";
		$casi      = array();
		$argomenti = array();

		foreach ( $candidati as $candidato ) {
			$casi[]      = "WHEN {$lato} = {$lato} THEN %s";
			$argomenti[] = $tipo;
			$argomenti[] = $candidato;
			$argomenti[] = $candidato;
		}

		/*
		 * Insieme di caratteri e ordinamento arrivano dalla banca dati stessa e
		 * sono stati verificati carattere per carattere in
		 * `regole_della_colonna()`; tutto il resto passa dai segnaposto.
		 */
		$istruzione = 'SELECT CASE ' . implode( ' ', $casi ) . " ELSE '' END";

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$trovato = $wpdb->get_var( $wpdb->prepare( $istruzione, $argomenti ) );
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		return is_string( $trovato ) && in_array( $trovato, $candidati, true ) ? $trovato : null;
	}

	/**
	 * Insieme di caratteri e ordinamento della colonna del tipo.
	 *
	 * Letti a ogni domanda, con la stessa istruzione che WordPress usa per
	 * conoscere le regole delle proprie colonne. Non si ricordano: nella stessa
	 * richiesta la tabella dei contenuti può cambiare nome (un altro sito della
	 * stessa installazione) o struttura (una tabella temporanea con lo stesso
	 * nome, una modifica delle regole), e una risposta ricordata varrebbe per
	 * una tabella che non c'è più. Righe C-255, C-256 e C-258.
	 *
	 * @return array{insieme: string, ordinamento: string}|null
	 */
	private static function regole_della_colonna() {
		global $wpdb;

		/*
		 * La colonna si cerca per nome esatto e non per somiglianza: in LIKE il
		 * trattino basso vale per qualunque carattere, e una colonna aggiunta da
		 * un componente, come `postXtype`, verrebbe presa al posto di quella del
		 * tipo. Il nome letto si ricontrolla. Riga C-258.
		 */
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Il nome della tabella viene da WordPress; la domanda riguarda la struttura, non i dati.
		$colonna     = $wpdb->get_row( "SHOW FULL COLUMNS FROM {$wpdb->posts} WHERE Field = 'post_type'", ARRAY_A );
		$ordinamento = is_array( $colonna ) && isset( $colonna['Field'], $colonna['Collation'] ) && 'post_type' === $colonna['Field'] && is_string( $colonna['Collation'] ) ? $colonna['Collation'] : '';
		$insieme     = strtok( $ordinamento, '_' );

		return preg_match( '/^[A-Za-z0-9_]+$/D', $ordinamento ) && is_string( $insieme ) && '' !== $insieme
			? array(
				'insieme'     => $insieme,
				'ordinamento' => $ordinamento,
			)
			: null;
	}

	/**
	 * Sezione che governa un tipo registrato.
	 *
	 * @param string $tipo Identificativo del tipo.
	 * @return string|WP_Error Identificativo della sezione, oppure errore.
	 */
	public static function sezione( $tipo ) {
		$voce = self::voce( $tipo );

		return is_wp_error( $voce ) ? $voce : $voce['sezione'];
	}

	/**
	 * Politica che governa un tipo registrato.
	 *
	 * La politica non è duplicata sul tipo: si legge dalla sezione, che resta
	 * l'unico posto in cui è dichiarata.
	 *
	 * @param string $tipo Identificativo del tipo.
	 * @return Conformita_Core_Politica|WP_Error Politica dichiarata, oppure errore.
	 */
	public static function politica( $tipo ) {
		$sezione = self::sezione( $tipo );

		return is_wp_error( $sezione ) ? $sezione : Conformita_Core_Sezioni::politica( $sezione );
	}

	/**
	 * Capability dedicate a un tipo registrato.
	 *
	 * Core le registra e non le assegna ad alcun ruolo: finché il componente non
	 * effettua l'assegnazione, nessun ruolo può gestire il tipo
	 * nell'amministrazione WordPress. L'assegnazione è compito del componente,
	 * che conosce la propria sezione e i propri ruoli. Fra le due direzioni
	 * possibili è quella che sbaglia in sicurezza.
	 *
	 * Detto così e non come assenza di visibilità: le capability governano la
	 * gestione del contenuto, non la sua consultazione pubblica, che discende
	 * dalla politica della sezione e dallo stato del contenuto. Un tipo senza
	 * capability assegnate resta pubblicamente consultabile se registrato come
	 * pubblico e con contenuti pubblicati.
	 *
	 * @param string $tipo Identificativo del tipo.
	 * @return array<string, string>|WP_Error Mappa delle capability, oppure errore.
	 */
	public static function capacita( $tipo ) {
		$voce = self::voce( $tipo );

		return is_wp_error( $voce ) ? $voce : $voce['capacita'];
	}

	/**
	 * Identificativi dei tipi registrati, nell'ordine di registrazione.
	 *
	 * @return array<int, string>
	 */
	public static function identificativi() {
		/*
		 * PHP trasforma in numero la chiave di un elenco scritta solo con
		 * cifre: un tipo `123` tornerebbe come intero, e ogni confronto stretto
		 * con il nome letto dalla banca dati fallirebbe. Riga C-257.
		 */
		return array_map( 'strval', array_keys( self::$tipi ) );
	}

	/**
	 * Voce di registro di un tipo, oppure l'errore che dice che non c'è.
	 *
	 * @param string $tipo Identificativo del tipo.
	 * @return array<string, mixed>|WP_Error
	 */
	private static function voce( $tipo ) {
		$canonico = self::canonico( $tipo );

		if ( null === $canonico ) {
			$tipo = is_string( $tipo ) ? trim( $tipo ) : '';

			return new WP_Error(
				'conformita_core_tipo_sconosciuto',
				sprintf(
					/* translators: %s: identificativo del tipo di contenuto. */
					__( 'Tipo %s non registrato attraverso Conformita Core: nessuna sezione e nessuna politica da applicare.', 'conformita-core' ),
					$tipo
				)
			);
		}

		return self::$tipi[ $canonico ];
	}

	/**
	 * Svuota il registro e smonta i tipi registrati.
	 *
	 * Serve alla suite di test, dove le registrazioni girano tutte nello stesso
	 * processo e un tipo lasciato dietro renderebbe verde o rossa la prova
	 * successiva per motivi che non c'entrano con quello che prova.
	 */
	public static function azzera() {
		foreach ( array_keys( self::$tipi ) as $tipo ) {
			if ( post_type_exists( $tipo ) ) {
				unregister_post_type( $tipo );
			}
		}

		self::$tipi = array();
	}
}
