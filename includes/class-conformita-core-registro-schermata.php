<?php
/**
 * La schermata di consultazione del registro delle modifiche.
 *
 * **Si legge e basta.** La schermata non ha pulsanti che scrivono, né azioni
 * sulle righe né azioni di gruppo: il modulo dei filtri si invia con una
 * richiesta di lettura. Una richiesta di scrittura verso la schermata non trova
 * nessuno che la ascolti.
 *
 * **Si apre solo con la capability dedicata.** Core non la assegna a nessuno:
 * finché il componente non la dà a un ruolo, la schermata non compare nel menu
 * di nessuno. Riga C-52.
 *
 * Righe di collaudo C-212..C-221.
 *
 * @package Conformita_Core
 */

defined( 'ABSPATH' ) || exit;

/**
 * Voce di menu e pagina di consultazione.
 */
final class Conformita_Core_Registro_Schermata {

	/**
	 * Identificativo della pagina di amministrazione.
	 */
	const PAGINA = 'conformita-core-registro';

	/**
	 * Azione del gettone che accompagna i filtri.
	 */
	const AZIONE_FILTRI = 'conformita_core_registro_filtri';

	/**
	 * Nome del campo che porta il gettone.
	 */
	const CAMPO_GETTONE = 'conformita_core_gettone';

	/**
	 * Voci per pagina.
	 *
	 * È un numero di impaginazione, non una soglia che cambi fra enti.
	 */
	const PER_PAGINA = 50;

	/**
	 * I filtri che la schermata accetta dall'indirizzo.
	 */
	const FILTRI = array( 'sezione', 'contenuto', 'azione', 'utente', 'dal', 'al' );

	/**
	 * La schermata ha già agganciato il menu.
	 *
	 * @var bool
	 */
	private static $avviata = false;

	/**
	 * Aggancia la voce di menu.
	 *
	 * @internal Si accende al caricamento di core.
	 */
	public static function avvia() {
		if ( self::$avviata ) {
			return;
		}

		self::$avviata = true;

		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
	}

	/**
	 * La schermata è agganciata.
	 *
	 * @return bool
	 */
	public static function avviata() {
		return self::$avviata;
	}

	/**
	 * Registra la voce di menu, protetta dalla capability dedicata.
	 */
	public static function menu() {
		add_menu_page(
			__( 'Registro delle modifiche', 'conformita-core' ),
			__( 'Registro delle modifiche', 'conformita-core' ),
			Conformita_Core_Registro::CAPACITA,
			self::PAGINA,
			array( __CLASS__, 'mostra' ),
			'dashicons-list-view'
		);
	}

