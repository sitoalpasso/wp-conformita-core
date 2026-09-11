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
 * singolo identificativo, le interrogazioni SQL dirette, la lettura diretta dei
 * metadati e le pagine servite da una memoria che risponde prima di WordPress.
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
 * C-92, C-93, C-97, C-98, C-101, C-102, C-103 e C-104.
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
			add_filter( $aggancio['aggancio'], array( __CLASS__, $aggancio['metodo'] ), 10, $aggancio['argomenti'] );
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
			remove_filter( $aggancio['aggancio'], array( __CLASS__, $aggancio['metodo'] ), 10 );
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
	 * @internal Aggancio di `the_posts`.
	 *
	 * @param mixed $contenuti      Contenuti restituiti dall'interrogazione.
	 * @param mixed $interrogazione Interrogazione in corso.
	 * @return mixed Contenuti senza quelli scaduti.
	 */
	public static function filtra_risultati( $contenuti, $interrogazione = null ) {
		unset( $interrogazione );

		if ( ! is_array( $contenuti ) || empty( $contenuti ) ) {
			return $contenuti;
		}

		if ( ! self::superficie_pubblica() ) {
			return $contenuti;
		}

		$rimasti = array();

		foreach ( $contenuti as $contenuto ) {
			if ( ! is_object( $contenuto ) || ! isset( $contenuto->post_type, $contenuto->post_status, $contenuto->ID ) ) {
				$rimasti[] = $contenuto;
				continue;
			}

			if ( 'attachment' === $contenuto->post_type ) {
				if ( ! self::da_nascondere( (int) $contenuto->post_parent ) ) {
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

		if ( ! $richiesta instanceof WP_REST_Request || 'GET' !== $richiesta->get_method() ) {
			return $risposta;
		}

		if ( ! self::e_lettura_di_un_singolo( $gestore ) ) {
			return $risposta;
		}

		/*
		 * Il contesto di modifica non e' la superficie pubblica: e' il canale con
		 * cui l'editor a blocchi di WordPress apre un contenuto per modificarlo.
		 * Rifiutarlo renderebbe un atto con la data sbagliata impossibile da
		 * correggere, cioe' l'esatto contrario di quello che l'amministrazione
		 * deve garantire.
		 *
		 * Lasciarlo passare non apre niente a nessuno: per quel contesto
		 * WordPress pretende gia' il permesso di modifica sul contenuto, e a chi
		 * non ce l'ha risponde con il proprio rifiuto. Il rifiuto arriva prima
		 * che si sappia se il contenuto sia scaduto, quindi non dice niente a chi
		 * non deve saperlo.
		 *
		 * Il confronto e' sul valore esatto e non sul suo contrario: un contesto
		 * sconosciuto o assente ricade nel trattamento pubblico, che e' la
		 * direzione sicura. Righe C-16 e C-112.
		 */
		if ( 'edit' === $richiesta['context'] ) {
			return $risposta;
		}

		if ( ! self::da_nascondere( (int) $richiesta['id'] ) ) {
			return $risposta;
		}

		return new WP_Error(
			'conformita_core_contenuto_non_trovato',
			__( 'Contenuto non trovato.', 'conformita-core' ),
			array( 'status' => 404 )
		);
	}

	/**
	 * La rotta in corso è la lettura di un singolo contenuto.
	 *
	 * Si riconosce da chi la servirebbe, non dalla forma dell'indirizzo: la
	 * forma degli indirizzi è una convenzione che cambia, mentre la funzione che
	 * legge un contenuto singolo è la stessa per ogni tipo. Così la collezione
	 * resta fuori senza doverla escludere a mano.
	 *
	 * @param mixed $gestore Descrizione della funzione che servirebbe la rotta.
	 * @return bool
	 */
	private static function e_lettura_di_un_singolo( $gestore ) {
		if ( ! is_array( $gestore ) || ! isset( $gestore['callback'] ) ) {
			return false;
		}

		$funzione = $gestore['callback'];

		if ( ! is_array( $funzione ) || ! isset( $funzione[0], $funzione[1] ) ) {
			return false;
		}

		return $funzione[0] instanceof WP_REST_Posts_Controller && 'get_item' === $funzione[1];
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
		if ( is_array( $contenuto ) ) {
			$contenuto = isset( $contenuto['ID'] ) ? get_post( (int) $contenuto['ID'] ) : null;
		} elseif ( is_numeric( $contenuto ) ) {
			$contenuto = get_post( (int) $contenuto );
		}

		if ( ! $contenuto instanceof WP_Post ) {
			return false;
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
	 * Riga di collaudo C-113.
	 *
	 * @return string Frammento di condizione, già preparato.
	 */
	private static function condizione_data_valida_e_non_scaduta() {
		global $wpdb;

		$anno   = 'CAST( SUBSTRING( pm.meta_value, 1, 4 ) AS UNSIGNED )';
		$mese   = 'SUBSTRING( pm.meta_value, 6, 2 )';
		$giorno = 'SUBSTRING( pm.meta_value, 9, 2 )';

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
					AND pm.meta_value >= %s
					AND pm.meta_value REGEXP '^[0-9]{4}-(0[1-9]|1[0-2])-(0[1-9]|[12][0-9]|3[01])$'
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
