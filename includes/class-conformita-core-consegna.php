<?php
/**
 * I due punti di consegna degli allegati.
 *
 * `Conformita_Core_Allegati` mette i byte dove l'accesso diretto non li
 * raggiunge. Questa classe è l'unica porta che resta, e a ogni richiesta rifà
 * tutti i controlli invece di fidarsi di quelli fatti quando il file è entrato.
 *
 * **Due punti, non uno che si comporta in due modi.** I documenti dell'albo
 * chiedono due cose insieme: dopo la scadenza il file non è più scaricabile dal
 * pubblico da nessuno, nemmeno da chi ha permessi, e resta raggiungibile
 * dall'amministrazione. La ragione per tenerli separati è tecnica prima che di
 * stile: `admin-post.php` definisce `WP_ADMIN`, quindi durante una sua
 * richiesta `is_admin()` è vero **anche per un visitatore anonimo**, e
 * `Conformita_Core_Filtro_Scadenza::superficie_pubblica()` considera esente
 * quella superficie. Un punto pubblico messo lì si troverebbe esente dalla
 * scadenza non per una svista ma per la definizione che S4 ha scritto e
 * collaudato. Con due punti, **sull'indirizzo pubblico l'esenzione non è
 * esprimibile**: non c'è nessun ramo che la produca. Riga C-144.
 *
 * **Perché variabili di interrogazione e non una regola di riscrittura.** Una
 * regola di riscrittura vive in un'opzione che va rigenerata, e se la
 * rigenerazione non è avvenuta (un altro componente che la sovrascrive, una
 * migrazione, un cambio di struttura dei permalink) la consegna diventa
 * silenziosamente "non trovato". È la stessa famiglia di errore del compito
 * pianificato che non gira: un meccanismo di conformità non può dipendere da
 * uno stato memorizzato che qualcun altro può perdere. Il costo è un indirizzo
 * brutto, ed è un costo che per un file che si scarica si accetta. Riga C-126.
 *
 * **Perché i rifiuti non dicono perché.** Un file che esiste ma è scaduto non
 * deve confessare di esistere: la politica dell'albo è `irraggiungibile`, e un
 * `403` direbbe "c'è, ma non per te", cioè esattamente quello che quella
 * politica non vuole dire. Tutti i rifiuti sono la stessa risposta, byte per
 * byte. Riga C-138.
 *
 * **Perché non c'è un nonce sul punto pubblico.** Un nonce difende dal CSRF,
 * cioè dal far compiere a un utente autenticato un'azione che non voleva. Qui
 * la richiesta è una lettura senza effetti su una risorsa che è pubblica finché
 * l'atto è valido: un nonce proverebbe solo che chi chiede ha una sessione, che
 * non è ciò che governa l'accesso, e renderebbe l'indirizzo incollabile. La
 * decisione di accesso, che la regola del repository pretende esplicita, è la
 * catena di `ammessa()`. Sul punto amministrativo, che è la superficie dove la
 * scadenza **non** si applica, il nonce c'è insieme alla capability.
 *
 * Righe di collaudo C-126..C-145, C-159, C-160, C-162.
 *
 * @package Conformita_Core
 */

defined( 'ABSPATH' ) || exit;

/**
 * Consegna pubblica e consegna amministrativa degli allegati.
 */
final class Conformita_Core_Consegna {

	/**
	 * Variabile d'interrogazione con il contenuto dichiarato.
	 */
	const VAR_ATTO = 'conformita_core_atto';

	/**
	 * Variabile d'interrogazione con l'allegato.
	 */
	const VAR_ALLEGATO = 'conformita_core_allegato';

	/**
	 * Azione del punto amministrativo.
	 */
	const AZIONE = 'conformita_core_allegato';

	/**
	 * I tipi che escono dentro la pagina invece che come scaricamento.
	 *
	 * **Elenco chiuso, nel codice, non filtrabile.** Il rischio che giustifica
	 * lo scaricamento forzato è l'esecuzione di codice nell'origine del sito, e
	 * riguarda i tipi che il browser interpreta come documento attivo, cioè un
	 * SVG o un HTML, non un PDF e non un'immagine raster. Servire tutto come
	 * scaricamento sarebbe una conclusione più larga della sua premessa, e in un
	 * albo costerebbe una regressione d'uso vera. Filtrabile non può essere: una
	 * decisione di sicurezza che un'installazione può allargare non è una
	 * decisione.
	 */
	const DENTRO_LA_PAGINA = array(
		'application/pdf',
		'image/jpeg',
		'image/png',
		'image/gif',
		'image/webp',
		'image/avif',
	);

