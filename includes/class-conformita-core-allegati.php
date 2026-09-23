<?php
/**
 * La cartella protetta, il deposito dei file, l'impronta.
 *
 * Questa classe risponde a tre domande: **dove stanno i byte**, **come si sa
 * che nessuno li raggiunge dal loro percorso**, e **che cosa si conserva del
 * file al momento in cui entra**. Chi li consegna è `Conformita_Core_Consegna`,
 * che senza una cartella davvero protetta consegnerebbe da un indirizzo
 * controllato dei file che si prendono anche da un altro.
 *
 * **Perché la cartella sta dentro i caricamenti.** Perché è l'unica cartella
 * che WordPress garantisce scrivibile e di cui sa il percorso. Fuori dalla
 * radice del sito sarebbe più sicuro per costruzione, ma su molte installazioni
 * in condivisione non esiste nessun posto scrivibile là fuori, e il percorso
 * diventerebbe un valore da configurare per ogni amministrazione: cioè un
 * valore che si può sbagliare, e che quando è sbagliato non produce nessun
 * errore visibile.
 *
 * **Perché la protezione si verifica invece di presumerla.** I file di regole
 * (`.htaccess`, `web.config`) funzionano su un server web e non sull'altro, e
 * nessuna delle condizioni che li rendono inerti si vede da dentro PHP:
 * `AllowOverride None`, un blocco `location` che precede, un proxy che serve i
 * file statici da sé. L'unica cosa che dice come stanno le cose è **chiedere**:
 * una richiesta all'esca, e poi guardare che cosa risponde. È una richiesta
 * verso il sito stesso, non verso un servizio esterno, ed è la stessa tecnica
 * che WordPress usa nel proprio controllo di integrità.
 *
 * La prima stesura di questa unità aveva un quarto esito, `presunta`, che
 * significava "il file di regole c'è e il server dice di leggerlo". È stato
 * eliminato: era il valore che avrebbe lasciato passare Apache con
 * `AllowOverride None`, cioè esattamente il caso che serviva prendere.
 *
 * **Perché il deposito si rifiuta quando la protezione non è verificata.** Fra
 * scrivere un file che non si è in grado di proteggere e non scriverlo, non si
 * scrive: un file già finito in una cartella aperta non si richiama indietro,
 * mentre un deposito rifiutato è un messaggio a chi sta installando, nel
 * momento in cui può ancora rimediare.
 *
 * Righe di collaudo C-115..C-125, C-146, C-154..C-158, C-163..C-166, C-169, C-170,
 * C-172..C-175, C-177..C-194.
 *
 * @package Conformita_Core
 */

defined( 'ABSPATH' ) || exit;

/**
 * Cartella protetta, deposito e impronta degli allegati.
 */
final class Conformita_Core_Allegati {

	/**
	 * Nome della cartella protetta dentro i caricamenti.
	 *
	 * Fisso, e non configurabile: è lo slug del componente, non un valore che
	 * dipende dall'amministrazione. Un nome non indovinabile generato
	 * all'attivazione sarebbe sicurezza per oscurità, e nel frattempo darebbe
	 * l'impressione di una protezione che non c'è.
	 */
	const CARTELLA = 'conformita-core-protetto';

	/**
	 * Il file esca fisso della radice.
	 *
	 * Nasce con i file di regole e, come loro, se manca si riscrive e fa
	 * dimenticare gli esiti. **Non è il file che la verifica chiede**: quello
	 * ha un nome nuovo a ogni verifica, vedi `crea_esca()`. Riga C-193.
	 */
	const ESCA = 'prova-accesso-diretto.txt';

	/**
	 * Il nome dell'esca senza estensione.
	 *
	 * L'estensione non è un dettaglio: un server può negare una cartella e
	 * servire lo stesso i file di una certa estensione, perché la regola che
	 * decide non è sempre quella che si legge per prima. Su nginx una
	 * `location` con espressione regolare per i PDF vince su una `location`
	 * di prefisso per la cartella; su Apache un `FilesMatch` fa lo stesso.
	 * L'esca `.txt` nella radice prova il diniego di quel percorso e di quella
	 * estensione, non di tutta la cartella.
	 *
	 * Ogni nome che comincia così è riservato alle esche: quelle che la
	 * verifica chiede si chiamano così più un suffisso nuovo a ogni verifica.
	 */
	const ESCA_PREFISSO = 'prova-accesso-diretto';

	/**
	 * L'opzione che conserva l'esito della verifica.
	 */
	const OPZIONE = 'conformita_core_protezione_allegati';

	/**
	 * La marca che dice "depositato attraverso il core".
	 *
	 * È la marca a fare fede, non il percorso: riconoscere un allegato protetto
	 * guardando se il percorso comincia per il nome della cartella funzionerebbe
	 * fino al giorno in cui qualcuno sposta la cartella dei caricamenti.
	 */
	const MARCA = '_conformita_core_allegato';

	/**
	 * Chiave dell'impronta.
	 */
	const IMPRONTA = '_conformita_core_impronta';

	/**
	 * Chiave della dimensione in byte.
	 */
	const DIMENSIONE = '_conformita_core_dimensione';

	/**
	 * Chiave dell'istante di deposito.
	 */
	const DEPOSITO = '_conformita_core_deposito';

	/**
	 * Gli stati HTTP che valgono come diniego, e sono un elenco chiuso.
	 *
	 * **Elenco, e non un intervallo.** La prima stesura trattava come diniego
	 * qualunque stato dal 400 in su, ed e' stato un difetto della stessa famiglia
	 * del reindirizzamento letto come rifiuto: un 503 o un 429 li dice un proxy
	 * che in quel momento non sta servendo niente, non un server che nega quel
	 * percorso, e quando il proxy torna a servire i file sono li'. Il 401 e'
	 * fuori per lo stesso motivo: lo dice un sito messo per intero dietro
	 * un'autenticazione, cioe' tipicamente un'installazione in costruzione, e il
	 * giorno che l'autenticazione si toglie l'esito conservato direbbe
	 * `verificata` su una cartella che nessuno ha mai provato. Un'installazione
	 * cosi' dichiara lo scavalcamento, che esiste per questo.
	 *
	 * Restano il 403, che e' quello che rispondono i file di regole scritti qui,
	 * e il 404, che rispondono i server configurati per negare senza confermare
	 * che il file esista.
	 */
	const DINIEGHI = array( 403, 404 );

	/**
	 * Algoritmo dell'impronta.
	 *
	 * Finisce **dentro** il valore memorizzato, non solo qui: il giorno in cui
	 * non basterà più, i valori vecchi continueranno a dire da soli con che cosa
	 * sono stati calcolati, invece di essere reinterpretati in silenzio con
	 * l'algoritmo nuovo.
	 */
	const ALGORITMO = 'sha256';

	/**
	 * Scavalcamento forzato, usato solo dalle prove.
	 *
	 * @internal In esercizio lo scavalcamento si dichiara con la costante.
	 *
	 * @var bool|null
	 */
	private static $scavalcamento = null;

	/**
	 * Guardia contro la ricorsione fra scrittura delle regole e verifica.
	 *
	 * @var bool
	 */
	private static $in_verifica = false;

	/**
	 * Perché la guardia sulla destinazione ha fermato lo spostamento.
	 *
	 * Vuoto quando non lo ha fermato. Serve perché chi ferma e chi risponde a
	 * chiama non sono lo stesso punto del codice: la guardia vive dentro un
	 * aggancio di WordPress, che sa solo dire sì o no, e il motivo andrebbe
	 * perso.
	 *
	 * @var array<string, string>
	 */
	private static $fermata = array();

	/**
	 * L'origine dichiarata dal deposito in corso, vuota fuori da un deposito.
	 *
	 * La guardia sulla destinazione la rilegge sul file che sta per essere
	 * copiato, perche' fra il controllo all'ingresso e lo spostamento c'e' un
	 * aggancio che puo' sostituire la sorgente. Riga C-184.
	 *
	 * @var string
	 */
	private static $origine_in_corso = '';

	/**
	 * Un deposito è in corso.
	 *
	 * Il deposito aggancia la guardia e il dirottamento e li sgancia alla
	 * fine, e la guardia legge l'origine da uno stato condiviso: un deposito
	 * fatto dentro un altro li sgancerebbe sotto i piedi di quello esterno.
	 * Riga C-188.
	 *
	 * @var bool
	 */
	private static $in_deposito = false;

	/**
	 * Percorso della cartella protetta.
	 *
	 * @return string
	 */
	public static function cartella() {
		$caricamenti = wp_get_upload_dir();

		return $caricamenti['basedir'] . '/' . self::CARTELLA;
	}

	/**
	 * Indirizzo della cartella protetta.
	 *
	 * È l'indirizzo che **non deve rispondere**: non si stampa da nessuna parte,
	 * serve alla verifica come bersaglio.
	 *
	 * @return string
	 */
	public static function indirizzo_cartella() {
		$caricamenti = wp_get_upload_dir();

		return $caricamenti['baseurl'] . '/' . self::CARTELLA;
	}

	/**
	 * Un nome nuovo per l'esca di una verifica, con l'estensione da provare.
	 *
	 * **Nuovo a ogni verifica, e mai chiesto prima.** Un server può ricordare
	 * le risposte per indirizzo: nginx conserva nella memoria dei file aperti
	 * anche gli errori, una rete di distribuzione davanti all'origine conserva
	 * i 404. Un indirizzo fisso, chiesto una volta quando il file non c'era,
	 * continuerebbe a rispondere 404 anche dopo la creazione dell'esca, e la
	 * verifica leggerebbe un diniego che non parla dell'esca; il documento,
	 * con un nome mai chiesto, verrebbe servito. Un parametro nell'indirizzo
	 * non basterebbe, perché quella memoria è per percorso sul disco. Riga
	 * C-193.
	 *
	 * Il suffisso viene da `casuale()`, non da `wp_generate_password()`. Riga
	 * C-194.
	 *
	 * @param string $estensione Estensione, senza punto.
	 * @return string|false False se non c'è una sorgente casuale.
	 */
	private static function nome_esca( $estensione ) {
		$suffisso = self::casuale();

		if ( '' === $suffisso ) {
			return false;
		}

		return self::ESCA_PREFISSO . '-' . $suffisso . '.' . self::estensione( $estensione );
	}

	/**
	 * Trentadue cifre esadecimali casuali, o una stringa vuota.
	 *
	 * **Da una sorgente che nessun altro componente può filtrare.**
	 * `wp_generate_password()` passa dal filtro `random_password`, e un
	 * componente che impone caratteri speciali alle password ignora la
	 * richiesta di non usarli: un `#` nel nome dell'esca farebbe chiedere al
	 * server un altro percorso, perché quello che segue il `#` in un indirizzo
	 * non gli arriva, e il 404 di un file che non esiste varrebbe come diniego.
	 * Le cifre esadecimali stanno uguali sul disco e in un indirizzo. Se la
	 * sorgente casuale manca, chi chiama tratta la stringa vuota come un
	 * fallimento. Riga C-194.
	 *
	 * @return string
	 */
	private static function casuale() {
		try {
			return bin2hex( random_bytes( 16 ) );
		} catch ( Exception $e ) {
			return '';
		}
	}

