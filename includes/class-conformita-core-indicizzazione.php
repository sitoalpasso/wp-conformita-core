<?php
/**
 * Meccanismo di indicizzazione: applica la politica dichiarata dalla sezione.
 *
 * La politica la dichiara il componente, sezione per sezione, e qui non se ne
 * inventa nessuna. Con `vietata` le pagine dei contenuti della sezione dicono ai
 * motori di ricerca di non indicizzarle, sia nell'intestazione della risposta
 * sia nel sorgente della pagina, e i tipi della sezione escono dalla mappa del
 * sito. Con `consentita` il meccanismo non aggiunge niente: per chi ha
 * l'obbligo di pubblicare, ostacolare l'indicizzazione sarebbe una violazione,
 * non una prudenza. Tutto cio' che non appartiene a una sezione registrata non
 * si tocca.
 *
 * **Perche' due segnali e non uno.** L'intestazione `X-Robots-Tag` vale anche
 * per cio' che non ha un sorgente HTML, come i feed e i file; il metatag nel
 * sorgente sopravvive alle memorie di pagina che conservano l'HTML e perdono
 * le intestazioni. Ciascuno copre il punto cieco dell'altro.
 *
 * **Perche' niente `robots.txt`.** Un indirizzo escluso da `robots.txt` non viene
 * visitato, quindi il motore non legge mai il `noindex`, e l'indirizzo puo'
 * finire nell'indice lo stesso se qualcuno lo collega da fuori, senza contenuto
 * ma con il titolo del collegamento. Il blocco giusto e' lasciare entrare e
 * dire di non indicizzare. Riga C-229.
 *
 * @package Conformita_Core
 */

defined( 'ABSPATH' ) || exit;

/**
 * Applicazione della politica di indicizzazione sui percorsi pubblici.
 */
final class Conformita_Core_Indicizzazione {

	/**
	 * Priorita' degli agganci.
	 *
	 * La piu' alta che WordPress ammette: il divieto passa dopo ogni filtro
	 * registrato a una priorita' minore, quindi un altro componente che prima
	 * ha scritto il contrario viene corretto. **Non e' una garanzia assoluta.**
	 * Un filtro registrato alla stessa priorita' dopo core passa dopo di lui e
	 * puo' togliere il divieto: nessuna priorita' lo impedisce, ed e' la ragione
	 * per cui la prova sul sito vero guarda la risposta finale e non il codice.
	 * Sugli altri contenuti gli agganci restituiscono quello che ricevono,
	 * quindi arrivare per ultimi non toglie niente a nessuno. Riga C-230.
	 *
	 * @var int
	 */
	const PRIORITA = PHP_INT_MAX;

	/**
	 * Direttive rivolte a un motore specifico, trovate nell'intestazione della
	 * richiesta in corso e da emettere su una riga propria. Riga C-233.
	 *
	 * @var array<int, string>
	 */
	private static $specifiche = array();

	/**
	 * Aggancia i filtri del meccanismo.
	 *
	 * @internal Non fa parte dell'interfaccia pubblica verso i componenti. Si
	 *           accende al caricamento del file di core, come il motore di
	 *           scadenza e per la stessa ragione: un meccanismo di conformita'
	 *           che dipende dall'ordine di caricamento dei plugin non e' un
	 *           meccanismo di conformita'. Riga C-236.
	 *
	 * Idempotente, e ripara: rimette gli agganci che mancano, anche se qualcuno
	 * ne ha tolto uno solo. WordPress identifica un aggancio a un metodo
	 * statico con una chiave fissa, quindi riagganciare quelli presenti non ne
	 * aggiunge un secondo.
	 */
	public static function avvia() {
		foreach ( self::agganci() as $aggancio ) {
			add_filter( $aggancio['aggancio'], array( __CLASS__, $aggancio['metodo'] ), self::PRIORITA, $aggancio['argomenti'] );
		}
	}