	/**
	 * Il meccanismo ha già agganciato i propri filtri.
	 *
	 * @var bool
	 */
	private static $avviato = false;

	/**
	 * Emettitore sostituito, usato solo dalle prove.
	 *
	 * @var callable|null
	 */
	private static $emettitore = null;

	/**
	 * È in corso una richiesta al punto amministrativo.
	 *
	 * @var bool
	 */
	private static $amministrativa = false;

	/**
	 * Aggancia i punti di consegna.
	 *
	 * @internal Non fa parte dell'interfaccia verso i componenti: il meccanismo
	 *           è infrastruttura di core e si accende al caricamento del file di
	 *           core, come il motore di scadenza.
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
	 * L'elenco degli agganci, in un posto solo.
	 *
	 * @internal Uno solo perché accensione e spegnimento devono per forza dire
	 *           la stessa cosa: è la lezione del punto 14.3 della scheda di S4,
	 *           dove due elenchi scritti a mano avevano prodotto uno spegnimento
	 *           che dichiarava spento un meccanismo ancora acceso a metà.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function agganci() {
		return array(
			array(
				'aggancio'  => 'query_vars',
				'metodo'    => 'filtra_variabili',
				'argomenti' => 1,
			),
			array(
				'aggancio'  => 'parse_request',
				'metodo'    => 'serve_pubblica',
				'argomenti' => 1,
			),
			array(
				'aggancio'  => 'admin_post_' . self::AZIONE,
				'metodo'    => 'serve_amministrativa',
				'argomenti' => 1,
			),
			array(
				'aggancio'  => 'admin_post_nopriv_' . self::AZIONE,
				'metodo'    => 'rifiuta_amministrativa',
				'argomenti' => 1,
			),
			array(
				'aggancio'  => 'wp_get_attachment_url',
				'metodo'    => 'filtra_indirizzo_allegato',
				'argomenti' => 2,
			),
		);
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
	 * Sgancia tutto e riporta il meccanismo allo stato iniziale.
	 *
	 * @internal Solo per le prove.
	 */
	public static function azzera_avvio() {
		self::$amministrativa = false;

		if ( ! self::$avviato ) {
			return;
		}

		foreach ( self::agganci() as $aggancio ) {
			remove_filter( $aggancio['aggancio'], array( __CLASS__, $aggancio['metodo'] ), 10 );
		}

		self::$avviato = false;
	}

	/**
	 * Sostituisce l'emissione della risposta.
	 *
	 * @internal Solo per le prove. In esercizio l'emissione manda le
	 *           intestazioni, riversa i byte ed esce: uscire dentro una prova
	 *           ucciderebbe il processo. Tutto quello che viene prima
	 *           dell'emissione gira davvero anche nelle prove.
	 *
	 * @param callable $emettitore Funzione che riceve la risposta.
	 */
	public static function fissa_emettitore( $emettitore ) {
		self::$emettitore = is_callable( $emettitore ) ? $emettitore : null;
	}

	/**
	 * Rimette l'emissione vera.
	 *
	 * @internal Solo per le prove.
	 */
	public static function azzera_emettitore() {
		self::$emettitore = null;
	}

	/**
	 * È in corso una richiesta al punto amministrativo.
	 *
	 * @return bool
	 */
	public static function superficie_amministrativa_in_corso() {
		return self::$amministrativa;
	}

	/**
	 * Dichiara le due variabili d'interrogazione.
	 *
	 * Senza, WordPress le scarta e non arrivano mai al punto di consegna.
	 *
	 * @internal Aggancio di `query_vars`.
	 *
	 * @param mixed $variabili Variabili pubbliche riconosciute.
	 * @return mixed
	 */
	public static function filtra_variabili( $variabili ) {
		if ( ! is_array( $variabili ) ) {
			return $variabili;
		}

		$variabili[] = self::VAR_ATTO;
		$variabili[] = self::VAR_ALLEGATO;

		return $variabili;
	}