	/**
	 * L'estensione come stringa, **e niente altro**.
	 *
	 * Un'estensione vuota resta vuota. Trasformarla in `txt` faceva di un file
	 * senza estensione un file dell'ambito dei `.txt`: si provava l'esca
	 * `.txt`, il server la negava, e si scriveva un file che il server poteva
	 * servire, perche' una regola sui `.txt` non parla dei file senza
	 * estensione. Il valore `txt` sta solo come predefinito esplicito di
	 * `verifica()`, dove indica l'esca generale; qui
	 * dentro l'estensione vuota non si sa provare, e `provabile()` la
	 * rifiuta. Riga C-182.
	 *
	 * @param string $estensione Estensione dichiarata.
	 * @return string
	 */
	private static function estensione( $estensione ) {
		return (string) $estensione;
	}

	/**
	 * La sottocartella con le barre a posto, **e niente altro**.
	 *
	 * Qui non si toglie nessun carattere, di proposito. Una riduzione che
	 * cambia il nome fa provare un percorso e scriverne un altro, che è il
	 * difetto della riga C-172: quello che la riduzione cambierebbe non si
	 * corregge in silenzio, si rifiuta. Vedi `provabile()`.
	 *
	 * @param string $sotto Sottocartella dichiarata.
	 * @return string
	 */
	private static function sotto( $sotto ) {
		$sotto = rtrim( str_replace( '\\', '/', (string) $sotto ), '/' );

		if ( '' === $sotto ) {
			return '';
		}

		return '/' === $sotto[0] ? $sotto : '/' . $sotto;
	}

