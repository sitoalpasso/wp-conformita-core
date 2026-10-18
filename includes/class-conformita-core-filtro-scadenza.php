<?php
/**
 * Il filtro che applica la scadenza sui percorsi di lettura.
 *
 * La classe `Conformita_Core_Scadenza` dice **quando** un contenuto è scaduto.
 * Questa dice **dove** quella decisione viene applicata, e lo fa in due strati
 * che rispondono a due domande diverse.
 *
 * **Perché due strati e non uno.** Il primo strato aggiunge una condizione
 * all'interrogazione della banca dati: è veloce, tiene i conteggi e
 * l'impaginazione coerenti, e non porta a casa righe che poi vanno buttate. Ma
 * confronta stringhe, e una data corrotta che ordina alta (`9999-99-99`) la
 * supererebbe. Il secondo strato ricontrolla ogni contenuto restituito con la
 * stessa funzione che decide la scadenza altrove, e **è lo strato che fa
 * fede**: se i due strati fossero in disaccordo, vince il secondo.
 *
 * **Il primo strato si aggiunge solo quando l'interrogazione riguarda
 * esclusivamente tipi gestiti.** Aggiungere la condizione sui metadati a
 * un'interrogazione che comprende anche i tipi nativi cancellerebbe dal sito
 * tutti gli articoli, che quel metadato non ce l'hanno: sarebbe un difetto
 * catastrofico e non un eccesso di prudenza. Nelle interrogazioni miste lavora
 * il secondo strato, che guarda un contenuto alla volta e tocca solo quelli dei
 * tipi registrati. Riga di collaudo C-90.
 *
 * **L'esenzione è legata alla superficie, non all'utente.** Non c'è nessun
 * controllo sulle capability qui dentro. Un utente autorizzato che naviga il
 * sito pubblico vede quello che vede chiunque altro: le capability governano la
 * gestione nell'amministrazione, non fanno ricomparire un contenuto scaduto in
 * un elenco, in un feed o nella mappa per i motori di ricerca. Riga C-92.
 *
 * **La scadenza non è la visibilità editoriale.** Il filtro agisce solo sui
 * contenuti pubblicati. Bozze, contenuti privati e revisioni li governa
 * WordPress con i suoi stati, e i due meccanismi non devono interferire: un
 * contenuto in bozza non ha ancora una data di fine, e trattarlo come scaduto
 * lo farebbe sparire dagli strumenti di chi lo sta scrivendo. Riga C-89.
 *
 * **Due limiti dichiarati, entrambi collaudati.** `WP_Query` con
 * `suppress_filters` non esegue `the_posts`, e `WP_Query` con
 * `fields => 'ids'` restituisce le colonne e torna prima di applicarlo: in
 * entrambi i casi resta il solo primo strato, quindi un valore corrotto che
 * ordina alto passerebbe. Il primo strato agisce comunque, perché
 * `pre_get_posts` viene emesso in ogni caso. I limiti si documentano e si
 * collaudano, così che un cambiamento di WordPress si veda nella suite e non
 * sul sito di qualcun altro. Righe C-93 e C-102.
 *
 * **Che cosa resta scoperto e non è coperto da nessuno**: `get_post()` sul
 * singolo identificativo, `get_comment()` sul singolo commento, le letture dei
 * commenti che chiedono solo il conteggio o solo gli identificativi, le
 * interrogazioni SQL dirette, la lettura diretta dei metadati e le pagine
 * servite da una memoria che risponde prima di WordPress.
 * Il controllo non avviene ogni volta che qualcuno chiede il contenuto: avviene
 * ogni volta che la richiesta arriva a WordPress.
 *
 * **L'impaginazione.** Il conteggio dei risultati viene dalla banca dati, quindi
 * riflette il primo strato. Quando il secondo toglie qualcosa che il primo aveva
 * lasciato passare, cioè solo in presenza di un valore corrotto, la pagina
 * mostra un elemento in meno del conteggio. Si preferisce un conteggio
 * impreciso a un contenuto scaduto visibile.
 *
 * Righe di collaudo C-10, C-11, C-12, C-13, C-14, C-21, C-22, C-89, C-90,
 * C-92, C-93, C-97, C-98, C-101, C-102, C-103, C-104 e C-259..C-275.
 *
 * @package Conformita_Core
 */

defined( 'ABSPATH' ) || exit;

/**
 * Applicazione della scadenza sui percorsi di lettura.
 */
final class Conformita_Core_Filtro_Scadenza {

	/**
	 * Il motore ha già agganciato i propri filtri.
	 *
	 * @var bool
	 */
	private static $avviato = false;

	/**
	 * Il filtro è sospeso per una lettura fatta da core stesso.
	 *
	 * Serve solo a `filtra_reindirizzamento()`, che deve sapere a quale
	 * contenuto porta un indirizzo anche quando quel contenuto è scaduto: con
	 * il filtro acceso la risposta sarebbe "a nessuno". Si accende e si spegne
	 * nella stessa funzione.
	 *
	 * @var bool
	 */
	private static $sospeso = false;

	/**
	 * Aggancia i filtri del motore.
	 *
	 * @internal Non fa parte dell'interfaccia pubblica verso i componenti. Il
	 *           motore è infrastruttura di core e si accende al caricamento del
	 *           file di core: accenderlo non è responsabilità di chi lo usa.
	 *
	 * Idempotente. La guardia **non** serve a impedire che WordPress esegua due
	 * volte lo stesso filtro: WordPress identifica ogni aggancio con una chiave
	 * univoca, e per un metodo statico quella chiave è deterministica, quindi
	 * riagganciare lo stesso metodo alla stessa priorità sostituisce la
	 * registrazione invece di aggiungerne una seconda. Togliere la guardia
	 * lascerebbe il conteggio degli agganci dov'è.
	 *
	 * La guardia serve per due ragioni vere. La prima è che `$avviato` è la
	 * condizione che `Conformita_Core_Sezioni::registra()` legge per rifiutare
	 * una sezione a motore spento, e quel rifiuto è l'unica cosa che impedisce
	 * a una sezione di esistere con una politica di scadenza e nessuno ad
	 * applicarla: senza la guardia quello stato non esisterebbe. La seconda è che
	 * la deduplicazione di WordPress vale per i metodi statici e non per le
	 * chiusure né per i metodi di un'istanza, che ogni volta producono una chiave
	 * diversa: se un domani uno di questi agganci diventasse una chiusura, la
	 * guardia è ciò che impedisce al filtro di girare due volte a ogni lettura.
	 *
	 * Riga C-98, che verifica lo stato osservabile degli agganci, non
	 * l'indispensabilità della guardia.
	 */
	public static function avvia() {
		if ( self::$avviato ) {
			return;
		}

		self::$avviato = true;

		foreach ( self::agganci() as $aggancio ) {
			add_filter( $aggancio['aggancio'], array( __CLASS__, $aggancio['metodo'] ), self::priorita( $aggancio ), $aggancio['argomenti'] );
		}
	}