	/**
	 * Il punto pubblico.
	 *
	 * Sta su `parse_request` e non su `template_redirect` perché scatta su ogni
	 * richiesta del front-end **prima** dell'interrogazione principale: la
	 * consegna serve i byte ed esce, senza che WordPress costruisca una pagina.
	 * `template_redirect`, come la scheda di S4 ha già annotato, non viene
	 * emesso quando la richiesta non arriva a disegnare una pagina.
	 *
	 * @internal Aggancio di `parse_request`.
	 *
	 * @param mixed $wp Ambiente della richiesta.
	 * @return mixed
	 */
	public static function serve_pubblica( $wp ) {
		if ( ! is_object( $wp ) || ! isset( $wp->query_vars ) || ! is_array( $wp->query_vars ) ) {
			return $wp;
		}

		$atto     = isset( $wp->query_vars[ self::VAR_ATTO ] ) ? (int) $wp->query_vars[ self::VAR_ATTO ] : 0;
		$allegato = isset( $wp->query_vars[ self::VAR_ALLEGATO ] ) ? (int) $wp->query_vars[ self::VAR_ALLEGATO ] : 0;

		if ( $atto <= 0 || $allegato <= 0 ) {
			return $wp;
		}

		self::emetti( self::componi( $atto, $allegato, false ) );

		return $wp;
	}

