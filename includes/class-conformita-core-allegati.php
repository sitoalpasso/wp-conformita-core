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
 * Righe di collaudo C-115..C-125, C-146, C-154..C-158, C-163..C-166.
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
	 * Il file esca, bersaglio della verifica.
	 */
	const ESCA = 'prova-accesso-diretto.txt';

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
	 * Indirizzo dell'esca.
	 *
	 * @return string
	 */
	public static function indirizzo_esca() {
		return self::indirizzo_cartella() . '/' . self::ESCA;
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

		$gettone = wp_generate_password( 32, false, false );

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
	 * @param bool $riscritto Riferimento: diventa vero se qualcosa è stato scritto.
	 * @return true|WP_Error
	 */
	private static function scrivi( &$riscritto ) {
		$riscritto = false;
		$cartella  = self::cartella();

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

			$riscritto = true;
		}

		return true;
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

		if ( $riscritto && ! self::$in_verifica ) {
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
	 * Righe C-154, C-155, C-156, C-163, C-164, C-165.
	 *
	 * @return array<string, mixed> Lo stato, con l'esito appena misurato.
	 */
	public static function verifica() {
		self::$in_verifica = true;

		$riscritto = false;
		$scrittura = self::scrivi( $riscritto );

		self::$in_verifica = false;

		if ( is_wp_error( $scrittura ) ) {
			return self::conserva(
				array(
					'copertura'  => 'ignota',
					'istante'    => self::adesso(),
					'stato_http' => 0,
					'motivo'     => $scrittura->get_error_message(),
				)
			);
		}

		$risposta = wp_remote_get(
			self::indirizzo_esca(),
			array(
				'timeout'     => 10,
				'redirection' => 0,
			)
		);

		if ( is_wp_error( $risposta ) ) {
			return self::conserva(
				array(
					'copertura'  => 'ignota',
					'istante'    => self::adesso(),
					'stato_http' => 0,
					'motivo'     => __( 'La richiesta all\'esca non è riuscita: il giro sul sito stesso è bloccato o irraggiungibile.', 'conformita-core' ),
				)
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
			return self::conserva(
				array(
					'copertura'  => 'non_coperta',
					'istante'    => self::adesso(),
					'stato_http' => $stato,
					'motivo'     => __( 'Il server serve il contenuto della cartella protetta: i file sarebbero scaricabili dal loro percorso.', 'conformita-core' ),
				)
			);
		}

		if ( in_array( $stato, self::DINIEGHI, true ) ) {
			return self::conserva(
				array(
					'copertura'  => 'verificata',
					'istante'    => self::adesso(),
					'stato_http' => $stato,
					'motivo'     => __( 'Il server nega il percorso della cartella protetta.', 'conformita-core' ),
				)
			);
		}

		if ( $stato >= 200 && $stato <= 299 ) {
			return self::conserva(
				array(
					'copertura'  => 'ignota',
					'istante'    => self::adesso(),
					'stato_http' => $stato,
					'motivo'     => __( 'Risposta positiva ma estranea: non è il nostro file, e non è un rifiuto. Può essere una schermata di accesso o una pagina generica.', 'conformita-core' ),
				)
			);
		}

		/*
		 * Tutto il resto non dimostra niente, in nessuna delle due direzioni: un
		 * reindirizzamento, di cui non si sa dove porti perche' la richiesta non
		 * lo segue; un'indisponibilita' temporanea, che parla del momento e non
		 * delle regole della cartella; uno stato fuori posto. Vale `ignota`, cioe'
		 * il deposito si rifiuta, che e' la direzione sicura. Righe C-163 e C-164.
		 */
		return self::conserva(
			array(
				'copertura'  => 'ignota',
				'istante'    => self::adesso(),
				'stato_http' => $stato,
				'motivo'     => __( 'Il server non serve e non nega: risponde con un reindirizzamento, con un\'indisponibilità temporanea o con un altro stato che non dice niente sulle regole della cartella.', 'conformita-core' ),
			)
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
	 * Lo stato della protezione, letto e non misurato.
	 *
	 * **Non fa nessuna richiesta.** Leggere lo stato è una cosa che capita
	 * spesso, misurarlo costa una richiesta HTTP: sono due operazioni diverse e
	 * hanno due funzioni diverse. Riga C-146.
	 *
	 * @return array<string, mixed>
	 */
	public static function stato() {
		$conservato = get_option( self::OPZIONE, array() );
		$conservato = is_array( $conservato ) ? $conservato : array();

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

		require_once ABSPATH . 'wp-admin/includes/file.php';

		add_filter( 'upload_dir', array( __CLASS__, 'dirotta' ) );

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
			$spostato = wp_handle_sideload(
				$file,
				array(
					'test_form' => false,
					'action'    => 'wp_handle_sideload',
				)
			);
		} finally {
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
		self::$scavalcamento = null;
		self::$in_verifica   = false;

		delete_option( self::OPZIONE );
	}
}