	/**
	 * L'elenco degli agganci del motore, in un posto solo.
	 *
	 * @internal L'elenco è uno perché accensione e spegnimento devono per forza
	 *           dire la stessa cosa. Nella prima stesura erano due elenchi
	 *           scritti a mano: l'accensione ne registrava otto e lo spegnimento
	 *           ne toglieva tre, quindi `azzera_avvio()` dichiarava il motore
	 *           spento mentre cinque agganci continuavano a girare. Il difetto
	 *           non poteva produrre nessun errore, perché uno spegnimento
	 *           incompleto non fallisce: fa semplicemente una cosa diversa da
	 *           quella che dice. Con un elenco solo quel disallineamento non è
	 *           più esprimibile.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function agganci() {
		return array(
			array(
				'aggancio'  => 'pre_get_posts',
				'metodo'    => 'filtra_interrogazione',
				'argomenti' => 1,
			),
			array(
				'aggancio'  => 'the_posts',
				'metodo'    => 'filtra_risultati',
				'argomenti' => 2,
			),
			array(
				'aggancio'  => 'wp_sitemaps_posts_query_args',
				'metodo'    => 'filtra_argomenti_mappa',
				'argomenti' => 2,
			),
			array(
				'aggancio'  => 'rest_request_before_callbacks',
				'metodo'    => 'filtra_richiesta_rest',
				'argomenti' => 3,
			),
			array(
				'aggancio'  => 'rest_post_dispatch',
				'metodo'    => 'normalizza_risposta_rest',
				'argomenti' => 3,
				// Dopo rest_send_allow_header(), che WordPress aggancia a 10.
				'priorita'  => 11,
			),
			array(
				'aggancio'  => 'redirect_canonical',
				'metodo'    => 'filtra_reindirizzamento',
				'argomenti' => 2,
			),
			array(
				'aggancio'  => 'old_slug_redirect_post_id',
				'metodo'    => 'filtra_vecchio_nome',
				'argomenti' => 1,
			),
			array(
				'aggancio'  => 'rest_comment_query',
				'metodo'    => 'filtra_commenti_rest',
				'argomenti' => 1,
			),
			array(
				'aggancio'  => 'oembed_response_data',
				'metodo'    => 'filtra_anteprima_incorporata',
				'argomenti' => 2,
			),
			array(
				'aggancio'  => 'xmlrpc_prepare_post',
				'metodo'    => 'filtra_dato_xmlrpc',
				'argomenti' => 2,
			),
			array(
				'aggancio'  => 'xmlrpc_prepare_media_item',
				'metodo'    => 'filtra_dato_xmlrpc',
				'argomenti' => 2,
			),
			array(
				'aggancio'  => 'xmlrpc_prepare_comment',
				'metodo'    => 'filtra_commento_xmlrpc',
				'argomenti' => 2,
			),
			array(
				'aggancio'  => 'the_comments',
				'metodo'    => 'filtra_commenti',
				'argomenti' => 1,
			),
			array(
				'aggancio'  => 'get_previous_post_where',
				'metodo'    => 'filtra_vicino',
				'argomenti' => 5,
			),
			array(
				'aggancio'  => 'get_next_post_where',
				'metodo'    => 'filtra_vicino',
				'argomenti' => 5,
			),
		);
	}

	/**
	 * La priorità di un aggancio dell'elenco: 10, salvo dove l'elenco dice
	 * altrimenti.
	 *
	 * @param array<string, mixed> $aggancio Voce di `agganci()`.
	 * @return int
	 */
	private static function priorita( array $aggancio ) {
		return isset( $aggancio['priorita'] ) ? (int) $aggancio['priorita'] : 10;
	}

	/**
	 * Il motore è agganciato.
	 *
	 * @return bool
	 */
	public static function avviato() {
		return self::$avviato;
	}

	/**
	 * Sgancia i filtri e riporta il motore allo stato iniziale.
	 *
	 * @internal Solo per le prove. Serve a collaudare la guardia difensiva della
	 *           riga C-94, cioè il caso in cui il file del motore non venga
	 *           caricato: in esercizio non si verifica, perché il motore si
	 *           accende al caricamento di core.
	 */
	public static function azzera_avvio() {
		if ( ! self::$avviato ) {
			return;
		}

		foreach ( self::agganci() as $aggancio ) {
			remove_filter( $aggancio['aggancio'], array( __CLASS__, $aggancio['metodo'] ), self::priorita( $aggancio ) );
		}

		self::$avviato = false;
	}

	/**
	 * La data civile di oggi nel fuso del sito, nel formato del metadato.
	 *
	 * Un contenuto è visibile finché la sua data di fine non è anteriore a
	 * oggi, perché la data di fine è inclusiva: con fine 2026-09-09 il contenuto
	 * resta pubblico per tutto il 9 e scade alla mezzanotte del 10. Il confronto
	 * per giorno civile equivale al confronto per istante di
	 * `Conformita_Core_Scadenza::scaduto()`, ma si può esprimere in SQL.
	 *
	 * @return string Data nel formato AAAA-MM-GG.
	 */
	private static function oggi() {
		return Conformita_Core_Scadenza::giorno_corrente();
	}

	/**
	 * La superficie corrente è pubblica.
	 *
	 * L'amministrazione è l'unica superficie esente, e l'esenzione dipende dalla
	 * superficie e non da chi la usa. Le richieste asincrone passate da
	 * `admin-ajax.php` non contano come amministrazione: servono anche pagine
	 * pubbliche, e considerarle amministrazione aprirebbe un percorso in cui i
	 * contenuti scaduti tornano visibili a chiunque.
	 *
	 * L'interfaccia informatica pubblica (REST) **non** è amministrazione: la
	 * riga C-15 pretende che il contenuto scaduto sia assente dalla collezione.
	 *
	 * @return bool
	 */
	private static function superficie_pubblica() {
		if ( self::$sospeso ) {
			return false;
		}

		return ! ( is_admin() && ! wp_doing_ajax() );
	}

	/**
	 * I tipi che l'interrogazione riguarda, se sono tutti gestiti da core.
	 *
	 * @param WP_Query $interrogazione Interrogazione in corso.
	 * @return array<int, string>|null Elenco dei tipi, oppure null se
	 *                                 l'interrogazione riguarda anche altro.
	 */
	private static function tipi_gestiti_soltanto( WP_Query $interrogazione ) {
		$tipo = $interrogazione->get( 'post_type' );

		if ( is_string( $tipo ) ) {
			if ( '' === $tipo || 'any' === $tipo ) {
				return null;
			}

			$tipo = array( $tipo );
		}

		if ( ! is_array( $tipo ) || empty( $tipo ) ) {
			return null;
		}

		foreach ( $tipo as $singolo ) {
			if ( ! is_string( $singolo ) || ! Conformita_Core_Tipi::registrato( $singolo ) ) {
				return null;
			}
		}

		return array_values( $tipo );
	}

	/**
	 * L'interrogazione riguarda soltanto contenuti pubblicati.
	 *
	 * La condizione sui metadati vale per la scadenza, che è un fatto dei
	 * contenuti pubblicati. Se l'interrogazione chiede altri stati, il primo
	 * strato si tira indietro e il lavoro resta al secondo, che distingue un
	 * contenuto alla volta. Riga C-89.
	 *
	 * @param WP_Query $interrogazione Interrogazione in corso.
	 * @return bool
	 */
	private static function soltanto_pubblicati( WP_Query $interrogazione ) {
		$stato = $interrogazione->get( 'post_status' );

		if ( '' === $stato || array() === $stato ) {
			return true;
		}

		$stato = (array) $stato;

		return array( 'publish' ) === array_values( array_unique( $stato ) );
	}