	/**
	 * Il punto amministrativo.
	 *
	 * Qui la scadenza non si applica, ed è la stessa esenzione che S4 dà
	 * all'amministrazione sulle pagine: una superficie di gestione, dove il
	 * contenuto scaduto resta raggiungibile perché resti correggibile. Non è
	 * l'archivio riservato, che è l'unità S10 e non esiste.
	 *
	 * La capability richiesta è quella **di tipo sull'insieme**, non una sul
	 * singolo contenuto: è la stessa che regge l'elenco amministrativo dove S4
	 * lascia visibile il contenuto scaduto, quindi le due esenzioni hanno la
	 * stessa porta. Una capability sul singolo, per esempio `edit_post`, sarebbe
	 * fragile: l'albo blocca la modifica dell'atto pubblicato, e una mappatura
	 * che la rendesse falsa chiuderebbe questa consegna senza che nessuno lo
	 * avesse deciso.
	 *
	 * @internal Aggancio di `admin_post_conformita_core_allegato`.
	 */
	public static function serve_amministrativa() {
		self::$amministrativa = true;

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- il nonce si verifica qui sotto: gli identificativi servono a sapere quale nonce aspettarsi.
		$atto = isset( $_GET['atto'] ) ? absint( wp_unslash( $_GET['atto'] ) ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- come sopra.
		$allegato = isset( $_GET['allegato'] ) ? absint( wp_unslash( $_GET['allegato'] ) ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- è il nonce stesso.
		$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';

		if ( $atto <= 0 || $allegato <= 0 ) {
			self::emetti( self::rifiuto() );
			return;
		}

		if ( ! wp_verify_nonce( $nonce, self::AZIONE . '_' . $allegato ) ) {
			self::emetti( self::rifiuto() );
			return;
		}

		$contenuto = get_post( $atto );

		if ( ! $contenuto instanceof WP_Post || ! Conformita_Core_Tipi::registrato( $contenuto->post_type ) ) {
			self::emetti( self::rifiuto() );
			return;
		}

		$capacita = Conformita_Core_Tipi::capacita( $contenuto->post_type );

		if ( is_wp_error( $capacita ) || empty( $capacita['edit_posts'] ) || ! current_user_can( $capacita['edit_posts'] ) ) {
			self::emetti( self::rifiuto() );
			return;
		}

		self::emetti( self::componi( $atto, $allegato, true ) );
	}

	/**
	 * Il punto amministrativo per chi non ha fatto accesso.
	 *
	 * Esiste perché l'anonimo veda lo stesso "non trovato" di tutti gli altri
	 * rifiuti, invece della pagina vuota che `admin-post.php` produrrebbe da sé
	 * quando nessuno risponde all'azione.
	 *
	 * @internal Aggancio di `admin_post_nopriv_conformita_core_allegato`.
	 */
	public static function rifiuta_amministrativa() {
		self::$amministrativa = true;

		self::emetti( self::rifiuto() );
	}

	/**
	 * L'indirizzo del file che WordPress pubblica diventa quello di consegna.
	 *
	 * Senza questo filtro il percorso diretto comparirebbe in ogni pagina che
	 * stampa il collegamento a un allegato, e la protezione della cartella
	 * resterebbe l'unica cosa fra quel percorso e chiunque. Riga C-127.
	 *
	 * @internal Aggancio di `wp_get_attachment_url`.
	 *
	 * @param mixed $indirizzo   Indirizzo calcolato da WordPress.
	 * @param mixed $allegato_id Identificativo dell'allegato.
	 * @return mixed
	 */
	public static function filtra_indirizzo_allegato( $indirizzo, $allegato_id = 0 ) {
		if ( ! Conformita_Core_Allegati::protetto( $allegato_id ) ) {
			return $indirizzo;
		}

		$consegna = self::indirizzo( (int) $allegato_id );

		return is_wp_error( $consegna ) ? $indirizzo : $consegna;
	}

	/**
	 * L'indirizzo pubblico di consegna di un allegato.
	 *
	 * Porta **due** identificativi e non uno. Il secondo non è ridondante: un
	 * indirizzo vale per la coppia, quindi se un allegato viene riagganciato a
	 * un altro contenuto gli indirizzi vecchi smettono di funzionare invece di
	 * cominciare in silenzio a obbedire alla scadenza di un atto diverso.
	 *
	 * @param int $allegato_id Identificativo dell'allegato.
	 * @return string|WP_Error
	 */
	public static function indirizzo( $allegato_id ) {
		$allegato_id = (int) $allegato_id;

		if ( ! Conformita_Core_Allegati::protetto( $allegato_id ) ) {
			return new WP_Error(
				'conformita_core_allegato_non_gestito',
				__( 'Consegna: l\'allegato non è stato depositato attraverso questo componente.', 'conformita-core' )
			);
		}

		$allegato = get_post( $allegato_id );

		return add_query_arg(
			array(
				self::VAR_ATTO     => (int) $allegato->post_parent,
				self::VAR_ALLEGATO => $allegato_id,
			),
			home_url( '/' )
		);
	}

	/**
	 * L'indirizzo amministrativo di consegna, con il proprio nonce.
	 *
	 * @param int $allegato_id Identificativo dell'allegato.
	 * @return string|WP_Error
	 */
	public static function indirizzo_amministrativo( $allegato_id ) {
		$allegato_id = (int) $allegato_id;

		if ( ! Conformita_Core_Allegati::protetto( $allegato_id ) ) {
			return new WP_Error(
				'conformita_core_allegato_non_gestito',
				__( 'Consegna: l\'allegato non è stato depositato attraverso questo componente.', 'conformita-core' )
			);
		}

		$allegato = get_post( $allegato_id );

		return wp_nonce_url(
			add_query_arg(
				array(
					'action'   => self::AZIONE,
					'atto'     => (int) $allegato->post_parent,
					'allegato' => $allegato_id,
				),
				admin_url( 'admin-post.php' )
			),
			self::AZIONE . '_' . $allegato_id
		);
	}

	/**
	 * La catena di controlli, in un posto solo e in quest'ordine.
	 *
	 * **Nessun anello scrive niente**, e nessuno guarda le capability sul punto
	 * pubblico: l'esenzione è della superficie e non dell'utente, come al punto
	 * 3 della scheda di S4. Righe C-131..C-137, C-167, C-168, C-171.
	 *
	 * L'ultimo anello controlla il percorso normalizzato. Il percorso non arriva
	 * mai dall'esterno, perché l'indirizzo porta due numeri e niente altro, ma
	 * `_wp_attached_file` è un dato memorizzato, e un dato memorizzato può
	 * essere stato scritto da una migrazione. Fra fidarsi e controllare, si
	 * controlla.
	 *
	 * @param int  $atto_id         Contenuto dichiarato nell'indirizzo.
	 * @param int  $allegato_id     Allegato richiesto.
	 * @param bool $amministrativa  La richiesta arriva dal punto amministrativo.
	 * @return string|false Percorso del file sul disco, oppure falso.
	 */
	private static function ammessa( $atto_id, $allegato_id, $amministrativa ) {
		$allegato = get_post( $allegato_id );

		if ( ! $allegato instanceof WP_Post || 'attachment' !== $allegato->post_type ) {
			return false;
		}

		if ( ! Conformita_Core_Allegati::protetto( $allegato_id ) ) {
			return false;
		}

		$atto = get_post( $atto_id );

		if ( ! $atto instanceof WP_Post ) {
			return false;
		}

		if ( (int) $allegato->post_parent !== (int) $atto->ID ) {
			return false;
		}

		if ( ! Conformita_Core_Tipi::registrato( $atto->post_type ) ) {
			return false;
		}

		/*
		 * La politica si controlla come presenza e validita', non come
		 * differenza di comportamento: un file governato da nessuna politica non
		 * si consegna. Fra `irraggiungibile` e `archivio` questo meccanismo non
		 * distingue, esattamente come non distingue il filtro di scadenza: la
		 * differenza e' l'unita' S10 e oggi non esiste. Attraverso l'API questo
		 * anello non e' raggiungibile, perche' un tipo si registra solo dentro
		 * una sezione che ha gia' dichiarato la politica: e' una guardia
		 * difensiva, come quella della riga C-94.
		 */
		if ( is_wp_error( Conformita_Core_Tipi::politica( $atto->post_type ) ) ) {
			return false;
		}

		if ( ! $amministrativa ) {
			if ( 'publish' !== $atto->post_status ) {
				return false;
			}

			/*
			 * Lo stato `publish` dice che il contenuto e' pubblicato, non che
			 * si legga. Con una password sopra, il corpo non si vede senza
			 * averla, ma l'allegato uscirebbe lo stesso da qui, che di numeri
			 * ne chiede due e di password nessuna. La password e' del contenuto
			 * padre e vale per tutto cio' che gli appartiene. Riga C-167.
			 *
			 * Sul punto amministrativo non si applica, ed e' deliberato: li' si
			 * entra con il nonce e la capability del tipo, cioe' con
			 * un'autorizzazione piu' forte di una password di lettura.
			 *
			 * **La password si guarda su tutti e due.** Quella del padre copre
			 * l'atto e cio' che gli appartiene, ma anche un allegato puo'
			 * averne una propria, e `post_password_required()` non risale dal
			 * file al padre: nessuna delle due domande risponde per l'altra.
			 * Riga C-171.
			 */
			if ( post_password_required( $atto ) || post_password_required( $allegato ) ) {
				return false;
			}

			/*
			 * Lo stato dell'allegato, non solo quello del padre. Con
			 * `MEDIA_TRASH` dichiarata, cestinare un allegato non cancella
			 * niente: restano il file, la marca del deposito e il padre, e
			 * cambia soltanto lo stato. Senza questo anello un indirizzo
			 * vecchio continuerebbe a servire un documento ritirato mentre il
			 * suo contenuto padre e' ancora pubblicato e non scaduto.
			 *
			 * Si ammette `inherit`, che e' lo stato che `deposita()` produce, e
			 * non si elencano gli stati da rifiutare: un elenco di ammessi non
			 * si allarga da se' quando WordPress inventa uno stato nuovo. E'
			 * la stessa ragione per cui i dinieghi dell'esca sono un elenco
			 * chiuso. Riga C-168.
			 *
			 * Dall'amministrazione l'allegato cestinato si vede ancora, per lo
			 * stesso motivo per cui si vede quello scaduto: perche' resti
			 * controllabile e ripristinabile.
			 */
			if ( 'inherit' !== $allegato->post_status ) {
				return false;
			}

			if ( Conformita_Core_Scadenza::scaduto( (int) $atto->ID ) ) {
				return false;
			}
		}

		$percorso = get_attached_file( $allegato_id );

		if ( ! is_string( $percorso ) || '' === $percorso ) {
			return false;
		}

		$reale  = realpath( $percorso );
		$radice = realpath( Conformita_Core_Allegati::cartella() );

		if ( false === $reale || false === $radice ) {
			return false;
		}

		if ( 0 !== strpos( $reale, $radice . DIRECTORY_SEPARATOR ) ) {
			return false;
		}

		if ( ! is_file( $reale ) || ! is_readable( $reale ) ) {
			return false;
		}

		return $reale;
	}

	/**
	 * Costruisce la risposta: il file, oppure il rifiuto.
	 *
	 * @param int  $atto_id        Contenuto dichiarato.
	 * @param int  $allegato_id    Allegato richiesto.
	 * @param bool $amministrativa Punto amministrativo.
	 * @return array<string, mixed>
	 */
	private static function componi( $atto_id, $allegato_id, $amministrativa ) {
		$percorso = self::ammessa( (int) $atto_id, (int) $allegato_id, (bool) $amministrativa );

		if ( false === $percorso ) {
			return self::rifiuto();
		}

		return self::consegna( get_post( (int) $allegato_id ), $percorso );
	}

	/**
	 * Le intestazioni che ogni risposta porta, consegna o rifiuto che sia.
	 *
	 * `no-store` è quella che conta: `no-cache` da solo autorizza a conservare
	 * una copia e a rivalidarla. Niente `ETag` e niente `Last-Modified`, per non
	 * offrire un appiglio alla rivalidazione. Riga C-130.
	 *
	 * @return array<string, string>
	 */
	private static function intestazioni_comuni() {
		return array(
			'Cache-Control'          => 'private, no-store, no-cache, must-revalidate, max-age=0',
			'Pragma'                 => 'no-cache',
			'Expires'                => 'Wed, 11 Jan 1984 05:00:00 GMT',
			'X-Content-Type-Options' => 'nosniff',
			'Accept-Ranges'          => 'none',
		);
	}

	/**
	 * Il rifiuto, uguale per ogni motivo.
	 *
	 * @return array<string, mixed>
	 */
	private static function rifiuto() {
		return array(
			'stato'        => 404,
			'intestazioni' => array_merge(
				self::intestazioni_comuni(),
				array( 'Content-Type' => 'text/plain; charset=utf-8' )
			),
			'percorso'     => '',
			'corpo'        => __( 'Non trovato.', 'conformita-core' ),
		);
	}

	/**
	 * La risposta che consegna il file.
	 *
	 * **Il tipo con cui si decide non è quello dichiarato e basta**: si deriva
	 * dall'estensione e si confronta con quello memorizzato sull'allegato. Se
	 * non coincidono si esce come scaricamento generico, perché una discordanza
	 * fra i due è esattamente la condizione in cui non si sa che cosa si sta
	 * servendo. Riga C-160.
	 *
	 * **La richiesta parziale si ignora.** Una gestione corretta degli
	 * intervalli di byte è un analizzatore sintattico con una storia di
	 * vulnerabilità propria, e la combinazione fra risposte parziali e memorie
	 * intermedie è dove i proxy si inventano le cose. Il costo, cioè che un file
	 * grande non si riprende dopo un'interruzione, è dichiarato. Riga C-139.
	 *
	 * @param WP_Post $allegato Allegato da consegnare.
	 * @param string  $percorso Percorso del file sul disco.
	 * @return array<string, mixed>
	 */
	private static function consegna( WP_Post $allegato, $percorso ) {
		$nome    = wp_basename( $percorso );
		$dedotto = wp_check_filetype( $nome );

		$concordi = ! empty( $dedotto['type'] ) && $dedotto['type'] === $allegato->post_mime_type;
		$tipo     = $concordi ? (string) $allegato->post_mime_type : 'application/octet-stream';
		$dentro   = $concordi && in_array( $tipo, self::DENTRO_LA_PAGINA, true );

		$intestazioni = array_merge(
			self::intestazioni_comuni(),
			array(
				'Content-Type'        => $tipo,
				'Content-Length'      => (string) filesize( $percorso ),
				'Content-Disposition' => ( $dentro ? 'inline' : 'attachment' )
					. '; filename="' . str_replace( '"', '', $nome ) . '"'
					. "; filename*=UTF-8''" . rawurlencode( $nome ),
			)
		);

		if ( $dentro ) {
			/*
			 * La direttiva `sandbox` e' deliberatamente fuori. I visualizzatori
			 * di PDF incorporati nei browser sono documenti a loro volta, e
			 * `sandbox` senza permessi li rompe: metterla riporterebbe il PDF a
			 * scaricarsi, cioe' disferebbe con una intestazione la decisione di
			 * servirlo dentro la pagina. Le direttive qui sotto vietano gia'
			 * script, plugin e moduli, che sono le vie per cui un documento
			 * servito dall'origine del sito farebbe danno.
			 */
			$intestazioni['Content-Security-Policy'] = "default-src 'none'; img-src 'self' data:; "
				. "object-src 'none'; script-src 'none'; style-src 'none'; "
				. "base-uri 'none'; form-action 'none'; frame-ancestors 'self'";
		}

		return array(
			'stato'        => 200,
			'intestazioni' => $intestazioni,
			'percorso'     => $percorso,
			'corpo'        => '',
		);
	}

	/**
	 * Manda la risposta e chiude.
	 *
	 * @param array<string, mixed> $risposta Risposta da emettere.
	 */
	private static function emetti( array $risposta ) {
		if ( null !== self::$emettitore ) {
			call_user_func( self::$emettitore, $risposta );

			return;
		}

		status_header( $risposta['stato'] );

		foreach ( $risposta['intestazioni'] as $nome => $valore ) {
			header( $nome . ': ' . $valore );
		}

		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		if ( '' !== $risposta['percorso'] ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- si riversa il file: WP_Filesystem lo caricherebbe tutto in memoria.
			readfile( $risposta['percorso'] );
		} else {
			echo esc_html( $risposta['corpo'] );
		}

		exit;
	}
}
