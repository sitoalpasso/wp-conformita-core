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
 * C-92, C-93, C-97, C-98, C-101, C-102 e C-103.
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
	 * Idempotente: invocata due volte non aggancia niente due volte e non
	 * produce errori. Riga C-98.
	 */
	public static function avvia() {
		if ( self::$avviato ) {
			return;
		}

		self::$avviato = true;

		add_action( 'pre_get_posts', array( __CLASS__, 'filtra_interrogazione' ) );
		add_filter( 'the_posts', array( __CLASS__, 'filtra_risultati' ), 10, 2 );
		add_filter( 'wp_sitemaps_posts_query_args', array( __CLASS__, 'filtra_argomenti_mappa' ), 10, 2 );
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

		remove_action( 'pre_get_posts', array( __CLASS__, 'filtra_interrogazione' ) );
		remove_filter( 'the_posts', array( __CLASS__, 'filtra_risultati' ), 10 );
		remove_filter( 'wp_sitemaps_posts_query_args', array( __CLASS__, 'filtra_argomenti_mappa' ), 10 );

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
	 * La clausola è già presente in una dichiarazione di `meta_query`.
	 *
	 * Serve perché la mappa per i motori di ricerca passa da due agganci: gli
	 * argomenti dell'interrogazione e poi l'interrogazione stessa. Aggiungere la
	 * clausola due volte non cambierebbe l'esito, ma lascerebbe una condizione
	 * duplicata che rende illeggibile il debug.
	 *
	 * @param mixed $meta Dichiarazione di `meta_query`, a qualsiasi profondità.
	 * @return bool
	 */
	private static function clausola_presente( $meta ) {
		if ( ! is_array( $meta ) ) {
			return false;
		}

		if ( isset( $meta['key'] ) && Conformita_Core_Scadenza::chiave() === $meta['key'] ) {
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
}