	/**
	 * L'elenco degli agganci, in un posto solo.
	 *
	 * @internal Letto dall'accensione, dallo spegnimento e dalla domanda "e'
	 *           acceso?", perche' i tre non possano dire cose diverse: e' la
	 *           lezione dello spegnimento incompleto del motore di scadenza.
	 *           Riga C-230.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function agganci() {
		return array(
			array(
				'aggancio'  => 'wp_robots',
				'metodo'    => 'filtra_robots',
				'argomenti' => 1,
			),
			array(
				'aggancio'  => 'wp_headers',
				'metodo'    => 'filtra_intestazioni',
				'argomenti' => 1,
			),
			array(
				'aggancio'  => 'send_headers',
				'metodo'    => 'emetti_divieto',
				'argomenti' => 0,
			),
			array(
				'aggancio'  => 'wp_sitemaps_post_types',
				'metodo'    => 'filtra_tipi_mappa',
				'argomenti' => 1,
			),
			array(
				'aggancio'  => 'pre_get_posts',
				'metodo'    => 'restringi_mappa',
				'argomenti' => 1,
			),
			array(
				'aggancio'  => 'the_posts',
				'metodo'    => 'filtra_contenuti_mappa',
				'argomenti' => 2,
			),
		);
	}

	/**
	 * Il meccanismo e' acceso: tutti i suoi agganci rispondono davvero.
	 *
	 * Si guarda lo stato degli agganci e non una variabile che lo ricorda: se un
	 * altro componente ne toglie uno, il meccanismo non e' piu' acceso, e la
	 * registrazione di una sezione lo deve sapere. Righe C-85 e C-235.
	 *
	 * @return bool
	 */
	public static function avviato() {
		foreach ( self::agganci() as $aggancio ) {
			if ( self::PRIORITA !== has_filter( $aggancio['aggancio'], array( __CLASS__, $aggancio['metodo'] ) ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Sgancia i filtri del meccanismo.
	 *
	 * @internal Esiste per la suite di test, per le righe C-85, C-230 e C-235.
	 *           Chiamarlo in esercizio, dopo che le sezioni si sono registrate,
	 *           lascia le loro pagine senza divieto: la guardia della
	 *           registrazione vale al momento della registrazione e non dopo. E'
	 *           lo stesso limite dello spegnimento del motore di scadenza, e per
	 *           la stessa ragione nessuna funzione pubblica lo espone.
	 */
	public static function azzera_avvio() {
		foreach ( self::agganci() as $aggancio ) {
			remove_filter( $aggancio['aggancio'], array( __CLASS__, $aggancio['metodo'] ), self::PRIORITA );
		}

		self::$specifiche = array();
	}

	/**
	 * Il tipo appartiene a una sezione che vieta l'indicizzazione.
	 *
	 * Un tipo che non appartiene a nessuna sezione registrata risponde falso: il
	 * meccanismo non ha una politica da applicare e non se ne inventa una. Riga
	 * C-84.
	 *
	 * @param mixed $tipo Identificativo del tipo di contenuto.
	 * @return bool
	 */
	public static function tipo_vietato( $tipo ) {
		if ( ! is_string( $tipo ) || ! Conformita_Core_Tipi::registrato( $tipo ) ) {
			return false;
		}

		$politica = Conformita_Core_Tipi::politica( $tipo );

		if ( is_wp_error( $politica ) ) {
			return false;
		}

		return Conformita_Core_Politica::INDICIZZAZIONE_VIETATA === $politica->indicizzazione();
	}

	/**
	 * Il contenuto appartiene a una sezione che vieta l'indicizzazione.
	 *
	 * Un allegato risponde per il contenuto a cui appartiene: il documento di un
	 * atto dell'albo porta gli stessi dati dell'atto. Un allegato senza padre, o
	 * con un padre che nessuna sezione governa, non si tocca. Righe C-227 e
	 * C-228.
	 *
	 * @param mixed $contenuto Contenuto, o suo identificativo.
	 * @return bool
	 */
	public static function contenuto_vietato( $contenuto ) {
		/*
		 * Niente `get_post()` su un valore vuoto: senza argomento restituisce
		 * il contenuto globale, cioe' la politica di un altro contenuto. Riga
		 * C-234.
		 */
		if ( ! $contenuto instanceof WP_Post ) {
			$contenuto = is_numeric( $contenuto ) && (int) $contenuto > 0 ? get_post( (int) $contenuto ) : null;
		}

		if ( ! $contenuto instanceof WP_Post ) {
			return false;
		}

		if ( 'attachment' !== $contenuto->post_type ) {
			return self::tipo_vietato( $contenuto->post_type );
		}

		/*
		 * Un livello solo, e nessuna ricorsione: il padre di un allegato che
		 * non esiste piu' non prende in prestito la politica di nessuno, e un
		 * padre che fosse a sua volta un allegato non apre una catena.
		 */
		$padre = (int) $contenuto->post_parent > 0 ? get_post( (int) $contenuto->post_parent ) : null;

		if ( ! $padre instanceof WP_Post || 'attachment' === $padre->post_type ) {
			return false;
		}

		return self::tipo_vietato( $padre->post_type );
	}

	/**
	 * La richiesta in corso mostra contenuti di una sezione che vieta
	 * l'indicizzazione.
	 *
	 * La pagina di un contenuto risponde per quel contenuto (compresa la pagina
	 * di un suo allegato, il feed dei suoi commenti e il suo incorporamento).
	 * Ogni altra richiesta risponde per quello che mostra davvero: se fra i
	 * contenuti dell'interrogazione principale ce n'e' anche uno solo vietato,
	 * vince il divieto. Vale per gli elenchi e i feed dei tipi, ma anche per la
	 * pagina iniziale, gli elenchi per autore o per data e i feed generali, che
	 * un tema o un componente possono allargare ai tipi di una sezione vietata:
	 * l'elenco espone comunque titoli e riassunti dei contenuti vietati. Le
	 * pagine dei contenuti consentiti di quello stesso elenco restano
	 * indicizzabili ciascuna per conto propria. Righe C-80, C-225, C-226, C-232.
	 *
	 * Per gli elenchi e i feed di un tipo vietato basta il tipo, anche a elenco
	 * vuoto: la pagina esiste e il suo indirizzo dice gia' a quale sezione
	 * appartiene.
	 *
	 * Si legge dopo l'interrogazione principale: da WordPress 6.1 le intestazioni
	 * si preparano dopo di essa, e la versione minima dichiarata e' 6.5.
	 *
	 * @return bool
	 */
	public static function richiesta_vietata() {
		if ( is_singular() ) {
			return self::contenuto_vietato( get_queried_object() );
		}

		if ( is_post_type_archive() || is_feed() ) {
			foreach ( (array) get_query_var( 'post_type' ) as $tipo ) {
				if ( self::tipo_vietato( $tipo ) ) {
					return true;
				}
			}
		}

		$principale = isset( $GLOBALS['wp_the_query'] ) ? $GLOBALS['wp_the_query'] : null;

		if ( $principale instanceof WP_Query && is_array( $principale->posts ) ) {
			foreach ( $principale->posts as $contenuto ) {
				if ( self::contenuto_vietato( $contenuto ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Il divieto nel sorgente della pagina.
	 *
	 * @internal Aggancio di `wp_robots`, che WordPress stampa come metatag
	 *           `robots` nell'intestazione HTML della pagina.
	 *
	 * @param mixed $direttive Direttive raccolte finora.
	 * @return mixed Direttive, con `noindex` sulle pagine vietate.
	 */
	public static function filtra_robots( $direttive ) {
		if ( ! is_array( $direttive ) || ! self::richiesta_vietata() ) {
			return $direttive;
		}

		unset( $direttive['index'] );
		$direttive['noindex'] = true;

		return $direttive;
	}

	/**
	 * Il divieto nell'intestazione della risposta.
	 *
	 * Le direttive rivolte a un motore specifico, che un altro componente
	 * avesse gia' scritto, non si mescolano al divieto generale: si mettono da
	 * parte e si emettono su una riga propria. Riga C-233.
	 *
	 * @internal Aggancio di `wp_headers`.
	 *
	 * @param mixed $intestazioni Intestazioni raccolte finora.
	 * @return mixed Intestazioni, con `X-Robots-Tag: noindex` sulle pagine vietate.
	 */
	public static function filtra_intestazioni( $intestazioni ) {
		self::$specifiche = array();

		if ( ! is_array( $intestazioni ) || ! self::richiesta_vietata() ) {
			return $intestazioni;
		}

		$composte         = self::componi_divieto( $intestazioni );
		self::$specifiche = $composte['specifiche'];

		return $composte['intestazioni'];
	}

	/**
	 * Emette il divieto su righe proprie, dopo che WordPress ha mandato le sue.
	 *
	 * Sulle pagine vietate escono qui, aggiunte e mai in sostituzione, le
	 * direttive per un motore specifico messe da parte e, sempre, una riga
	 * generale `X-Robots-Tag: noindex` autonoma. La riga autonoma serve perche'
	 * la composizione in `wp_headers` non basta: un altro componente che su
	 * `send_headers` chiama `header()` con lo stesso nome sostituisce la riga
	 * che WordPress ha appena mandato, divieto compreso. Questo aggancio passa
	 * dopo di lui, alla priorita' piu' alta, e aggiunge senza togliere. Riga
	 * C-239.
	 *
	 * La richiesta si valuta di nuovo qui e non si ricorda da `wp_headers`: e'
	 * la stessa interrogazione, e il divieto non dipende dall'essere passati
	 * da un altro aggancio.
	 *
	 * @internal Aggancio di `send_headers`.
	 */
	public static function emetti_divieto() {
		$specifiche       = self::$specifiche;
		self::$specifiche = array();

		if ( ! self::richiesta_vietata() ) {
			return;
		}

		foreach ( $specifiche as $valore ) {
			Conformita_Core_Intestazioni::manda( 'X-Robots-Tag: ' . $valore, false );
		}

		Conformita_Core_Intestazioni::manda( 'X-Robots-Tag: noindex', false );
	}

	/**
	 * Le direttive per un motore specifico messe da parte per la richiesta in
	 * corso.
	 *
	 * @internal Serve alla riga C-233.
	 *
	 * @return array<int, string>
	 */
	public static function specifiche_in_attesa() {
		return self::$specifiche;
	}

	/**
	 * Aggiunge il divieto generale a `X-Robots-Tag`, senza cancellare cio' che
	 * c'era.
	 *
	 * Usata anche dal punto di consegna degli allegati, che serve i file prima
	 * che WordPress prepari le intestazioni della pagina. Riga C-228.
	 *
	 * @param array<string, string> $intestazioni Intestazioni.
	 * @return array<string, string>
	 */
	public static function aggiungi_divieto( array $intestazioni ) {
		return self::componi_divieto( $intestazioni )['intestazioni'];
	}

	/**
	 * Compone `X-Robots-Tag` con il divieto generale.
	 *
	 * Il nome dell'intestazione si riconosce senza badare a maiuscole e
	 * minuscole, e ne resta uno solo. Il valore generale gia' presente si
	 * conserva, e `noindex` si aggiunge se non c'e' gia' come direttiva a se'
	 * (anche `none` lo contiene). Un valore che nomina un motore in qualunque
	 * punto, come `googlebot: nofollow` ma anche `nofollow, googlebot: nofollow`,
	 * non si tocca: dopo il nome del motore le direttive valgono per quel
	 * motore soltanto, quindi un `noindex` aggiunto in coda non varrebbe per
	 * gli altri, e un `noindex` gia' presente in coda non e' un divieto
	 * generale. Il valore si restituisce a parte, intero, e il divieto
	 * generale lo porta la riga propria. Righe C-233 e C-239.
	 *
	 * @param array<string, string> $intestazioni Intestazioni.
	 * @return array{intestazioni: array<string, string>, specifiche: array<int, string>}
	 */
	private static function componi_divieto( array $intestazioni ) {
		$generali   = array();
		$specifiche = array();

		foreach ( $intestazioni as $nome => $valore ) {
			if ( ! is_string( $nome ) || 0 !== strcasecmp( $nome, 'X-Robots-Tag' ) ) {
				continue;
			}

			unset( $intestazioni[ $nome ] );

			$valore = trim( (string) $valore );

			if ( '' === $valore ) {
				continue;
			}

			if ( self::rivolto_a_un_motore( $valore ) ) {
				$specifiche[] = $valore;
			} else {
				$generali[] = $valore;
			}
		}

		$valore = implode( ', ', $generali );

		if ( ! self::contiene_divieto( $valore ) ) {
			$valore = '' === $valore ? 'noindex' : $valore . ', noindex';
		}

		$intestazioni['X-Robots-Tag'] = $valore;

		return array(
			'intestazioni' => $intestazioni,
			'specifiche'   => $specifiche,
		);
	}

	/**
	 * Il valore nomina un motore in qualunque punto: una delle sue parti,
	 * separate dalle virgole, comincia con un nome seguito dai due punti.
	 *
	 * Le direttive generali che hanno un valore dopo i due punti non sono nomi
	 * di motori, e restano generali. Nel dubbio il valore conta come rivolto a
	 * un motore: costa soltanto una riga in piu', perche' il divieto generale
	 * esce comunque su una riga propria.
	 *
	 * @param string $valore Valore di `X-Robots-Tag`.
	 * @return bool
	 */
	private static function rivolto_a_un_motore( $valore ) {
		$direttive_con_valore = array( 'max-snippet', 'max-image-preview', 'max-video-preview', 'unavailable_after' );

		foreach ( explode( ',', $valore ) as $parte ) {
			if ( preg_match( '/^\s*([A-Za-z0-9_.-]+)\s*:/', $parte, $parti )
				&& ! in_array( strtolower( $parti[1] ), $direttive_con_valore, true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Il valore generale contiene gia' una direttiva che vieta l'indicizzazione.
	 *
	 * @param string $valore Valore generale di `X-Robots-Tag`.
	 * @return bool
	 */
	private static function contiene_divieto( $valore ) {
		foreach ( explode( ',', $valore ) as $direttiva ) {
			if ( in_array( strtolower( trim( $direttiva ) ), array( 'noindex', 'none' ), true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * I tipi delle sezioni vietate escono dalla mappa del sito.
	 *
	 * Si toglie il tipo intero e non i singoli contenuti: un tipo appartiene a
	 * una sezione sola, quindi tutti i suoi contenuti hanno la stessa politica.
	 * Togliendo il tipo scompare anche la sua pagina nell'indice della mappa,
	 * che altrimenti resterebbe come elenco vuoto. Riga C-82.
	 *
	 * @internal Aggancio di `wp_sitemaps_post_types`.
	 *
	 * @param mixed $tipi Tipi elencati nella mappa, per identificativo.
	 * @return mixed Tipi senza quelli vietati.
	 */
	public static function filtra_tipi_mappa( $tipi ) {
		if ( ! is_array( $tipi ) ) {
			return $tipi;
		}

		foreach ( array_keys( $tipi ) as $tipo ) {
			if ( self::tipo_vietato( $tipo ) ) {
				unset( $tipi[ $tipo ] );
			}
		}

		return $tipi;
	}

	/**
	 * Le interrogazioni della mappa non leggono i tipi vietati.
	 *
	 * Prima difesa sulla mappa allargata, riga C-238. Il filtro sui contenuti
	 * restituiti non basta da solo: un componente che chiede la lettura con
	 * `suppress_filters` spegne `the_posts`, mentre `pre_get_posts` passa
	 * sempre. Si tolgono i tipi vietati dall'elenco dei tipi letti, anche
	 * quando l'elenco e' `any` o manca con una tassonomia, che WordPress
	 * allarga a tutti i tipi ricercabili. Se non resta nessun tipo,
	 * l'interrogazione non restituisce niente: un elenco di tipi vuoto,
	 * per WordPress, vorrebbe dire gli articoli. Se fra i tipi letti ci sono
	 * gli allegati, si escludono quelli appesi a un contenuto vietato. Stessi
	 * confini del filtro sui contenuti: solo durante la mappa e solo sulle
	 * interrogazioni secondarie.
	 *
	 * **Limite dichiarato.** Un componente che interviene su `pre_get_posts`
	 * dopo questo aggancio, o che scrive l'elenco degli indirizzi da se' con
	 * `wp_sitemaps_posts_pre_url_list`, resta fuori dalla sua portata; il
	 * primo caso lo ripara ancora `the_posts`, se non e' spento.
	 *
	 * @internal Aggancio di `pre_get_posts`.
	 *
	 * @param mixed $interrogazione Interrogazione in preparazione.
	 */
	public static function restringi_mappa( $interrogazione ) {
		if ( ! $interrogazione instanceof WP_Query || $interrogazione->is_main_query() ) {
			return;
		}

		if ( '' === (string) get_query_var( 'sitemap' ) ) {
			return;
		}

		$vietati = array_values( array_filter( Conformita_Core_Tipi::identificativi(), array( __CLASS__, 'tipo_vietato' ) ) );

		if ( empty( $vietati ) ) {
			return;
		}

		$tipi = $interrogazione->get( 'post_type' );

		if ( 'any' === $tipi || ( empty( $tipi ) && ! empty( $interrogazione->get( 'tax_query' ) ) ) ) {
			$tipi = array_values( get_post_types( array( 'exclude_from_search' => false ) ) );
		}

		if ( empty( $tipi ) ) {
			return;
		}

		$tipi    = (array) $tipi;
		$rimasti = array_values( array_diff( $tipi, $vietati ) );

		if ( empty( $rimasti ) ) {
			$interrogazione->set( 'post__in', array( 0 ) );

			return;
		}

		if ( $rimasti !== $tipi ) {
			$interrogazione->set( 'post_type', $rimasti );
		}

		if ( in_array( 'attachment', $rimasti, true ) ) {
			$padri = self::contenuti_dei_tipi( $vietati );

			if ( ! empty( $padri ) ) {
				$interrogazione->set( 'post_parent__not_in', array_merge( (array) $interrogazione->get( 'post_parent__not_in' ), $padri ) );
			}
		}
	}

	/**
	 * Gli identificativi dei contenuti dei tipi indicati, in ogni stato.
	 *
	 * Si legge la tabella direttamente e non con `get_posts()`: questa lettura
	 * avviene dentro `pre_get_posts`, e una nuova interrogazione ripasserebbe
	 * da qui.
	 *
	 * @param array<int, string> $tipi Tipi di contenuto.
	 * @return array<int, int>
	 */
	private static function contenuti_dei_tipi( array $tipi ) {
		global $wpdb;

		$segnaposto = implode( ', ', array_fill( 0, count( $tipi ), '%s' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- lettura dentro pre_get_posts, vedi sopra; i segnaposto sono costruiti qui e i valori passano da prepare().
		$identificativi = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type IN ( {$segnaposto} )", $tipi ) );

		return array_map( 'intval', $identificativi );
	}

	/**
	 * Nessun contenuto vietato nelle pagine della mappa degli altri tipi.
	 *
	 * Togliere il tipo dalla mappa non basta se un tema o un componente
	 * allarga le interrogazioni di un altro tipo, per esempio degli articoli,
	 * ai tipi di una sezione vietata: la mappa degli articoli elencherebbe
	 * anche gli atti. E' lo stesso caso degli elenchi misti della riga C-232,
	 * sul percorso della mappa. Si agisce soltanto mentre WordPress costruisce
	 * la mappa, riconoscibile dalla variabile `sitemap` della richiesta, e
	 * soltanto sulle interrogazioni secondarie, che sono quelle con cui la
	 * mappa legge i contenuti. Riga C-238. E' la seconda difesa: la prima e'
	 * `restringi_mappa()`, che vale anche con `suppress_filters`.
	 *
	 * @internal Aggancio di `the_posts`.
	 *
	 * @param mixed $contenuti      Contenuti restituiti dall'interrogazione.
	 * @param mixed $interrogazione Interrogazione.
	 * @return mixed Contenuti senza quelli vietati, durante la mappa.
	 */
	public static function filtra_contenuti_mappa( $contenuti, $interrogazione = null ) {
		if ( ! is_array( $contenuti ) || ! $interrogazione instanceof WP_Query || $interrogazione->is_main_query() ) {
			return $contenuti;
		}

		if ( '' === (string) get_query_var( 'sitemap' ) ) {
			return $contenuti;
		}

		$rimasti = array();

		foreach ( $contenuti as $contenuto ) {
			if ( ! self::contenuto_vietato( $contenuto ) ) {
				$rimasti[] = $contenuto;
			}
		}

		return $rimasti;
	}
}
