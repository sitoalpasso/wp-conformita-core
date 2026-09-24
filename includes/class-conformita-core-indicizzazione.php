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
	 * Alta di proposito: il divieto deve essere l'ultima parola sui contenuti di
	 * una sezione `vietata`, anche se un altro componente, prima, ha scritto il
	 * contrario. Sugli altri contenuti gli agganci restituiscono quello che
	 * ricevono, quindi arrivare per ultimi non toglie niente a nessuno.
	 *
	 * @var int
	 */
	const PRIORITA = 9999;

	/**
	 * Il meccanismo ha gia' agganciato i propri filtri.
	 *
	 * @var bool
	 */
	private static $avviato = false;

	/**
	 * Aggancia i filtri del meccanismo.
	 *
	 * @internal Non fa parte dell'interfaccia pubblica verso i componenti. Si
	 *           accende al caricamento del file di core, come il motore di
	 *           scadenza e per la stessa ragione: un meccanismo di conformita'
	 *           che dipende dall'ordine di caricamento dei plugin non e' un
	 *           meccanismo di conformita'.
	 *
	 * Idempotente. `$avviato` e' la condizione che `Conformita_Core_Sezioni::registra()`
	 * legge per rifiutare una sezione a meccanismo spento: una sezione `vietata`
	 * senza nessuno ad applicare il divieto avrebbe pagine indicizzabili, e il
	 * difetto non si vedrebbe guardando la pagina. Riga C-85.
	 */
	public static function avvia() {
		if ( self::$avviato ) {
			return;
		}

		self::$avviato = true;

		foreach ( self::agganci() as $aggancio ) {
			add_filter( $aggancio['aggancio'], array( __CLASS__, $aggancio['metodo'] ), self::PRIORITA, $aggancio['argomenti'] );
		}
	}

	/**
	 * L'elenco degli agganci, in un posto solo.
	 *
	 * @internal Letto sia dall'accensione sia dallo spegnimento, perche' i due
	 *           non possano dire cose diverse: e' la lezione dello spegnimento
	 *           incompleto del motore di scadenza. Riga C-230.
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
				'aggancio'  => 'wp_sitemaps_post_types',
				'metodo'    => 'filtra_tipi_mappa',
				'argomenti' => 1,
			),
		);
	}

	/**
	 * Il meccanismo ha agganciato i propri filtri.
	 *
	 * @return bool
	 */
	public static function avviato() {
		return self::$avviato;
	}

	/**
	 * Sgancia i filtri del meccanismo.
	 *
	 * @internal Serve alla suite di test, per la riga C-85 e per la C-230.
	 */
	public static function azzera_avvio() {
		if ( ! self::$avviato ) {
			return;
		}

		foreach ( self::agganci() as $aggancio ) {
			remove_filter( $aggancio['aggancio'], array( __CLASS__, $aggancio['metodo'] ), self::PRIORITA );
		}

		self::$avviato = false;
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
		$contenuto = get_post( $contenuto );

		if ( ! $contenuto instanceof WP_Post ) {
			return false;
		}

		if ( 'attachment' === $contenuto->post_type ) {
			$padre = (int) $contenuto->post_parent;

			return $padre > 0 && self::contenuto_vietato( get_post( $padre ) );
		}

		return self::tipo_vietato( $contenuto->post_type );
	}

	/**
	 * La richiesta in corso mostra contenuti di una sezione che vieta
	 * l'indicizzazione.
	 *
	 * Tre casi: la pagina di un contenuto (compresa la pagina di un suo allegato
	 * e il feed dei commenti di quel contenuto), l'elenco di un tipo, e il feed
	 * di un tipo. Quando una richiesta riguarda piu' tipi insieme e anche uno
	 * solo e' vietato, vince il divieto: l'elenco espone comunque i titoli dei
	 * contenuti vietati, mentre le pagine dei contenuti consentiti restano
	 * indicizzabili ciascuna per conto proprio. Righe C-80, C-225, C-226.
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
	 * @internal Aggancio di `wp_headers`.
	 *
	 * @param mixed $intestazioni Intestazioni raccolte finora.
	 * @return mixed Intestazioni, con `X-Robots-Tag: noindex` sulle pagine vietate.
	 */
	public static function filtra_intestazioni( $intestazioni ) {
		if ( ! is_array( $intestazioni ) || ! self::richiesta_vietata() ) {
			return $intestazioni;
		}

		return self::aggiungi_divieto( $intestazioni );
	}

	/**
	 * Aggiunge `noindex` a `X-Robots-Tag`, senza cancellare cio' che c'era.
	 *
	 * Usata anche dal punto di consegna degli allegati, che serve i file prima
	 * che WordPress prepari le intestazioni della pagina. Riga C-228.
	 *
	 * @param array<string, string> $intestazioni Intestazioni.
	 * @return array<string, string>
	 */
	public static function aggiungi_divieto( array $intestazioni ) {
		$presente = isset( $intestazioni['X-Robots-Tag'] ) ? trim( (string) $intestazioni['X-Robots-Tag'] ) : '';

		if ( '' === $presente ) {
			$intestazioni['X-Robots-Tag'] = 'noindex';
		} elseif ( ! preg_match( '/(^|[\s,:])noindex([\s,]|$)/i', $presente ) ) {
			$intestazioni['X-Robots-Tag'] = $presente . ', noindex';
		}

		return $intestazioni;
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
}