	/**
	 * L'indirizzo della schermata.
	 *
	 * @param array<string, string> $argomenti Argomenti da aggiungere.
	 * @return string
	 */
	public static function indirizzo( array $argomenti = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::PAGINA ), $argomenti ), admin_url( 'admin.php' ) );
	}

	/**
	 * I filtri richiesti, se il gettone è valido.
	 *
	 * Senza gettone valido i filtri non si applicano e la schermata lo dice:
	 * filtrare è una lettura, ma la regola vuole il gettone su ogni superficie,
	 * e un indirizzo con i filtri costruito da altri non deve poter far credere
	 * a chi lo apre di vedere una selezione che nessuno ha chiesto.
	 *
	 * @return array{filtri: array<string, string>, rifiutati: bool}
	 */
	public static function filtri_richiesti() {
		$presenti = array();

		foreach ( self::FILTRI as $nome ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Il gettone si verifica subito sotto, prima di usare qualunque filtro.
			if ( isset( $_GET[ $nome ] ) && is_string( $_GET[ $nome ] ) ) {
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Come sopra.
				$valore = sanitize_text_field( wp_unslash( $_GET[ $nome ] ) );

				if ( '' !== $valore ) {
					$presenti[ $nome ] = $valore;
				}
			}
		}

		if ( array() === $presenti ) {
			return array(
				'filtri'    => array(),
				'rifiutati' => false,
			);
		}

		$gettone = isset( $_GET[ self::CAMPO_GETTONE ] ) && is_string( $_GET[ self::CAMPO_GETTONE ] )
			? sanitize_text_field( wp_unslash( $_GET[ self::CAMPO_GETTONE ] ) )
			: '';

		if ( ! wp_verify_nonce( $gettone, self::AZIONE_FILTRI ) ) {
			return array(
				'filtri'    => array(),
				'rifiutati' => true,
			);
		}

		return array(
			'filtri'    => $presenti,
			'rifiutati' => false,
		);
	}

	/**
	 * La pagina richiesta.
	 *
	 * @return int
	 */
	private static function pagina_richiesta() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Il numero di pagina non seleziona niente: sposta la finestra sulle stesse voci.
		$pagina = isset( $_GET['paged'] ) && is_string( $_GET['paged'] ) ? absint( wp_unslash( $_GET['paged'] ) ) : 1;

		return max( 1, $pagina );
	}

	/**
	 * Stampa la schermata.
	 */
	public static function mostra() {
		if ( ! current_user_can( Conformita_Core_Registro::CAPACITA ) ) {
			wp_die(
				esc_html__( 'Non hai il permesso di consultare il registro delle modifiche.', 'conformita-core' ),
				'',
				array( 'response' => 403 )
			);
		}

		$richiesta = self::filtri_richiesti();
		$filtri    = $richiesta['filtri'];
		$pagina    = self::pagina_richiesta();

		$interrogazione = $filtri;
		$errore         = false;
		$voci           = array();
		$totale         = 0;

		$totale = Conformita_Core_Registro::conta( $interrogazione );

		if ( is_wp_error( $totale ) ) {
			$errore = true;
			$totale = 0;
		} else {
			$voci = Conformita_Core_Registro::voci(
				array_merge(
					$interrogazione,
					array(
						'ordine'     => 'decrescente',
						'per_pagina' => self::PER_PAGINA,
						'pagina'     => $pagina,
					)
				)
			);

			if ( is_wp_error( $voci ) ) {
				$errore = true;
				$voci   = array();
			}
		}

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Registro delle modifiche', 'conformita-core' ) . '</h1>';
		echo '<p>' . esc_html__( 'Le voci si aggiungono e non si modificano né si cancellano. Gli orari sono nel fuso del sito.', 'conformita-core' ) . '</p>';

		self::mostra_mancate();

		if ( $richiesta['rifiutati'] ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'I filtri dell\'indirizzo non sono stati applicati perché la richiesta non è valida o è scaduta. Reimpostali dal modulo.', 'conformita-core' ) . '</p></div>';
		}

		if ( $errore ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Uno dei filtri non è valido: controlla il numero del contenuto, dell\'utente e le date (AAAA-MM-GG).', 'conformita-core' ) . '</p></div>';
		}

		self::mostra_modulo( $filtri );
		self::mostra_tabella( $voci );
		self::mostra_pagine( $filtri, $pagina, $totale );

		echo '</div>';
	}

	/**
	 * L'avviso delle voci automatiche che non si sono potute scrivere.
	 */
	private static function mostra_mancate() {
		$mancate = Conformita_Core_Registro::mancate();

		if ( $mancate['conteggio'] <= 0 ) {
			return;
		}

		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: 1: numero di operazioni, 2: data e ora della prima, 3: data e ora dell'ultima. */
					__( 'Attenzione: %1$d operazioni non sono state registrate perché la scrittura nel registro è fallita, la prima il %2$s e l\'ultima il %3$s. Il registro ha un buco in quel periodo.', 'conformita-core' ),
					$mancate['conteggio'],
					self::data( $mancate['prima'] ),
					self::data( $mancate['ultima'] )
				)
			)
		);
	}

	/**
	 * Il modulo dei filtri, inviato con una richiesta di lettura.
	 *
	 * @param array<string, string> $filtri Filtri applicati.
	 */
	private static function mostra_modulo( array $filtri ) {
		$etichette = array(
			'sezione'   => __( 'Sezione', 'conformita-core' ),
			'contenuto' => __( 'Numero del contenuto', 'conformita-core' ),
			'azione'    => __( 'Operazione', 'conformita-core' ),
			'utente'    => __( 'Numero dell\'utente (0 per il sistema)', 'conformita-core' ),
			'dal'       => __( 'Dal giorno', 'conformita-core' ),
			'al'        => __( 'Al giorno', 'conformita-core' ),
		);

		echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::PAGINA ) . '" />';
		wp_nonce_field( self::AZIONE_FILTRI, self::CAMPO_GETTONE, false );
		echo '<fieldset><legend>' . esc_html__( 'Filtri', 'conformita-core' ) . '</legend>';

		foreach ( $etichette as $nome => $etichetta ) {
			$tipo  = in_array( $nome, array( 'dal', 'al' ), true ) ? 'date' : 'text';
			$campo = 'conformita-core-filtro-' . $nome;

			echo '<p><label for="' . esc_attr( $campo ) . '">' . esc_html( $etichetta ) . '</label> ';
			echo '<input type="' . esc_attr( $tipo ) . '" id="' . esc_attr( $campo ) . '" name="' . esc_attr( $nome ) . '" value="' . esc_attr( isset( $filtri[ $nome ] ) ? $filtri[ $nome ] : '' ) . '" /></p>';
		}

		echo '</fieldset>';
		echo '<p><button type="submit" class="button">' . esc_html__( 'Filtra', 'conformita-core' ) . '</button> ';
		echo '<a href="' . esc_url( self::indirizzo() ) . '">' . esc_html__( 'Togli i filtri', 'conformita-core' ) . '</a></p>';
		echo '</form>';
	}

	/**
	 * La tabella delle voci.
	 *
	 * @param array<int, array<string, mixed>> $voci Voci da mostrare.
	 */
	private static function mostra_tabella( array $voci ) {
		if ( array() === $voci ) {
			echo '<p>' . esc_html__( 'Nessuna voce.', 'conformita-core' ) . '</p>';
			return;
		}

		$colonne = array(
			__( 'Voce', 'conformita-core' ),
			__( 'Quando', 'conformita-core' ),
			__( 'Chi', 'conformita-core' ),
			__( 'Sezione', 'conformita-core' ),
			__( 'Contenuto', 'conformita-core' ),
			__( 'Operazione', 'conformita-core' ),
			__( 'Origine', 'conformita-core' ),
			__( 'Motivazione', 'conformita-core' ),
			__( 'Dettagli', 'conformita-core' ),
			__( 'Rimanda alla voce', 'conformita-core' ),
		);

		echo '<table class="widefat striped"><caption class="screen-reader-text">' . esc_html__( 'Voci del registro, dalla più recente', 'conformita-core' ) . '</caption><thead><tr>';

		foreach ( $colonne as $colonna ) {
			echo '<th scope="col">' . esc_html( $colonna ) . '</th>';
		}

		echo '</tr></thead><tbody>';

		foreach ( $voci as $voce ) {
			echo '<tr>';
			echo '<td>' . esc_html( (string) $voce['id'] ) . '</td>';
			echo '<td>' . esc_html( self::data( $voce['istante']->format( 'Y-m-d H:i:s' ) ) ) . '</td>';
			echo '<td>' . esc_html( self::utente( $voce['utente'] ) ) . '</td>';
			echo '<td>' . esc_html( $voce['sezione'] ) . '</td>';
			echo '<td>' . esc_html( self::contenuto( $voce['contenuto'], $voce['tipo'] ) ) . '</td>';
			echo '<td>' . esc_html( $voce['azione'] ) . '</td>';
			echo '<td>' . esc_html( $voce['origine'] ) . '</td>';
			echo '<td>' . ( null === $voce['motivazione'] ? '' : nl2br( esc_html( $voce['motivazione'] ) ) ) . '</td>';
			echo '<td>' . esc_html( self::dettagli( $voce['dettagli'] ) ) . '</td>';
			echo '<td>' . esc_html( null === $voce['riferimento'] ? '' : (string) $voce['riferimento'] ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * I collegamenti alle pagine, con i filtri e il gettone.
	 *
	 * @param array<string, string> $filtri Filtri applicati.
	 * @param int                   $pagina Pagina corrente.
	 * @param int                   $totale Voci che rispondono ai filtri.
	 */
	private static function mostra_pagine( array $filtri, $pagina, $totale ) {
		$pagine = (int) ceil( $totale / self::PER_PAGINA );

		if ( $pagine <= 1 ) {
			return;
		}

		$argomenti = $filtri;

		if ( array() !== $filtri ) {
			$argomenti[ self::CAMPO_GETTONE ] = wp_create_nonce( self::AZIONE_FILTRI );
		}

		echo '<nav aria-label="' . esc_attr__( 'Pagine del registro', 'conformita-core' ) . '"><p>';

		if ( $pagina > 1 ) {
			echo '<a href="' . esc_url( self::indirizzo( array_merge( $argomenti, array( 'paged' => (string) ( $pagina - 1 ) ) ) ) ) . '">' . esc_html__( 'Pagina precedente', 'conformita-core' ) . '</a> ';
		}

		echo esc_html(
			sprintf(
				/* translators: 1: pagina corrente, 2: numero di pagine. */
				__( 'Pagina %1$d di %2$d', 'conformita-core' ),
				$pagina,
				$pagine
			)
		);

		if ( $pagina < $pagine ) {
			echo ' <a href="' . esc_url( self::indirizzo( array_merge( $argomenti, array( 'paged' => (string) ( $pagina + 1 ) ) ) ) ) . '">' . esc_html__( 'Pagina successiva', 'conformita-core' ) . '</a>';
		}

		echo '</p></nav>';
	}

	/**
	 * Una data e ora della banca dati, che è in UTC, nel fuso del sito.
	 *
	 * @param string $utc Data e ora in UTC, formato AAAA-MM-GG HH:MM:SS.
	 * @return string
	 */
	public static function data( $utc ) {
		$istante = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', (string) $utc, Conformita_Core_Registro::utc() );

		if ( false === $istante ) {
			return '';
		}

		return $istante->setTimezone( wp_timezone() )->format( 'd/m/Y H:i:s' );
	}

	/**
	 * Chi ha agito, letto adesso dal numero registrato.
	 *
	 * Il nome non sta nel registro: si legge al momento della consultazione, e
	 * un utente eliminato resta un numero.
	 *
	 * @param int $utente Numero dell'utente.
	 * @return string
	 */
	private static function utente( $utente ) {
		if ( 0 === $utente ) {
			return __( 'sistema (nessun utente)', 'conformita-core' );
		}

		$dati = get_userdata( $utente );

		if ( ! $dati instanceof WP_User ) {
			/* translators: %d: numero dell'utente. */
			return sprintf( __( 'utente %d, non più presente', 'conformita-core' ), $utente );
		}

		/* translators: 1: nome visualizzato, 2: numero dell'utente. */
		return sprintf( __( '%1$s (utente %2$d)', 'conformita-core' ), $dati->display_name, $utente );
	}

	/**
	 * Il contenuto, con il titolo di adesso se esiste ancora.
	 *
	 * @param int|null    $contenuto Numero del contenuto.
	 * @param string|null $tipo      Tipo al momento della voce.
	 * @return string
	 */
	private static function contenuto( $contenuto, $tipo ) {
		if ( null === $contenuto ) {
			return '';
		}

		$post = get_post( $contenuto );

		if ( ! $post instanceof WP_Post ) {
			/* translators: 1: numero del contenuto, 2: tipo. */
			return sprintf( __( '%1$d (%2$s), non più presente', 'conformita-core' ), $contenuto, (string) $tipo );
		}

		/* translators: 1: numero del contenuto, 2: tipo, 3: titolo attuale. */
		return sprintf( __( '%1$d (%2$s): %3$s', 'conformita-core' ), $contenuto, (string) $tipo, get_the_title( $post ) );
	}

	/**
	 * I dettagli in una riga leggibile.
	 *
	 * @param array<string, mixed> $dettagli Dettagli della voce.
	 * @return string
	 */
	private static function dettagli( array $dettagli ) {
		$parti = array();

		foreach ( $dettagli as $nome => $valore ) {
			$valori  = is_array( $valore ) ? $valore : array( $valore );
			$parti[] = $nome . ': ' . implode( ', ', array_map( array( __CLASS__, 'scalare' ), $valori ) );
		}

		return implode( '; ', $parti );
	}

	/**
	 * Un valore semplice come testo.
	 *
	 * @param mixed $valore Valore.
	 * @return string
	 */
	private static function scalare( $valore ) {
		if ( null === $valore ) {
			return '-';
		}

		if ( is_bool( $valore ) ) {
			return $valore ? __( 'sì', 'conformita-core' ) : __( 'no', 'conformita-core' );
		}

		return (string) $valore;
	}
}