	/**
	 * L'ambito si sa provare: il percorso che si chiede al server è esattamente
	 * quello in cui i byte finiscono.
	 *
	 * Passa quello che si può mettere in un percorso e in un indirizzo senza
	 * trasformarlo: lettere, cifre, punto, trattino e trattino basso nei nomi
	 * delle cartelle, e gli stessi meno il punto nell'estensione, che il punto
	 * lo ha già davanti. Fuori restano gli spazi, che in un indirizzo vanno
	 * scritti in un altro modo, e i due punti, che risalgono la cartella. Le
	 * maiuscole restano maiuscole: un server può distinguerle.
	 *
	 * **Le ancore sono `\A` e `\z`, non `^` e `$`.** In PCRE il dollaro accetta
	 * anche la posizione prima di un a capo finale, quindi una sottocartella che
	 * finisce con un a capo passerebbe la convalida: il disco quel carattere lo
	 * conserva, mentre l'indirizzo chiesto al server lo perde per strada, e si
	 * tornerebbe a provare un percorso e a scriverne un altro. Riga C-177.
	 *
	 * **Un'estensione vuota non passa**: un file senza estensione non e' un
	 * `.txt`, e l'esca che lo rappresenterebbe non si sa scrivere. Riga C-182.
	 *
	 * Righe C-172, C-174, C-177 e C-182.
	 *
	 * @param string $sotto      Sottocartella.
	 * @param string $estensione Estensione.
	 * @return bool
	 */
	private static function provabile( $sotto, $estensione ) {
		if ( ! preg_match( '/\A[A-Za-z0-9_-]{1,32}\z/', self::estensione( $estensione ) ) ) {
			return false;
		}

		$sotto = self::sotto( $sotto );

		if ( '' === $sotto ) {
			return true;
		}

		foreach ( explode( '/', ltrim( $sotto, '/' ) ) as $pezzo ) {
			if ( '.' === $pezzo || '..' === $pezzo || ! preg_match( '/\A[A-Za-z0-9._-]{1,64}\z/', $pezzo ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Il nome, senza estensione, comincia come quello delle esche.
	 *
	 * Tutto lo spazio dei nomi delle esche è riservato, non solo il nome
	 * fisso, e senza distinguere maiuscole e minuscole, che su alcuni dischi
	 * sono lo stesso file. Righe C-181 e C-193.
	 *
	 * @param string $percorso Percorso o nome di file.
	 * @return bool
	 */
	private static function nome_riservato( $percorso ) {
		return 0 === stripos( (string) pathinfo( $percorso, PATHINFO_FILENAME ), self::ESCA_PREFISSO );
	}

	/**
	 * Il messaggio con cui un nome riservato si rifiuta.
	 *
	 * @return string
	 */
	private static function messaggio_nome_riservato() {
		return sprintf(
			/* translators: %s: nome riservato all'esca. */
			__( 'Deposito rifiutato: i nomi che cominciano con «%s» sono riservati ai file esca della verifica.', 'conformita-core' ),
			self::ESCA_PREFISSO
		);
	}

	/**
	 * La chiave con cui l'esito di un ambito si conserva.
	 *
	 * Un ambito è la coppia sottocartella più estensione, cioè esattamente
	 * quello che un deposito produce e quello che una regola di server può
	 * trattare in modo diverso dal resto.
	 *
	 * @param string $sotto      Sottocartella.
	 * @param string $estensione Estensione.
	 * @return string
	 */
	public static function chiave_ambito( $sotto, $estensione ) {
		$sotto = self::sotto( $sotto );

		return ( '' === $sotto ? '/' : $sotto ) . '|' . self::estensione( $estensione );
	}

	/**
	 * L'ambito è quello generale, cioè l'esca `.txt` nella radice.
	 *
	 * @param string $sotto      Sottocartella.
	 * @param string $estensione Estensione.
	 * @return bool
	 */
	private static function generale( $sotto, $estensione ) {
		return '' === self::sotto( $sotto ) && 'txt' === self::estensione( $estensione );
	}

	/**
	 * La sottocartella in cui un deposito fatto adesso finirebbe.
	 *
	 * @return string
	 */
	public static function sottocartella_corrente() {
		$caricamenti = wp_upload_dir();

		return isset( $caricamenti['subdir'] ) ? (string) $caricamenti['subdir'] : '';
	}

	/**
	 * Il gettone dell'esca, generato una volta e conservato.
	 *
	 * Serve a distinguere "il server ha negato" da "è tornata una pagina
	 * qualsiasi con stato 200": senza, una schermata di accesso o un catch-all
	 * verrebbero scambiati per il nostro file, o per un rifiuto, a seconda di
	 * come si legge la risposta. Con il gettone le due cose non si confondono.
	 *
	 * @return string
	 */
	public static function gettone() {
		$conservato = get_option( self::OPZIONE, array() );

		if ( is_array( $conservato ) && ! empty( $conservato['gettone'] ) ) {
			return (string) $conservato['gettone'];
		}

		/*
		 * Dalla stessa sorgente del nome dell'esca, e per la stessa ragione:
		 * un gettone filtrato non deve poter essere vuoto o una parola che
		 * compare in qualunque pagina. Se la sorgente manca non si conserva
		 * niente, e `crea_esca()` non crea l'esca. Riga C-194.
		 */
		$gettone = self::casuale();

		if ( '' === $gettone ) {
			return '';
		}

		self::conserva( array( 'gettone' => $gettone ) );

		return $gettone;
	}

	/**
	 * Il contenuto atteso dell'esca.
	 *
	 * @return string
	 */
	public static function contenuto_esca() {
		return "Conformita Core: prova di accesso diretto.\n"
			. "Questo file esiste perche' un rifiuto a questo indirizzo dimostri che la cartella e' protetta.\n"
			. 'gettone: ' . self::gettone() . "\n";
	}

	/**
	 * I file di regole e il loro contenuto atteso.
	 *
	 * Le due forme di Apache convivono nello stesso file apposta: `Require all
	 * denied` è la 2.4, `deny from all` la 2.2, e un server legge quella che
	 * conosce ignorando l'altra.
	 *
	 * @return array<string, string>
	 */
	private static function regole() {
		return array(
			'.htaccess'  => "# Generato da Conformita Core: i file di questa cartella si servono solo\n"
				. "# dal punto di consegna, mai dal loro percorso.\n"
				. "<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n"
				. "<IfModule !mod_authz_core.c>\n\tOrder allow,deny\n\tdeny from all\n</IfModule>\n",
			'web.config' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration>\n\t<system.webServer>\n"
				. "\t\t<authorization>\n\t\t\t<deny users=\"*\" />\n\t\t</authorization>\n"
				. "\t</system.webServer>\n</configuration>\n",
			'index.php'  => "<?php\n// Silenzio.\n",
		);
	}

	/**
	 * Crea la cartella e scrive i file che mancano o che sono cambiati.
	 *
	 * @param bool        $riscritto Riferimento: diventa vero se qualcosa è stato scritto.
	 * @param string|null $cartella  Cartella fotografata dalla verifica, o null per quella di adesso.
	 * @return true|WP_Error
	 */
	private static function scrivi( &$riscritto, $cartella = null ) {
		$riscritto = false;
		$cartella  = null === $cartella ? self::cartella() : $cartella;

		if ( ! wp_mkdir_p( $cartella ) ) {
			return new WP_Error(
				'conformita_core_cartella_non_protetta',
				sprintf(
					/* translators: %s: percorso della cartella. */
					__( 'Cartella protetta non creabile: %s. Nessun file viene depositato finché non lo è.', 'conformita-core' ),
					$cartella
				),
				array( 'cartella' => $cartella )
			);
		}

		$attesi               = self::regole();
		$attesi[ self::ESCA ] = self::contenuto_esca();

		foreach ( $attesi as $nome => $contenuto ) {
			$percorso = $cartella . '/' . $nome;

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- si confronta il contenuto di un file locale appena scritto, non si scarica un indirizzo remoto. WP_Filesystem chiede credenziali e può non essere disponibile: la protezione della cartella non può dipendere da quelle.
			if ( is_readable( $percorso ) && file_get_contents( $percorso ) === $contenuto ) {
				continue;
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- come sopra: la protezione della cartella non può dipendere da credenziali FTP.
			if ( false === file_put_contents( $percorso, $contenuto, LOCK_EX ) ) {
				return new WP_Error(
					'conformita_core_cartella_non_protetta',
					sprintf(
						/* translators: %s: nome del file di regole. */
						__( 'File di protezione %s non scrivibile: la cartella resterebbe senza regole, quindi non si deposita niente.', 'conformita-core' ),
						$nome
					),
					array( 'file' => $nome )
				);
			}

			/*
			 * **La dimenticanza sta qui, al primo file scritto davvero.** Se si
			 * e' dovuto scrivere, le regole mancavano o erano diverse, quindi
			 * la cartella era aperta e ogni giudizio conservato parla di una
			 * configurazione che non c'e' piu'. Sta qui e non in chi chiama
			 * perche' questa funzione puo' scrivere il primo file e fallire sul
			 * secondo: chi chiama vedrebbe solo l'errore e conserverebbe
			 * giudizi vecchi su una cartella riscritta a meta'. Riga C-175.
			 */
			if ( ! $riscritto ) {
				self::dimentica();
			}

			$riscritto = true;
		}

		return true;
	}

	/**
	 * Crea la sottocartella di un ambito, se manca.
	 *
	 * @param string $sotto  Sottocartella.
	 * @param string $radice Cartella protetta fotografata dalla verifica.
	 * @return true|WP_Error
	 */
	private static function prepara_sottocartella( $sotto, $radice ) {
		$cartella = $radice . self::sotto( $sotto );

		if ( ! wp_mkdir_p( $cartella ) ) {
			return new WP_Error(
				'conformita_core_cartella_non_protetta',
				sprintf(
					/* translators: %s: percorso della sottocartella. */
					__( 'Sottocartella dei depositi non creabile: %s.', 'conformita-core' ),
					$cartella
				)
			);
		}

		return true;
	}

	/**
	 * I permessi che WordPress darà a un documento scritto in una cartella.
	 *
	 * Sono quelli della cartella, tolta l'esecuzione: è la regola con cui
	 * `_wp_handle_upload()` sistema il file appena spostato, qualunque sia la
	 * maschera del processo. Falso se la cartella non c'è. Riga C-189.
	 *
	 * @param string $cartella Cartella di destinazione.
	 * @return int|false
	 */
	private static function permessi_attesi( $cartella ) {
		clearstatcache( true, $cartella );

		if ( ! is_dir( $cartella ) ) {
			return false;
		}

		$stato = stat( $cartella );

		return false === $stato ? false : $stato['mode'] & 0666;
	}

	/**
	 * L'impronta di quello che decide se un utente qualunque arriva a un file
	 * di questa cartella.
	 *
	 * **I permessi del file non bastano.** Il server arriva al documento solo
	 * se può attraversare ogni cartella lungo il percorso, e questo lo
	 * decidono i permessi di esecuzione delle cartelle e i loro proprietari,
	 * non i permessi che WordPress dà al file. Una cartella a 0744 lascia il
	 * documento leggibile ma irraggiungibile, e l'ambito risulta negato;
	 * portata a 0755 lo rende raggiungibile senza che i permessi del documento
	 * cambino. Quindi l'impronta raccoglie, per ogni cartella dalla cartella
	 * dell'esca fino a quella dei caricamenti compresa, proprietario, gruppo e
	 * permessi interi, più utente e gruppo del processo che crea i file; un
	 * esito misurato con un'impronta diversa non si legge.
	 * Le cartelle sopra quella dei caricamenti restano fuori: se il server non
	 * le attraversasse, non servirebbe nessun caricamento del sito. Falso se
	 * una cartella manca o la cartella non sta sotto quella dei caricamenti.
	 * Riga C-191.
	 *
	 * @param string $cartella          Cartella dell'esca.
	 * @param string $cartella_protetta Cartella protetta, di cui si risale fino al genitore.
	 * @return string|false
	 */
	private static function impronta_accesso( $cartella, $cartella_protetta ) {
		$corrente = realpath( $cartella );
		$fine     = realpath( dirname( $cartella_protetta ) );

		if ( false === $corrente || false === $fine
			|| ( $corrente !== $fine && 0 !== strpos( $corrente, $fine . DIRECTORY_SEPARATOR ) )
		) {
			return false;
		}

		$pezzi = array();

		while ( true ) {
			clearstatcache( true, $corrente );
			$stato = stat( $corrente );

			if ( false === $stato ) {
				return false;
			}

			$pezzi[] = $corrente . '|' . $stato['uid'] . '|' . $stato['gid'] . '|' . decoct( $stato['mode'] & 07777 );

			if ( $corrente === $fine ) {
				break;
			}

			$corrente = dirname( $corrente );
		}

		/*
		 * Il proprietario e il gruppo dei documenti nuovi dipendono anche da
		 * chi li crea: un processo che cambia utente o gruppo crea file che il
		 * server può leggere diversamente. Dove l'identità del processo si sa
		 * leggere entra nell'impronta. Riga C-192.
		 */
		if ( function_exists( 'posix_geteuid' ) && function_exists( 'posix_getegid' ) ) {
			$pezzi[] = 'processo|' . posix_geteuid() . '|' . posix_getegid();
		}

		return hash( 'sha256', implode( "\n", $pezzi ) );
	}

	/**
	 * Crea l'esca di una verifica: un file nuovo, con un nome nuovo, con i
	 * permessi che avrà il documento, e controlla che li abbia.
	 *
	 * **Si prova un file che il server tratta come tratterà il documento, o
	 * non si prova niente.** Il documento è un file nuovo, creato da questo
	 * processo in questa cartella, con i permessi che gli dà WordPress e con
	 * un nome che nessuno ha mai chiesto. L'esca nasce allo stesso modo, a
	 * ogni verifica.
	 *
	 * - I permessi: con una maschera stretta l'esca nascerebbe leggibile solo
	 *   dal proprietario, e un server con un altro utente la negherebbe perché
	 *   non la sa leggere. Riga C-189.
	 * - Il file: un'esca rimasta da prima può avere un gruppo, liste di
	 *   controllo d'accesso o etichette diverse da quelle di un file nuovo.
	 *   Riga C-192.
	 * - Il nome: un indirizzo già chiesto può avere una risposta ricordata dal
	 *   server. Riga C-193.
	 *
	 * Il file si apre in modo esclusivo, quindi non ne sovrascrive mai un
	 * altro. Chi chiama lo toglie finita la richiesta. Se un passo non riesce,
	 * l'esito è `ignota`.
	 *
	 * @param string    $cartella   Cartella in cui il documento finirà.
	 * @param string    $estensione Estensione da provare.
	 * @param int|false $permessi   Permessi attesi per il documento.
	 * @return string|WP_Error Il percorso dell'esca.
	 */
	private static function crea_esca( $cartella, $estensione, $permessi ) {
		$nome   = self::nome_esca( $estensione );
		$errore = new WP_Error(
			'conformita_core_cartella_non_protetta',
			__( 'Esca non creata come un documento nuovo, con i suoi permessi: un diniego del server potrebbe voler dire solo che non la sa leggere.', 'conformita-core' )
		);

		if ( false === $permessi || false === $nome || '' === self::gettone() ) {
			return $errore;
		}

		$percorso = $cartella . '/' . $nome;

		// phpcs:disable WordPress.WP.AlternativeFunctions -- la protezione della cartella non può dipendere da credenziali FTP, e l'apertura esclusiva non ha un equivalente in WP_Filesystem.
		$file = fopen( $percorso, 'xb' );

		if ( false === $file ) {
			return $errore;
		}

		$scritto = fwrite( $file, self::contenuto_esca() );
		fclose( $file );

		clearstatcache( true, $percorso );

		if ( false !== $scritto && ( fileperms( $percorso ) & 0777 ) !== $permessi ) {
			chmod( $percorso, $permessi );
			clearstatcache( true, $percorso );
		}
		// phpcs:enable WordPress.WP.AlternativeFunctions

		if ( false === $scritto || ( fileperms( $percorso ) & 0777 ) !== $permessi ) {
			wp_delete_file( $percorso );

			return $errore;
		}

		return $percorso;
	}

	/**
	 * La fotografia di quello che una verifica prova.
	 *
	 * @param string $cartella  Cartella protetta, com'era all'inizio della verifica.
	 * @param string $indirizzo Indirizzo della cartella, com'era all'inizio della verifica.
	 * @param string $sotto     Sottocartella dell'esca.
	 * @return array<string, mixed>
	 */
	private static function fotografia( $cartella, $indirizzo, $sotto ) {
		return array(
			'cartella' => $cartella,
			'identita' => self::identita_di( $cartella, $indirizzo ),
			'permessi' => self::permessi_attesi( $cartella . self::sotto( $sotto ) ),
			'accesso'  => self::impronta_accesso( $cartella . self::sotto( $sotto ), $cartella ),
		);
	}

	/**
	 * Prepara la cartella, e rifà la verifica se ha dovuto scrivere.
	 *
	 * La riscrittura delle regole è uno dei tre momenti in cui la verifica si
	 * rifà, ed è quello che chiude il modo di guasto peggiore: un esito
	 * conservato direbbe ancora `verificata` su una cartella che nel frattempo
	 * qualcuno ha riaperto cancellando il file di regole. Righe C-117 e C-158.
	 *
	 * @return true|WP_Error
	 */
	public static function prepara() {
		$riscritto = false;
		$esito     = self::scrivi( $riscritto );

		if ( is_wp_error( $esito ) ) {
			return $esito;
		}

		/*
		 * Anche quando la cartella dei caricamenti non è quella in cui gli
		 * esiti sono stati misurati la verifica si rifà: è il secondo modo in
		 * cui un esito conservato smette di parlare della configurazione di
		 * adesso. Riga C-187.
		 */
		if ( ( $riscritto || ! self::stessa_radice( self::opzione() ) ) && ! self::$in_verifica ) {
			self::verifica();
		}

		return true;
	}

	/**
	 * Chiede l'esca al server e legge la risposta.
	 *
	 * **Prima si assicura che l'esca esista.** Senza, un 404 perché il file non
	 * c'è sarebbe indistinguibile da un 404 perché il server lo nega, e la
	 * verifica direbbe "protetta" su una cartella che non è nemmeno stata
	 * creata.
	 *
	 * **La verifica della certezza del certificato resta accesa.** Spegnerla
	 * renderebbe la verifica cieca a chi si mette in mezzo; su un sito con un
	 * certificato non riconosciuto l'esito è `ignota`, cioè il deposito si
	 * rifiuta, che è la direzione sicura.
	 *
	 * **Che cosa dimostra una verifica, e che cosa no.** Dimostra che quel
	 * percorso, con quell'estensione, è negato. Non dimostra niente sugli
	 * altri: un server può negare una cartella e servire lo stesso i file di
	 * una certa estensione, perché fra due regole non vince sempre quella che
	 * parla della cartella. Perciò l'esito si conserva per ambito, cioè per la
	 * coppia sottocartella più estensione, e il deposito guarda l'ambito suo.
	 * Righe C-169 e C-170.
	 *
	 * Una verifica dell'ambito generale azzera gli ambiti già provati: se le
	 * regole sono cambiate, quelle prove parlano di una configurazione che non
	 * c'è più.
	 *
	 * Righe C-154, C-155, C-156, C-163, C-164, C-165.
	 *
	 * @param string $sotto      Sottocartella da provare, vuota per la radice.
	 * @param string $estensione Estensione da provare.
	 * @return array<string, mixed> Lo stato, con l'esito appena misurato.
	 */
	public static function verifica( $sotto = '', $estensione = 'txt' ) {
		/*
		 * **Una fotografia sola, presa prima di scrivere l'esca.** Cartella e
		 * indirizzo si leggono qui una volta, e da qui vengono il percorso in
		 * cui si scrive l'esca, l'indirizzo che si chiede e l'identità con cui
		 * l'esito si conserva. Rileggerli dopo la richiesta attribuirebbe la
		 * risposta alla cartella che c'è dopo, che può non essere quella a cui
		 * si è chiesto: un diniego ricevuto da un indirizzo finirebbe
		 * conservato come esito di un altro, e il deposito lo riuserebbe senza
		 * chiedere. Conservato con l'identità della fotografia, un esito che
		 * non parla della cartella di adesso non si legge. Riga C-190.
		 */
		$cartella  = self::cartella();
		$indirizzo = self::indirizzo_cartella();

		/*
		 * Un ambito che non si sa provare non si misura nemmeno: scrivere
		 * un'esca in un percorso che non si sa chiedere darebbe una risposta
		 * che parla di un altro file. Vale `ignota`, cioe' il deposito si
		 * rifiuta. Righe C-172 e C-174.
		 */
		if ( ! self::provabile( $sotto, $estensione ) ) {
			return self::conserva_esito(
				$sotto,
				$estensione,
				array(
					'copertura'  => 'ignota',
					'istante'    => self::adesso(),
					'stato_http' => 0,
					'motivo'     => __( 'Questo percorso non si sa provare: il nome della sottocartella o dell\'estensione non si può chiedere al server così com\'è.', 'conformita-core' ),
				),
				self::fotografia( $cartella, $indirizzo, $sotto )
			);
		}

		self::$in_verifica = true;

		$riscritto = false;
		$scrittura = self::scrivi( $riscritto, $cartella );

		self::$in_verifica = false;

		/*
		 * **Se qui si e' dovuto riscrivere, tutto quello che si sapeva non vale
		 * piu'.** Le regole mancavano, quindi la cartella era aperta, e gli
		 * esiti conservati parlano di una configurazione che nel frattempo e'
		 * cambiata due volte. A dimenticarli e' `scrivi()`, al primo file che
		 * scrive davvero, cosi' vale anche quando poi fallisce a meta' e chi
		 * chiama vede solo l'errore. Qui resta l'altra meta': se la verifica
		 * chiesta era quella di un ambito solo, il giudizio generale non si
		 * lascia vuoto, si rimisura. Righe C-173 e C-175.
		 */
		if ( $riscritto && ! self::generale( $sotto, $estensione ) ) {
			self::verifica();
		}

		if ( ! is_wp_error( $scrittura ) && ! self::generale( $sotto, $estensione ) ) {
			$scrittura = self::prepara_sottocartella( $sotto, $cartella );
		}

		/*
		 * La fotografia si completa qui, a cartelle create: l'identità legge
		 * il percorso vero della cartella, e i permessi attesi quelli della
		 * sottocartella in cui il documento finirà.
		 */
		$prova = self::fotografia( $cartella, $indirizzo, $sotto );

		if ( ! is_wp_error( $scrittura ) ) {
			$scrittura = self::crea_esca( $cartella . self::sotto( $sotto ), $estensione, $prova['permessi'] );
		}

		if ( is_wp_error( $scrittura ) ) {
			return self::conserva_esito(
				$sotto,
				$estensione,
				array(
					'copertura'  => 'ignota',
					'istante'    => self::adesso(),
					'stato_http' => 0,
					'motivo'     => $scrittura->get_error_message(),
				),
				$prova
			);
		}

		$esca = $scrittura;

		try {
			$risposta = wp_remote_get(
				$indirizzo . self::sotto( $sotto ) . '/' . wp_basename( $esca ),
				array(
					'timeout'     => 10,
					'redirection' => 0,
				)
			);
		} finally {
			wp_delete_file( $esca );
		}

		if ( is_wp_error( $risposta ) ) {
			return self::conserva_esito(
				$sotto,
				$estensione,
				array(
					'copertura'  => 'ignota',
					'istante'    => self::adesso(),
					'stato_http' => 0,
					'motivo'     => __( 'La richiesta all\'esca non è riuscita: il giro sul sito stesso è bloccato o irraggiungibile.', 'conformita-core' ),
				),
				$prova
			);
		}

		$stato = (int) wp_remote_retrieve_response_code( $risposta );
		$corpo = (string) wp_remote_retrieve_body( $risposta );

		/*
		 * **Il gettone si guarda per primo, prima dello stato.** Se il contenuto
		 * dell'esca e' tornato indietro, i byte sono usciti dalla cartella, e con
		 * quale stato siano usciti non cambia niente. Guardare lo stato per primo
		 * lasciava passare il server che serve il file accompagnandolo con uno
		 * stato di errore, che e' quello che fa una rete di distribuzione mal
		 * configurata davanti all'origine. Riga C-165.
		 */
		if ( false !== strpos( $corpo, self::gettone() ) ) {
			return self::conserva_esito(
				$sotto,
				$estensione,
				array(
					'copertura'  => 'non_coperta',
					'istante'    => self::adesso(),
					'stato_http' => $stato,
					'motivo'     => __( 'Il server serve il contenuto della cartella protetta: i file sarebbero scaricabili dal loro percorso.', 'conformita-core' ),
				),
				$prova
			);
		}

		if ( in_array( $stato, self::DINIEGHI, true ) ) {
			return self::conserva_esito(
				$sotto,
				$estensione,
				array(
					'copertura'  => 'verificata',
					'istante'    => self::adesso(),
					'stato_http' => $stato,
					'motivo'     => __( 'Il server nega il percorso della cartella protetta.', 'conformita-core' ),
				),
				$prova
			);
		}

		if ( $stato >= 200 && $stato <= 299 ) {
			return self::conserva_esito(
				$sotto,
				$estensione,
				array(
					'copertura'  => 'ignota',
					'istante'    => self::adesso(),
					'stato_http' => $stato,
					'motivo'     => __( 'Risposta positiva ma estranea: non è il nostro file, e non è un rifiuto. Può essere una schermata di accesso o una pagina generica.', 'conformita-core' ),
				),
				$prova
			);
		}

		/*
		 * Tutto il resto non dimostra niente, in nessuna delle due direzioni: un
		 * reindirizzamento, di cui non si sa dove porti perche' la richiesta non
		 * lo segue; un'indisponibilita' temporanea, che parla del momento e non
		 * delle regole della cartella; uno stato fuori posto. Vale `ignota`, cioe'
		 * il deposito si rifiuta, che e' la direzione sicura. Righe C-163 e C-164.
		 */
		return self::conserva_esito(
			$sotto,
			$estensione,
			array(
				'copertura'  => 'ignota',
				'istante'    => self::adesso(),
				'stato_http' => $stato,
				'motivo'     => __( 'Il server non serve e non nega: risponde con un reindirizzamento, con un\'indisponibilità temporanea o con un altro stato che non dice niente sulle regole della cartella.', 'conformita-core' ),
			),
			$prova
		);
	}

	/**
	 * L'istante corrente nel fuso del sito, in forma leggibile e confrontabile.
	 *
	 * @return string
	 */
	private static function adesso() {
		return ( new DateTimeImmutable( 'now', wp_timezone() ) )->format( 'c' );
	}

	/**
	 * Scrive nell'opzione i campi indicati, lasciando intatti gli altri.
	 *
	 * @param array<string, mixed> $campi Campi da aggiornare.
	 * @return array<string, mixed> Lo stato completo.
	 */
	private static function conserva( array $campi ) {
		$conservato = get_option( self::OPZIONE, array() );
		$conservato = is_array( $conservato ) ? $conservato : array();

		update_option( self::OPZIONE, array_merge( $conservato, $campi ), false );

		return self::stato();
	}

	/**
	 * Conserva l'esito di un ambito, e quando serve anche quello generale.
	 *
	 * Due regole, e tutte e due hanno un motivo.
	 *
	 * La verifica dell'ambito generale **azzera gli ambiti già provati**: se si
	 * rifà quella, o le regole sono cambiate o qualcuno l'ha chiesta, e in
	 * tutti e due i casi le prove vecchie parlano di una configurazione che non
	 * c'è più.
	 *
	 * Un esito `non_coperta` di qualunque ambito **diventa anche quello
	 * generale**: il gettone è uscito, quindi la cartella serve i suoi file, e
	 * non è una cosa che riguardi solo quell'estensione. In tutti gli altri
	 * casi un ambito parla solo di sé, perché un diniego su un percorso non
	 * dimostra niente sugli altri.
	 *
	 * @param string               $sotto      Sottocartella provata.
	 * @param string               $estensione Estensione provata.
	 * @param array<string, mixed> $campi      Esito misurato.
	 * @param array<string, mixed> $prova      Fotografia di quello che si è provato: l'esito si conserva per quella, non per la cartella di adesso.
	 * @return array<string, mixed> Lo stato completo.
	 */
	private static function conserva_esito( $sotto, $estensione, array $campi, array $prova ) {
		$generale = self::generale( $sotto, $estensione );

		$conservato = get_option( self::OPZIONE, array() );
		$conservato = is_array( $conservato ) ? $conservato : array();
		$stessa     = isset( $conservato['radice'] ) && $prova['identita'] === $conservato['radice'];

		/*
		 * **L'identità della cartella si scrive solo insieme all'esito
		 * generale**, perché dice dove quell'esito è stato misurato, e gli
		 * esiti degli ambiti valgono solo accanto a un generale della stessa
		 * cartella. Scriverla accanto all'esito di un ambito, dopo uno
		 * spostamento, farebbe tornare valido il generale misurato sulla
		 * cartella vecchia. Finché il generale non si rimisura, l'ambito appena
		 * provato si conserva ma non si legge, e `prepara()` vede l'identità
		 * diversa e rifà la verifica generale. Riga C-187.
		 */
		$ambiti = $stessa && isset( $conservato['ambiti'] ) && is_array( $conservato['ambiti'] ) ? $conservato['ambiti'] : array();

		if ( $generale ) {
			$ambiti = array();
		}

		/*
		 * Ogni ambito porta la sua identità, e non solo il generale: un
		 * ambito misurato su una cartella B, conservato accanto al generale
		 * di A, tornerebbe valido il giorno in cui si torna ad A, e direbbe
		 * `verificata` per un indirizzo di A mai provato. Riga C-187.
		 */
		$ambiti[ self::chiave_ambito( $sotto, $estensione ) ] = array_merge(
			$campi,
			array(
				'radice'  => $prova['identita'],
				'accesso' => $prova['accesso'],
			)
		);

		$nuovi = array( 'ambiti' => $ambiti );

		if ( $generale || 'non_coperta' === $campi['copertura'] ) {
			$nuovi = array_merge(
				$campi,
				$nuovi,
				array(
					'radice'  => $prova['identita'],
					'accesso' => self::impronta_accesso( $prova['cartella'], $prova['cartella'] ),
				)
			);
		}

		return self::conserva( $nuovi );
	}

	/**
	 * Qualcuno parlerebbe prima della guardia sullo spostamento dei byte.
	 *
	 * Vero quando alla priorità più bassa che esiste c'è già un aggancio che
	 * non è la guardia, in testa alla fila: prima che la guardia sia agganciata
	 * vuol dire un aggancio qualunque, dopo vuol dire uno che le sta davanti.
	 * Riga C-186.
	 *
	 * @return bool
	 */
	private static function conteso() {
		global $wp_filter;

		if ( ! isset( $wp_filter['pre_move_uploaded_file'] )
			|| ! $wp_filter['pre_move_uploaded_file'] instanceof WP_Hook
			|| empty( $wp_filter['pre_move_uploaded_file']->callbacks[ PHP_INT_MIN ] )
		) {
			return false;
		}

		$primo = array_key_first( $wp_filter['pre_move_uploaded_file']->callbacks[ PHP_INT_MIN ] );

		return _wp_filter_build_unique_id( 'pre_move_uploaded_file', array( __CLASS__, 'guardia_destinazione' ), PHP_INT_MIN ) !== $primo;
	}

	/**
	 * L'errore con cui un deposito conteso si rifiuta.
	 *
	 * @return WP_Error
	 */
	private static function errore_conteso() {
		return new WP_Error(
			'conformita_core_spostamento_conteso',
			__( 'Deposito rifiutato: un altro componente è agganciato allo spostamento dei file prima del controllo sulla destinazione, quindi potrebbe scrivere i byte prima che il controllo avvenga.', 'conformita-core' )
		);
	}

	/**
	 * L'opzione conservata così com'è.
	 *
	 * @return array<string, mixed>
	 */
	private static function opzione() {
		$conservato = get_option( self::OPZIONE, array() );

		return is_array( $conservato ) ? $conservato : array();
	}

	/**
	 * L'identità della cartella protetta: dove sta sul disco e da quale
	 * indirizzo si raggiunge.
	 *
	 * Un esito dice che un indirizzo è negato, e le regole di un server come
	 * nginx parlano di indirizzi, non di cartelle. Se la cartella dei
	 * caricamenti si sposta, o cambia indirizzo, con regole ed esche copiate
	 * tali e quali, nessun file risulta riscritto: senza questa identità gli
	 * esiti vecchi continuerebbero a valere per un indirizzo mai provato.
	 * Riga C-187.
	 *
	 * @return string
	 */
	private static function identita() {
		return self::identita_di( self::cartella(), self::indirizzo_cartella() );
	}

	/**
	 * L'identità di una cartella e di un indirizzo dati.
	 *
	 * @param string $cartella  Cartella protetta.
	 * @param string $indirizzo Indirizzo della cartella protetta.
	 * @return string
	 */
	private static function identita_di( $cartella, $indirizzo ) {
		$reale = realpath( $cartella );

		return wp_normalize_path( false === $reale ? $cartella : $reale ) . '|' . $indirizzo;
	}

	/**
	 * La cartella in cui sta l'esca di un ambito conservato.
	 *
	 * @param string $chiave Chiave dell'ambito.
	 * @return string
	 */
	private static function cartella_ambito( $chiave ) {
		$fine  = strrpos( $chiave, '|' );
		$sotto = false === $fine ? '' : substr( $chiave, 0, $fine );

		return self::cartella() . ( '/' === $sotto ? '' : $sotto );
	}

	/**
	 * Gli esiti conservati parlano della cartella di adesso.
	 *
	 * Della stessa cartella, allo stesso indirizzo, e con la stessa impronta
	 * di accesso: permessi e proprietari delle cartelle decidono sia i
	 * permessi che WordPress dà a un documento sia se il server ci arriva, e
	 * un esito misurato quando erano altri parla di documenti diversi da
	 * quelli che si scriverebbero. Righe C-187, C-189 e C-191.
	 *
	 * @param array<string, mixed> $conservato Opzione conservata.
	 * @return bool
	 */
	private static function stessa_radice( array $conservato ) {
		return isset( $conservato['radice'] )
			&& array_key_exists( 'accesso', $conservato )
			&& self::identita() === $conservato['radice']
			&& self::impronta_accesso( self::cartella(), self::cartella() ) === $conservato['accesso'];
	}

	/**
	 * L'opzione conservata, con gli esiti tolti se parlano di un'altra cartella.
	 *
	 * La scadenza di un esito è una proprietà del dato letto: non serve che
	 * qualcuno rifaccia la verifica perché un esito misurato altrove smetta di
	 * valere. Il gettone resta, perché non dipende dalla cartella.
	 *
	 * @return array<string, mixed>
	 */
	private static function conservato() {
		$conservato = get_option( self::OPZIONE, array() );
		$conservato = is_array( $conservato ) ? $conservato : array();

		if ( self::stessa_radice( $conservato ) ) {
			$identita = self::identita();

			if ( isset( $conservato['ambiti'] ) && is_array( $conservato['ambiti'] ) ) {
				$conservato['ambiti'] = array_filter(
					$conservato['ambiti'],
					static function ( $ambito, $chiave ) use ( $identita ) {
						return is_array( $ambito )
							&& isset( $ambito['radice'] )
							&& array_key_exists( 'accesso', $ambito )
							&& $identita === $ambito['radice']
							&& self::impronta_accesso( self::cartella_ambito( (string) $chiave ), self::cartella() ) === $ambito['accesso'];
					},
					ARRAY_FILTER_USE_BOTH
				);
			}

			return $conservato;
		}

		$misurato = isset( $conservato['copertura'] ) || ! empty( $conservato['ambiti'] );

		unset( $conservato['copertura'], $conservato['istante'], $conservato['stato_http'], $conservato['motivo'], $conservato['ambiti'] );

		if ( $misurato ) {
			$conservato['copertura'] = 'ignota';
			$conservato['motivo']    = self::motivo_radice_cambiata();
		}

		return $conservato;
	}

	/**
	 * Il motivo con cui un esito misurato altrove smette di valere.
	 *
	 * @return string
	 */
	private static function motivo_radice_cambiata() {
		return __( 'La cartella dei caricamenti, il suo indirizzo o i permessi delle sue cartelle non sono quelli con cui la protezione è stata verificata: gli esiti misurati prima parlano di un\'altra cartella.', 'conformita-core' );
	}

	/**
	 * Dimentica ogni esito conservato, generale e di ambito.
	 *
	 * Si chiama quando le regole della cartella sono state riscritte: da quel
	 * momento nessuna delle prove fatte prima parla della configurazione che
	 * c'è adesso.
	 *
	 * @return void
	 */
	private static function dimentica() {
		$conservato = get_option( self::OPZIONE, array() );
		$conservato = is_array( $conservato ) ? $conservato : array();

		update_option(
			self::OPZIONE,
			array_merge(
				$conservato,
				array(
					'copertura'  => 'ignota',
					'istante'    => '',
					'stato_http' => 0,
					'motivo'     => __( 'I file di regole sono stati riscritti: quello che si sapeva prima non vale più.', 'conformita-core' ),
					'ambiti'     => array(),
					'radice'     => self::identita(),
					'accesso'    => self::impronta_accesso( self::cartella(), self::cartella() ),
				)
			),
			false
		);
	}

	/**
	 * L'esito conservato per un ambito, letto e non misurato.
	 *
	 * @param string $sotto      Sottocartella.
	 * @param string $estensione Estensione.
	 * @return array<string, mixed>
	 */
	public static function stato_ambito( $sotto, $estensione ) {
		$conservato = self::conservato();

		$ambiti = isset( $conservato['ambiti'] ) && is_array( $conservato['ambiti'] ) ? $conservato['ambiti'] : array();
		$chiave = self::chiave_ambito( $sotto, $estensione );

		if ( ! isset( $ambiti[ $chiave ] ) || ! is_array( $ambiti[ $chiave ] ) ) {
			return array(
				'copertura'  => 'ignota',
				'istante'    => '',
				'stato_http' => 0,
				'motivo'     => __( 'Questo percorso, con questa estensione, non è mai stato provato.', 'conformita-core' ),
				'ambito'     => $chiave,
			);
		}

		return array_merge( $ambiti[ $chiave ], array( 'ambito' => $chiave ) );
	}

	/**
	 * Lo stato della protezione, letto e non misurato.
	 *
	 * **Non fa nessuna richiesta.** Leggere lo stato è una cosa che capita
	 * spesso, misurarlo costa una richiesta HTTP: sono due operazioni diverse e
	 * hanno due funzioni diverse. Riga C-146.
	 *
	 * @return array<string, mixed>
	 */
	public static function stato() {
		$conservato = self::conservato();

		$cartella = self::cartella();
		$regole   = array();

		foreach ( array_keys( self::regole() ) as $nome ) {
			$regole[ $nome ] = file_exists( $cartella . '/' . $nome );
		}

		$regole[ self::ESCA ] = file_exists( $cartella . '/' . self::ESCA );

		return array(
			'copertura'  => isset( $conservato['copertura'] ) ? (string) $conservato['copertura'] : 'ignota',
			'istante'    => isset( $conservato['istante'] ) ? (string) $conservato['istante'] : '',
			'stato_http' => isset( $conservato['stato_http'] ) ? (int) $conservato['stato_http'] : 0,
			'motivo'     => isset( $conservato['motivo'] ) ? (string) $conservato['motivo'] : __( 'La protezione non è mai stata verificata.', 'conformita-core' ),
			'cartella'   => $cartella,
			'esiste'     => is_dir( $cartella ),
			'regole'     => $regole,
			'ambiti'     => isset( $conservato['ambiti'] ) && is_array( $conservato['ambiti'] ) ? $conservato['ambiti'] : array(),
			'scavalcata' => self::scavalcata(),
		);
	}

	/**
	 * Lo scavalcamento è dichiarato.
	 *
	 * Non è la strada normale per installare dove i file di regole non bastano:
	 * quella è configurare il server e far dire `verificata` alla verifica. È
	 * per gli ambienti in cui il giro sul sito stesso non funziona per
	 * costruzione, e chi lo dichiara si prende la responsabilità che la verifica
	 * non ha potuto prendersi. Lo stato continua a riportare l'esito vero
	 * accanto allo scavalcamento, senza mascherarlo. Riga C-120.
	 *
	 * @return bool
	 */
	private static function scavalcata() {
		if ( null !== self::$scavalcamento ) {
			return self::$scavalcamento;
		}

		return defined( 'CONFORMITA_CORE_PROTEZIONE_CONFERMATA' ) && CONFORMITA_CORE_PROTEZIONE_CONFERMATA;
	}

	/**
	 * Forza lo scavalcamento.
	 *
	 * @internal Solo per le prove: in esercizio si dichiara con la costante.
	 *
	 * @param bool|null $valore Valore forzato, oppure null per tornare alla costante.
	 */
	public static function fissa_scavalcamento( $valore ) {
		self::$scavalcamento = null === $valore ? null : (bool) $valore;
	}

	/**
	 * Ferma lo spostamento quando la destinazione vera non è quella provata.
	 *
	 * **Perché non basta prevedere il nome.** `deposita()` calcola in anticipo
	 * dove il file andrà a finire, e lo calcola con la stessa funzione che
	 * deciderà il nome: è la previsione più fedele che si possa fare da fuori.
	 * Resta una previsione. Dentro `wp_handle_sideload()` c'è un aggancio,
	 * `wp_handle_sideload_prefilter`, con cui un altro componente può cambiare il
	 * nome del file **dopo** quel calcolo, estensione compresa; da lì il nome
	 * definitivo si rifà da capo. Un componente che rinomina `atto.pdf` in
	 * `atto.pdf-x` farebbe provare un ambito e scriverne un altro, che è il
	 * difetto della riga C-172 all'ultimo momento utile.
	 *
	 * Quindi l'ultima parola non ce l'ha la previsione: ce l'ha questa guardia,
	 * che gira sul percorso definitivo, dopo tutti gli agganci e prima che un
	 * solo byte si muova.
	 *
	 * **Come si ferma.** Non restituendo `false`: quel filtro non ha un modo di
	 * dire «rifiuta», e un valore diverso da `null` fa saltare la copia ma non
	 * ferma la sequenza, che poi prova a dare i permessi a un file che non
	 * esiste. Si esce con un'eccezione dedicata, che `deposita()` raccoglie e
	 * converte nell'errore con il motivo vero. Riga C-178.
	 *
	 * **Decide sempre, anche se qualcun altro ha già risposto.** Lo stesso
	 * aggancio serve ai componenti che spostano i file per conto proprio, per
	 * esempio verso un deposito esterno: rispondono con un valore e la copia
	 * non si fa. Se la guardia tacesse ogni volta che trova una risposta
	 * pronta, basterebbe uno di quei componenti perché il controllo sulla
	 * destinazione non girasse mai. Quindi la guardia si aggancia per prima,
	 * alla priorità più bassa che esiste, e controlla comunque. Chi si fosse
	 * agganciato a quella stessa priorità prima di lei parlerebbe prima di
	 * lei, e per questo il deposito, in quel caso, si rifiuta prima di
	 * cominciare. Un file che la guardia trova già al suo posto, quindi,
	 * c'era prima dello spostamento: lo spostamento si ferma, e il file non si
	 * tocca. Righe C-179 e C-186.
	 *
	 * @internal Aggiunta e tolta attorno allo spostamento dei byte, come il
	 *           dirottamento.
	 *
	 * @param null|bool $scavalco    Esito già deciso da qualcun altro.
	 * @param mixed     $file        Voce del file che sta per essere copiato.
	 * @param string    $nuovo_file  Percorso definitivo della destinazione.
	 * @return null|bool
	 * @throws Conformita_Core_Deposito_Fermato Quando la destinazione non è provata.
	 */
	public static function guardia_destinazione( $scavalco, $file = null, $nuovo_file = '' ) {
		$nuovo_file = (string) $nuovo_file;

		/*
		 * **L'origine si ricontrolla sul file che si sposta davvero.**
		 * `deposita()` chiede `is_uploaded_file()` all'ingresso, ma fra
		 * l'ingresso e questo punto c'e' `wp_handle_sideload_prefilter`, e un
		 * aggancio li' puo' sostituire il file caricato con un file locale
		 * qualunque: un caricamento dichiarato diventerebbe la copia di un file
		 * del server, cioe' la lettura di file arbitrari che quel controllo
		 * doveva impedire. Qui il file e' quello che WordPress sta per copiare,
		 * e nessun aggancio viene dopo a cambiarlo. Si rifiuta prima di
		 * guardare la destinazione, e non si tocca ne' la sorgente ne' la
		 * destinazione. Riga C-184.
		 *
		 * Un'origine che la guardia non conosce la ferma, invece di lasciarla
		 * passare: vuol dire che la guardia sta girando fuori da un deposito,
		 * o che qualcosa ha azzerato lo stato a meta' strada, e in tutti e due
		 * i casi il controllo sull'origine non si sa fare.
		 */
		if ( 'caricamento' !== self::$origine_in_corso && 'percorso_locale' !== self::$origine_in_corso ) {
			self::$fermata = array(
				'codice'    => 'conformita_core_origine_non_dichiarata',
				'messaggio' => __( 'Deposito fermato: la guardia non conosce l\'origine dichiarata per questo spostamento, quindi non può controllarla.', 'conformita-core' ),
			);

			throw new Conformita_Core_Deposito_Fermato( 'conformita_core_origine_non_dichiarata' );
		}

		if ( 'caricamento' === self::$origine_in_corso
			&& ( ! is_array( $file ) || empty( $file['tmp_name'] ) || ! is_string( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) )
		) {
			self::$fermata = array(
				'codice'    => 'conformita_core_origine_incoerente',
				'messaggio' => __( 'Deposito fermato: dichiarata origine «caricamento», ma il file che stava per essere copiato non proviene da un caricamento HTTP. Un aggancio lo ha sostituito dopo il controllo all\'ingresso.', 'conformita-core' ),
			);

			throw new Conformita_Core_Deposito_Fermato( 'conformita_core_origine_incoerente' );
		}

		/*
		 * **Una destinazione che esiste gia' ferma lo spostamento, e non si
		 * tocca.** Nessuno puo' averla scritta in questo spostamento: il
		 * deposito si rifiuta se trova qualcuno agganciato prima della guardia
		 * (riga C-186), e la guardia e' la prima a parlare. Quindi un file gia'
		 * li' c'era prima, e l'ha fatto trovare un aggancio che ha imposto quel
		 * nome: copiarci sopra sostituirebbe un documento senza nessun errore,
		 * e toglierlo lo cancellerebbe. La prima stesura della guardia lo
		 * toglieva, pensando a un file messo li' da chi aveva risposto prima di
		 * lei; adesso quel caso non si puo' piu' dare, e il file che si
		 * toglierebbe e' solo quello di qualcun altro. Righe C-179 e C-186.
		 */
		if ( '' === $nuovo_file || file_exists( $nuovo_file ) ) {
			self::$fermata = array(
				'codice'    => 'conformita_core_destinazione_occupata',
				'messaggio' => __( 'Deposito fermato: al percorso di destinazione c\'è già un file, e copiarci sopra lo sostituirebbe senza nessun errore.', 'conformita-core' ),
			);

			throw new Conformita_Core_Deposito_Fermato( 'conformita_core_destinazione_occupata' );
		}

		/*
		 * Il dirottamento si spegne per il tempo del controllo: `cartella()` e
		 * `indirizzo_cartella()` leggono la cartella dei caricamenti, e con il
		 * dirottamento acceso vedrebbero la cartella protetta dentro se stessa.
		 */
		remove_filter( 'upload_dir', array( __CLASS__, 'dirotta' ) );

		try {
			$ammessa = self::destinazione_ammessa( $nuovo_file );
		} finally {
			add_filter( 'upload_dir', array( __CLASS__, 'dirotta' ) );
		}

		if ( ! $ammessa ) {
			throw new Conformita_Core_Deposito_Fermato( 'conformita_core_destinazione_non_ammessa' );
		}

		return $scavalco;
	}

	/**
	 * Il percorso definitivo sta in un ambito provato.
	 *
	 * @param string $percorso Percorso assoluto della destinazione.
	 * @return bool
	 */
	private static function destinazione_ammessa( $percorso ) {
		/*
		 * **La barra inversa si guarda prima di normalizzare.** Dove il
		 * separatore e' la barra, la barra inversa e' un carattere come un
		 * altro e puo' stare nel nome di un file; `wp_normalize_path()` la
		 * trasforma in un separatore. Si controllerebbe allora il file `b`
		 * nella cartella `a`, mentre la copia scrive il file `a\b` nella
		 * cartella di sopra, che e' un altro ambito. Il percorso che si
		 * controlla deve essere quello che si copia, carattere per carattere.
		 * Riga C-185.
		 */
		if ( '/' === DIRECTORY_SEPARATOR && false !== strpos( (string) $percorso, '\\' ) ) {
			self::$fermata = array(
				'codice'    => 'conformita_core_destinazione_non_canonica',
				'messaggio' => __( 'Deposito fermato: il nome della destinazione contiene una barra inversa, che su questo sistema non separa le cartelle, quindi il percorso controllato non sarebbe quello copiato.', 'conformita-core' ),
			);

			return false;
		}

		$percorso = wp_normalize_path( $percorso );
		$radice   = wp_normalize_path( self::cartella() );

		/*
		 * **Il confronto si fa sul percorso vero, non su quello scritto.** Il
		 * nome puo' cominciare per quello della cartella protetta e portare
		 * lo stesso fuori: basta che una delle sottocartelle sia un
		 * collegamento simbolico verso un'altra parte del disco. La consegna
		 * fa lo stesso confronto con `realpath()` prima di leggere, e la
		 * stessa domanda va fatta prima di scrivere, perche' un file finito
		 * fuori dalla cartella protetta non e' protetto da niente. Si guarda
		 * la cartella di destinazione, che esiste gia', perche' il file no.
		 * Riga C-180.
		 */
		$cartella_reale = realpath( dirname( $percorso ) );
		$radice_reale   = realpath( $radice );

		if ( 0 !== strpos( $percorso, $radice . '/' )
			|| false === $cartella_reale
			|| false === $radice_reale
			|| ( $cartella_reale !== $radice_reale && 0 !== strpos( $cartella_reale, $radice_reale . DIRECTORY_SEPARATOR ) )
		) {
			self::$fermata = array(
				'codice'    => 'conformita_core_destinazione_fuori_cartella',
				'messaggio' => __( 'Deposito fermato: la destinazione definitiva del file è fuori dalla cartella protetta.', 'conformita-core' ),
			);

			return false;
		}

		$relativo   = substr( $percorso, strlen( $radice ) );
		$sotto      = dirname( $relativo );
		$sotto      = '/' === $sotto || '.' === $sotto ? '' : $sotto;
		$estensione = (string) pathinfo( $relativo, PATHINFO_EXTENSION );

		/*
		 * **Il percorso vero deve essere quello scritto, non solo stare
		 * dentro.** Il controllo qui sopra ferma i collegamenti che portano
		 * fuori; questo ferma quelli che restano dentro. Se la sottocartella
		 * scritta e' un collegamento a un'altra sottocartella, lo stesso file
		 * si raggiunge da due indirizzi, e l'esito si ricava da quello
		 * scritto: il server puo' negare l'uno e servire l'altro, e provarne
		 * uno non dice niente dell'altro. Quindi la cartella vera deve essere
		 * esattamente la radice vera piu' la sottocartella scritta.
		 *
		 * La radice vera si ricava da quella della cartella dei caricamenti, e
		 * non dalla cartella protetta stessa: se fosse la cartella protetta a
		 * essere un collegamento verso un'altra cartella servita, il confronto
		 * con la sua stessa radice vera passerebbe sempre. La cartella dei
		 * caricamenti invece puo' stare dietro un collegamento, com'e' normale
		 * quando i file stanno su un disco condiviso: il suo indirizzo e'
		 * quello che WordPress conosce, e non ne apre un altro.
		 *
		 * Per la stessa ragione il file di destinazione non puo' essere gia'
		 * un collegamento: uno che non punta a niente fa sembrare libero il
		 * nome, e la copia scriverebbe dove punta. Riga C-183.
		 */
		$base_reale = realpath( dirname( $radice ) );
		$attesa     = false === $base_reale ? '' : wp_normalize_path( $base_reale ) . '/' . self::CARTELLA . $sotto;
		$vera       = wp_normalize_path( $cartella_reale );

		if ( '' === $attesa || $vera !== $attesa || is_link( $percorso ) ) {
			self::$fermata = array(
				'codice'    => 'conformita_core_destinazione_non_canonica',
				'messaggio' => __( 'Deposito fermato: la destinazione passa per un collegamento simbolico, quindi il file sarebbe raggiungibile anche da un percorso che non è stato provato.', 'conformita-core' ),
			);

			return false;
		}

		if ( self::nome_riservato( $relativo ) ) {
			self::$fermata = array(
				'codice'    => 'conformita_core_nome_riservato',
				'messaggio' => self::messaggio_nome_riservato(),
			);

			return false;
		}

		if ( ! self::provabile( $sotto, $estensione ) ) {
			self::$fermata = array(
				'codice'    => 'conformita_core_destinazione_non_provabile',
				'messaggio' => sprintf(
					/* translators: 1: sottocartella di destinazione, 2: estensione del file. */
					__( 'Deposito fermato: la destinazione definitiva («%1$s», estensione «%2$s») non è provabile, perché non si può chiedere al server esattamente com\'è.', 'conformita-core' ),
					'' === $sotto ? '/' : $sotto,
					$estensione
				),
			);

			return false;
		}

		if ( self::scavalcata() ) {
			return true;
		}

		$ambito = self::stato_ambito( $sotto, $estensione );

		if ( 'verificata' !== $ambito['copertura'] ) {
			self::verifica( $sotto, $estensione );
			$ambito = self::stato_ambito( $sotto, $estensione );
		}

		if ( 'verificata' === $ambito['copertura'] ) {
			return true;
		}

		self::$fermata = array(
			'codice'    => 'conformita_core_protezione_non_verificata',
			'messaggio' => sprintf(
				/* translators: 1: estensione del file, 2: sottocartella di destinazione, 3: esito della verifica, 4: motivo. */
				__( 'Deposito fermato: per i file «%1$s» in «%2$s» la protezione risulta «%3$s». %4$s Finché non è verificata non si scrive nessun file.', 'conformita-core' ),
				$estensione,
				'' === $sotto ? '/' : $sotto,
				$ambito['copertura'],
				$ambito['motivo']
			),
		);

		return false;
	}

	/**
	 * Dirotta la cartella di destinazione dei caricamenti.
	 *
	 * @internal Aggiunto e tolto attorno allo spostamento dei byte, mai lasciato
	 *           acceso: vedi la nota in `deposita()`.
	 *
	 * @param array<string, string> $caricamenti Cartella dei caricamenti.
	 * @return array<string, string>
	 */
	public static function dirotta( $caricamenti ) {
		if ( ! is_array( $caricamenti ) || ! isset( $caricamenti['basedir'], $caricamenti['baseurl'] ) ) {
			return $caricamenti;
		}

		$sotto = isset( $caricamenti['subdir'] ) ? $caricamenti['subdir'] : '';

		$caricamenti['basedir'] = $caricamenti['basedir'] . '/' . self::CARTELLA;
		$caricamenti['baseurl'] = $caricamenti['baseurl'] . '/' . self::CARTELLA;
		$caricamenti['path']    = $caricamenti['basedir'] . $sotto;
		$caricamenti['url']     = $caricamenti['baseurl'] . $sotto;

		return $caricamenti;
	}

	/**
	 * Deposita un file su un contenuto di tipo gestito.
	 *
	 * **L'ordine dei controlli non è casuale.** La cartella si prepara prima di
	 * guardare l'esito della verifica, perché preparare può accorgersi che le
	 * regole sono sparite e rifare la verifica: guardare prima significherebbe
	 * decidere con un esito vecchio. E la verifica si guarda prima di toccare i
	 * byte, perché un file scritto in una cartella aperta non si richiama
	 * indietro.
	 *
	 * **Il filtro che dirotta i caricamenti si spegne prima di registrare
	 * l'allegato**, e non è un dettaglio. `_wp_relative_upload_path()` calcola
	 * il percorso memorizzato rispetto alla cartella dei caricamenti *come la
	 * vede in quel momento*: a filtro acceso memorizzerebbe `2026/09/atto.pdf`,
	 * che riletto a filtro spento punta a un file pubblico con lo stesso nome,
	 * se esiste. Sarebbe una collisione che consegna il documento sbagliato
	 * senza produrre nessun errore. A filtro spento memorizza il percorso per
	 * intero, segmento protetto compreso. Riga C-115.
	 *
	 * **Non si generano dimensioni intermedie.** `wp_generate_attachment_metadata()`
	 * non viene chiamata: per un documento le dimensioni intermedie non servono,
	 * e ogni file derivato sarebbe un indirizzo in più da governare.
	 *
	 * @param int                  $post_id Contenuto padre.
	 * @param array<string, mixed> $file    Voce nella forma di `$_FILES`.
	 * @param array<string, mixed> $opzioni Opzioni: `origine` è obbligatoria.
	 * @return int|WP_Error Identificativo dell'allegato, oppure errore.
	 */
	public static function deposita( $post_id, array $file, array $opzioni ) {
		/*
		 * **Un deposito alla volta.** Dentro uno spostamento girano gli
		 * agganci di chiunque, e uno di loro potrebbe chiamare il deposito
		 * una seconda volta. Quel deposito aggancerebbe gli stessi filtri,
		 * che WordPress non registra due volte, e alla fine li sgancerebbe:
		 * il deposito esterno riprenderebbe senza guardia e senza
		 * dirottamento, e il suo file finirebbe nella cartella pubblica dei
		 * caricamenti. Il deposito interno si rifiuta prima di toccare
		 * qualunque cosa, e il segno si toglie in un `finally` che copre
		 * tutto il deposito esterno. Riga C-188.
		 */
		if ( self::$in_deposito ) {
			return new WP_Error(
				'conformita_core_deposito_annidato',
				__( 'Deposito rifiutato: un altro deposito è in corso, e questo è stato chiamato dal suo interno. Si deposita un file alla volta.', 'conformita-core' )
			);
		}

		self::$in_deposito = true;

		try {
			return self::deposita_uno( $post_id, $file, $opzioni );
		} finally {
			self::$in_deposito = false;
		}
	}

	/**
	 * Il deposito vero, con la garanzia che nessun altro sia in corso.
	 *
	 * @param int                  $post_id Contenuto padre.
	 * @param array<string, mixed> $file    Voce nella forma di `$_FILES`.
	 * @param array<string, mixed> $opzioni Opzioni.
	 * @return int|WP_Error
	 */
	private static function deposita_uno( $post_id, array $file, array $opzioni ) {
		$post_id = (int) $post_id;
		$tipo    = get_post_type( $post_id );

		if ( false === $tipo || ! Conformita_Core_Tipi::registrato( $tipo ) ) {
			return new WP_Error(
				'conformita_core_tipo_non_gestito',
				__( 'Deposito: il contenuto non è di un tipo registrato da questo componente, quindi nessuna politica lo governa.', 'conformita-core' )
			);
		}

		$origine = isset( $opzioni['origine'] ) ? (string) $opzioni['origine'] : '';

		if ( 'caricamento' !== $origine && 'percorso_locale' !== $origine ) {
			return new WP_Error(
				'conformita_core_origine_non_dichiarata',
				__( 'Deposito: l\'origine del file va dichiarata, e vale «caricamento» oppure «percorso_locale». Non esiste un valore predefinito: darne uno sarebbe sbagliato in tutte e due le direzioni.', 'conformita-core' )
			);
		}

		if ( empty( $file['tmp_name'] ) || empty( $file['name'] ) || ! is_string( $file['tmp_name'] ) ) {
			return new WP_Error(
				'conformita_core_deposito_fallito',
				__( 'Deposito: il file da depositare non è indicato.', 'conformita-core' )
			);
		}

		if ( 'caricamento' === $origine && ! is_uploaded_file( $file['tmp_name'] ) ) {
			return new WP_Error(
				'conformita_core_origine_incoerente',
				__( 'Deposito: dichiarata origine «caricamento», ma il file non proviene da un caricamento HTTP. Un percorso ricevuto dall\'esterno e trattato come caricamento sarebbe una lettura di file arbitrari.', 'conformita-core' )
			);
		}

		/*
		 * **Nessuno prima della guardia.** La guardia si aggancia alla
		 * priorita' piu' bassa che esiste, ma a quella stessa priorita' un
		 * componente agganciato prima di lei parla prima di lei: se copia il
		 * file, i byte sono sul disco prima di ogni controllo; se lo manda
		 * altrove, la guardia non ha nemmeno piu' niente da togliere. Non c'e'
		 * modo di passargli davanti, quindi il deposito si rifiuta prima di
		 * cominciare, senza chiedere niente al server. Chi si aggancia dopo, a
		 * qualunque priorita', parla dopo la guardia. Riga C-186.
		 */
		if ( self::conteso() ) {
			return self::errore_conteso();
		}

		$preparata = self::prepara();

		if ( is_wp_error( $preparata ) ) {
			return $preparata;
		}

		$stato = self::stato();

		if ( 'verificata' !== $stato['copertura'] && ! $stato['scavalcata'] ) {
			return new WP_Error(
				'conformita_core_protezione_non_verificata',
				sprintf(
					/* translators: 1: esito della verifica, 2: motivo, 3: istante della verifica. */
					__( 'Deposito rifiutato: la protezione della cartella risulta «%1$s». %2$s Verifica del %3$s. Finché non è verificata non si scrive nessun file.', 'conformita-core' ),
					$stato['copertura'],
					$stato['motivo'],
					'' === $stato['istante'] ? __( 'mai eseguita', 'conformita-core' ) : $stato['istante']
				),
				$stato
			);
		}

		$tipo_file = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'] );

		if ( empty( $tipo_file['type'] ) || empty( $tipo_file['ext'] ) ) {
			return new WP_Error(
				'conformita_core_tipo_file_non_ammesso',
				sprintf(
					/* translators: %s: nome del file. */
					__( 'Deposito: il tipo del file %s non è fra quelli che questa installazione ammette.', 'conformita-core' ),
					$file['name']
				)
			);
		}

		/*
		 * **L'istante si fissa qui e si passa a chi sposta i byte.** La
		 * sottocartella dipende dalla data, quindi calcolarla adesso e lasciare
		 * che `wp_handle_sideload()` la ricalcoli per conto suo significa
		 * provare un percorso e scriverne un altro, la notte del primo del
		 * mese. Riga C-172.
		 */
		$tempo       = current_time( 'mysql' );
		$caricamenti = wp_upload_dir( $tempo );
		$sotto       = isset( $caricamenti['subdir'] ) ? (string) $caricamenti['subdir'] : '';

		/*
		 * **L'estensione e' quella che finira' sul disco, non quella
		 * dichiarata.** Provare `pdf` mentre il file si chiama `.pdf-x`, o
		 * `.PDF` mentre WordPress lo scrivera' `.pdf`, e' lo stesso difetto
		 * della sottocartella per un'altra via: si prova un indirizzo e se ne
		 * apre un altro.
		 *
		 * Il nome definitivo non si ricostruisce a mano, si chiede alla stessa
		 * funzione che lo decidera' fra poche righe: `wp_unique_filename()`
		 * ripulisce il nome, abbassa le maiuscole dell'estensione e per le
		 * immagini tiene conto della conversione di formato. Rifarne il lavoro
		 * qui significherebbe rifarlo per meta', e la meta' che manca e'
		 * esattamente quella che apre un percorso non provato. Riga C-174.
		 *
		 * Quello che resta non provabile dopo questo passaggio, come
		 * un'estensione con uno spazio, non si deposita: non si corregge in
		 * silenzio a un nome vicino. Righe C-172 e C-174.
		 */
		$nome_dichiarato = empty( $tipo_file['proper_filename'] )
			? (string) $file['name']
			: (string) $tipo_file['proper_filename'];
		$nome_finale     = wp_unique_filename( self::cartella() . self::sotto( $sotto ), $nome_dichiarato );
		$estensione      = (string) pathinfo( $nome_finale, PATHINFO_EXTENSION );

		/*
		 * **Il nome dell'esca e' riservato.** Un documento che si chiamasse
		 * come lei finirebbe al suo posto il giorno in cui l'esca manca, e la
		 * verifica successiva, trovando un contenuto diverso da quello atteso,
		 * lo sovrascriverebbe con il proprio: un atto sostituito da una riga di
		 * prova, senza nessun errore. Il controllo sta qui per rifiutare prima
		 * di chiedere niente al server, e nella guardia per il nome definitivo.
		 * Riga C-181.
		 */
		if ( self::nome_riservato( $nome_finale ) ) {
			return new WP_Error( 'conformita_core_nome_riservato', self::messaggio_nome_riservato() );
		}

		if ( ! self::provabile( $sotto, $estensione ) ) {
			return new WP_Error(
				'conformita_core_destinazione_non_provabile',
				sprintf(
					/* translators: 1: sottocartella di destinazione, 2: estensione del file. */
					__( 'Deposito rifiutato: il percorso di destinazione («%1$s», estensione «%2$s») non è provabile, perché non si può chiedere al server esattamente com\'è.', 'conformita-core' ),
					'' === $sotto ? '/' : $sotto,
					$estensione
				),
				array(
					'sottocartella' => $sotto,
					'estensione'    => $estensione,
				)
			);
		}

		/*
		 * **L'ambito di questo deposito, provato prima di muovere i byte.**
		 * L'esito generale dice che la radice della cartella e' negata a una
		 * richiesta per un `.txt`. Questo file non e' quello: ha un'altra
		 * estensione e finisce in un'altra sottocartella, e fra due regole di
		 * server non vince sempre quella che parla della cartella. Quindi
		 * prima di scrivere si prova il percorso vero, una volta per ambito e
		 * non a ogni deposito: se l'esito c'e' gia' non parte nessuna
		 * richiesta. Righe C-169 e C-170.
		 */
		if ( ! $stato['scavalcata'] ) {
			$ambito = self::stato_ambito( $sotto, $estensione );

			if ( 'verificata' !== $ambito['copertura'] ) {
				self::verifica( $sotto, $estensione );
				$ambito = self::stato_ambito( $sotto, $estensione );
			}

			if ( 'verificata' !== $ambito['copertura'] ) {
				return new WP_Error(
					'conformita_core_protezione_non_verificata',
					sprintf(
						/* translators: 1: estensione del file, 2: sottocartella di destinazione, 3: esito della verifica, 4: motivo. */
						__( 'Deposito rifiutato: per i file «%1$s» in «%2$s» la protezione risulta «%3$s». %4$s Finché non è verificata non si scrive nessun file.', 'conformita-core' ),
						$estensione,
						'' === $sotto ? '/' : $sotto,
						$ambito['copertura'],
						$ambito['motivo']
					),
					$ambito
				);
			}
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';

		self::$fermata          = array();
		self::$origine_in_corso = $origine;

		add_filter( 'upload_dir', array( __CLASS__, 'dirotta' ) );
		add_filter( 'pre_move_uploaded_file', array( __CLASS__, 'guardia_destinazione' ), PHP_INT_MIN, 3 );

		/*
		 * **Il `finally` non e' prudenza generica.** `wp_handle_sideload()` emette
		 * agganci a cui si attacca chiunque, e un'eccezione sollevata la' dentro
		 * salterebbe la riga che spegne il filtro: da quel punto in poi, per tutto
		 * il resto della richiesta, ogni caricamento finirebbe nella cartella
		 * protetta e ogni percorso memorizzato sarebbe calcolato rispetto a una
		 * cartella diversa da quella vera. E' la stessa collisione silenziosa che
		 * la riga C-115 sorveglia, per un'altra via. Riga C-166.
		 */
		try {
			/*
			 * Lo stesso controllo, ripetuto adesso che la guardia e'
			 * agganciata: fra il primo e questo punto ci sono la preparazione
			 * della cartella e le richieste al server, e un aggancio aggiunto
			 * li' in mezzo, alla priorita' della guardia, parlerebbe prima di
			 * lei. Da qui in poi chi si aggancia a quella priorita' finisce
			 * dopo. Riga C-186.
			 */
			if ( self::conteso() ) {
				return self::errore_conteso();
			}

			$spostato = wp_handle_sideload(
				$file,
				array(
					'test_form' => false,
					'action'    => 'wp_handle_sideload',
				),
				$tempo
			);
		} catch ( Conformita_Core_Deposito_Fermato $fermato ) {
			/*
			 * L'ha sollevata la guardia qui sotto, e solo lei: e' un tipo
			 * dedicato apposta, cosi' un'eccezione di un aggancio altrui
			 * continua a propagarsi invece di diventare un rifiuto silenzioso.
			 * Il motivo vero e' quello che la guardia ha messo da parte, perche'
			 * l'aggancio da cui esce sa dire solo si' o no. Riga C-178.
			 */
			unset( $fermato );

			$fermata       = self::$fermata;
			self::$fermata = array();

			return new WP_Error(
				$fermata['codice'],
				$fermata['messaggio']
			);
		} finally {
			self::$origine_in_corso = '';

			remove_filter( 'pre_move_uploaded_file', array( __CLASS__, 'guardia_destinazione' ), PHP_INT_MIN );
			remove_filter( 'upload_dir', array( __CLASS__, 'dirotta' ) );
		}

		if ( ! is_array( $spostato ) || isset( $spostato['error'] ) || empty( $spostato['file'] ) ) {
			return new WP_Error(
				'conformita_core_deposito_fallito',
				isset( $spostato['error'] )
					? (string) $spostato['error']
					: __( 'Deposito: lo spostamento del file non è riuscito.', 'conformita-core' )
			);
		}

		$percorso = $spostato['file'];

		$allegato = wp_insert_attachment(
			array(
				'post_mime_type' => $tipo_file['type'],
				'post_title'     => sanitize_text_field( wp_basename( $percorso ) ),
				'post_content'   => '',
				'post_status'    => 'inherit',
				'post_parent'    => $post_id,
			),
			$percorso,
			$post_id,
			true
		);

		if ( is_wp_error( $allegato ) ) {
			return $allegato;
		}

		$impronta = hash_file( self::ALGORITMO, $percorso );

		update_post_meta( $allegato, self::MARCA, '1' );
		update_post_meta( $allegato, self::IMPRONTA, self::ALGORITMO . ':' . $impronta );
		update_post_meta( $allegato, self::DIMENSIONE, (int) filesize( $percorso ) );
		update_post_meta( $allegato, self::DEPOSITO, self::adesso() );

		return (int) $allegato;
	}

	/**
	 * L'allegato è stato depositato attraverso il core.
	 *
	 * **Non restituisce mai un errore**, per la stessa ragione per cui non lo fa
	 * `Conformita_Core_Scadenza::scaduto()`: è chiamata nel percorso di lettura.
	 *
	 * @param int $allegato_id Identificativo dell'allegato.
	 * @return bool
	 */
	public static function protetto( $allegato_id ) {
		$allegato_id = (int) $allegato_id;

		if ( $allegato_id <= 0 || 'attachment' !== get_post_type( $allegato_id ) ) {
			return false;
		}

		return '1' === (string) get_post_meta( $allegato_id, self::MARCA, true );
	}

	/**
	 * L'impronta conservata al deposito.
	 *
	 * **Non si ricalcola.** Ricalcolarla alla lettura costerebbe la lettura
	 * dell'intero file a ogni richiesta e introdurrebbe un modo di guasto nuovo,
	 * cioè rifiutare un file pubblico perché un confronto non torna. Il limite
	 * che ne discende, cioè che un file sostituito sul disco non si nota, è
	 * dichiarato e ha la sua riga, la C-125.
	 *
	 * @param int $allegato_id Identificativo dell'allegato.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function impronta( $allegato_id ) {
		$allegato_id = (int) $allegato_id;

		if ( ! self::protetto( $allegato_id ) ) {
			return new WP_Error(
				'conformita_core_allegato_non_gestito',
				__( 'Impronta: l\'allegato non è stato depositato attraverso questo componente.', 'conformita-core' )
			);
		}

		$valore = (string) get_post_meta( $allegato_id, self::IMPRONTA, true );

		if ( '' === $valore || false === strpos( $valore, ':' ) ) {
			return new WP_Error(
				'conformita_core_impronta_assente',
				__( 'Impronta: l\'allegato non ne ha una registrata.', 'conformita-core' )
			);
		}

		list( $algoritmo, $cifre ) = explode( ':', $valore, 2 );

		return array(
			'algoritmo'  => $algoritmo,
			'valore'     => $cifre,
			'dimensione' => (int) get_post_meta( $allegato_id, self::DIMENSIONE, true ),
			'deposito'   => (string) get_post_meta( $allegato_id, self::DEPOSITO, true ),
		);
	}

	/**
	 * Riporta la classe allo stato iniziale.
	 *
	 * @internal Solo per le prove.
	 */
	public static function azzera() {
		self::$scavalcamento    = null;
		self::$in_verifica      = false;
		self::$fermata          = array();
		self::$origine_in_corso = '';
		self::$in_deposito      = false;

		delete_option( self::OPZIONE );
	}
}