	/**
	 * La clausola sui metadati che tiene fuori i contenuti scaduti.
	 *
	 * Un contenuto senza il metadato non entra nel confronto e resta fuori dai
	 * risultati: è la direzione sicura, la stessa di
	 * `Conformita_Core_Scadenza::scaduto()`.
	 *
	 * Il confronto è fra stringhe e non fra date: il formato è a lunghezza fissa
	 * con le cifre riempite di zeri, quindi l'ordine lessicografico coincide con
	 * l'ordine cronologico, e non serve nessuna conversione. Un valore corrotto
	 * che ordina alto supererebbe questo confronto: lo ferma il secondo strato.
	 *
	 * @return array<string, string> Clausola per `meta_query`.
	 */
	private static function clausola() {
		return array(
			'key'     => Conformita_Core_Scadenza::chiave(),
			'value'   => self::oggi(),
			'compare' => '>=',
			'type'    => 'CHAR',
		);
	}

	/**
	 * La clausola di core è già presente in una dichiarazione di `meta_query`.
	 *
	 * Serve perché la mappa per i motori di ricerca passa da due agganci: gli
	 * argomenti dell'interrogazione e poi l'interrogazione stessa. Aggiungere la
	 * clausola due volte non cambierebbe l'esito, ma lascerebbe una condizione
	 * duplicata che rende illeggibile il debug.
	 *
	 * **Il riconoscimento è sulla clausola intera, non sulla chiave.** La prima
	 * stesura si accontentava di trovare la chiave del metadato, e quella era una
	 * falla: un componente di terzi che interroga la stessa chiave, per esempio
	 * con `EXISTS` per elencare i contenuti che hanno una data di fine, sarebbe
	 * stato scambiato per la clausola di core, e core avrebbe creduto di aver già
	 * aggiunto il confronto con la data senza averlo fatto. Nelle interrogazioni
	 * normali il secondo strato avrebbe coperto il difetto; con
	 * `fields => 'ids'` o con `suppress_filters`, dove il secondo strato non
	 * gira, il contenuto scaduto sarebbe uscito davvero. Riga di collaudo C-104.
	 *
	 * Il confronto pretende le stesse quattro voci con gli stessi valori, e anche
	 * lo stesso numero di voci: una condizione di terzi che aggiungesse una
	 * chiave in più non è la nostra, e trattarla come tale riaprirebbe la stessa
	 * falla da un'altra porta.
	 *
	 * @param mixed $meta Dichiarazione di `meta_query`, a qualsiasi profondità.
	 * @return bool
	 */
	private static function clausola_presente( $meta ) {
		if ( ! is_array( $meta ) ) {
			return false;
		}

		if ( self::e_clausola_di_core( $meta ) ) {
			return true;
		}

		foreach ( $meta as $voce ) {
			if ( self::clausola_presente( $voce ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * La voce è esattamente la clausola che core aggiunge.
	 *
	 * @param array $voce Una voce di `meta_query`.
	 * @return bool
	 */
	private static function e_clausola_di_core( array $voce ) {
		$nostra = self::clausola();

		if ( count( $voce ) !== count( $nostra ) ) {
			return false;
		}

		foreach ( $nostra as $chiave => $valore ) {
			if ( ! isset( $voce[ $chiave ] ) || $voce[ $chiave ] !== $valore ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Aggiunge la clausola a una dichiarazione di `meta_query` esistente.
	 *
	 * La dichiarazione esistente viene annidata come sottogruppo invece di
	 * essere allungata: se contenesse `'relation' => 'OR'`, allungarla metterebbe
	 * la nostra condizione in alternativa alle altre invece che in aggiunta, e il
	 * filtro sarebbe aggirabile da qualunque componente che usi una `meta_query`
	 * in OR.
	 *
	 * @param mixed $esistente Dichiarazione esistente, se c'è.
	 * @return array Dichiarazione con la clausola aggiunta.
	 */
	private static function aggiungi_clausola( $esistente ) {
		if ( ! is_array( $esistente ) || empty( $esistente ) ) {
			return array( self::clausola() );
		}

		if ( self::clausola_presente( $esistente ) ) {
			return $esistente;
		}

		return array(
			'relation' => 'AND',
			$esistente,
			self::clausola(),
		);
	}

	/**
	 * Primo strato: la condizione sui metadati nell'interrogazione.
	 *
	 * @internal Aggancio di `pre_get_posts`.
	 *
	 * @param WP_Query $interrogazione Interrogazione in corso, per riferimento.
	 */
	public static function filtra_interrogazione( $interrogazione ) {
		if ( ! $interrogazione instanceof WP_Query ) {
			return;
		}

		if ( ! self::superficie_pubblica() ) {
			return;
		}

		if ( null === self::tipi_gestiti_soltanto( $interrogazione ) ) {
			return;
		}

		if ( ! self::soltanto_pubblicati( $interrogazione ) ) {
			return;
		}

		$interrogazione->set( 'meta_query', self::aggiungi_clausola( $interrogazione->get( 'meta_query' ) ) );
	}

	/**
	 * Secondo strato: il ricontrollo di ogni contenuto restituito.
	 *
	 * È lo strato che fa fede. Guarda un contenuto alla volta, quindi funziona
	 * anche sulle interrogazioni miste, dove il primo strato non può agire, e
	 * usa la stessa funzione che decide la scadenza ovunque, quindi non può
	 * essere in disaccordo con il resto del componente.
	 *
	 * **Gli allegati li guarda sul padre.** Un allegato non ha una data di fine
	 * propria, e dargliene una sarebbe un secondo dato da tenere allineato al
	 * primo, cioè un secondo posto dove sbagliare. Togliendolo dai risultati
	 * sparisce anche la pagina che WordPress genera per lui, che altrimenti
	 * continuerebbe a mostrare titolo e descrizione di un allegato a un atto non
	 * più pubblicabile. Riguarda la pagina e non il file: servire il file da un
	 * indirizzo protetto è l'unità della consegna allegati, e finché non c'è, il
	 * file resta scaricabile dal suo indirizzo diretto. Riga C-19.
	 *
	 * **Nei feed dei commenti toglie anche i commenti.** WordPress li legge con
	 * un'interrogazione propria, prima dei contenuti per il feed generale e
	 * dopo per quello di un contenuto solo, ma in tutti e due i casi prima di
	 * questo aggancio: e' il primo punto in cui sono tutti e due sul tavolo.
	 * Riga C-265.
	 *
	 * @internal Aggancio di `the_posts`.
	 *
	 * @param mixed $contenuti      Contenuti restituiti dall'interrogazione.
	 * @param mixed $interrogazione Interrogazione in corso.
	 * @return mixed Contenuti senza quelli scaduti.
	 */
	public static function filtra_risultati( $contenuti, $interrogazione = null ) {
		if ( ! self::superficie_pubblica() ) {
			return $contenuti;
		}

		if ( $interrogazione instanceof WP_Query && is_array( $interrogazione->comments ) && ! empty( $interrogazione->comments ) ) {
			$interrogazione->comments      = self::filtra_commenti( $interrogazione->comments );
			$interrogazione->comment_count = count( $interrogazione->comments );
		}

		if ( ! is_array( $contenuti ) || empty( $contenuti ) ) {
			return $contenuti;
		}

		$rimasti = array();

		foreach ( $contenuti as $contenuto ) {
			if ( ! is_object( $contenuto ) || ! isset( $contenuto->post_type, $contenuto->post_status, $contenuto->ID ) ) {
				$rimasti[] = $contenuto;
				continue;
			}

			/*
			 * L'allegato si riconosce con lo stesso criterio dei tipi gestiti,
			 * quello della banca dati: un allegato salvato con il tipo in
			 * maiuscolo è trovato dalle ricerche degli allegati, e deve seguire
			 * il padre come gli altri. Riga C-250.
			 */
			if ( Conformita_Core_Tipi::uguale( $contenuto->post_type, 'attachment' ) ) {
				if ( ! self::padre_da_nascondere( (int) $contenuto->post_parent ) ) {
					$rimasti[] = $contenuto;
				}

				continue;
			}

			if ( ! Conformita_Core_Tipi::registrato( $contenuto->post_type ) ) {
				$rimasti[] = $contenuto;
				continue;
			}

			if ( 'publish' !== $contenuto->post_status ) {
				$rimasti[] = $contenuto;
				continue;
			}

			if ( Conformita_Core_Scadenza::scaduto( (int) $contenuto->ID ) ) {
				continue;
			}

			$rimasti[] = $contenuto;
		}

		return array_values( $rimasti );
	}

	/**
	 * La mappa per i motori di ricerca non elenca contenuti scaduti.
	 *
	 * L'interrogazione della mappa passa comunque da `pre_get_posts`, quindi il
	 * primo strato agirebbe lo stesso. L'aggancio dedicato resta perché rende
	 * esplicito che la mappa è coperta, e perché una versione futura di WordPress
	 * che costruisse l'elenco senza `WP_Query` lascerebbe scoperto un percorso
	 * indicizzabile dai motori: qui il difetto sarebbe silenzioso e permanente.
	 * La riga C-14 collauda l'esito, non l'aggancio.
	 *
	 * Questo filtro riguarda solo la scadenza. L'esclusione dalla mappa delle
	 * sezioni che vietano l'indicizzazione è il meccanismo di indicizzazione, che
	 * è un'altra unità.
	 *
	 * @internal Aggancio di `wp_sitemaps_posts_query_args`.
	 *
	 * @param mixed $argomenti Argomenti dell'interrogazione della mappa.
	 * @param mixed $tipo      Tipo di contenuto della mappa.
	 * @return mixed Argomenti con la condizione sulla scadenza.
	 */
	public static function filtra_argomenti_mappa( $argomenti, $tipo = '' ) {
		if ( ! is_array( $argomenti ) || ! is_string( $tipo ) || ! Conformita_Core_Tipi::registrato( $tipo ) ) {
			return $argomenti;
		}

		/*
		 * La condizione sulla scadenza e' una condizione sui metadati, e lo
		 * standard segnala ogni `meta_query` come possibile interrogazione lenta.
		 * Qui la segnalazione si accetta consapevolmente: la scadenza e' una
		 * proprieta' del dato letto, quindi deve entrare nell'interrogazione. Le
		 * alternative piu' veloci, una tassonomia o un cambio di stato del
		 * contenuto, dipenderebbero dall'esecuzione del compito pianificato, che
		 * e' esattamente cio' che il progetto vieta. La chiave e' indicizzata da
		 * WordPress nella tabella dei metadati.
		 */
		// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Vedi la nota qui sopra: la condizione sui metadati e' il meccanismo, non un dettaglio ottimizzabile.
		$argomenti['meta_query'] = self::aggiungi_clausola(
			isset( $argomenti['meta_query'] ) ? $argomenti['meta_query'] : array()
		);

		return $argomenti;
	}

	/**
	 * L'indirizzo di un contenuto scaduto nell'interfaccia informatica pubblica
	 * risponde "non trovato".
	 *
	 * **Perché serve un aggancio a parte.** La collezione passa da `WP_Query`,
	 * quindi la coprono i due strati generali. La richiesta di un singolo
	 * identificativo no: WordPress legge il contenuto direttamente e lo prepara,
	 * senza nessuna interrogazione da filtrare. Senza questo aggancio
	 * risponderebbe 200 con i dati di un atto che non è più pubblicabile.
	 *
	 * **Non trovato, non vietato.** Sull'interfaccia pubblica la risposta è 404 e
	 * non 401 né 403: un codice che distingue "non esiste" da "non ti è permesso"
	 * è esso stesso un'informazione. Vale anche per l'utente autenticato con
	 * permessi di gestione, perché l'esenzione è della superficie e l'interfaccia
	 * informatica pubblica non è l'amministrazione. Riga C-16.
	 *
	 * **Perché qui e non sulla preparazione della risposta.** La scheda indicava
	 * come alternativa `rest_prepare_{$tipo}`, e sarebbe stata una scelta
	 * sbagliata: WordPress applica quel filtro e poi, per un tipo consultabile
	 * dal web, chiama un metodo sull'oggetto restituito per aggiungere
	 * un'intestazione. Restituire lì un errore farebbe chiamare quel metodo su un
	 * oggetto che non ce l'ha, cioè un errore fatale al posto di un 404. Lo stesso
	 * filtro, inoltre, prepara ogni elemento della collezione, quindi avrebbe
	 * richiesto di distinguere a mano il singolo dall'elenco per non corrompere
	 * la risposta della collezione. Questo aggancio, che WordPress espone proprio
	 * perché ci si possa rifiutare di eseguire la richiesta, non ha nessuno dei
	 * due problemi: riguarda solo la lettura del singolo, e l'errore diventa una
	 * risposta d'errore per costruzione. Righe C-16 e C-105.
	 *
	 * @internal Aggancio di `rest_request_before_callbacks`.
	 *
	 * @param mixed $risposta  Esito già deciso da qualcun altro, di norma nullo.
	 * @param mixed $gestore   Descrizione della funzione che servirebbe la rotta.
	 * @param mixed $richiesta Richiesta in corso.
	 * @return mixed La risposta invariata, oppure l'errore "non trovato".
	 */
	public static function filtra_richiesta_rest( $risposta, $gestore = null, $richiesta = null ) {
		if ( null !== $risposta ) {
			return $risposta;
		}

		/*
		 * La richiesta di sole intestazioni WordPress la serve con la stessa
		 * funzione della lettura, e la risposta porta l'indirizzo del contenuto,
		 * che contiene il titolo: e' una lettura come l'altra. Riga C-262.
		 */
		if ( ! $richiesta instanceof WP_REST_Request || ! in_array( $richiesta->get_method(), array( 'GET', 'HEAD' ), true ) ) {
			return $risposta;
		}

		$controllore = self::controllore_del_singolo( $gestore );

		if ( null === $controllore ) {
			return $risposta;
		}

		if ( ! self::singolo_da_nascondere( $controllore, $richiesta['id'] ) ) {
			return $risposta;
		}

		/*
		 * Il contesto di modifica non e' la superficie pubblica: e' il canale con
		 * cui l'editor a blocchi di WordPress apre un contenuto per modificarlo.
		 * Rifiutarlo renderebbe un atto con la data sbagliata impossibile da
		 * correggere, cioe' l'esatto contrario di quello che l'amministrazione
		 * deve garantire.
		 *
		 * **Si lascia passare solo chi la rotta autorizza davvero.** A chi non
		 * puo' modificare, WordPress risponderebbe "non hai il permesso" per un
		 * contenuto che esiste e "non trovato" per uno che non esiste: bastava
		 * aggiungere il contesto alla richiesta per sapere che dietro un numero
		 * c'era stato qualcosa. Il controllo e' quello della rotta stessa, che
		 * non scrive niente e che WordPress ripeterebbe subito dopo.
		 *
		 * Il confronto e' sul valore esatto e non sul suo contrario: un contesto
		 * sconosciuto o assente ricade nel trattamento pubblico, che e' la
		 * direzione sicura. Righe C-16, C-112 e C-268.
		 */
		if ( 'edit' === $richiesta['context'] && true === $controllore->get_item_permissions_check( $richiesta ) ) {
			return $risposta;
		}

		return self::rifiuto_come_inesistente( $controllore, $richiesta );
	}

	/**
	 * Ogni risposta sul singolo scaduto porta le stesse intestazioni di un
	 * numero che non esiste.
	 *
	 * Prima di spedire la risposta WordPress aggiunge l'intestazione `Allow`,
	 * e per farlo chiede di nuovo i permessi di ogni metodo della rotta con il
	 * numero della richiesta. Per un contenuto scaduto ma esistente la lettura
	 * risulterebbe permessa, per un numero inesistente no: l'intestazione
	 * direbbe quello che il rifiuto tace. Qui la si ricalcola con la richiesta
	 * di paragone.
	 *
	 * **Si decide dalla rotta e dal numero, non da chi ha risposto.** La prima
	 * stesura ricalcolava l'intestazione solo dove il rifiuto era venuto da
	 * questo filtro, e la seconda vi aggiungeva la richiesta di opzioni. Ma la
	 * risposta può arrivare anche da altrove prima di questo filtro, per esempio
	 * dalla verifica dei parametri, che rifiuta un contesto sconosciuto con lo
	 * stesso 400 per ogni numero: l'intestazione restava quella del numero vero.
	 * Ora vale per qualunque lettura o richiesta di opzioni sul singolo da
	 * togliere di mezzo, tranne il contesto di modifica di chi la rotta
	 * autorizza, che riceve il contenuto vero. Righe C-259, C-261, C-262,
	 * C-263, C-270 e C-272.
	 *
	 * @internal Aggancio di `rest_post_dispatch`, dopo `rest_send_allow_header()`.
	 *
	 * @param mixed $risposta  Risposta pronta.
	 * @param mixed $server    Server dell'interfaccia.
	 * @param mixed $richiesta Richiesta servita.
	 * @return mixed La risposta, con le intestazioni del numero inesistente se
	 *               riguarda un singolo da togliere di mezzo.
	 */
	public static function normalizza_risposta_rest( $risposta, $server = null, $richiesta = null ) {
		if ( ! $richiesta instanceof WP_REST_Request || ! $risposta instanceof WP_REST_Response || ! $server instanceof WP_REST_Server ) {
			return $risposta;
		}

		$finta = self::paragone_del_singolo( $risposta, $server, $richiesta );

		if ( null === $finta ) {
			return $risposta;
		}

		$intestazioni = $risposta->get_headers();
		unset( $intestazioni['Allow'] );
		$risposta->set_headers( $intestazioni );

		return rest_send_allow_header( $risposta, $server, $finta );
	}

	/**
	 * La richiesta di paragone per una risposta sul singolo da togliere di
	 * mezzo, se è quel caso.
	 *
	 * La rotta si riconosce da quella che la risposta dichiara di aver servito
	 * e dalla funzione che ne leggerebbe il singolo, come nel filtro della
	 * richiesta: vale anche per la richiesta di opzioni, che WordPress serve da
	 * sé con la descrizione della rotta, uguale per ogni numero (C-270), e per
	 * un rifiuto della verifica dei parametri (C-272).
	 *
	 * @param WP_REST_Response $risposta  Risposta pronta.
	 * @param WP_REST_Server   $server    Server dell'interfaccia.
	 * @param WP_REST_Request  $richiesta Richiesta servita.
	 * @return WP_REST_Request|null La richiesta di paragone, oppure null.
	 */
	private static function paragone_del_singolo( WP_REST_Response $risposta, WP_REST_Server $server, WP_REST_Request $richiesta ) {
		$metodo = $richiesta->get_method();

		if ( ! in_array( $metodo, array( 'GET', 'HEAD', 'OPTIONS' ), true ) ) {
			return null;
		}

		$rotte = $server->get_routes();
		$rotta = $risposta->get_matched_route();

		if ( ! is_string( $rotta ) || ! isset( $rotte[ $rotta ] ) || ! is_array( $rotte[ $rotta ] ) ) {
			return null;
		}

		foreach ( $rotte[ $rotta ] as $gestore ) {
			$controllore = self::controllore_del_singolo( $gestore );

			if ( null === $controllore ) {
				continue;
			}

			if ( ! self::singolo_da_nascondere( $controllore, $richiesta['id'] ) ) {
				return null;
			}

			// Il contesto di modifica di chi la rotta autorizza ha ricevuto il contenuto vero.
			if ( 'OPTIONS' !== $metodo && 'edit' === $richiesta['context'] && true === $controllore->get_item_permissions_check( $richiesta ) ) {
				return null;
			}

			$finta = clone $richiesta;
			$finta->set_param( 'id', -1 );

			return $finta;
		}

		return null;
	}

	/**
	 * Il singolo che la rotta servirebbe è da togliere di mezzo.
	 *
	 * Per il controllore dei commenti si guarda il contenuto a cui il commento
	 * appartiene, per gli altri il contenuto stesso.
	 *
	 * @param WP_REST_Posts_Controller|WP_REST_Comments_Controller $controllore Chi servirebbe la rotta.
	 * @param mixed                                                $numero      Numero chiesto.
	 * @return bool
	 */
	private static function singolo_da_nascondere( $controllore, $numero ) {
		if ( ! is_numeric( $numero ) ) {
			return false;
		}

		if ( $controllore instanceof WP_REST_Comments_Controller ) {
			$commento = (int) $numero > 0 ? get_comment( (int) $numero ) : null;

			return $commento instanceof WP_Comment && self::da_nascondere( (int) $commento->comment_post_ID );
		}

		return self::da_nascondere( (int) $numero );
	}

	/**
	 * Il controllore che servirebbe la lettura di un singolo contenuto o di un
	 * singolo commento, se la rotta in corso è quella.
	 *
	 * Si riconosce da chi la servirebbe, non dalla forma dell'indirizzo: la
	 * forma degli indirizzi è una convenzione che cambia, mentre la funzione che
	 * legge un contenuto singolo è la stessa per ogni tipo, allegati compresi.
	 * Così la collezione resta fuori senza doverla escludere a mano.
	 *
	 * @param mixed $gestore Descrizione della funzione che servirebbe la rotta.
	 * @return WP_REST_Posts_Controller|WP_REST_Comments_Controller|null
	 */
	private static function controllore_del_singolo( $gestore ) {
		if ( ! is_array( $gestore ) || ! isset( $gestore['callback'] ) ) {
			return null;
		}

		$funzione = $gestore['callback'];

		if ( ! is_array( $funzione ) || ! isset( $funzione[0], $funzione[1] ) || 'get_item' !== $funzione[1] ) {
			return null;
		}

		if ( $funzione[0] instanceof WP_REST_Posts_Controller || $funzione[0] instanceof WP_REST_Comments_Controller ) {
			return $funzione[0];
		}

		return null;
	}

	/**
	 * Il rifiuto che WordPress darebbe per un numero che non esiste.
	 *
	 * **Perché lo stesso rifiuto e non uno nostro.** Un "non trovato" con un
	 * codice proprio dice a chi prova i numeri uno per uno che dietro quel
	 * numero c'era qualcosa, anche se non ne mostra niente. Il rifiuto deve
	 * essere quello di un numero sbagliato, parola per parola.
	 *
	 * **Perché lo si chiede al controllore invece di scriverlo qui.** Per un
	 * numero che non esiste WordPress risponde dal controllo dei permessi, che
	 * cerca il contenuto e non lo trova; un numero negativo lo rifiuta prima
	 * ancora di cercarlo, quindi non dipende da che cosa c'è nella banca dati.
	 * Chiedere a quel controllo la risposta per un numero negativo dà il rifiuto
	 * esatto di quella rotta, nella lingua del sito, e resta esatto se una
	 * versione futura di WordPress cambia codice o messaggio. Il rifiuto scritto
	 * qui sotto è solo il ripiego per un controllore che non rispondesse con un
	 * "non trovato", ed è quello di WordPress per i contenuti. Righe C-261 e
	 * C-263.
	 *
	 * @param WP_REST_Posts_Controller|WP_REST_Comments_Controller $controllore Chi servirebbe la rotta.
	 * @param WP_REST_Request                                      $richiesta   Richiesta in corso.
	 * @return WP_Error
	 */
	private static function rifiuto_come_inesistente( $controllore, WP_REST_Request $richiesta ) {
		$finta = clone $richiesta;
		$finta->set_param( 'id', -1 );

		$rifiuto = $controllore->get_item_permissions_check( $finta );

		if ( is_wp_error( $rifiuto ) ) {
			$dati = $rifiuto->get_error_data();

			if ( is_array( $dati ) && isset( $dati['status'] ) && 404 === (int) $dati['status'] ) {
				return $rifiuto;
			}
		}

		return new WP_Error(
			'rest_post_invalid_id',
			__( 'Invalid post ID.' ), // phpcs:ignore WordPress.WP.I18n.MissingArgDomain -- e' il messaggio di WordPress, e deve restare identico al suo.
			array( 'status' => 404 )
		);
	}

	/**
	 * Il contenuto è uno di quelli che il filtro deve togliere di mezzo.
	 *
	 * Le quattro condizioni sono sempre le stesse e vanno sempre nello stesso
	 * ordine: deve essere un contenuto vero, di un tipo che core governa,
	 * pubblicato, e scaduto. Stanno in un posto solo perché una di esse
	 * dimenticata in un aggancio è un percorso scoperto, e un percorso scoperto
	 * non si vede rileggendo il codice: si vede quando qualcuno trova l'atto
	 * dove non doveva esserci.
	 *
	 * **Accetta tre forme dello stesso contenuto** perché i percorsi di lettura
	 * di WordPress non sono coerenti fra loro: la preparazione delle anteprime
	 * incorporate passa l'oggetto, l'interfaccia di pubblicazione remota passa un
	 * elenco di dati con l'identificativo dentro, e altrove si ha solo il numero.
	 * Pretendere una sola forma qui significherebbe che un aggancio non fa niente
	 * e nessuno se ne accorge, perché un filtro che non filtra non produce
	 * nessun errore.
	 *
	 * @param mixed $contenuto Contenuto da valutare: oggetto, elenco di dati con
	 *                         la chiave `ID`, oppure identificativo.
	 * @return bool
	 */
	private static function da_nascondere( $contenuto ) {
		/*
		 * Il numero zero, per `get_post()`, e' il contenuto corrente della
		 * pagina: un commento che non appartiene a nessun contenuto ne
		 * erediterebbe la scadenza. Un numero che non e' positivo non e' di
		 * nessun contenuto. Riga C-269.
		 */
		if ( is_array( $contenuto ) ) {
			$numero    = isset( $contenuto['ID'] ) ? (int) $contenuto['ID'] : 0;
			$contenuto = $numero > 0 ? get_post( $numero ) : null;
		} elseif ( is_numeric( $contenuto ) ) {
			$contenuto = (int) $contenuto > 0 ? get_post( (int) $contenuto ) : null;
		}

		if ( ! $contenuto instanceof WP_Post ) {
			return false;
		}

		if ( Conformita_Core_Tipi::uguale( $contenuto->post_type, 'attachment' ) ) {
			return self::padre_da_nascondere( (int) $contenuto->post_parent );
		}

		if ( ! Conformita_Core_Tipi::registrato( $contenuto->post_type ) ) {
			return false;
		}

		if ( 'publish' !== $contenuto->post_status ) {
			return false;
		}

		return Conformita_Core_Scadenza::scaduto( (int) $contenuto->ID );
	}

	/**
	 * Il padre di un allegato è un contenuto da togliere di mezzo.
	 *
	 * Un allegato non ha una data di fine propria: la sua scadenza è quella del
	 * contenuto a cui appartiene, su ogni strada che legge l'allegato, non
	 * soltanto nell'elenco dei risultati. Prima di questa riga la scheda
	 * dell'allegato nell'interfaccia informatica, la sua anteprima incorporata e
	 * la pubblicazione remota guardavano l'allegato stesso, che non è di un tipo
	 * gestito, e lo lasciavano passare con il nome del file caricato.
	 *
	 * Si risale di un solo livello: il padre che è a sua volta un allegato non
	 * porta a nessun tipo gestito, e fermarsi lì toglie di mezzo anche il caso
	 * di due allegati padri l'uno dell'altro, che altrimenti girerebbe senza
	 * fine. Righe C-19, C-259, C-260 e C-266.
	 *
	 * @param int $padre Identificativo del padre.
	 * @return bool
	 */
	private static function padre_da_nascondere( $padre ) {
		if ( $padre <= 0 ) {
			return false;
		}

		$contenuto = get_post( $padre );

		if ( ! $contenuto instanceof WP_Post || Conformita_Core_Tipi::uguale( $contenuto->post_type, 'attachment' ) ) {
			return false;
		}

		return self::da_nascondere( $contenuto );
	}

	/**
	 * I commenti di un contenuto scaduto non escono dalla superficie pubblica.
	 *
	 * Un commento non ha una data di fine propria, come un allegato: segue il
	 * contenuto a cui è scritto, e quello di un allegato segue il padre
	 * dell'allegato. WordPress legge i commenti con un'interrogazione propria,
	 * che non passa dai due strati dei contenuti, quindi senza questo aggancio
	 * il commento di un atto scaduto continuerebbe a uscire dall'elenco
	 * dell'interfaccia informatica, dal riquadro degli ultimi commenti e dalle
	 * altre letture del sito. Nell'amministrazione resta, come il contenuto.
	 *
	 * **Il limite, dichiarato.** È un ricontrollo dei commenti restituiti, come
	 * il secondo strato dei contenuti, senza un primo strato nell'interrogazione:
	 * le letture che chiedono solo il conteggio o solo gli identificativi non
	 * passano da qui, e il totale che l'interfaccia informatica dichiara può
	 * contare anche i commenti tolti. Il numero non dice niente di quale
	 * contenuto fossero. Righe C-263 e C-264.
	 *
	 * @internal Aggancio di `the_comments`, e aiuto di filtra_risultati().
	 *
	 * @param mixed $commenti Commenti restituiti.
	 * @return mixed I commenti senza quelli dei contenuti scaduti.
	 */
	public static function filtra_commenti( $commenti ) {
		if ( ! is_array( $commenti ) || empty( $commenti ) || ! self::superficie_pubblica() ) {
			return $commenti;
		}

		$rimasti = array();

		foreach ( $commenti as $commento ) {
			if ( is_object( $commento ) && isset( $commento->comment_post_ID ) && self::da_nascondere( (int) $commento->comment_post_ID ) ) {
				continue;
			}

			$rimasti[] = $commento;
		}

		return $rimasti;
	}

	/**
	 * Il reindirizzamento automatico non porta a un contenuto da togliere di
	 * mezzo.
	 *
	 * Quando una pagina non si trova, WordPress prova a indovinare dove si
	 * voleva andare: legge il numero chiesto nell'indirizzo, oppure cerca un
	 * contenuto il cui nome cominci come l'indirizzo, e manda il visitatore lì.
	 * Tutte e due le strade leggono la banca dati direttamente, senza passare
	 * dal filtro. Per uno scaduto l'indirizzo di destinazione, che contiene il
	 * titolo, uscirebbe nel reindirizzamento, mentre per un numero inesistente
	 * la pagina resta "non trovata".
	 *
	 * La decisione guarda la destinazione e non la strada che l'ha trovata:
	 * il numero chiesto, se ce n'è uno, e il contenuto a cui porta l'indirizzo
	 * di arrivo, letto con il filtro sospeso perché altrimenti uno scaduto non
	 * porterebbe a niente. Se l'uno o l'altro è da togliere di mezzo, il
	 * reindirizzamento si annulla e la pagina resta "non trovata". Riga C-273.
	 *
	 * @internal Aggancio di `redirect_canonical`.
	 *
	 * @param mixed $destinazione Indirizzo di arrivo scelto da WordPress.
	 * @param mixed $richiesto    Indirizzo chiesto.
	 * @return mixed L'indirizzo di arrivo, oppure false.
	 */
	public static function filtra_reindirizzamento( $destinazione, $richiesto = '' ) {
		unset( $richiesto );

		if ( ! is_string( $destinazione ) || '' === $destinazione || ! self::superficie_pubblica() ) {
			return $destinazione;
		}

		foreach ( array( 'p', 'page_id', 'attachment_id' ) as $variabile ) {
			if ( self::da_nascondere( (int) get_query_var( $variabile ) ) ) {
				return false;
			}
		}

		self::$sospeso = true;

		try {
			$arrivo = url_to_postid( $destinazione );
		} finally {
			self::$sospeso = false;
		}

		return self::da_nascondere( (int) $arrivo ) ? false : $destinazione;
	}

	/**
	 * Il vecchio nome di un contenuto da togliere di mezzo non porta al suo
	 * indirizzo nuovo.
	 *
	 * Quando un contenuto cambia nome WordPress conserva quello vecchio e manda
	 * chi apre il vecchio indirizzo a quello nuovo. Il vecchio nome si cerca
	 * direttamente nella banca dati, senza passare dal filtro: per uno scaduto
	 * il reindirizzamento direbbe che il contenuto esiste e quale è il suo
	 * indirizzo. Con il numero azzerato WordPress rinuncia e la pagina resta
	 * "non trovata", come per un nome mai esistito. Riga C-274.
	 *
	 * @internal Aggancio di `old_slug_redirect_post_id`.
	 *
	 * @param mixed $numero Contenuto trovato dal vecchio nome.
	 * @return mixed Il numero invariato, oppure 0.
	 */
	public static function filtra_vecchio_nome( $numero ) {
		if ( ! self::superficie_pubblica() || ! is_numeric( $numero ) ) {
			return $numero;
		}

		return self::da_nascondere( (int) $numero ) ? 0 : $numero;
	}

	/**
	 * L'elenco dei commenti chiesto per un contenuto da togliere di mezzo è
	 * quello di un contenuto che non esiste.
	 *
	 * Togliere i commenti restituiti, come fa filtra_commenti(), non basta qui:
	 * l'interfaccia informatica conta i commenti con un'interrogazione a parte e
	 * dichiara il totale nelle intestazioni, che per uno scaduto con dei
	 * commenti non sarebbe zero. L'interrogazione stessa esclude allora i
	 * contenuti da togliere di mezzo fra quelli chiesti, e conteggio ed elenco
	 * vengono tutti e due da quella. Riga C-275.
	 *
	 * **Il limite resta per l'elenco senza contenuto.** Lì il totale conta
	 * ancora i commenti tolti, come dice filtra_commenti(): il numero non dice
	 * di quale contenuto fossero.
	 *
	 * @internal Aggancio di `rest_comment_query`.
	 *
	 * @param mixed $argomenti Argomenti dell'interrogazione dei commenti.
	 * @return mixed Gli argomenti, con i contenuti da togliere di mezzo esclusi.
	 */
	public static function filtra_commenti_rest( $argomenti ) {
		if ( ! is_array( $argomenti ) || empty( $argomenti['post__in'] ) || ! self::superficie_pubblica() ) {
			return $argomenti;
		}

		$esclusi = array();

		foreach ( (array) $argomenti['post__in'] as $numero ) {
			if ( self::da_nascondere( (int) $numero ) ) {
				$esclusi[] = (int) $numero;
			}
		}

		if ( ! empty( $esclusi ) ) {
			$argomenti['post__not_in'] = array_merge( isset( $argomenti['post__not_in'] ) ? (array) $argomenti['post__not_in'] : array(), $esclusi );
		}

		return $argomenti;
	}

	/**
	 * Le anteprime incorporate non descrivono un contenuto scaduto.
	 *
	 * È il percorso con cui un altro sito chiede a questo una scheda del
	 * contenuto da mostrare dentro una propria pagina: titolo, autore, immagine.
	 * Senza questo aggancio l'anteprima di un atto non più pubblicabile
	 * continuerebbe a essere servita a chiunque la chieda, e finirebbe
	 * memorizzata sul sito che la incorpora, cioè fuori dal nostro controllo.
	 *
	 * L'insieme vuoto è il modo in cui WordPress riconosce "niente da mostrare":
	 * chi ha chiesto riceve "non trovato". Riga C-17.
	 *
	 * @internal Aggancio di `oembed_response_data`.
	 *
	 * @param mixed $dati      Dati preparati da WordPress.
	 * @param mixed $contenuto Contenuto a cui si riferiscono.
	 * @return mixed I dati invariati, oppure l'insieme vuoto.
	 */
	public static function filtra_anteprima_incorporata( $dati, $contenuto = null ) {
		return self::da_nascondere( $contenuto ) ? array() : $dati;
	}

	/**
	 * L'interfaccia di pubblicazione remota non restituisce un contenuto scaduto.
	 *
	 * **Qui si è scelta fra due letture, e la riga C-18 lo dice.** Le chiamate
	 * che leggono un contenuto da questa interfaccia sono autenticate e
	 * pretendono il permesso di modifica, quindi si potrebbe sostenere che
	 * meritino l'esenzione dell'amministrazione, dove il contenuto scaduto resta
	 * visibile perché resti correggibile. Si è scelta la lettura prudente, perché
	 * il costo delle due direzioni non è simmetrico: chi deve correggere una data
	 * va nell'amministrazione, che è dove andrebbe comunque, mentre non filtrando
	 * un atto scaduto resterebbe leggibile da una porta remota che quasi nessuno
	 * presidia.
	 *
	 * @internal Aggancio di `xmlrpc_prepare_post`.
	 *
	 * @param mixed $dati      Dati preparati da WordPress.
	 * @param mixed $contenuto Contenuto a cui si riferiscono.
	 * @return mixed I dati invariati, oppure l'insieme vuoto.
	 */
	public static function filtra_dato_xmlrpc( $dati, $contenuto = null ) {
		return self::da_nascondere( $contenuto ) ? array() : $dati;
	}

	/**
	 * L'interfaccia di pubblicazione remota non restituisce il commento di un
	 * contenuto scaduto.
	 *
	 * Il singolo commento lo legge con `get_comment()`, che non passa dal
	 * ricontrollo dei commenti, e lo prepara di qui. Stessa scelta prudente e
	 * stesso esito della riga C-18 per il contenuto: l'insieme vuoto. L'elenco
	 * dei commenti della stessa interfaccia passa invece da `the_comments`.
	 * Riga C-271.
	 *
	 * @internal Aggancio di `xmlrpc_prepare_comment`.
	 *
	 * @param mixed $dati     Dati preparati da WordPress.
	 * @param mixed $commento Commento a cui si riferiscono.
	 * @return mixed I dati invariati, oppure l'insieme vuoto.
	 */
	public static function filtra_commento_xmlrpc( $dati, $commento = null ) {
		if ( ! $commento instanceof WP_Comment ) {
			return $dati;
		}

		return self::da_nascondere( (int) $commento->comment_post_ID ) ? array() : $dati;
	}

	/**
	 * Il contenuto scaduto non è mai il vicino di un altro.
	 *
	 * I collegamenti al contenuto precedente e al successivo li cerca WordPress
	 * con un'interrogazione propria, che non passa né da `pre_get_posts` né dal
	 * ricontrollo per contenuto: senza questo aggancio un atto scaduto resterebbe
	 * raggiungibile dai suoi vicini, che è il modo meno vistoso di restare
	 * pubblicato.
	 *
	 * **Qui non c'è secondo strato, quindi la condizione deve bastare da sola.**
	 * WordPress restituisce direttamente il risultato della propria
	 * interrogazione, e non c'è niente su cui agganciare un ricontrollo. Per
	 * questo la condizione non si limita a confrontare le stringhe ma ammette
	 * soltanto date che esistono davvero: altrimenti un valore corrotto che ordina
	 * alto tornerebbe raggiungibile come vicino, contraddicendo la riga C-88.
	 *
	 * La condizione si aggiunge in coda alla condizione già costruita da
	 * WordPress, e vale solo per i tipi che core governa: su un articolo normale
	 * escluderebbe tutto, perché il metadato non ce l'ha. Riga C-20.
	 *
	 * @internal Agganci di `get_previous_post_where` e `get_next_post_where`.
	 *
	 * @param mixed $condizione       Condizione costruita da WordPress.
	 * @param mixed $stesso_termine   Non usato.
	 * @param mixed $termini_esclusi  Non usato.
	 * @param mixed $tassonomia       Non usato.
	 * @param mixed $contenuto        Contenuto di partenza.
	 * @return mixed La condizione, con l'aggiunta sulla scadenza dove serve.
	 */
	public static function filtra_vicino( $condizione, $stesso_termine = null, $termini_esclusi = null, $tassonomia = null, $contenuto = null ) {
		unset( $stesso_termine, $termini_esclusi, $tassonomia );

		if ( ! is_string( $condizione ) || ! $contenuto instanceof WP_Post ) {
			return $condizione;
		}

		if ( ! Conformita_Core_Tipi::registrato( $contenuto->post_type ) ) {
			return $condizione;
		}

		return $condizione . self::condizione_data_valida_e_non_scaduta();
	}

	/**
	 * La condizione che ammette soltanto date valide e non scadute.
	 *
	 * **Perché non basta il confronto fra stringhe.** Il formato è a lunghezza
	 * fissa, quindi l'ordine alfabetico coincide con quello cronologico, e su
	 * ogni altro percorso il confronto basta perché c'è un secondo strato che
	 * ricontrolla ogni contenuto restituito. Qui quel secondo strato non esiste:
	 * WordPress cerca il vicino con un'interrogazione propria e restituisce
	 * direttamente il risultato. Un valore corrotto che ordina alto, come
	 * `9999-99-99`, supererebbe il confronto e il contenuto tornerebbe
	 * raggiungibile come vicino.
	 *
	 * **Perché è un difetto e non un limite.** Il contratto della riga C-88 dice
	 * che una data corrotta rende il contenuto scaduto. Un percorso che lo mostra
	 * lo contraddice, e la navigazione adiacente è uno dei percorsi che questo
	 * componente dichiara di coprire: non è come `suppress_filters`, che chi lo
	 * usa sceglie deliberatamente.
	 *
	 * **Perché le condizioni sono queste e non una conversione a data.** Le
	 * funzioni che trasformano una stringa in data si comportano in modo diverso
	 * fra versioni e configurazioni della banca dati: su una respingono il 31
	 * febbraio, su un'altra lo accettano. Un componente di conformità che gira su
	 * ospiti sconosciuti non può appoggiarsi a quella differenza, quindi la
	 * validità è scritta per intero: la forma con il controllo sull'espressione,
	 * poi i tre soli modi in cui una data ben formata può non esistere, cioè il
	 * giorno 31 nei mesi di trenta giorni, il 30 e il 31 di febbraio, e il 29 di
	 * febbraio in un anno non bisestile.
	 *
	 * **Perché il raggruppamento con il conteggio a uno.** WordPress ammette più
	 * righe di metadato con la stessa chiave. La condizione senza raggruppamento
	 * avrebbe accettato il contenuto se *almeno una* riga fosse valida e futura,
	 * mentre la lettura normale ne guarda una sola: con un valore corrotto e uno
	 * futuro le due strade avrebbero deciso in modo opposto, e su questo percorso
	 * avrebbe vinto la più permissiva. Il conteggio a uno rende il caso
	 * impossibile invece di risolverlo, che è la direzione sicura, e coincide con
	 * il contratto: un valore solo per chiave. Riga C-114.
	 *
	 * Righe di collaudo C-113 e C-114.
	 *
	 * @return string Frammento di condizione, già preparato.
	 */
	private static function condizione_data_valida_e_non_scaduta() {
		global $wpdb;

		/*
		 * Il valore si legge attraverso un'aggregazione perché la condizione
		 * raggruppa per contenuto: con una riga sola, che è quello che il
		 * raggruppamento pretende, l'aggregazione restituisce quell'unica riga.
		 */
		$valore = 'MAX( pm.meta_value )';
		$anno   = "CAST( SUBSTRING( $valore, 1, 4 ) AS UNSIGNED )";
		$mese   = "SUBSTRING( $valore, 6, 2 )";
		$giorno = "SUBSTRING( $valore, 9, 2 )";

		$bisestile = "( MOD( $anno, 4 ) = 0 AND ( MOD( $anno, 100 ) <> 0 OR MOD( $anno, 400 ) = 0 ) )";

		/*
		 * Le parti interpolate qui sotto sono i tre frammenti costruiti qui sopra
		 * da costanti scritte nel codice: non contengono niente che arrivi da
		 * fuori. I due soli valori variabili, la chiave del metadato e il giorno
		 * corrente, passano dai segnaposto come devono.
		 */
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$condizione = $wpdb->prepare(
			" AND p.ID IN (
				SELECT pm.post_id FROM {$wpdb->postmeta} AS pm
				WHERE pm.meta_key = %s
				GROUP BY pm.post_id
				HAVING COUNT( * ) = 1
					AND $valore >= %s
					AND $valore REGEXP '^[0-9]{4}-(0[1-9]|1[0-2])-(0[1-9]|[12][0-9]|3[01])$'
					AND NOT ( $mese IN ( '04', '06', '09', '11' ) AND $giorno = '31' )
					AND NOT ( $mese = '02' AND $giorno > '29' )
					AND NOT ( $mese = '02' AND $giorno = '29' AND NOT $bisestile )
			)",
			Conformita_Core_Scadenza::chiave(),
			self::oggi()
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return $condizione;
	}
}
