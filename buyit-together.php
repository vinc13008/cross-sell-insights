<?php
/**
 * Plugin Name:       BuyIt Together
 * Plugin URI:        https://github.com/vinc13008/buyit-together
 * Description:       Shows on each product page the parts customers actually bought with it, deduced from your order history. No per-product setup: associations are recalculated weekly.
 * Version:           1.3.2
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            POWERLOOP
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       buyit-together
 * Domain Path:       /languages
 * WC requires at least: 7.0
 * WC tested up to:   11.0
 *
 * @package BuyIt_Together
 */

defined( 'ABSPATH' ) || exit;

final class BuyIt_Together {

	/** Meta des associations calculées, réécrite à chaque recalcul. */
	private const META = '_bit_compagnons';

	/**
	 * Meta des suggestions saisies à la main dans l'éditeur.
	 *
	 * Séparée du calcul : sans cela, le recalcul hebdomadaire effacerait
	 * silencieusement toute correction manuelle. Elle est prioritaire.
	 */
	private const META_MANUEL = '_bit_manuel';

	/** Hook du recalcul hebdomadaire. */
	private const CRON = 'bit_recalcul';

	/** Réglages par défaut, surchargeables par filtre. */
	private const NB_AFFICHES   = 3;   // compagnons montrés sur la fiche
	private const MIN_PAIRES    = 2;   // occurrences minimales pour retenir une paire
	private const FENETRE_JOURS = 365; // profondeur d'historique analysée
	// La fenêtre a une taille fixe et pas de défilement : contrairement au bloc
	// de fiche, elle ne peut pas simplement s'allonger si on lui donne trop de
	// suggestions. `_bit_manuel` n'étant pas plafonné à la saisie, la limite se
	// prend ici, à l'affichage.
	private const NB_MODAL = 4;

	public static function init(): void {
		// Aucun chargement manuel du domaine de traduction : sur le dépôt
		// WordPress.org, les traductions sont servies automatiquement d'après le
		// slug de l'extension, et l'appeler soi-même est découragé depuis 4.6.

		add_action( self::CRON, [ self::class, 'recalculer' ] );
		// Priorité 16 : après les montées en gamme de WooCommerce (15), avant les
		// produits similaires (20). À 15, l'ordre d'affichage aurait été indéterminé.
		add_action( 'woocommerce_after_single_product_summary', [ self::class, 'afficher' ], 16 );
		add_action( 'admin_menu', [ self::class, 'menu' ] );
		add_action( 'admin_post_bit_recalcul', [ self::class, 'recalcul_manuel' ] );
		add_action( 'admin_post_bit_regles', [ self::class, 'enregistrer_regles' ] );
		add_action( 'admin_post_bit_exclus', [ self::class, 'enregistrer_exclus' ] );
		add_action( 'admin_post_bit_muets', [ self::class, 'enregistrer_muets' ] );
		add_action( 'admin_post_bit_masse', [ self::class, 'appliquer_masse' ] );
		add_action( 'admin_post_bit_annuler', [ self::class, 'annuler_masse' ] );
		add_action( 'admin_post_bit_recos', [ self::class, 'appliquer_recos' ] );
		add_action( 'admin_post_bit_editeur', [ self::class, 'enregistrer_editeur' ] );
		add_action( 'admin_post_bit_mode', [ self::class, 'enregistrer_mode' ] );
		add_action( 'admin_post_bit_style_modal', [ self::class, 'enregistrer_style_modal' ] );
		add_filter( 'bit_compagnons', [ self::class, 'repli_regles' ], 10, 2 );

		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'weekly', self::CRON );
		}
	}

	/**
	 * À l'activation, on amorce la règle la plus évidente des données :
	 * le kit d'outils sur les pièces qui demandent un démontage. Elle reste
	 * entièrement modifiable ou supprimable depuis l'écran de réglages.
	 */
	/**
	 * Programme le premier calcul.
	 *
	 * Rien n'est pré-rempli : les associations viennent de l'historique du site,
	 * et toute règle livrée d'avance serait une supposition sur un catalogue
	 * qu'on ne connaît pas.
	 */
	public static function activation(): void {
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'weekly', self::CRON );
		}
	}

	public static function desactivation(): void {
		wp_clear_scheduled_hook( self::CRON );
	}

	// ------------------------------------------------------------------ calcul --

	/**
	 * Compte, sur la fenêtre d'analyse, combien de fois chaque couple de produits
	 * apparaît dans une même commande.
	 *
	 * Implémentation unique, partagée par le recalcul et le diagnostic : deux
	 * versions auraient fini par diverger.
	 *
	 * @return array{paires: array<int, array<int, int>>, commandes: int}
	 */
	private static function paires_reelles(): array {
		global $wpdb;

		$jours = (int) apply_filters( 'bit_fenetre_jours', self::FENETRE_JOURS );

		// Une agrégation par commande sur les tables HPOS, sans équivalent dans
		// l'API WooCommerce ; le résultat est mis en cache en amont, par
		// analyser() (une heure) et par le cron hebdomadaire qui appelle cette
		// fonction — la requête elle-même n'a donc pas à l'être une seconde fois.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$lignes = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT o.id AS commande, CAST(im.meta_value AS UNSIGNED) AS produit
				 FROM {$wpdb->prefix}wc_orders o
				 JOIN {$wpdb->prefix}woocommerce_order_items oi
				   ON oi.order_id = o.id AND oi.order_item_type = 'line_item'
				 JOIN {$wpdb->prefix}woocommerce_order_itemmeta im
				   ON im.order_item_id = oi.order_item_id AND im.meta_key = '_product_id'
				 WHERE o.type = 'shop_order'
				   AND o.status IN ('wc-completed','wc-processing')
				   AND o.date_created_gmt >= (CURDATE() - INTERVAL %d DAY)
				   AND im.meta_value > 0",
				$jours
			),
			ARRAY_A
		);

		// Les exclus sont retirés du panier avant l'appariement : une commande
		// qui n'en gardait qu'un seul article ne produit plus aucune paire.
		$exclus  = array_flip( self::exclus() );
		$paniers = [];
		foreach ( $lignes as $l ) {
			$pid = (int) $l['produit'];
			if ( isset( $exclus[ $pid ] ) ) {
				continue;
			}
			$paniers[ $l['commande'] ][ $pid ] = true;
		}
		unset( $lignes ); // le jeu de résultats brut n'a plus d'utilité

		$paires = [];
		foreach ( $paniers as $produits ) {
			$ids = array_keys( $produits );
			if ( count( $ids ) < 2 ) {
				continue; // un seul article : aucune paire à tirer
			}
			foreach ( $ids as $a ) {
				foreach ( $ids as $b ) {
					if ( $a !== $b ) {
						$paires[ $a ][ $b ] = ( $paires[ $a ][ $b ] ?? 0 ) + 1;
					}
				}
			}
		}

		return [ 'paires' => $paires, 'commandes' => count( $paniers ) ];
	}

	/**
	 * Parcourt les commandes récentes, compte les paires de produits achetés
	 * ensemble, et stocke pour chaque produit ses meilleurs compagnons.
	 *
	 * Le calcul se fait en une requête puis en mémoire : sur ~2 000 commandes
	 * l'empreinte reste faible, et rien n'est recalculé à l'affichage.
	 */
	public static function recalculer(): int {
		$analyse = self::paires_reelles();
		$compte  = $analyse['paires'];

		if ( ! $compte ) {
			return 0;
		}

		$min = (int) apply_filters( 'bit_min_paires', self::MIN_PAIRES );
		$nb  = (int) apply_filters( 'bit_nb_affiches', self::NB_AFFICHES );

		// On purge d'abord : un produit qui n'a plus d'association ne doit rien garder.
		// delete_post_meta_by_key() — et non une suppression SQL directe — car le site
		// utilise un cache objet persistant : une purge en base laisserait les anciennes
		// valeurs vivantes dans Redis, et les fiches afficheraient des données mortes.
		delete_post_meta_by_key( self::META );

		$enregistres = 0;
		foreach ( $compte as $produit => $voisins ) {
			arsort( $voisins );
			$retenus = [];
			foreach ( $voisins as $id => $n ) {
				if ( $n < $min ) {
					break; // trié par fréquence : inutile d'aller plus loin
				}
				$retenus[] = (int) $id;
				if ( count( $retenus ) >= $nb ) {
					break;
				}
			}
			if ( $retenus ) {
				update_post_meta( $produit, self::META, $retenus );
				$enregistres++;
			}
		}

		update_option( 'bit_dernier_calcul', [
			'date'     => current_time( 'mysql' ),
			'produits' => $enregistres,
			'paniers'  => (int) $analyse['commandes'],
		], false );

		return $enregistres;
	}

	// --------------------------------------------------------------- affichage --

	/**
	 * Compagnons calculés d'un produit, prêts à afficher.
	 *
	 * Isolé d'afficher() pour que le bloc de fiche et la fenêtre d'ajout au
	 * panier partagent exactement la même liste — deux calculs séparés
	 * auraient fini par diverger au premier réglage touché d'un seul côté.
	 *
	 * @return WC_Product[]
	 */
	private static function suggestions_pour( WC_Product $produit ): array {
		// Ordre de priorité : saisie manuelle, puis calcul, puis règles de repli.
		$manuel = get_post_meta( $produit->get_id(), self::META_MANUEL, true );
		$calcul = get_post_meta( $produit->get_id(), self::META, true );

		$ids = array_merge(
			is_array( $manuel ) ? $manuel : [],
			is_array( $calcul ) ? $calcul : []
		);

		/**
		 * Permet d'ajouter un repli (par exemple le kit d'outils sur les pièces
		 * structurelles) quand l'historique ne fournit encore aucune association.
		 */
		$ids = apply_filters( 'bit_compagnons', $ids, $produit );

		// Filtré ici aussi, et pas seulement au calcul : un produit exclu ne doit
		// jamais s'afficher, y compris s'il vient d'une saisie manuelle ou d'une
		// règle de repli, qui ne passent pas par le calcul.
		$exclus = array_flip( self::exclus() );

		$produits = [];
		foreach ( array_unique( array_map( 'intval', $ids ) ) as $id ) {
			if ( $id === $produit->get_id() || isset( $exclus[ $id ] ) ) {
				continue;
			}
			$p = wc_get_product( $id );
			if ( $p instanceof WC_Product && $p->is_visible() && $p->is_purchasable() && $p->is_in_stock() ) {
				$produits[] = $p;
			}
		}

		return $produits;
	}

	public static function afficher(): void {
		global $product;

		if ( ! $product instanceof WC_Product ) {
			return;
		}

		$produits = self::suggestions_pour( $product );
		if ( ! $produits ) {
			return;
		}

		$mode = self::mode_fiche();

		if ( 'modal' !== $mode ) {
			self::afficher_bloc( $produits );
		}
		if ( 'bloc' !== $mode ) {
			self::afficher_modal( $product, $produits );
		}
	}

	/** Le bloc statique, affiché en bas de la fiche produit. */
	private static function afficher_bloc( array $produits ): void {
		$titre = apply_filters(
			'bit_titre',
			__( 'Frequently bought with this part', 'buyit-together' )
		);
		?>
		<section class="bit">
			<h3 class="bit__titre"><?php echo esc_html( $titre ); ?></h3>
			<ul class="bit__liste products">
				<?php foreach ( $produits as $p ) : ?>
					<li class="bit__item">
						<a class="bit__lien" href="<?php echo esc_url( $p->get_permalink() ); ?>">
							<?php echo wp_kses_post( $p->get_image( 'woocommerce_thumbnail' ) ); ?>
							<span class="bit__nom"><?php echo esc_html( $p->get_name() ); ?></span>
							<span class="bit__prix"><?php echo wp_kses_post( $p->get_price_html() ); ?></span>
						</a>
					</li>
				<?php endforeach; ?>
			</ul>
		</section>
		<style>
			/* Peu d'air au-dessus : le bloc suit le contenu de la fiche et n'a pas
			   à s'en détacher. On en garde en dessous, avant les apparentés. */
			.bit { margin: 1.2em 0 2.5em; clear: both; }
			.bit__titre { font-size: 1.1em; margin: 0 0 1em; }
			.bit__liste { display: grid; gap: 1.25em; list-style: none; margin: 0; padding: 0;
				grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); }
			.bit__item { margin: 0; }
			.bit__lien { display: block; text-decoration: none; color: inherit;
				border: 1px solid rgba(0,0,0,.08); border-radius: 6px; overflow: hidden;
				transition: border-color .15s ease, transform .15s ease; }
			.bit__lien:hover, .bit__lien:focus-visible {
				border-color: rgba(0,0,0,.22); transform: translateY(-2px); }
			.bit__lien img { display: block; width: 100%; height: auto; }
			.bit__nom { display: block; padding: .7em .8em 0; font-size: .88em; line-height: 1.35; }
			.bit__prix { display: block; padding: .35em .8em .8em; font-weight: 600; font-size: .95em; }
			@media (prefers-reduced-motion: reduce) { .bit__lien { transition: none; } }
		</style>
		<?php
	}

	/**
	 * Fenêtre déclenchée à l'ajout au panier, avec les compagnons du produit.
	 *
	 * L'ajout lui-même passe par l'API REST publique du panier (Store API),
	 * appelée en JavaScript : c'est la seule manière de garder le client sur la
	 * page pour lui montrer la fenêtre, plutôt que de subir le rechargement
	 * complet qu'un simple <form method="post"> déclencherait par défaut.
	 *
	 * L'interception du formulaire se fait en phase de capture, sur le document
	 * plutôt que sur le formulaire lui-même : un gestionnaire posé par le thème
	 * s'exécute forcément après, quel que soit l'ordre de chargement des scripts,
	 * et stopImmediatePropagation() l'empêche de s'exécuter à son tour — sans
	 * cela, le produit s'ajouterait deux fois.
	 */
	/**
	 * CSS de la fenêtre, partagée entre le vrai modal côté client et
	 * l'aperçu de l'écran de réglages — sans quoi les deux auraient fini par
	 * diverger, et l'aperçu aurait menti sur le rendu réel.
	 *
	 * Les couleurs et le rayon sont lus en variables CSS, posées par l'appelant
	 * sur l'élément qui porte cette feuille ; jamais codés en dur ici.
	 */
	private static function css_modal(): string {
		ob_start();
		?>
			.bit-modal { border: 0; padding: 0; max-width: 680px; width: calc(100% - 2em);
				border-radius: var(--bit-rayon, 14px); background: var(--bit-fond, #fff); color: var(--bit-texte, #1d2327);
				box-shadow: 0 24px 60px -16px rgba(0,0,0,.35), 0 4px 18px rgba(0,0,0,.14);
				opacity: 1; transform: none;
				transition: opacity 180ms ease-out, transform 180ms cubic-bezier(.23,1,.32,1); }
			@starting-style { .bit-modal[open] { opacity: 0; transform: scale(.96) translateY(8px); } }
			.bit-modal::backdrop { background: rgba(12,12,16,.55); backdrop-filter: blur(2px); }
			.bit-modal__panneau { position: relative; padding: 1.6em 1.7em 1.4em; }
			.bit-modal__fermer { position: absolute; top: .6em; right: .6em; width: 2.2em; height: 2.2em;
				display: flex; align-items: center; justify-content: center; border: 0; border-radius: 50%;
				background: transparent; font-size: 1.3em; line-height: 1; cursor: pointer; color: inherit;
				opacity: .55; transition: background-color 140ms ease, opacity 140ms ease; }
			.bit-modal__fermer:hover { opacity: 1; background: color-mix(in srgb, var(--bit-texte, #1d2327) 8%, transparent); }
			.bit-modal__ajoute { display: flex; align-items: center; flex-wrap: wrap; gap: .55em;
				margin: 0 0 1em; padding-right: 2.2em; font-weight: 600; }
			.bit-modal__coche { display: inline-flex; align-items: center; justify-content: center;
				width: 1.5em; height: 1.5em; border-radius: 50%; background: var(--bit-accent, #1d2327); color: #fff;
				font-size: .72em; flex: 0 0 auto; }
			.bit-modal__compte { flex-basis: 100%; font-weight: 400; opacity: .65; font-size: .92em; }
			.bit-modal__titre { font-size: 1em; margin: 0 0 .8em; }
			/* Ni sur la liste ni sur la fenêtre : aucun défilement interne. Les
			   vignettes fixes et le format compact ci-dessous existent pour que le
			   contenu tienne réellement, plutôt que de masquer le débordement
			   derrière une barre de défilement. */
			.bit-modal__liste { list-style: none; margin: 0 0 1.1em; padding: 0; display: grid; gap: .7em; }
			.bit-modal__liste--ligne { grid-template-columns: 1fr; }
			.bit-modal__liste--colonnes { grid-template-columns: repeat(auto-fill, minmax(112px, 1fr)); }
			.bit-modal__liste--ligne .bit-modal__item { display: flex; align-items: center; gap: .8em; }
			.bit-modal__liste--ligne .bit-modal__item a:first-child { display: flex; align-items: center;
				gap: .8em; flex: 1 1 auto; min-width: 0; text-decoration: none; color: inherit; }
			/* Colonne = carte compacte : la vignette a une taille FIXE plutôt que de
			   suivre la largeur de la colonne — une photo produit qui n'est pas
			   carrée (deux pièces côte à côte, un gros plan tout en longueur…)
			   gonflait sinon la case bien au-delà de ce que son contenu réel exige,
			   c'est ce qui rendait la fenêtre trop haute pour tenir sans défiler.
			   Le nom est plafonné à deux lignes, hauteur toujours réservée, pour que
			   les cartes restent de la même taille quel que soit le nom réel ; le
			   bouton reste collé en bas (margin-top:auto) même sur un nom court. */
			.bit-modal__liste--colonnes .bit-modal__item { display: flex; flex-direction: column;
				align-items: center; text-align: center; gap: .4em; }
			.bit-modal__liste--colonnes .bit-modal__item a:first-child { display: flex; flex-direction: column;
				align-items: center; gap: .4em; flex: 1 1 auto; text-decoration: none; color: inherit; }
			.bit-modal__liste--colonnes .bit-modal__item img { width: 56px; height: 56px; }
			.bit-modal__liste--colonnes .bit-modal__nom { display: -webkit-box; -webkit-line-clamp: 2;
				-webkit-box-orient: vertical; overflow: hidden; min-height: calc(.8em * 1.3 * 2); }
			.bit-modal__liste--colonnes .bit-modal__ajouter, .bit-modal__liste--colonnes .bit-modal__voir {
				width: 100%; margin-top: auto; }
			.bit-modal__item img { display: block; object-fit: cover; flex: 0 0 auto; width: 56px; height: 56px;
				border-radius: max(4px, calc(var(--bit-rayon, 14px) * .5)); }
			.bit-modal__nom { font-size: .8em; line-height: 1.3; }
			.bit-modal__prix { display: block; font-size: .8em; font-weight: 700; color: var(--bit-accent, #1d2327);
				margin-top: .15em; }
			.bit-modal__ajouter, .bit-modal__voir, .bit-modal__pied .button {
				box-sizing: border-box; flex: 0 0 auto; white-space: nowrap; border: 0; cursor: pointer;
				text-decoration: none; display: inline-block; text-align: center; min-width: 118px;
				background: var(--bit-accent, #1d2327); color: #fff; font-weight: 600;
				font-size: .74em; padding: .5em .7em; border-radius: max(4px, calc(var(--bit-rayon, 14px) * .55));
				transition: transform 140ms ease-out, opacity 140ms ease; }
			.bit-modal__ajouter:hover, .bit-modal__voir:hover, .bit-modal__pied .button:hover { opacity: .88; }
			.bit-modal__ajouter:active, .bit-modal__voir:active, .bit-modal__pied .button:active { transform: scale(.96); }
			.bit-modal__ajouter[disabled] { opacity: .55; cursor: default; transform: none; }
			.bit-modal__pied { display: flex; gap: 1em; align-items: center; justify-content: center; flex-wrap: wrap;
				padding-top: 1em; border-top: 1px solid color-mix(in srgb, var(--bit-texte, #1d2327) 12%, transparent); }
			.bit-modal__continuer { background: none; border: 0; text-decoration: underline; cursor: pointer;
				color: inherit; font-size: .9em; padding: 0; opacity: .75; }
			.bit-modal__continuer:hover { opacity: 1; }
			@media (prefers-reduced-motion: reduce) { .bit-modal { transition: none; } }
			.bit-avertir { position: fixed; bottom: 1.2em; right: 1.2em; left: auto; max-width: 320px;
				background: #d63638; color: #fff; padding: .8em 1.1em; border-radius: 6px;
				font-size: .9em; box-shadow: 0 4px 14px rgba(0,0,0,.2); z-index: 100000; }
		<?php
		return ob_get_clean();
	}

	private static function afficher_modal( WC_Product $produit, array $produits ): void {
		static $deja_affiche = false;
		if ( $deja_affiche ) {
			return; // un seul modal par page, même si le hook était appelé deux fois
		}
		$deja_affiche = true;

		// Plafonné ici, pas dans suggestions_pour() : le bloc de fiche peut se
		// permettre une liste plus longue (il fait simplement grandir la page),
		// la fenêtre non — elle n'a ni la hauteur ni le défilement pour ça.
		$produits = array_slice( $produits, 0, (int) apply_filters( 'bit_nb_modal', self::NB_MODAL ) );

		$style = self::modal_style();

		// Couleurs choisies à la main : posées une fois pour toutes. Sinon, la
		// fenêtre part sans elles et un script les déduit des couleurs déjà
		// appliquées par le thème sur cette page — le bouton d'ajout réel dit
		// mieux que n'importe quel réglage à quoi ressemble « la couleur du site ».
		$vars = sprintf( '--bit-rayon:%dpx;', $style['rayon'] );
		if ( $style['couleurs_personnalisees'] ) {
			$vars .= sprintf(
				'--bit-accent:%s;--bit-fond:%s;--bit-texte:%s;',
				esc_attr( $style['couleur_accent'] ), esc_attr( $style['couleur_fond'] ), esc_attr( $style['couleur_texte'] )
			);
		}
		?>
		<dialog id="bit-modal" class="bit-modal" style="<?php echo esc_attr( $vars ); ?>"
		        data-produit="<?php echo (int) $produit->get_id(); ?>"
		        data-rest="<?php echo esc_url( rest_url( 'wc/store/v1/' ) ); ?>"
		        data-panier="<?php echo esc_url( wc_get_cart_url() ); ?>"
		        <?php echo $style['couleurs_personnalisees'] ? '' : 'data-couleurs-auto="1"'; ?>>
			<div class="bit-modal__panneau">
				<button type="button" class="bit-modal__fermer" aria-label="<?php esc_attr_e( 'Close', 'buyit-together' ); ?>">&times;</button>
				<p class="bit-modal__ajoute">
					<span class="bit-modal__coche" aria-hidden="true">&#10003;</span>
					<?php echo esc_html( $style['message_ajoute'] ); ?>
					<span id="bit-modal-compte" class="bit-modal__compte"></span>
				</p>
				<h3 class="bit-modal__titre">
					<?php echo esc_html( apply_filters( 'bit_titre', $style['titre'] ) ); ?>
				</h3>
				<ul class="bit-modal__liste bit-modal__liste--<?php echo esc_attr( $style['disposition'] ); ?>">
					<?php foreach ( $produits as $p ) :
						// Une variation exige de choisir ses options avant de s'ajouter ;
						// pas de raccourci fiable ici, on renvoie vers sa fiche à la place.
						$ajoutable = $p->is_type( 'simple' );
						?>
						<li class="bit-modal__item">
							<a href="<?php echo esc_url( $p->get_permalink() ); ?>">
								<?php echo wp_kses_post( $p->get_image( 'woocommerce_thumbnail' ) ); ?>
								<span class="bit-modal__nom"><?php echo esc_html( $p->get_name() ); ?></span>
								<span class="bit-modal__prix"><?php echo wp_kses_post( $p->get_price_html() ); ?></span>
							</a>
							<?php if ( $ajoutable ) : ?>
								<button type="button" class="bit-modal__ajouter" data-id="<?php echo (int) $p->get_id(); ?>">
									<?php esc_html_e( 'Add', 'buyit-together' ); ?>
								</button>
							<?php else : ?>
								<a class="bit-modal__voir" href="<?php echo esc_url( $p->get_permalink() ); ?>">
									<?php esc_html_e( 'View product', 'buyit-together' ); ?>
								</a>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
				<div class="bit-modal__pied">
					<a class="button" href="<?php echo esc_url( wc_get_cart_url() ); ?>"><?php esc_html_e( 'View cart', 'buyit-together' ); ?></a>
					<button type="button" class="bit-modal__continuer"><?php esc_html_e( 'Continue shopping', 'buyit-together' ); ?></button>
				</div>
			</div>
		</dialog>
		<style><?php echo self::css_modal(); // phpcs:ignore WordPress.Security.EscapeOutput -- static CSS text, no user input ?></style>
		<script>
		( function () {
			var dialogue = document.getElementById( 'bit-modal' );
			// Pas de <dialog> côté navigateur (très ancien, ou désactivé) : on ne
			// touche à rien plutôt que de casser l'ajout au panier normal.
			if ( ! dialogue || typeof dialogue.showModal !== 'function' ) { return; }

			// Aucune couleur choisie à la main : on les déduit de ce que le thème
			// applique déjà sur cette page, plutôt que d'imposer un choix arbitraire.
			// Le vrai bouton d'ajout au panier dit mieux que n'importe quel réglage
			// à quoi ressemble la couleur de la boutique.
			if ( dialogue.dataset.couleursAuto ) {
				(function () {
					function estTransparente( couleur ) {
						return ! couleur || 'transparent' === couleur || /rgba?\(\s*0\s*,\s*0\s*,\s*0\s*,\s*0\s*\)/.test( couleur );
					}
					var boutonReel = document.querySelector( 'form.cart button[type="submit"], form.cart input[type="submit"]' );
					if ( boutonReel ) {
						var styleBouton = getComputedStyle( boutonReel );
						if ( ! estTransparente( styleBouton.backgroundColor ) ) {
							dialogue.style.setProperty( '--bit-accent', styleBouton.backgroundColor );
						}
					}
					var styleCorps = getComputedStyle( document.body );
					if ( ! estTransparente( styleCorps.backgroundColor ) ) {
						dialogue.style.setProperty( '--bit-fond', styleCorps.backgroundColor );
					}
					if ( styleCorps.color ) {
						dialogue.style.setProperty( '--bit-texte', styleCorps.color );
					}
				})();
			}

			var base       = dialogue.dataset.rest;
			var idProduit  = dialogue.dataset.produit;
			var conteneur  = 'product-' + idProduit;
			var nonce      = null;
			<?php
			// Les deux formes sont fournies telles quelles, avec leur « %d » ; la
			// valeur réelle n'est connue qu'à l'ajout, côté navigateur — c'est donc
			// le script qui choisit laquelle utiliser et fait le remplacement.
			/* translators: %d: number of items in the cart (singular form) */
			$libelle_panier_un = _n( '%d item in your cart.', '%d items in your cart.', 1, 'buyit-together' );
			/* translators: %d: number of items in the cart (plural form) */
			$libelle_panier_plusieurs = _n( '%d item in your cart.', '%d items in your cart.', 2, 'buyit-together' );
			?>
			var texteUn    = '<?php echo esc_js( $libelle_panier_un ); ?>';
			var textePlur  = '<?php echo esc_js( $libelle_panier_plusieurs ); ?>';
			var texteErreur = '<?php echo esc_js( __( 'Could not add to cart. Please try again.', 'buyit-together' ) ); ?>';

			function nonceCourant() {
				if ( nonce ) { return Promise.resolve( nonce ); }
				return fetch( base + 'cart', { credentials: 'same-origin' } )
					.then( function ( r ) { nonce = r.headers.get( 'Nonce' ); return nonce; } );
			}

			function ajouter( id, quantite, variation ) {
				var corps = { id: parseInt( id, 10 ), quantity: quantite || 1 };
				if ( variation && variation.length ) { corps.variation = variation; }
				return nonceCourant().then( function ( n ) {
					return fetch( base + 'cart/add-item', {
						method: 'POST',
						credentials: 'same-origin',
						headers: { 'Content-Type': 'application/json', 'Nonce': n },
						body: JSON.stringify( corps ),
					} );
				} ).then( function ( r ) {
					var n2 = r.headers.get( 'Nonce' );
					if ( n2 ) { nonce = n2; } // le jeton tourne à chaque appel
					if ( ! r.ok ) { throw new Error( 'add failed' ); }
					return r.json();
				} );
			}

			function rafraichirFragments() {
				// Laisse WooCommerce mettre à jour lui-même le compteur du panier
				// dans l'en-tête du thème, où qu'il soit sur la page : c'est le
				// mécanisme que les thèmes écoutent déjà, pas la peine d'en refaire un.
				if ( window.jQuery ) { window.jQuery( document.body ).trigger( 'wc_fragment_refresh' ); }
			}

			function accord( n, un, plusieurs ) {
				return ( 1 === n ? un : plusieurs ).replace( '%d', n );
			}

			// Une erreur qui s'efface d'elle-même, plutôt qu'une boîte native
			// bloquante : celle-ci peut survenir juste avant qu'on rende la main
			// à WooCommerce, pas question de retarder ça pour un clic sur « OK ».
			function avertir( message ) {
				var existant = document.getElementById( 'bit-avertir' );
				if ( existant ) { existant.remove(); }
				var el = document.createElement( 'div' );
				el.id = 'bit-avertir';
				el.className = 'bit-avertir';
				el.setAttribute( 'role', 'alert' );
				el.textContent = message;
				document.body.appendChild( el );
				setTimeout( function () { el.remove(); }, 6000 );
			}

			// --- Interception du formulaire principal --------------------------
			document.addEventListener( 'submit', function ( e ) {
				var form = e.target;
				if ( ! form.matches || ! form.matches( 'form.cart' ) ) { return; }
				var carte = form.closest( '[id="' + conteneur + '"]' );
				if ( ! carte ) { return; } // pas le formulaire de CE produit — on n'y touche pas

				e.preventDefault();
				e.stopImmediatePropagation();

				var donnees = new FormData( form );
				// Sur la fiche produit, WooCommerce pose l'identifiant du produit
				// sur le bouton lui-même (name="add-to-cart" value="123"), pas sur
				// un champ caché séparé. FormData(form) sans préciser quel contrôle
				// a déclenché l'envoi ne le capture pas — il faut aller le lire.
				var boutonEnvoi = form.querySelector( 'button[type="submit"], input[type="submit"]' );
				var idBouton    = boutonEnvoi && 'add-to-cart' === boutonEnvoi.name ? boutonEnvoi.value : null;
				var idAjout     = donnees.get( 'variation_id' ) && '0' !== donnees.get( 'variation_id' )
					? donnees.get( 'variation_id' )
					: ( donnees.get( 'add-to-cart' ) || idBouton );
				var quantite  = parseInt( donnees.get( 'quantity' ) || '1', 10 );
				var variation = [];
				donnees.forEach( function ( valeur, cle ) {
					if ( 0 === cle.indexOf( 'attribute_' ) ) {
						variation.push( { attribute: cle.replace( 'attribute_', '' ), value: valeur } );
					}
				} );

				var bouton = boutonEnvoi;
				// Sur un <button>, .value est l'identifiant du produit, pas le
				// texte visible (« Ajouter au panier ») : il ne faut pas les
				// confondre en restaurant l'état du bouton après coup.
				var estInput = bouton && 'INPUT' === bouton.tagName;
				var libelleOrigine = bouton ? ( estInput ? bouton.value : bouton.textContent ) : '';
				if ( bouton ) {
					bouton.disabled = true;
					var enCoursBouton = '<?php echo esc_js( __( 'Adding…', 'buyit-together' ) ); ?>';
					if ( estInput ) { bouton.value = enCoursBouton; } else { bouton.textContent = enCoursBouton; }
				}

				ajouter( idAjout, quantite, variation ).then( function ( reponse ) {
					rafraichirFragments();
					var n = reponse && reponse.items_count ? reponse.items_count : null;
					document.getElementById( 'bit-modal-compte' ).textContent = n ? accord( n, texteUn, textePlur ) : '';
					dialogue.showModal();
				} ).catch( function () {
					// Repli : notre appel a échoué, on laisse WooCommerce faire
					// l'ajout à sa manière plutôt que de bloquer l'achat. Pas de
					// window.alert() : une boîte native bloquante juste avant un
					// rechargement de page n'aide personne.
					avertir( texteErreur );
					form.submit();
				} ).finally( function () {
					if ( bouton ) {
						bouton.disabled = false;
						if ( estInput ) { bouton.value = libelleOrigine; } else { bouton.textContent = libelleOrigine; }
					}
				} );
			}, true );

			// --- Ajout direct depuis une suggestion du modal --------------------
			dialogue.querySelectorAll( '.bit-modal__ajouter' ).forEach( function ( bouton ) {
				bouton.addEventListener( 'click', function () {
					bouton.disabled = true;
					ajouter( bouton.dataset.id, 1, [] ).then( function ( reponse ) {
						rafraichirFragments();
						bouton.textContent = '<?php echo esc_js( __( 'Added', 'buyit-together' ) ); ?>';
						var n = reponse && reponse.items_count ? reponse.items_count : null;
						if ( n ) { document.getElementById( 'bit-modal-compte' ).textContent = accord( n, texteUn, textePlur ); }
					} ).catch( function () {
						bouton.disabled = false;
						avertir( texteErreur );
					} );
				} );
			} );

			dialogue.querySelector( '.bit-modal__fermer' ).addEventListener( 'click', function () { dialogue.close(); } );
			dialogue.querySelector( '.bit-modal__continuer' ).addEventListener( 'click', function () { dialogue.close(); } );
			// Clic sur le fond (::backdrop) : le clic tombe sur <dialog> lui-même,
			// jamais sur .bit-modal__panneau, puisque le panneau occupe tout
			// l'espace intérieur — c'est ce qui distingue les deux cas.
			dialogue.addEventListener( 'click', function ( e ) { if ( e.target === dialogue ) { dialogue.close(); } } );
		} )();
		</script>
		<?php
	}


	/**
	 * Repli par règles : pour les produits sans historique suffisant, on propose
	 * les articles définis dans WooCommerce → BuyIt Together.
	 *
	 * Une règle cible des étiquettes ou des catégories produit — donc une famille
	 * de pièces (Nacelle, Châssis…) ou une série de drone. Les associations
	 * calculées gardent la priorité : les règles ne font que compléter.
	 */
	public static function repli_regles( array $ids, WC_Product $produit ): array {
		$regles = self::regles();
		if ( ! $regles ) {
			return $ids;
		}

		$max = (int) apply_filters( 'bit_nb_affiches', self::NB_AFFICHES );
		$pid = $produit->get_id();

		foreach ( $regles as $regle ) {
			if ( ! self::produit_correspond( $pid, $regle['termes'] ) ) {
				continue;
			}

			foreach ( $regle['produits'] as $id ) {
				if ( $id !== $pid && ! in_array( $id, $ids, true ) ) {
					$ids[] = $id;
				}
				if ( count( $ids ) >= $max ) {
					return $ids;
				}
			}
		}

		return $ids;
	}

	/** Le produit porte-t-il l'un des termes visés ? */
	private static function produit_correspond( int $produit_id, array $termes ): bool {
		foreach ( $termes as $terme ) {
			[ $taxonomie, $id ] = array_pad( explode( ':', (string) $terme, 2 ), 2, '' );
			if ( $taxonomie && $id && has_term( (int) $id, $taxonomie, $produit_id ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Produits exclus des associations, nettoyés.
	 *
	 * Un consommable, un article offert ou une pièce en fin de vie fausse le
	 * calcul : il se retrouve dans presque toutes les commandes et devient le
	 * compagnon de tout le catalogue sans rien dire de pertinent.
	 *
	 * @return int[]
	 */
	private static function exclus(): array {
		$brut = get_option( 'bit_exclus', [] );
		$ids  = is_array( $brut ) ? array_map( 'absint', $brut ) : [];

		/** Permet d'exclure par code, par exemple toute une catégorie. */
		$ids = apply_filters( 'bit_exclus', $ids );

		return array_values( array_unique( array_filter( (array) $ids ) ) );
	}

	/**
	 * Fiches retirées de la colonne « Sur cette fiche produit ».
	 *
	 * Réglage d'écran, non d'affichage : ces produits gardent leur bloc de
	 * suggestions côté client et continuent de compter dans le calcul. On cesse
	 * seulement de recommander des changements pour eux, parce qu'on a décidé
	 * une fois pour toutes de ne pas y toucher.
	 *
	 * @return int[]
	 */
	private static function non_recommandes(): array {
		$brut = get_option( 'bit_non_recommandes', [] );
		$ids  = is_array( $brut ) ? array_map( 'absint', $brut ) : [];

		/** Permet d'en retirer par code, par exemple toute une catégorie. */
		$ids = apply_filters( 'bit_non_recommandes', $ids );

		return array_values( array_unique( array_filter( (array) $ids ) ) );
	}

	/**
	 * L'ajout au panier ouvre-t-il une fenêtre de vente croisée ?
	 *
	 * Le modal appelle l'API REST publique du panier de WooCommerce (Store
	 * API), stable dans le cœur depuis la 8.3 — avant cela, l'extension WooCommerce
	 * Blocks devait la fournir séparément, sans garantie qu'elle soit active.
	 * Sans ce plancher de version, un site plus ancien choisirait un mode qui ne
	 * fonctionne pas, en silence.
	 */
	private static function store_api_disponible(): bool {
		return defined( 'WC_VERSION' ) && version_compare( WC_VERSION, '8.3', '>=' );
	}

	/**
	 * Où proposer les suggestions sur la fiche produit : dans le bloc en bas de
	 * page, dans une fenêtre à l'ajout au panier, ou les deux à la fois.
	 */
	private static function mode_fiche(): string {
		if ( ! self::store_api_disponible() ) {
			return 'bloc';
		}
		$mode = get_option( 'bit_mode_fiche', 'bloc' );
		return in_array( $mode, [ 'bloc', 'modal', 'both' ], true ) ? $mode : 'bloc';
	}

	/**
	 * Apparence de la fenêtre d'ajout au panier, nettoyée avec repli sur des
	 * valeurs par défaut sûres — un champ vidé ou une couleur invalide ne doit
	 * jamais produire une fenêtre cassée ou illisible.
	 *
	 * @return array{titre:string,message_ajoute:string,couleur_accent:string,couleur_fond:string,couleur_texte:string,rayon:int,disposition:string}
	 */
	private static function modal_style(): array {
		$defaut = [
			'titre'                 => __( 'Frequently bought with this part', 'buyit-together' ),
			'message_ajoute'        => __( 'Added to your cart.', 'buyit-together' ),
			// Sans effet tant que couleurs_personnalisees est faux : ce sont les
			// couleurs de repli si jamais la détection automatique échouait.
			'couleur_accent'        => '#1d2327',
			'couleur_fond'          => '#ffffff',
			'couleur_texte'         => '#1d2327',
			'rayon'                 => 14,
			'disposition'           => 'ligne',
			// Faux par défaut : tant que personne n'a rien enregistré ici, la
			// fenêtre reprend les couleurs du thème plutôt qu'un choix arbitraire.
			'couleurs_personnalisees' => false,
		];

		$brut  = get_option( 'bit_modal_style', [] );
		$brut  = is_array( $brut ) ? $brut : [];
		$style = array_merge( $defaut, $brut );

		$titre          = sanitize_text_field( (string) $style['titre'] );
		$message_ajoute = sanitize_text_field( (string) $style['message_ajoute'] );

		return [
			'titre'                   => '' !== $titre ? $titre : $defaut['titre'],
			'message_ajoute'          => '' !== $message_ajoute ? $message_ajoute : $defaut['message_ajoute'],
			'couleur_accent'          => sanitize_hex_color( (string) $style['couleur_accent'] ) ?: $defaut['couleur_accent'],
			'couleur_fond'            => sanitize_hex_color( (string) $style['couleur_fond'] ) ?: $defaut['couleur_fond'],
			'couleur_texte'           => sanitize_hex_color( (string) $style['couleur_texte'] ) ?: $defaut['couleur_texte'],
			'rayon'                   => max( 0, min( 32, (int) $style['rayon'] ) ),
			'disposition'             => in_array( $style['disposition'], [ 'ligne', 'colonnes' ], true ) ? $style['disposition'] : 'ligne',
			'couleurs_personnalisees' => (bool) $style['couleurs_personnalisees'],
		];
	}

	/** Règles enregistrées, nettoyées. */
	private static function regles(): array {
		$brut = get_option( 'bit_regles', [] );
		if ( ! is_array( $brut ) ) {
			return [];
		}

		$out = [];
		foreach ( $brut as $r ) {
			$termes = isset( $r['termes'] ) ? array_filter( array_map( 'sanitize_text_field', (array) $r['termes'] ) ) : [];
			$prods  = isset( $r['produits'] ) ? array_filter( array_map( 'intval', (array) $r['produits'] ) ) : [];
			if ( $termes && $prods ) {
				$out[] = [ 'termes' => array_values( $termes ), 'produits' => array_values( $prods ) ];
			}
		}
		return $out;
	}

	/** Étiquettes et catégories disponibles, pour le sélecteur de l'admin. */
	private static function termes_disponibles(): array {
		$liste = [];
		foreach ( [ 'product_tag' => __( 'Tag', 'buyit-together' ), 'product_cat' => __( 'Category', 'buyit-together' ) ] as $tx => $label ) {
			$termes = get_terms( [ 'taxonomy' => $tx, 'hide_empty' => true ] );
			if ( is_wp_error( $termes ) ) {
				continue;
			}
			foreach ( $termes as $t ) {
				$liste[ $tx . ':' . $t->term_id ] = sprintf( '%s — %s (%d)', $label, $t->name, $t->count );
			}
		}
		return $liste;
	}


	// ------------------------------------------------------------- diagnostic --

	/**
	 * Compare les ventes croisées configurées aux achats réellement constatés.
	 *
	 * Renvoie un bilan chiffré et la liste des associations fortes absentes de la
	 * configuration. Le résultat est mis en cache : le calcul parcourt tout
	 * l'historique et n'a pas à être refait à chaque affichage.
	 */
	public static function analyser( bool $forcer = false ): array {
		$cache = get_transient( 'bit_analyse' );
		if ( ! $forcer && is_array( $cache ) ) {
			return $cache;
		}

		$analyse = self::paires_reelles();
		$reel    = $analyse['paires'];

		global $wpdb;

		// Ventes croisées actuellement configurées. Aucune fonction WooCommerce ne
		// liste ce champ sur tout le catalogue ; le résultat est mis en cache par
		// l'appelant, analyser(), pendant une heure.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			"SELECT pm.post_id AS produit, pm.meta_value AS valeur
			 FROM {$wpdb->postmeta} pm
			 JOIN {$wpdb->posts} po ON po.ID = pm.post_id
			  AND po.post_type = 'product' AND po.post_status = 'publish'
			 WHERE pm.meta_key = '_crosssell_ids' AND pm.meta_value NOT IN ('', 'a:0:{}')",
			ARRAY_A
		);

		// Un produit exclu ne produit plus aucune paire : ses ventes croisées ne
		// pourraient donc jamais être confirmées. Les compter au dénominateur
		// ferait chuter le taux sans qu'aucune configuration ait changé — on les
		// retire des deux côtés du rapport.
		$exclus = array_flip( self::exclus() );

		$config = [];
		$total = 0;
		$confirmees = 0;
		foreach ( $rows as $r ) {
			$ids = maybe_unserialize( $r['valeur'] );
			if ( ! is_array( $ids ) ) {
				continue;
			}
			$a = (int) $r['produit'];
			foreach ( $ids as $id ) {
				$b = (int) $id;
				$config[ $a ][ $b ] = true;

				if ( isset( $exclus[ $a ] ) || isset( $exclus[ $b ] ) ) {
					continue;
				}
				$total++;
				if ( ! empty( $reel[ $a ][ $b ] ) ) {
					$confirmees++;
				}
			}
		}

		// Associations fortes non configurées.
		$seuil = (int) apply_filters( 'bit_seuil_reco', 3 );
		$non_reco = array_flip( self::non_recommandes() );
		$recos = [];
		foreach ( $reel as $a => $voisins ) {
			// Une fiche qui ne reçoit aucune suggestion n'a rien à faire dans la
			// colonne « Sur cette fiche produit » : la recommander serait proposer
			// un réglage sans effet.
			if ( isset( $non_reco[ (int) $a ] ) ) {
				continue;
			}
			arsort( $voisins );
			foreach ( $voisins as $b => $n ) {
				if ( $n < $seuil ) {
					break;
				}
				if ( empty( $config[ $a ][ $b ] ) ) {
					$recos[] = [ 'produit' => (int) $a, 'croise' => (int) $b, 'fois' => (int) $n ];
				}
			}
		}
		usort( $recos, static fn( $x, $y ) => $y['fois'] <=> $x['fois'] );

		// Classement des couples les plus fréquents, toutes configurations confondues.
		$classement = [];
		foreach ( $reel as $a => $voisins ) {
			foreach ( $voisins as $b => $n ) {
				if ( $a < $b && $n >= 3 ) { // $a < $b : chaque couple une seule fois
					$classement[] = [ 'a' => (int) $a, 'b' => (int) $b, 'fois' => (int) $n ];
				}
			}
		}
		usort( $classement, static fn( $x, $y ) => $y['fois'] <=> $x['fois'] );

		$resultat = [
			'date'       => current_time( 'mysql' ),
			'commandes'  => (int) $analyse['commandes'],
			'total'      => $total,
			'confirmees' => $confirmees,
			'recos'      => array_slice( $recos, 0, 100 ),
			'recos_tot'  => count( $recos ),
			'classement' => array_slice( $classement, 0, 20 ),
			'paires_tot' => count( $classement ),
		];

		set_transient( 'bit_analyse', $resultat, HOUR_IN_SECONDS );

		return $resultat;
	}

	/** Applique les recommandations cochées aux ventes croisées WooCommerce. */
	public static function appliquer_recos(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'buyit-together' ) );
		}
		check_admin_referer( 'bit_recos' );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce checked above via check_admin_referer(); each element is sanitized on the next lines.
		$choix = isset( $_POST['reco'] ) ? (array) wp_unslash( $_POST['reco'] ) : [];
		$sauvegarde = [];
		$appliquees = 0;

		foreach ( $choix as $paire ) {
			[ $a, $b ] = array_pad( array_map( 'absint', explode( '-', (string) $paire, 2 ) ), 2, 0 );
			if ( ! $a || ! $b || $a === $b ) {
				continue;
			}

			$actuel = get_post_meta( $a, '_crosssell_ids', true );
			$actuel = is_array( $actuel ) ? array_map( 'intval', $actuel ) : [];

			if ( in_array( $b, $actuel, true ) ) {
				continue;
			}

			if ( ! isset( $sauvegarde[ $a ] ) ) {
				$sauvegarde[ $a ] = $actuel;
			}

			$actuel[] = $b;
			update_post_meta( $a, '_crosssell_ids', array_values( array_unique( $actuel ) ) );
			$appliquees++;
		}

		if ( $sauvegarde ) {
			self::empiler_sauvegarde( __( 'Recommendations applied', 'buyit-together' ), $sauvegarde );
		}
		delete_transient( 'bit_analyse' );

		wp_safe_redirect( admin_url( 'admin.php?page=buyit-together&onglet=analyse&recos=' . $appliquees ) );
		exit;
	}

	// ------------------------------------------------------------------- admin --

	public static function menu(): void {
		add_submenu_page(
			'woocommerce',
			__( 'BuyIt Together', 'buyit-together' ),
			__( 'BuyIt Together', 'buyit-together' ),
			'manage_woocommerce',
			'buyit-together',
			[ self::class, 'page' ]
		);
	}

	public static function page(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation parameter, no state change.
		$onglet = isset( $_GET['onglet'] ) ? sanitize_key( $_GET['onglet'] ) : 'fiche';
		if ( ! in_array( $onglet, [ 'analyse', 'fiche', 'panier', 'gamme', 'editeur', 'reglages' ], true ) ) {
			$onglet = 'analyse';
		}
		$base   = admin_url( 'admin.php?page=buyit-together' );

		// Sélecteur de produits natif de WooCommerce (recherche AJAX).
		wp_enqueue_script( 'wc-enhanced-select' );
		wp_enqueue_style( 'woocommerce_admin_styles' );
		self::styles();
		?>
		<div class="wrap bit-wrap">
			<h1 class="bit-marque">
				<svg class="bit-marque__logo" viewBox="0 0 40 40" width="28" height="28" aria-hidden="true" focusable="false">
					<circle cx="15.5" cy="20" r="8.5" fill="#2271b1"></circle>
					<circle cx="24.5" cy="20" r="8.5" fill="#f0a30a"></circle>
					<path d="M 20,12.05 A 8.5,8.5 0 0 1 20,27.95 A 8.5,8.5 0 0 1 20,12.05 Z" fill="#fff"></path>
				</svg>
				<?php esc_html_e( 'BuyIt Together', 'buyit-together' ); ?>
			</h1>

			<?php self::notices(); ?>

			<nav class="nav-tab-wrapper wp-clearfix">
				<a href="<?php echo esc_url( $base . '&onglet=analyse' ); ?>"
				   class="nav-tab <?php echo 'analyse' === $onglet ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Analysis', 'buyit-together' ); ?>
				</a>
				<a href="<?php echo esc_url( $base . '&onglet=fiche' ); ?>"
				   class="nav-tab <?php echo 'fiche' === $onglet ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Product page suggestions', 'buyit-together' ); ?>
				</a>
				<a href="<?php echo esc_url( $base . '&onglet=panier' ); ?>"
				   class="nav-tab <?php echo 'panier' === $onglet ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Cart cross-sells', 'buyit-together' ); ?>
				</a>
				<a href="<?php echo esc_url( $base . '&onglet=gamme' ); ?>"
				   class="nav-tab <?php echo 'gamme' === $onglet ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Upsells', 'buyit-together' ); ?>
				</a>
				<a href="<?php echo esc_url( $base . '&onglet=editeur' ); ?>"
				   class="nav-tab <?php echo 'editeur' === $onglet ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Category editor', 'buyit-together' ); ?>
				</a>
				<a href="<?php echo esc_url( $base . '&onglet=reglages' ); ?>"
				   class="nav-tab <?php echo 'reglages' === $onglet ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Settings', 'buyit-together' ); ?>
				</a>
			</nav>

			<?php
			match ( $onglet ) {
				'fiche'    => self::onglet_fiche(),
				'panier'   => self::onglet_panier(),
				'gamme'    => self::onglet_editeur( 'gamme' ),
				'editeur'  => self::onglet_editeur( 'liens' ),
				'reglages' => self::onglet_reglages(),
				default    => self::onglet_analyse(),
			};
			?>
		</div>
		<?php
	}

	/**
	 * Nom de produit cliquable, menant à l'éditeur filtré sur ce seul produit.
	 *
	 * Les tableaux de résultats désignent des produits qu'on veut corriger tout
	 * de suite. Plutôt que de redéployer les sélecteurs d'édition dans chaque
	 * tableau — et d'entretenir deux implémentations divergentes du même écran —
	 * on renvoie vers l'éditeur, qui sait déjà les afficher.
	 */
	private static function lien_produit( int $id, string $depuis = '' ): string {
		$titre = get_the_title( $id );
		if ( '' === $titre ) {
			return '<em>' . esc_html__( '(deleted product)', 'buyit-together' ) . '</em>';
		}

		$args = [
			'page'     => 'buyit-together',
			'onglet'   => 'editeur',
			'produits' => [ $id ],
		];
		if ( '' !== $depuis ) {
			$args['depuis'] = $depuis;
		}

		return sprintf(
			'<a class="bit-lien" href="%s" title="%s">%s</a>',
			esc_url( add_query_arg( $args, admin_url( 'admin.php' ) ) ),
			esc_attr__( 'Edit its suggestions and cross-sells', 'buyit-together' ),
			esc_html( $titre )
		);
	}

	/**
	 * Confirmation de la dernière action, juste sous le titre.
	 *
	 * Chaque formulaire redirige après enregistrement plutôt que de laisser un
	 * POST rejouable au rafraîchissement — mais un redirect silencieux ne dit
	 * rien de ce qui vient de se passer. Ces paramètres sont ceux que chaque
	 * gestionnaire dépose dans son URL de retour ; aucun n'a d'effet de bord,
	 * ils ne font que choisir quel message afficher.
	 */
	private static function notices(): void {
		$messages = [];

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation parameter, no state change.
		if ( isset( $_GET['recos'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation parameter, no state change.
			$n = absint( $_GET['recos'] );
			$messages[] = $n
				/* translators: %d: number of recommendations applied */
				? sprintf( _n( '%d recommendation applied to your cross-sells.', '%d recommendations applied to your cross-sells.', $n, 'buyit-together' ), $n )
				: __( 'Nothing to apply: the selected pairs were already configured.', 'buyit-together' );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation parameter, no state change.
		if ( isset( $_GET['edites'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation parameter, no state change.
			$n = absint( $_GET['edites'] );
			/* translators: %d: number of products updated */
			$messages[] = sprintf( _n( '%d product updated.', '%d products updated.', $n, 'buyit-together' ), $n );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation parameter, no state change.
		if ( isset( $_GET['exclus'] ) ) {
			$messages[] = __( 'Exclusions saved.', 'buyit-together' );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation parameter, no state change.
		if ( isset( $_GET['muets'] ) ) {
			$messages[] = __( 'Saved. These product pages are out of the recommendations table.', 'buyit-together' );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation parameter, no state change.
		if ( isset( $_GET['regles'] ) ) {
			$messages[] = __( 'Rules saved.', 'buyit-together' );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation parameter, no state change.
		if ( isset( $_GET['mode'] ) ) {
			$messages[] = __( 'Saved.', 'buyit-together' );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation parameter, no state change.
		if ( isset( $_GET['style'] ) ) {
			$messages[] = __( 'Window appearance saved.', 'buyit-together' );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation parameter, no state change.
		if ( isset( $_GET['masse'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation parameter, no state change.
			$valeur = sanitize_text_field( wp_unslash( $_GET['masse'] ) );
			if ( 'vide' === $valeur ) {
				$messages[] = __( 'Choose at least one cross-sell product to add, and a category, tag or product to apply it to.', 'buyit-together' );
			} elseif ( '0' === $valeur ) {
				$messages[] = __( 'No product matched your selection.', 'buyit-together' );
			} else {
				$n = absint( $valeur );
				/* translators: %d: number of products updated */
				$messages[] = sprintf( _n( '%d product updated.', '%d products updated.', $n, 'buyit-together' ), $n );
			}
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation parameter, no state change.
		if ( isset( $_GET['annule'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation parameter, no state change.
			$n = absint( $_GET['annule'] );
			$messages[] = $n
				/* translators: %d: number of products restored */
				? sprintf( _n( '%d product restored to its previous cross-sells.', '%d products restored to their previous cross-sells.', $n, 'buyit-together' ), $n )
				: __( 'Nothing to undo.', 'buyit-together' );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation parameter, no state change.
		if ( isset( $_GET['recalcul'] ) ) {
			$messages[] = __( 'Calculation refreshed — see the numbers below.', 'buyit-together' );
		}

		foreach ( $messages as $message ) {
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html( $message )
			);
		}
	}

	/**
	 * Habillage de l'écran d'administration.
	 *
	 * On reste dans le langage visuel de WordPress — mêmes neutres, mêmes rayons,
	 * même typographie — et on n'ajoute de la couleur que là où elle porte un sens :
	 * l'état du diagnostic. Les teintes de statut sont sous-contrastées sur fond
	 * clair par construction ; elles sont donc toujours accompagnées d'une icône
	 * et d'un libellé, jamais laissées seules à porter l'information.
	 */
	private static function styles(): void {
		?>
		<style>
		.bit-wrap { --ae-encre:#1d2327; --ae-encre-2:#50575e; --ae-encre-3:#787c82;
			--ae-bord:#dcdcde; --ae-fond:#fff; --ae-fond-2:#f6f7f7; --ae-accent:#2271b1;
			--ae-bon:#0ca30c; --ae-attention:#fab219; --ae-serieux:#ec835a; --ae-critique:#d03b3b;
			--ae-ressort:cubic-bezier(.23,1,.32,1); }

		/* --- Marque ---------------------------------------------------------
		   Même dessin que l'icône du dépôt : deux produits, l'intersection est
		   ce qui s'achète ensemble. Le retrouver ici referme la boucle entre
		   la fiche du dépôt et l'écran qu'on utilise vraiment. */
		.bit-marque { display:flex; align-items:center; gap:10px; }
		.bit-marque__logo { flex:0 0 auto; }

		/* --- Retour tactile : les boutons et onglets répondent à la pression,
		   pas seulement au survol — l'interface montre qu'elle a entendu. */
		.bit-wrap .button, .bit-wrap .nav-tab, .bit-tuile {
			transition:transform 140ms var(--ae-ressort), border-color 140ms ease, background-color 140ms ease; }
		.bit-wrap .button:active, .bit-wrap .nav-tab:active { transform:scale(.97); }
		@media (prefers-reduced-motion:reduce) {
			.bit-wrap .button, .bit-wrap .nav-tab, .bit-tuile, .bit-jauge__part { transition:none !important; }
		}

		/* --- Bandeau de tête --------------------------------------------- */
		.bit-tete { display:flex; flex-wrap:wrap; gap:24px; align-items:flex-start;
			background:var(--ae-fond); border:1px solid var(--ae-bord); border-radius:6px;
			padding:22px 24px; margin:16px 0 24px; }
		.bit-heros { flex:0 0 auto; min-width:190px; }
		.bit-heros__valeur { font-size:52px; line-height:1; font-weight:600; color:var(--ae-encre);
			letter-spacing:-.02em; }
		.bit-heros__legende { margin-top:6px; font-size:13px; color:var(--ae-encre-2); }

		/* --- Jauge : le remplissage porte la sévérité, la piste est le même ton, éclairci */
		.bit-jauge { flex:1 1 260px; min-width:240px; align-self:center; }
		.bit-jauge__piste { height:10px; border-radius:5px; overflow:hidden;
			background:color-mix(in srgb, var(--ae-teinte) 18%, #fff); }
		.bit-jauge__part { height:100%; background:var(--ae-teinte); border-radius:5px 0 0 5px;
			width:0; transition:width 700ms var(--ae-ressort); }
		.bit-jauge__note { margin-top:8px; font-size:12px; color:var(--ae-encre-3); }

		/* --- Pastille d'état : couleur + icône + libellé ------------------ */
		.bit-etat { display:inline-flex; align-items:center; gap:7px; margin-top:10px;
			font-size:13px; font-weight:600; color:var(--ae-encre); }
		.bit-etat__point { width:9px; height:9px; border-radius:50%; background:var(--ae-teinte);
			box-shadow:0 0 0 3px color-mix(in srgb, var(--ae-teinte) 22%, transparent); flex:0 0 auto; }

		/* --- Tuiles ------------------------------------------------------- */
		.bit-tuiles { display:grid; gap:12px; margin:0 0 24px;
			grid-template-columns:repeat(auto-fit,minmax(168px,1fr)); }
		.bit-tuile { background:var(--ae-fond); border:1px solid var(--ae-bord);
			border-radius:6px; padding:14px 16px; display:block; text-decoration:none; }
		.bit-tuile__valeur { font-size:26px; font-weight:600; line-height:1.15; color:var(--ae-encre); }
		.bit-tuile__label { margin-top:3px; font-size:12px; color:var(--ae-encre-2); }
		/* Tuile = raccourci vers la section qui en dit plus, pas juste un chiffre. */
		a.bit-tuile:hover { border-color:var(--ae-accent); transform:translateY(-1px); }
		a.bit-tuile:active { transform:scale(.98) translateY(0); }
		@media (prefers-reduced-motion:reduce) { a.bit-tuile:hover { transform:none; } }

		/* --- Sections ----------------------------------------------------- */
		.bit-section { background:var(--ae-fond); border:1px solid var(--ae-bord);
			border-radius:6px; padding:20px 24px; margin:0 0 20px; }
		.bit-section h3 { font-size:13px; margin:26px 0 4px; color:var(--ae-encre); }
		.bit-section > h2:first-child { margin-top:0; padding-bottom:12px;
			border-bottom:1px solid var(--ae-bord); font-size:15px; }
		.bit-section .description { color:var(--ae-encre-3); }
		.bit-intro { color:var(--ae-encre-2); font-size:13px; margin:14px 0 20px; max-width:62em; }

		/* --- Tableaux ------------------------------------------------------ */
		.bit-section .widefat { border-radius:4px; }
		.bit-section .widefat thead th { font-size:12px; text-transform:uppercase;
			letter-spacing:.03em; color:var(--ae-encre-3); }
		.bit-num { font-variant-numeric:tabular-nums; white-space:nowrap; }
		.bit-paire { color:var(--ae-encre-3); padding:0 6px; }

		/* --- Éditeur de catégorie ------------------------------------------ */
		.bit-groupe { background:var(--ae-fond); border:1px solid var(--ae-bord);
			border-radius:6px; padding:16px 18px; margin:0 0 16px;
			/* Reste atteignable en bas d'une longue catégorie plutôt que
			   forcer un aller-retour en haut de page pour l'appliquer. */
			position:sticky; top:calc(var(--wp-admin--admin-bar--height, 32px) + 12px); z-index:10; }
		.bit-groupe .description { color:var(--ae-encre-3); }
		.bit-defile { border:1px solid var(--ae-bord); border-radius:6px;
			background:var(--ae-fond); }
		.bit-defile .widefat { border:0; }
		.bit-wrap .nav-tab-wrapper { margin-bottom:0; }
		.bit-retour { margin:14px 0 0; font-size:13px; }
		.bit-lien { text-decoration:none; border-bottom:1px solid transparent; }
		.bit-lien:hover, .bit-lien:focus-visible { border-bottom-color:currentColor; }
		.bit-actions { display:block; font-size:12px; }
		.bit-actions a + a { margin-left:.5em; padding-left:.6em;
			border-left:1px solid var(--ae-bord); }
		</style>
		<?php
	}

	/**
	 * Onglet « analyse » : tout ce qui lit les données sans rien configurer.
	 *
	 * Le calcul des associations, le diagnostic des ventes croisées existantes,
	 * les recommandations qui en découlent, et le classement des couples les plus
	 * achetés ensemble. Les autres onglets ne servent qu'à configurer.
	 */
	private static function onglet_analyse(): void {
		$info = get_option( 'bit_dernier_calcul', [] );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation parameter, no state change.
		$a    = self::analyser( isset( $_GET['analyser'] ) );
		$taux = $a['total'] ? round( $a['confirmees'] * 100 / $a['total'] ) : 0;

		// Seuils et vocabulaire de l'état : la couleur ne dit rien seule.
		if ( ! $a['total'] ) {
			$teinte = 'var(--ae-encre-3)';
			$etat   = __( 'No cross-sells configured', 'buyit-together' );
		} elseif ( $taux < 15 ) {
			$teinte = 'var(--ae-critique)';
			$etat   = __( 'Configuration unrelated to your sales', 'buyit-together' );
		} elseif ( $taux < 40 ) {
			$teinte = 'var(--ae-serieux)';
			$etat   = __( 'Weak match', 'buyit-together' );
		} elseif ( $taux < 70 ) {
			$teinte = 'var(--ae-attention)';
			$etat   = __( 'Partial match', 'buyit-together' );
		} else {
			$teinte = 'var(--ae-bon)';
			$etat   = __( 'Configuration matches your sales', 'buyit-together' );
		}
		?>
		<p class="bit-intro">
			<?php esc_html_e( "What your sales say. Nothing to configure here: the suggested corrections can be applied, but configuration happens in the other tabs.", 'buyit-together' ); ?>
		</p>

		<div class="bit-tete" style="--ae-teinte: <?php echo esc_attr( $teinte ); ?>">
			<div class="bit-heros">
				<div class="bit-heros__valeur"><?php echo esc_html( $taux ); ?>&nbsp;%</div>
				<div class="bit-heros__legende"><?php esc_html_e( 'of your cross-sells match a real purchase', 'buyit-together' ); ?></div>
				<div class="bit-etat">
					<span class="bit-etat__point" aria-hidden="true"></span>
					<span><?php echo esc_html( $etat ); ?></span>
				</div>
			</div>
			<div class="bit-jauge">
				<div class="bit-jauge__piste">
					<?php
					// Largeur posée à 0 dans le balisage ; un script la porte à sa
					// valeur juste après, pour que le remplissage se lise comme la
					// réponse à une question plutôt qu'un simple fait affiché.
					$largeur_cible = max( 1, min( 100, $taux ) );
					?>
					<div class="bit-jauge__part" data-taux="<?php echo esc_attr( $largeur_cible ); ?>"></div>
				</div>
				<p class="bit-jauge__note">
					<?php printf(
						/* translators: 1: confirmed associations, 2: configured cross-sells, 3: orders analysed */
						esc_html__( '%1$d associations confirmed out of %2$d configured, measured across %3$d orders from the last 12 months.', 'buyit-together' ),
						(int) $a['confirmees'], (int) $a['total'], (int) $a['commandes']
					); ?>
				</p>
				<p class="bit-jauge__note">
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=buyit-together&onglet=analyse&analyser=1' ) ); ?>">
						<?php esc_html_e( "Run the analysis again", 'buyit-together' ); ?>
					</a>
				</p>
			</div>
		</div>
		<script>
		( function () {
			var jauge = document.querySelector( '.bit-jauge__part' );
			if ( ! jauge ) { return; }
			var reduit = window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;
			if ( reduit ) {
				jauge.style.width = jauge.dataset.taux + '%';
				return;
			}
			// Deux passages par requestAnimationFrame : le premier laisse le
			// navigateur peindre la largeur à 0, le second déclenche la
			// transition CSS vers la vraie valeur plutôt que de la court-circuiter.
			requestAnimationFrame( function () {
				requestAnimationFrame( function () {
					jauge.style.width = jauge.dataset.taux + '%';
				} );
			} );
		} )();
		</script>

		<?php
		$nb_exclus = count( self::exclus() );
		$nb_muets  = count( self::non_recommandes() );
		?>
		<?php if ( $nb_exclus || $nb_muets ) : ?>
			<p class="bit-intro" style="margin-top:-8px">
				<?php if ( $nb_exclus ) : ?>
					<?php printf(
						/* translators: %d: number of products excluded from suggestions */
						esc_html( _n( '%d product is never suggested elsewhere.', '%d products are never suggested elsewhere.', $nb_exclus, 'buyit-together' ) ),
						(int) $nb_exclus
					); ?>
				<?php endif; ?>
				<?php if ( $nb_muets ) : ?>
					<?php printf(
						/* translators: %d: number of product pages excluded from recommendations */
						esc_html( _n( '%d product page is excluded from recommendations.', '%d product pages are excluded from recommendations.', $nb_muets, 'buyit-together' ) ),
						(int) $nb_muets
					); ?>
				<?php endif; ?>
			</p>
		<?php endif; ?>

		<?php
		// Chaque tuile mène à la section qui explique le chiffre : un résumé
		// n'a d'intérêt que si on peut creuser sans chercher où regarder.
		$vers_calcul = '#bit-calcul';
		$vers_recos  = '#bit-recommandations';
		?>
		<div class="bit-tuiles">
			<a class="bit-tuile" href="<?php echo esc_url( $vers_calcul ); ?>">
				<div class="bit-tuile__valeur"><?php echo esc_html( number_format_i18n( (int) $a['total'] ) ); ?></div>
				<div class="bit-tuile__label"><?php esc_html_e( 'Cross-sells configured', 'buyit-together' ); ?></div>
			</a>
			<a class="bit-tuile" href="<?php echo esc_url( $vers_calcul ); ?>">
				<div class="bit-tuile__valeur"><?php echo esc_html( number_format_i18n( (int) $a['confirmees'] ) ); ?></div>
				<div class="bit-tuile__label"><?php esc_html_e( 'Confirmed by your sales', 'buyit-together' ); ?></div>
			</a>
			<a class="bit-tuile" href="<?php echo esc_url( $vers_recos ); ?>">
				<div class="bit-tuile__valeur"><?php echo esc_html( number_format_i18n( (int) $a['recos_tot'] ) ); ?></div>
				<div class="bit-tuile__label"><?php esc_html_e( 'Corrections suggested', 'buyit-together' ); ?></div>
			</a>
			<a class="bit-tuile" href="<?php echo esc_url( $vers_calcul ); ?>">
				<div class="bit-tuile__valeur"><?php echo esc_html( number_format_i18n( (int) ( $info['produits'] ?? 0 ) ) ); ?></div>
				<div class="bit-tuile__label"><?php esc_html_e( 'Products with suggestions', 'buyit-together' ); ?></div>
			</a>
		</div>

		<div class="bit-section" id="bit-calcul">
		<h2><?php esc_html_e( 'Association calculation', 'buyit-together' ); ?></h2>
		<p><?php esc_html_e( "Counts product pairs appearing in the same order over the last 12 months. Recalculated weekly, and feeds the product page suggestions.", 'buyit-together' ); ?></p>
		<?php if ( ! empty( $info['date'] ) ) : ?>
			<p>
				<strong><?php esc_html_e( 'Last calculation:', 'buyit-together' ); ?></strong>
				<?php echo esc_html( $info['date'] ); ?> —
				<?php printf(
					/* translators: 1: products with associations, 2: orders the calculation used */
					esc_html__( '%1$d products associated, from %2$d orders.', 'buyit-together' ),
					(int) ( $info['produits'] ?? 0 ),
					(int) ( $info['paniers'] ?? 0 )
				); ?>
			</p>
		<?php else : ?>
			<p><em><?php esc_html_e( 'No calculation has been run yet.', 'buyit-together' ); ?></em></p>
		<?php endif; ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="bit-form-recalcul">
			<input type="hidden" name="action" value="bit_recalcul">
			<?php wp_nonce_field( 'bit_recalcul' ); ?>
			<?php submit_button( __( 'Recalculate now', 'buyit-together' ), 'secondary' ); ?>
		</form>
		<script>
		// Sur un gros catalogue le calcul prend quelques secondes : sans retour,
		// un clic de plus semble naturel — et double le travail pour rien.
		document.getElementById( 'bit-form-recalcul' )?.addEventListener( 'submit', function ( e ) {
			var bouton = e.target.querySelector( 'button[type="submit"], input[type="submit"]' );
			if ( ! bouton || bouton.disabled ) { return; }
			bouton.disabled = true;
			bouton.dataset.texteOrigine = bouton.value || bouton.textContent;
			var enCours = '<?php echo esc_js( __( 'Recalculating…', 'buyit-together' ) ); ?>';
			if ( 'value' in bouton ) { bouton.value = enCours; } else { bouton.textContent = enCours; }
		} );
		</script>

		<h3><?php esc_html_e( 'Products never to suggest elsewhere', 'buyit-together' ); ?></h3>
		<p><?php esc_html_e( "A consumable, a free gift or an end-of-life part shows up in almost every order: it becomes the companion of your whole catalogue without saying anything useful. Products listed here are removed from the calculation and never appear in suggestions, even if they were added by hand or by a rule.", 'buyit-together' ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="bit_exclus">
			<?php wp_nonce_field( 'bit_exclus' ); ?>
			<?php // Sans ce champ, vider la liste n'enverrait rien et l'exclusion resterait. ?>
			<input type="hidden" name="exclus[]" value="">
			<select multiple name="exclus[]" class="wc-product-search" style="width:100%;max-width:520px"
			        data-placeholder="<?php esc_attr_e( 'Search for a product to exclude…', 'buyit-together' ); ?>"
			        data-action="woocommerce_json_search_products_and_variations">
				<?php foreach ( self::exclus() as $eid ) :
					$e = wc_get_product( $eid ); if ( ! $e ) { continue; } ?>
					<option value="<?php echo (int) $eid; ?>" selected><?php echo esc_html( $e->get_formatted_name() ); ?></option>
				<?php endforeach; ?>
			</select>
			<p class="description"><?php esc_html_e( "Exclusion takes effect immediately on product pages. The ranking and recommendations below update at the next calculation.", 'buyit-together' ); ?></p>
			<?php submit_button( __( 'Save exclusions', 'buyit-together' ), 'secondary' ); ?>
		</form>

		<h3><?php esc_html_e( 'Product pages to stop recommending', 'buyit-together' ); ?></h3>
		<p><?php esc_html_e( "These products disappear from the “On this product page” column of the recommendations table below. Use it for product pages whose configuration you have decided once and for all not to touch, and whose reminders clutter the list.", 'buyit-together' ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="bit_muets">
			<?php wp_nonce_field( 'bit_muets' ); ?>
			<?php // Sans ce champ, vider la liste n'enverrait rien et le réglage resterait. ?>
			<input type="hidden" name="muets[]" value="">
			<select multiple name="muets[]" class="wc-product-search" style="width:100%;max-width:520px"
			        data-placeholder="<?php esc_attr_e( 'Search for a product page to stop recommending…', 'buyit-together' ); ?>"
			        data-action="woocommerce_json_search_products_and_variations">
				<?php foreach ( self::non_recommandes() as $mid ) :
					$m = wc_get_product( $mid ); if ( ! $m ) { continue; } ?>
					<option value="<?php echo (int) $mid; ?>" selected><?php echo esc_html( $m->get_formatted_name() ); ?></option>
				<?php endforeach; ?>
			</select>
			<p class="description"><?php esc_html_e( "Screen setting only: these product pages keep showing their suggestion block to visitors, and their products keep counting in the calculation and the ranking.", 'buyit-together' ); ?></p>
			<?php submit_button( __( 'Save these product pages', 'buyit-together' ), 'secondary' ); ?>
		</form>
		</div>

		<?php
		// Les deux tableaux affichent jusqu'à 90 titres. Sans amorçage, chacun
		// déclenche sa propre requête : on les charge en une fois.
		$a_amorcer = [];
		foreach ( array_slice( $a['recos'], 0, 25 ) as $r ) {
			$a_amorcer[] = (int) $r['produit'];
			$a_amorcer[] = (int) $r['croise'];
		}
		foreach ( $a['classement'] as $c ) {
			$a_amorcer[] = (int) $c['a'];
			$a_amorcer[] = (int) $c['b'];
		}
		$a_amorcer = array_values( array_unique( array_filter( $a_amorcer ) ) );
		if ( $a_amorcer ) {
			_prime_post_caches( $a_amorcer, false, false );
		}
		?>

		<div class="bit-section" id="bit-recommandations">
		<h2><?php esc_html_e( 'Recommendations', 'buyit-together' ); ?></h2>
		<?php if ( ! empty( $a['recos'] ) ) : ?>
			<p>
				<?php printf(
					/* translators: %d: number of missing associations */
					esc_html__( '%d associations are firmly established by your sales but missing from your configuration. The most frequent:', 'buyit-together' ),
					(int) $a['recos_tot']
				); ?>
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="bit_recos">
				<?php wp_nonce_field( 'bit_recos' ); ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<td style="width:28px"><input type="checkbox" id="bit-tout"></td>
							<th><?php esc_html_e( 'On this product page', 'buyit-together' ); ?></th>
							<th><?php esc_html_e( 'suggest', 'buyit-together' ); ?></th>
							<th style="width:110px"><?php esc_html_e( 'Bought together', 'buyit-together' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( array_slice( $a['recos'], 0, 25 ) as $r ) : ?>
						<tr>
							<td><input type="checkbox" name="reco[]" value="<?php echo (int) $r['produit'] . '-' . (int) $r['croise']; ?>"></td>
							<td><?php echo self::lien_produit( (int) $r['produit'], 'analyse' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
							<td><?php echo self::lien_produit( (int) $r['croise'], 'analyse' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
							<td class="bit-num"><?php /* translators: %d: how many times the pair was bought together */ printf( esc_html__( '%d times', 'buyit-together' ), (int) $r['fois'] ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<p class="description"><?php esc_html_e( "The plugin adds these products to WooCommerce cross-sells. The operation can be undone from the Cart tab.", 'buyit-together' ); ?></p>
				<p class="submit" style="display:flex;align-items:center;gap:.8em">
					<?php submit_button( __( 'Apply selection', 'buyit-together' ), 'primary', 'submit', false ); ?>
					<span id="bit-compte" class="description" aria-live="polite"></span>
				</p>
			</form>
			<?php
			// Les deux formes restent au singulier « %d » côté PHP : c'est le
			// script qui choisit laquelle utiliser et fait lui-même le remplacement.
			// Une chaîne français comme « sélectionné » prend un accord au pluriel
			// que l'anglais ne marque pas — les figer à l'avance aurait été faux.
			/* translators: %d: number of selected rows (singular form) */
			$libelle_un        = _n( '%d selected', '%d selected', 1, 'buyit-together' );
			/* translators: %d: number of selected rows (plural form) */
			$libelle_plusieurs = _n( '%d selected', '%d selected', 2, 'buyit-together' );
			?>
			<script>
			( function () {
				// Combien de lignes va vraiment toucher « Appliquer » : la case
				// « tout cocher » ne le dit pas, et 25 lignes se comptent mal à l'œil.
				var cases  = document.querySelectorAll( 'input[name="reco[]"]' );
				var compte = document.getElementById( 'bit-compte' );
				var tout   = document.getElementById( 'bit-tout' );
				var libUn        = '<?php echo esc_js( $libelle_un ); ?>';
				var libPlusieurs = '<?php echo esc_js( $libelle_plusieurs ); ?>';

				function rafraichir() {
					var n = document.querySelectorAll( 'input[name="reco[]"]:checked' ).length;
					compte.textContent = n
						? ( 1 === n ? libUn : libPlusieurs ).replace( '%d', n )
						: '';
				}
				tout?.addEventListener( 'change', function () {
					cases.forEach( function ( c ) { c.checked = tout.checked; } );
					rafraichir();
				} );
				cases.forEach( function ( c ) { c.addEventListener( 'change', rafraichir ); } );
			} )();
			</script>
		<?php else : ?>
			<p><em><?php esc_html_e( 'No missing association: your configuration matches your sales.', 'buyit-together' ); ?></em></p>
		<?php endif; ?>

		</div>

		<div class="bit-section">
		<h2><?php esc_html_e( 'Most frequently bought together', 'buyit-together' ); ?></h2>
		<?php if ( ! empty( $a['classement'] ) ) : ?>
			<p>
				<?php printf(
					/* translators: %d: number of product pairs above the threshold */
					esc_html__( '%d pairs appear at least 3 times in the same order. The top 20:', 'buyit-together' ),
					(int) $a['paires_tot']
				); ?>
			</p>
			<table class="widefat striped">
				<thead>
					<tr>
						<th style="width:70px"><?php esc_html_e( 'Frequency', 'buyit-together' ); ?></th>
						<th><?php esc_html_e( 'Product', 'buyit-together' ); ?></th>
						<th><?php esc_html_e( 'bought with', 'buyit-together' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $a['classement'] as $c ) : ?>
					<tr>
						<td class="bit-num"><strong><?php /* translators: %d: how many times the pair was bought together */ printf( esc_html__( '%d times', 'buyit-together' ), (int) $c['fois'] ); ?></strong></td>
						<td><?php echo self::lien_produit( (int) $c['a'], 'analyse' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
						<td><?php echo self::lien_produit( (int) $c['b'], 'analyse' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<p class="description"><?php esc_html_e( "Useful for spotting families of parts that get repaired together, and deriving rules from them.", 'buyit-together' ); ?></p>
		<?php else : ?>
			<p><em><?php esc_html_e( 'Not enough multi-item orders yet to build a ranking.', 'buyit-together' ); ?></em></p>
		<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Onglet « réglages » : où et comment les suggestions s'affichent côté
	 * client — bloc, fenêtre, ou les deux — et l'apparence de la fenêtre.
	 * Rien ici n'agit sur le contenu des suggestions elles-mêmes, seulement
	 * sur leur présentation ; le contenu se règle dans les autres onglets.
	 */
	private static function onglet_reglages(): void {
		?>
		<p class="bit-intro">
			<?php esc_html_e( "How and where suggestions appear to the customer. Their content is configured in the other tabs; only presentation lives here.", 'buyit-together' ); ?>
		</p>

		<div class="bit-section">
		<h2><?php esc_html_e( 'How suggestions are shown', 'buyit-together' ); ?></h2>
		<?php if ( self::store_api_disponible() ) : ?>
			<p><?php esc_html_e( "The block sits at the bottom of the page, where the customer may not scroll to. The window opens right after Add to cart, at the moment they are already buying — the two are not mutually exclusive.", 'buyit-together' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="bit_mode">
				<?php wp_nonce_field( 'bit_mode' ); ?>
				<?php $mode_actuel = self::mode_fiche(); ?>
				<p>
					<label style="display:block;margin-bottom:.5em">
						<input type="radio" name="mode_fiche" value="bloc" <?php checked( $mode_actuel, 'bloc' ); ?>>
						<?php esc_html_e( 'Block at the bottom of the product page only', 'buyit-together' ); ?>
					</label>
					<label style="display:block;margin-bottom:.5em">
						<input type="radio" name="mode_fiche" value="modal" <?php checked( $mode_actuel, 'modal' ); ?>>
						<?php esc_html_e( 'Window on Add to cart only', 'buyit-together' ); ?>
					</label>
					<label style="display:block;margin-bottom:.5em">
						<input type="radio" name="mode_fiche" value="both" <?php checked( $mode_actuel, 'both' ); ?>>
						<?php esc_html_e( 'Both', 'buyit-together' ); ?>
					</label>
				</p>
				<p class="description"><?php esc_html_e( "The window calls WooCommerce's own cart API in the browser, so the page never reloads. If the theme's Add to cart button already does something similar, test this on a staging copy of the site before turning it on everywhere.", 'buyit-together' ); ?></p>
				<?php submit_button( __( 'Save', 'buyit-together' ), 'secondary' ); ?>
			</form>
		<?php else : ?>
			<p class="description">
				<?php
				/* translators: %s: installed WooCommerce version number */
				printf( esc_html__( 'The Add to cart window needs WooCommerce 8.3 or later (this site runs %s). The block below stays available regardless.', 'buyit-together' ), esc_html( defined( 'WC_VERSION' ) ? WC_VERSION : '?' ) );
				?>
			</p>
		<?php endif; ?>
		</div>

		<?php if ( self::store_api_disponible() ) : ?>
		<div class="bit-section">
		<h2><?php esc_html_e( 'Window appearance', 'buyit-together' ); ?></h2>
		<p><?php esc_html_e( "Applies whenever the window is enabled above, whether or not the block is also shown.", 'buyit-together' ); ?></p>
		<?php $style = self::modal_style(); ?>
		<div class="bit-apercu-mise-en-page">
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="bit-form-style">
				<input type="hidden" name="action" value="bit_style_modal">
				<?php wp_nonce_field( 'bit_style_modal' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="bit-titre"><?php esc_html_e( 'Suggestions title', 'buyit-together' ); ?></label></th>
						<td><input type="text" id="bit-titre" name="titre" class="regular-text"
						           value="<?php echo esc_attr( $style['titre'] ); ?>"
						           data-apercu="bit-previsu-titre"></td>
					</tr>
					<tr>
						<th scope="row"><label for="bit-message"><?php esc_html_e( '"Added to cart" message', 'buyit-together' ); ?></label></th>
						<td><input type="text" id="bit-message" name="message_ajoute" class="regular-text"
						           value="<?php echo esc_attr( $style['message_ajoute'] ); ?>"
						           data-apercu="bit-previsu-message"></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Colours', 'buyit-together' ); ?></th>
						<td>
							<label style="display:block;margin-bottom:.7em">
								<input type="checkbox" name="couleur_auto" id="bit-couleur-auto" value="1" <?php checked( ! $style['couleurs_personnalisees'] ); ?>>
								<?php esc_html_e( "Automatically match my theme's colours", 'buyit-together' ); ?>
							</label>
							<p class="description" style="margin:0 0 .8em"><?php esc_html_e( "Reads the colour of the real Add to cart button and the page background at the moment the window opens. Uncheck to set fixed colours instead.", 'buyit-together' ); ?></p>
							<div id="bit-couleurs-fixes">
								<label style="margin-right:1.5em"><?php esc_html_e( 'Accent', 'buyit-together' ); ?>
									<input type="color" name="couleur_accent" value="<?php echo esc_attr( $style['couleur_accent'] ); ?>"
									       data-var="--bit-accent"></label>
								<label style="margin-right:1.5em"><?php esc_html_e( 'Background', 'buyit-together' ); ?>
									<input type="color" name="couleur_fond" value="<?php echo esc_attr( $style['couleur_fond'] ); ?>"
									       data-var="--bit-fond"></label>
								<label><?php esc_html_e( 'Text', 'buyit-together' ); ?>
									<input type="color" name="couleur_texte" value="<?php echo esc_attr( $style['couleur_texte'] ); ?>"
									       data-var="--bit-texte"></label>
								<p class="description"><?php esc_html_e( 'Pick background and text colours with enough contrast between them to stay readable.', 'buyit-together' ); ?></p>
							</div>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="bit-rayon"><?php esc_html_e( 'Corner rounding', 'buyit-together' ); ?></label></th>
						<td>
							<input type="range" id="bit-rayon" name="rayon" min="0" max="32" step="1"
							       value="<?php echo (int) $style['rayon']; ?>" data-var="--bit-rayon" data-unite="px"
							       style="vertical-align:middle;width:200px">
							<span id="bit-rayon-valeur"><?php echo (int) $style['rayon']; ?>px</span>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Layout', 'buyit-together' ); ?></th>
						<td>
							<label style="margin-right:1.5em">
								<input type="radio" name="disposition" value="ligne" <?php checked( $style['disposition'], 'ligne' ); ?> data-apercu-disposition="ligne">
								<?php esc_html_e( 'Row: one suggestion per line', 'buyit-together' ); ?>
							</label>
							<label>
								<input type="radio" name="disposition" value="colonnes" <?php checked( $style['disposition'], 'colonnes' ); ?> data-apercu-disposition="colonnes">
								<?php esc_html_e( 'Columns: a small grid of cards', 'buyit-together' ); ?>
							</label>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Save', 'buyit-together' ), 'secondary' ); ?>
			</form>

			<div class="bit-apercu">
				<p class="description" style="margin-top:0"><?php esc_html_e( 'Preview', 'buyit-together' ); ?></p>
				<div class="bit-modal bit-apercu__panneau" id="bit-previsu"
				     style="<?php echo esc_attr( sprintf(
				     	'--bit-accent:%s;--bit-fond:%s;--bit-texte:%s;--bit-rayon:%dpx;',
				     	$style['couleur_accent'], $style['couleur_fond'], $style['couleur_texte'], (int) $style['rayon']
				     ) ); ?>">
					<div class="bit-modal__panneau">
						<p class="bit-modal__ajoute">
							<span class="bit-modal__coche" aria-hidden="true">&#10003;</span>
							<span id="bit-previsu-message"><?php echo esc_html( $style['message_ajoute'] ); ?></span>
							<span class="bit-modal__compte">1 item in your cart.</span>
						</p>
						<h3 class="bit-modal__titre" id="bit-previsu-titre"><?php echo esc_html( $style['titre'] ); ?></h3>
						<ul class="bit-modal__liste bit-modal__liste--<?php echo esc_attr( $style['disposition'] ); ?>" id="bit-previsu-liste">
							<?php foreach ( [ 'Adhesive Seal Strip', 'Precision Screwdriver Kit' ] as $nom_exemple ) : ?>
								<li class="bit-modal__item">
									<a href="#" onclick="return false">
										<span style="display:block;width:56px;height:56px;border-radius:max(4px,calc(var(--bit-rayon)*.5));background:color-mix(in srgb, var(--bit-texte) 12%, transparent)"></span>
										<span class="bit-modal__nom"><?php echo esc_html( $nom_exemple ); ?></span>
										<span class="bit-modal__prix">$12.90</span>
									</a>
									<button type="button" class="bit-modal__ajouter"><?php esc_html_e( 'Add', 'buyit-together' ); ?></button>
								</li>
							<?php endforeach; ?>
						</ul>
						<div class="bit-modal__pied">
							<a class="button" href="#" onclick="return false"><?php esc_html_e( 'View cart', 'buyit-together' ); ?></a>
							<button type="button" class="bit-modal__continuer"><?php esc_html_e( 'Continue shopping', 'buyit-together' ); ?></button>
						</div>
					</div>
				</div>
			</div>
		</div>
		<style>
			.bit-apercu-mise-en-page { display: flex; gap: 2.5em; flex-wrap: wrap; align-items: flex-start; }
			.bit-apercu-mise-en-page form { flex: 1 1 380px; min-width: 320px; }
			.bit-apercu { flex: 0 0 auto; }
			.bit-apercu__panneau { position: static; width: 320px; max-width: 100%; margin: 0; }
			<?php echo self::css_modal(); // phpcs:ignore WordPress.Security.EscapeOutput -- static CSS text, no user input ?>
		</style>
		<script>
		( function () {
			var previsu = document.getElementById( 'bit-previsu' );
			if ( ! previsu ) { return; }
			document.querySelectorAll( '#bit-form-style [data-var]' ).forEach( function ( champ ) {
				champ.addEventListener( 'input', function () {
					var valeur = champ.value + ( champ.dataset.unite || '' );
					previsu.style.setProperty( champ.dataset.var, valeur );
					if ( 'bit-rayon' === champ.id ) {
						document.getElementById( 'bit-rayon-valeur' ).textContent = valeur;
					}
				} );
			} );
			document.querySelectorAll( '#bit-form-style [data-apercu]' ).forEach( function ( champ ) {
				champ.addEventListener( 'input', function () {
					document.getElementById( champ.dataset.apercu ).textContent = champ.value;
				} );
			} );
			document.querySelectorAll( '#bit-form-style [data-apercu-disposition]' ).forEach( function ( champ ) {
				champ.addEventListener( 'change', function () {
					var liste = document.getElementById( 'bit-previsu-liste' );
					liste.className = 'bit-modal__liste bit-modal__liste--' + champ.dataset.apercuDisposition;
				} );
			} );

			// Case « couleurs automatiques » : grise les trois sélecteurs plutôt
			// que de les cacher — on veut qu'on comprenne qu'ils existent, juste
			// qu'ils ne servent à rien tant que la case reste cochée.
			var caseAuto     = document.getElementById( 'bit-couleur-auto' );
			var blocCouleurs = document.getElementById( 'bit-couleurs-fixes' );
			function appliquerEtatCouleurs() {
				var fixe = ! caseAuto.checked;
				blocCouleurs.style.opacity = fixe ? '1' : '.45';
				blocCouleurs.querySelectorAll( 'input[type="color"]' ).forEach( function ( c ) { c.disabled = ! fixe; } );
			}
			if ( caseAuto ) {
				caseAuto.addEventListener( 'change', appliquerEtatCouleurs );
				appliquerEtatCouleurs();
			}
		} )();
		</script>
		<?php endif; ?>


		<?php
	}

	/**
	 * Onglet « fiche produit » : le bloc affiché sous le résumé du produit,
	 * alimenté par les associations calculées puis, à défaut, par les règles.
	 */
	private static function onglet_fiche(): void {
		$regles   = self::regles();
		$regles[] = [ 'termes' => [], 'produits' => [] ]; // une ligne vierge en fin de tableau
		$termes_dispo = self::termes_disponibles();
		?>
		<p class="bit-intro">
			<?php esc_html_e( "What the customer sees on the product page, before adding to the cart. These suggestions belong to the plugin and do not affect WooCommerce cross-sells.", 'buyit-together' ); ?>
		</p>

		<p><?php esc_html_e( "Associations calculated from history take priority; the rules below only serve products that do not have any yet. The calculation and its diagnosis are in the Analysis tab.", 'buyit-together' ); ?></p>

		<div class="bit-section">
		<h2><?php esc_html_e( 'Rules by family of parts', 'buyit-together' ); ?></h2>
		<p><?php esc_html_e( "For products that do not have enough history yet. Choose a tag (a family of items) or a category (a product line), then the items to suggest.", 'buyit-together' ); ?></p>
		<p class="description"><?php esc_html_e( 'Example: tag “Battery” → Charger, Protective case', 'buyit-together' ); ?></p>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="bit_regles">
			<?php wp_nonce_field( 'bit_regles' ); ?>
			<table class="widefat striped" id="bit-regles">
				<thead>
					<tr>
						<th style="width:38%"><?php esc_html_e( 'Target tag or category', 'buyit-together' ); ?></th>
						<th><?php esc_html_e( 'Products to suggest', 'buyit-together' ); ?></th>
						<th style="width:60px"></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $regles as $i => $regle ) : ?>
					<tr>
						<td>
							<select multiple class="wc-enhanced-select" style="width:100%"
							        name="regles[<?php echo (int) $i; ?>][termes][]"
							        data-placeholder="<?php esc_attr_e( 'Choose a tag or a category…', 'buyit-together' ); ?>">
								<?php foreach ( $termes_dispo as $cle => $libelle ) : ?>
									<option value="<?php echo esc_attr( $cle ); ?>"
										<?php selected( in_array( $cle, (array) $regle['termes'], true ) ); ?>>
										<?php echo esc_html( $libelle ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</td>
						<td>
							<select multiple class="wc-product-search" style="width:100%"
							        name="regles[<?php echo (int) $i; ?>][produits][]"
							        data-placeholder="<?php esc_attr_e( 'Search for a product…', 'buyit-together' ); ?>"
							        data-action="woocommerce_json_search_products_and_variations">
								<?php foreach ( (array) $regle['produits'] as $pid ) :
									$prod = wc_get_product( $pid );
									if ( ! $prod ) { continue; } ?>
									<option value="<?php echo (int) $pid; ?>" selected>
										<?php echo esc_html( $prod->get_formatted_name() ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</td>
						<td>
							<button type="button" class="button bit-suppr" title="<?php esc_attr_e( 'Remove', 'buyit-together' ); ?>">&times;</button>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<p>
				<button type="button" class="button" id="bit-ajouter"><?php esc_html_e( 'Add a rule', 'buyit-together' ); ?></button>
			</p>
			<?php submit_button( __( 'Save rules', 'buyit-together' ) ); ?>
		</form>
		</div>
		<script>
		( function ( $ ) {
			// On construit une ligne neuve à partir d'un modèle plutôt que de cloner
			// un champ déjà transformé par select2 : le clone d'un select2 initialisé
			// ne se réinitialise pas correctement.
			var modeleTermes = <?php echo wp_json_encode( $termes_dispo ); ?>;

			function nouvelleLigne( i ) {
				var options = '';
				$.each( modeleTermes, function ( cle, libelle ) {
					options += '<option value="' + cle + '">' + libelle + '</option>';
				} );
				return $(
					'<tr>' +
						'<td><select multiple class="wc-enhanced-select" style="width:100%" ' +
							'name="regles[' + i + '][termes][]">' + options + '</select></td>' +
						'<td><select multiple class="wc-product-search" style="width:100%" ' +
							'name="regles[' + i + '][produits][]" ' +
							'data-action="woocommerce_json_search_products_and_variations"></select></td>' +
						'<td><button type="button" class="button bit-suppr">&times;</button></td>' +
					'</tr>'
				);
			}

			$( '#bit-ajouter' ).on( 'click', function () {
				var $corps = $( '#bit-regles tbody' );
				$corps.append( nouvelleLigne( $corps.find( 'tr' ).length ) );
				$( document.body ).trigger( 'wc-enhanced-select-init' );
			} );

			$( document ).on( 'click', '.bit-suppr', function () {
				var $corps = $( '#bit-regles tbody' );
				if ( $corps.find( 'tr' ).length > 1 ) {
					$( this ).closest( 'tr' ).remove();
				}
			} );
		} )( jQuery );
		</script>
		<?php
	}

	/**
	 * Onglet « panier » : les ventes croisées natives de WooCommerce, affichées
	 * dans le panier une fois le produit choisi. Diagnostic, recommandations
	 * issues des ventes réelles, et attribution en masse.
	 */
	private static function onglet_panier(): void {
		?>
		<p class="bit-intro">
			<?php esc_html_e( "What the customer sees in their cart, after choosing a product. These are WooCommerce cross-sells, shared with the rest of the site.", 'buyit-together' ); ?>
		</p>

		<?php $historique = self::historique(); ?>
		<?php if ( $historique ) : ?>
			<div class="notice notice-info inline">
				<p><strong><?php esc_html_e( 'Recent cross-sell changes', 'buyit-together' ); ?></strong></p>
				<ol style="margin:0 0 .5em 1.5em">
					<?php foreach ( array_reverse( $historique ) as $i => $op ) : ?>
						<li>
							<?php printf(
								/* translators: 1: date, 2: name of the operation, 3: number of products affected */
								esc_html__( '%1$s — %2$s — %3$d products', 'buyit-together' ),
								esc_html( $op['date'] ?? '' ),
								esc_html( $op['libelle'] ?? '' ),
								count( (array) ( $op['donnees'] ?? [] ) )
							); ?>
							<?php if ( 0 === $i ) : ?>
								<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=bit_annuler' ), 'bit_annuler' ) ); ?>"
								   class="button button-small"><?php esc_html_e( 'Undo this one', 'buyit-together' ); ?></a>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ol>
				<p class="description"><?php esc_html_e( "Undo works one operation at a time, from the most recent to the oldest.", 'buyit-together' ); ?></p>
			</div>
		<?php endif; ?>

		<div class="bit-section">
		<h2><?php esc_html_e( 'Bulk assignment', 'buyit-together' ); ?></h2>
		<p><?php esc_html_e( "Adds cross-sell products to a whole category, a tag or a selection of products.", 'buyit-together' ); ?></p>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="bit_masse">
			<?php wp_nonce_field( 'bit_masse' ); ?>
			<table class="form-table">
				<tr>
					<th scope="row"><label><?php esc_html_e( 'Apply to', 'buyit-together' ); ?></label></th>
					<td>
						<select multiple class="wc-enhanced-select" style="width:100%;max-width:520px" name="cibles_termes[]"
						        data-placeholder="<?php esc_attr_e( 'Categories or tags…', 'buyit-together' ); ?>">
							<?php foreach ( self::termes_disponibles() as $cle => $libelle ) : ?>
								<option value="<?php echo esc_attr( $cle ); ?>"><?php echo esc_html( $libelle ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description"><?php esc_html_e( 'and / or specific products:', 'buyit-together' ); ?></p>
						<select multiple class="wc-product-search" style="width:100%;max-width:520px" name="cibles_produits[]"
						        data-placeholder="<?php esc_attr_e( 'Search for a product…', 'buyit-together' ); ?>"
						        data-action="woocommerce_json_search_products_and_variations"></select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label><?php esc_html_e( 'Cross-sell products to add', 'buyit-together' ); ?></label></th>
					<td>
						<select multiple class="wc-product-search" style="width:100%;max-width:520px" name="croises[]"
						        data-placeholder="<?php esc_attr_e( 'Search for a product…', 'buyit-together' ); ?>"
						        data-action="woocommerce_json_search_products_and_variations"></select>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Mode', 'buyit-together' ); ?></th>
					<td>
						<label><input type="radio" name="mode" value="ajouter" checked>
							<?php esc_html_e( 'Add to existing cross-sells', 'buyit-together' ); ?></label><br>
						<label><input type="radio" name="mode" value="remplacer">
							<?php esc_html_e( 'Replace existing cross-sells', 'buyit-together' ); ?></label>
						<p class="description"><?php esc_html_e( "Previous values are saved: the operation can be undone.", 'buyit-together' ); ?></p>
					</td>
				</tr>
			</table>
			<?php submit_button( __( 'Apply', 'buyit-together' ) ); ?>
		</form>
		</div>
		<?php
	}

	/**
	 * Onglet « éditeur » : relecture d'une catégorie entière, un produit par ligne,
	 * avec ses montées en gamme et ses ventes croisées modifiables sur place.
	 *
	 * Corriger 1 000 fiches une à une est impraticable ; cette vue permet de
	 * traiter une famille de pièces d'un seul regard.
	 */
	private static function onglet_editeur( string $mode = 'liens' ): void {
		$onglet_courant = ( 'gamme' === $mode ) ? 'gamme' : 'editeur';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation parameter, no state change.
		$terme  = isset( $_GET['terme'] ) ? sanitize_text_field( wp_unslash( $_GET['terme'] ) ) : '';
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only navigation parameter, no state change.
		$produits_choisis = isset( $_GET['produits'] )
			? array_values( array_filter( array_map( 'absint', (array) wp_unslash( $_GET['produits'] ) ) ) )
			: [];
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation parameter, no state change.
		$page   = max( 1, isset( $_GET['p'] ) ? absint( $_GET['p'] ) : 1 );

		// Deux sélecteurs select2 par ligne : au-delà de 100 lignes, la page
		// devient lourde à initialiser. On borne volontairement le choix.
		$choix_par = [ 10, 25, 50, 100 ];
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation parameter, no state change.
		$par = isset( $_GET['par'] ) ? absint( $_GET['par'] ) : 25;
		if ( ! in_array( $par, $choix_par, true ) ) {
			$par = 25;
		}
		$base   = admin_url( 'admin.php?page=buyit-together&onglet=' . $onglet_courant );

		// On arrive parfois d'un tableau de résultats : garder le chemin du retour.
		// Le nom « retour » est déjà pris par le formulaire d'enregistrement.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation parameter, no state change.
		$depuis = ( isset( $_GET['depuis'] ) && 'analyse' === $_GET['depuis'] ) ? 'analyse' : '';
		if ( $depuis ) {
			$base = add_query_arg( 'depuis', $depuis, $base );
		}
		?>
		<?php if ( $depuis ) : ?>
			<p class="bit-retour">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=buyit-together&onglet=analyse' ) ); ?>">
					&larr; <?php esc_html_e( "Back to the analysis", 'buyit-together' ); ?>
				</a>
			</p>
		<?php endif; ?>
		<p class="bit-intro">
			<?php if ( 'gamme' === $mode ) : ?>
				<?php esc_html_e( "Upsells offer a higher-end alternative to the product being viewed, on its page. Rarely useful on a spare-parts catalogue, but editable here if needed.", 'buyit-together' ); ?>
			<?php else : ?>
				<?php esc_html_e( "Review view: the products of a category or a tag, with their product page suggestions and their cart cross-sells. Calculated associations are shown for reference.", 'buyit-together' ); ?>
			<?php endif; ?>
		</p>

		<form method="get" style="margin-bottom:1em">
			<input type="hidden" name="page" value="buyit-together">
			<input type="hidden" name="onglet" value="<?php echo esc_attr( $onglet_courant ); ?>">
			<?php if ( $depuis ) : ?>
				<input type="hidden" name="depuis" value="<?php echo esc_attr( $depuis ); ?>">
			<?php endif; ?>
			<p style="margin:0 0 .5em">
				<select name="terme" class="wc-enhanced-select" style="min-width:340px"
				        data-allow_clear="true"
				        data-placeholder="<?php esc_attr_e( 'A category or a tag…', 'buyit-together' ); ?>">
					<option value=""><?php esc_html_e( '— none —', 'buyit-together' ); ?></option>
					<?php foreach ( self::termes_disponibles() as $cle => $libelle ) : ?>
						<option value="<?php echo esc_attr( $cle ); ?>" <?php selected( $terme, $cle ); ?>>
							<?php echo esc_html( $libelle ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</p>
			<p style="margin:0 0 .5em">
				<select multiple name="produits[]" class="wc-product-search" style="min-width:340px;max-width:520px"
				        data-placeholder="<?php esc_attr_e( 'and / or specific products…', 'buyit-together' ); ?>"
				        data-action="woocommerce_json_search_products_and_variations">
					<?php foreach ( $produits_choisis as $cid ) :
						$c = wc_get_product( $cid ); if ( ! $c ) { continue; } ?>
						<option value="<?php echo (int) $cid; ?>" selected><?php echo esc_html( $c->get_formatted_name() ); ?></option>
					<?php endforeach; ?>
				</select>
			</p>
			<p style="margin:0 0 .5em">
				<label for="bit-par"><?php esc_html_e( 'Products per page:', 'buyit-together' ); ?></label>
				<select name="par" id="bit-par">
					<?php foreach ( $choix_par as $n ) : ?>
						<option value="<?php echo (int) $n; ?>" <?php selected( $par, $n ); ?>><?php echo (int) $n; ?></option>
					<?php endforeach; ?>
				</select>
			</p>
			<?php submit_button( __( 'Show', 'buyit-together' ), 'secondary', '', false ); ?>
			<?php if ( $terme || $produits_choisis ) : ?>
				<a class="button-link" style="margin-left:.8em"
				   href="<?php echo esc_url( admin_url( 'admin.php?page=buyit-together&onglet=' . $onglet_courant ) ); ?>">
					<?php esc_html_e( 'Reset the selection', 'buyit-together' ); ?>
				</a>
			<?php endif; ?>
		</form>

		<?php
		if ( ! $terme && ! $produits_choisis ) {
			echo '<p><em>' . esc_html__( 'Choose a category, a tag or products to start.', 'buyit-together' ) . '</em></p>';
			return;
		}

		// Union des deux sélections : on résout d'abord la taxonomie en
		// identifiants, puis on interroge par post__in. WP_Query ne sait pas
		// combiner une tax_query et une liste d'ID par un OU.
		$ids = $produits_choisis;

		if ( $terme ) {
			[ $taxonomie, $term_id ] = array_pad( explode( ':', $terme, 2 ), 2, '' );
			if ( $taxonomie && $term_id ) {
				$ids = array_merge( $ids, get_posts( [
					'post_type'      => 'product',
					'post_status'    => 'publish',
					'posts_per_page' => -1,
					'fields'         => 'ids',
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- one term lookup, not a repeated meta query; there is no lighter way to list a whole taxonomy term's products.
					'tax_query'      => [ [
						'taxonomy' => sanitize_key( $taxonomie ),
						'field'    => 'term_id',
						'terms'    => (int) $term_id,
					] ],
				] ) );
			}
		}

		$ids = array_values( array_unique( array_map( 'intval', $ids ) ) );

		if ( ! $ids ) {
			echo '<p><em>' . esc_html__( 'No product in this selection.', 'buyit-together' ) . '</em></p>';
			return;
		}

		$q = new WP_Query( [
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'post__in'       => $ids,
			'posts_per_page' => $par,
			'paged'          => $page,
			'orderby'        => 'title',
			'order'          => 'ASC',
			'ignore_sticky_posts' => true,
		] );

		if ( ! $q->have_posts() ) {
			echo '<p><em>' . esc_html__( 'No product in this selection.', 'buyit-together' ) . '</em></p>';
			return;
		}
		?>
		<p>
			<?php printf(
				/* translators: 1: total products, 2: current page, 3: total pages */
				esc_html__( '%1$d products — page %2$d of %3$d', 'buyit-together' ),
				(int) $q->found_posts, (int) $page, (int) $q->max_num_pages
			); ?>
		</p>

		<style>
			/* Les noms de produits sont longs : sans largeur fixe, les étiquettes
			   select2 poussent le tableau hors du conteneur d'administration. */
			.bit-editeur { table-layout: fixed; width: 100%; }
			.bit-editeur th,
			.bit-editeur td { overflow-wrap: anywhere; vertical-align: top; }
			.bit-editeur .select2-container { max-width: 100% !important; }
			.bit-editeur .select2-selection--multiple { min-height: 34px; }
			.bit-editeur .select2-selection__choice {
				max-width: 100%;
				white-space: normal;
				line-height: 1.35;
			}
			.bit-editeur ul { list-style: disc; padding-left: 1.1em; }
			.bit-defile { overflow-x: auto; }
			@media screen and (max-width: 1100px) {
				.bit-editeur { min-width: 900px; }
			}
		</style>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="bit_editeur">
			<input type="hidden" name="retour" value="<?php echo esc_attr( $terme . '|' . $page . '|' . $onglet_courant . '|' . $par ); ?>">
			<?php if ( $depuis ) : ?>
				<input type="hidden" name="depuis" value="<?php echo esc_attr( $depuis ); ?>">
			<?php endif; ?>
			<?php foreach ( $produits_choisis as $cid ) : ?>
				<input type="hidden" name="retour_produits[]" value="<?php echo (int) $cid; ?>">
			<?php endforeach; ?>
			<?php wp_nonce_field( 'bit_editeur' ); ?>
			<div class="bit-groupe">
				<strong><?php esc_html_e( 'Apply to every row shown', 'buyit-together' ); ?></strong>
				<p style="margin:.6em 0 .4em">
					<select class="wc-product-search" style="width:100%;max-width:460px" id="bit-groupe-produit"
					        data-placeholder="<?php esc_attr_e( 'Choose the product to add…', 'buyit-together' ); ?>"
					        data-action="woocommerce_json_search_products_and_variations"></select>
				</p>
				<p style="margin:.4em 0 0">
					<?php if ( 'gamme' === $mode ) : ?>
						<button type="button" class="button" data-cible="upsell"><?php esc_html_e( 'Add to upsells', 'buyit-together' ); ?></button>
					<?php else : ?>
						<button type="button" class="button" data-cible="suggestion"><?php esc_html_e( 'Add to suggestions', 'buyit-together' ); ?></button>
						<button type="button" class="button" data-cible="croise"><?php esc_html_e( 'Add to cross-sells', 'buyit-together' ); ?></button>
					<?php endif; ?>
					<button type="button" class="button" data-cible="retirer"><?php esc_html_e( 'Remove everywhere', 'buyit-together' ); ?></button>
				</p>
				<p class="description" style="margin:.6em 0 0">
					<?php printf(
						/* translators: %d: number of products shown on the current page */
						esc_html__( "Acts on the %d products shown on this page. Nothing is saved until you confirm at the bottom.", 'buyit-together' ),
						(int) $q->post_count
					); ?>
				</p>
			</div>

			<div class="bit-defile">
			<table class="widefat striped bit-editeur">
				<thead>
					<tr>
						<?php if ( 'gamme' === $mode ) : ?>
							<th style="width:35%"><?php esc_html_e( 'Product', 'buyit-together' ); ?></th>
							<th style="width:65%">
								<?php esc_html_e( 'Upsells', 'buyit-together' ); ?>
								<span style="font-weight:400;color:#646970"><?php esc_html_e( '— product page, as an alternative', 'buyit-together' ); ?></span>
							</th>
						<?php else : ?>
							<th style="width:24%"><?php esc_html_e( 'Product', 'buyit-together' ); ?></th>
							<th style="width:38%">
								<?php esc_html_e( 'Suggestions', 'buyit-together' ); ?>
								<span style="font-weight:400;color:#646970"><?php esc_html_e( '— product page', 'buyit-together' ); ?></span>
							</th>
							<th style="width:38%">
								<?php esc_html_e( 'Cross-sells', 'buyit-together' ); ?>
								<span style="font-weight:400;color:#646970"><?php esc_html_e( '— cart', 'buyit-together' ); ?></span>
							</th>
						<?php endif; ?>
					</tr>
				</thead>
				<tbody>
				<?php while ( $q->have_posts() ) : $q->the_post();
					$pid     = get_the_ID();
					$produit = wc_get_product( $pid );
					if ( ! $produit ) { continue; }

					$calcules = [];
					$manuels  = [];
					$croise   = [];

					// Le mode « montées en gamme » n'affiche pas ces colonnes :
					// inutile de payer deux lectures de meta par ligne.
					if ( 'gamme' !== $mode ) {
						$calcules = get_post_meta( $pid, self::META, true );
						$calcules = is_array( $calcules ) ? $calcules : [];
						$manuels  = get_post_meta( $pid, self::META_MANUEL, true );
						$manuels  = is_array( $manuels ) ? $manuels : [];
						$croise   = $produit->get_cross_sell_ids();
					}
					?>
					<tr>
						<td>
							<strong style="display:block;font-weight:600"><?php echo esc_html( get_the_title() ); ?></strong>
							<span class="bit-actions">
								<a href="<?php echo esc_url( get_edit_post_link( $pid ) ); ?>" target="_blank">
									<?php esc_html_e( 'Edit', 'buyit-together' ); ?>
								</a>
								<a href="<?php echo esc_url( get_permalink( $pid ) ); ?>" target="_blank">
									<?php esc_html_e( 'View product', 'buyit-together' ); ?>
								</a>
							</span>
						</td>
						<?php if ( 'gamme' === $mode ) : ?>
							<td>
								<input type="hidden" name="lignes[<?php echo (int) $pid; ?>][upsell][]" value="">
								<select multiple class="wc-product-search" style="width:100%"
								        name="lignes[<?php echo (int) $pid; ?>][upsell][]"
								        data-placeholder="<?php esc_attr_e( 'Search…', 'buyit-together' ); ?>"
								        data-action="woocommerce_json_search_products_and_variations">
									<?php foreach ( $produit->get_upsell_ids() as $uid ) :
										$u = wc_get_product( $uid ); if ( ! $u ) { continue; } ?>
										<option value="<?php echo (int) $uid; ?>" selected><?php echo esc_html( $u->get_formatted_name() ); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
						<?php else : ?>
							<td>
								<input type="hidden" name="lignes[<?php echo (int) $pid; ?>][suggestion][]" value="">
								<select multiple class="wc-product-search" style="width:100%"
								        name="lignes[<?php echo (int) $pid; ?>][suggestion][]"
								        data-placeholder="<?php esc_attr_e( 'Search…', 'buyit-together' ); ?>"
								        data-action="woocommerce_json_search_products_and_variations">
									<?php foreach ( $manuels as $mid ) :
										$m = wc_get_product( $mid ); if ( ! $m ) { continue; } ?>
										<option value="<?php echo (int) $mid; ?>" selected><?php echo esc_html( $m->get_formatted_name() ); ?></option>
									<?php endforeach; ?>
								</select>
								<?php if ( $calcules ) : ?>
									<p class="description" style="margin:.4em 0 0">
										<?php esc_html_e( 'Calculated:', 'buyit-together' ); ?>
										<?php
										$noms = array_map( static fn( $c ) => get_the_title( (int) $c ), $calcules );
										echo esc_html( implode( ' · ', array_filter( $noms ) ) );
										?>
									</p>
								<?php endif; ?>
							</td>
							<td>
								<input type="hidden" name="lignes[<?php echo (int) $pid; ?>][croise][]" value="">
								<select multiple class="wc-product-search" style="width:100%"
								        name="lignes[<?php echo (int) $pid; ?>][croise][]"
								        data-placeholder="<?php esc_attr_e( 'Search…', 'buyit-together' ); ?>"
								        data-action="woocommerce_json_search_products_and_variations">
									<?php foreach ( $croise as $cid ) :
										$c = wc_get_product( $cid ); if ( ! $c ) { continue; } ?>
										<option value="<?php echo (int) $cid; ?>" selected><?php echo esc_html( $c->get_formatted_name() ); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
						<?php endif; ?>
					</tr>
				<?php endwhile; wp_reset_postdata(); ?>
				</tbody>
			</table>
			</div>
			<?php submit_button( __( 'Save this page', 'buyit-together' ) ); ?>
		</form>
		<script>
		jQuery( function ( $ ) {
			// Recalcule la largeur des sélecteurs une fois la table rendue.
			$( document.body ).trigger( 'wc-enhanced-select-init' );
			$( window ).on( 'resize', function () {
				$( '.bit-editeur .select2-container' ).css( 'width', '100%' );
			} ).trigger( 'resize' );

			// --- Application groupée aux lignes affichées ---------------------
			// On modifie les champs sans rien enregistrer : l'utilisateur voit le
			// résultat, peut l'ajuster ligne par ligne, puis valide le formulaire.
			$( '.bit-groupe button[data-cible]' ).on( 'click', function () {
				var cible = $( this ).data( 'cible' ),
				    $src  = $( '#bit-groupe-produit' ),
				    id    = $src.val(),
				    label = $src.find( 'option:selected' ).text();

				if ( ! id ) {
					window.alert( '<?php echo esc_js( __( 'Choose a product first.', 'buyit-together' ) ); ?>' );
					return;
				}

				var suffixe = ( 'retirer' === cible ) ? null : '[' + cible + '][]',
				    touches = 0;

				$( '.bit-editeur tbody tr' ).each( function () {
					var $ligne = $( this );

					// « Retirer partout » agit sur les deux colonnes.
					var selecteurs = suffixe
						? $ligne.find( 'select[name$="' + suffixe + '"]' )
						: $ligne.find( 'select[name*="[suggestion][]"], select[name*="[croise][]"], select[name*="[upsell][]"]' );

					selecteurs.each( function () {
						var $sel = $( this ),
						    vals = $sel.val() || [];

						// Un produit ne se propose jamais lui-même.
						// Le nom d'un champ vaut « lignes[123][croise][] » : la garde doit
						// viser le préfixe complet, sans quoi elle ne se déclenche jamais.
						if ( $sel.attr( 'name' ).indexOf( 'lignes[' + id + ']' ) === 0 ) { return; }

						if ( 'retirer' === cible ) {
							if ( vals.indexOf( id ) === -1 ) { return; }
							$sel.find( 'option[value="' + id + '"]' ).remove();
							$sel.val( vals.filter( function ( v ) { return v !== id; } ) ).trigger( 'change' );
							touches++;
							return;
						}

						if ( vals.indexOf( id ) !== -1 ) { return; } // déjà présent

						if ( ! $sel.find( 'option[value="' + id + '"]' ).length ) {
							$sel.append( new Option( label, id, true, true ) );
						}
						$sel.val( vals.concat( [ id ] ) ).trigger( 'change' );
						touches++;
					} );
				} );

				$( '.bit-groupe .description' ).append(
					$( '<span style="color:#2271b1;display:block;margin-top:.4em"></span>' )
						.text( touches + ' <?php echo esc_js( __( 'field(s) changed — remember to save.', 'buyit-together' ) ); ?>' )
				);
			} );
		} );
		</script>

		<?php if ( $q->max_num_pages > 1 ) : ?>
			<div class="tablenav"><div class="tablenav-pages">
				<?php echo wp_kses_post( paginate_links( [
					'base'      => add_query_arg(
						array_filter( [
							'terme'    => $terme,
							'produits' => $produits_choisis ?: null,
							'par'      => $par,
						] ),
						$base
					) . '&p=%#%',
					'format'    => '',
					'current'   => $page,
					'total'     => $q->max_num_pages,
					'prev_text' => '‹',
					'next_text' => '›',
				] ) ); ?>
			</div></div>
		<?php endif; ?>
		<?php
	}

	/** Enregistre les produits liés saisis dans l'éditeur par catégorie. */
	public static function enregistrer_editeur(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'buyit-together' ) );
		}
		check_admin_referer( 'bit_editeur' );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce checked above via check_admin_referer(); each element is sanitized on the next lines.
		$lignes     = isset( $_POST['lignes'] ) && is_array( $_POST['lignes'] ) ? wp_unslash( $_POST['lignes'] ) : [];
		$sauvegarde = [];
		$modifies   = 0;

		foreach ( $lignes as $pid => $valeurs ) {
			$pid = absint( $pid );
			$produit = $pid ? wc_get_product( $pid ) : null;
			if ( ! $produit ) {
				continue;
			}

			$lire = static function ( $cle ) use ( $valeurs, $pid ): array {
				$ids = isset( $valeurs[ $cle ] ) ? array_map( 'absint', (array) $valeurs[ $cle ] ) : [];
				// Un produit ne se propose jamais lui-même.
				return array_values( array_diff( array_filter( $ids ), [ $pid ] ) );
			};

			$change = false;

			// Suggestions de fiche produit : meta propre à l'extension.
			if ( isset( $valeurs['suggestion'] ) ) {
				$suggestion = $lire( 'suggestion' );
				$avant      = get_post_meta( $pid, self::META_MANUEL, true );
				$avant      = is_array( $avant ) ? array_map( 'intval', $avant ) : [];
				if ( $suggestion !== $avant ) {
					if ( $suggestion ) {
						update_post_meta( $pid, self::META_MANUEL, $suggestion );
					} else {
						delete_post_meta( $pid, self::META_MANUEL );
					}
					$change = true;
				}
			}

			// Ventes croisées : champ natif WooCommerce, donc sauvegardé.
			if ( isset( $valeurs['croise'] ) ) {
				$croise       = $lire( 'croise' );
				$avant_croise = $produit->get_cross_sell_ids();
				if ( $croise !== $avant_croise ) {
					$sauvegarde[ $pid ] = array_map( 'intval', $avant_croise );
					$produit->set_cross_sell_ids( $croise );
					$produit->save();
					$change = true;
				}
			}

			// Montées en gamme : champ natif WooCommerce.
			if ( isset( $valeurs['upsell'] ) ) {
				$upsell = $lire( 'upsell' );
				if ( $upsell !== $produit->get_upsell_ids() ) {
					$produit->set_upsell_ids( $upsell );
					$produit->save();
					$change = true;
				}
			}

			if ( $change ) {
				$modifies++;
			}
		}

		if ( $sauvegarde ) {
			self::empiler_sauvegarde( __( 'Category editor', 'buyit-together' ), $sauvegarde );
		}
		delete_transient( 'bit_analyse' );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce checked above via check_admin_referer(); each element is sanitized on the next lines.
		$retour = isset( $_POST['retour'] ) ? sanitize_text_field( wp_unslash( $_POST['retour'] ) ) : '';
		[ $terme, $page, $onglet, $par ] = array_pad( explode( '|', $retour, 4 ), 4, '' );
		$onglet = in_array( $onglet, [ 'gamme', 'editeur' ], true ) ? $onglet : 'editeur';
		$par    = in_array( (int) $par, [ 10, 25, 50, 100 ], true ) ? (int) $par : 25;

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce checked above via check_admin_referer(); each element is sanitized on the next lines.
		$retour_produits = isset( $_POST['retour_produits'] )
			? array_values( array_filter( array_map( 'absint', (array) $_POST['retour_produits'] ) ) )
			: [];

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce checked above via check_admin_referer(); each element is sanitized on the next lines.
		$depuis = ( isset( $_POST['depuis'] ) && 'analyse' === $_POST['depuis'] ) ? 'analyse' : null;

		wp_safe_redirect( add_query_arg( array_filter( [
			'page'     => 'buyit-together',
			'onglet'   => $onglet,
			'terme'    => $terme ?: null,
			'produits' => $retour_produits ?: null,
			'par'      => $par,
			'p'        => max( 1, (int) $page ),
			'edites'   => $modifies,
			'depuis'   => $depuis,
		] ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/** Enregistre la liste des produits exclus des associations. */
	public static function enregistrer_exclus(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'buyit-together' ) );
		}
		check_admin_referer( 'bit_exclus' );

		// La clé existe toujours grâce au champ caché : une liste vidée se
		// distingue ainsi d'un formulaire qui n'aurait rien envoyé.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce checked above via check_admin_referer(); each element is sanitized on the next lines.
		$ids = isset( $_POST['exclus'] )
			? array_values( array_unique( array_filter( array_map( 'absint', (array) wp_unslash( $_POST['exclus'] ) ) ) ) )
			: [];

		// Autochargée : exclus() est appelée à l'affichage de chaque fiche produit.
		update_option( 'bit_exclus', $ids, true );
		delete_transient( 'bit_analyse' ); // le diagnostic repose sur ces paires

		wp_safe_redirect( admin_url( 'admin.php?page=buyit-together&onglet=analyse&exclus=1' ) );
		exit;
	}

	/** Enregistre la liste des fiches qui n'affichent aucune suggestion. */
	public static function enregistrer_muets(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'buyit-together' ) );
		}
		check_admin_referer( 'bit_muets' );

		// Le champ caché garantit que la clé existe : une liste vidée doit se
		// distinguer d'un formulaire qui n'aurait rien envoyé.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce checked above via check_admin_referer(); each element is sanitized on the next lines.
		$ids = isset( $_POST['muets'] )
			? array_values( array_unique( array_filter( array_map( 'absint', (array) wp_unslash( $_POST['muets'] ) ) ) ) )
			: [];

		update_option( 'bit_non_recommandes', $ids, false );
		delete_transient( 'bit_analyse' ); // les recommandations en dépendent

		wp_safe_redirect( admin_url( 'admin.php?page=buyit-together&onglet=analyse&muets=1' ) );
		exit;
	}

	/** Enregistre le mode d'affichage choisi pour la fiche produit. */
	public static function enregistrer_mode(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'buyit-together' ) );
		}
		check_admin_referer( 'bit_mode' );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce checked above via check_admin_referer(); sanitized on the next line.
		$mode = isset( $_POST['mode_fiche'] ) ? sanitize_key( wp_unslash( $_POST['mode_fiche'] ) ) : 'bloc';
		if ( ! in_array( $mode, [ 'bloc', 'modal', 'both' ], true ) || ! self::store_api_disponible() ) {
			$mode = 'bloc';
		}

		// Autochargée : lue à chaque fiche produit, via mode_fiche().
		update_option( 'bit_mode_fiche', $mode, true );

		wp_safe_redirect( admin_url( 'admin.php?page=buyit-together&onglet=reglages&mode=1' ) );
		exit;
	}

	/** Enregistre l'apparence de la fenêtre d'ajout au panier. */
	public static function enregistrer_style_modal(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'buyit-together' ) );
		}
		check_admin_referer( 'bit_style_modal' );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce checked above via check_admin_referer(); each field is sanitized by modal_style() when read back.
		$style = [
			'titre'                   => isset( $_POST['titre'] ) ? sanitize_text_field( wp_unslash( $_POST['titre'] ) ) : '',
			'message_ajoute'          => isset( $_POST['message_ajoute'] ) ? sanitize_text_field( wp_unslash( $_POST['message_ajoute'] ) ) : '',
			'couleur_accent'          => isset( $_POST['couleur_accent'] ) ? sanitize_hex_color( wp_unslash( $_POST['couleur_accent'] ) ) : '',
			'couleur_fond'            => isset( $_POST['couleur_fond'] ) ? sanitize_hex_color( wp_unslash( $_POST['couleur_fond'] ) ) : '',
			'couleur_texte'           => isset( $_POST['couleur_texte'] ) ? sanitize_hex_color( wp_unslash( $_POST['couleur_texte'] ) ) : '',
			'rayon'                   => isset( $_POST['rayon'] ) ? absint( $_POST['rayon'] ) : 14,
			'disposition'             => isset( $_POST['disposition'] ) ? sanitize_key( wp_unslash( $_POST['disposition'] ) ) : 'ligne',
			// Case cochée : la fenêtre continue de suivre le thème, les trois
			// couleurs choisies ci-dessus restent alors sans effet.
			'couleurs_personnalisees' => ! isset( $_POST['couleur_auto'] ),
		];

		// Autochargée : lue à chaque fiche produit, via modal_style().
		update_option( 'bit_modal_style', $style, true );

		wp_safe_redirect( admin_url( 'admin.php?page=buyit-together&onglet=reglages&style=1' ) );
		exit;
	}

	/** Enregistre les règles saisies dans l'écran d'administration. */
	public static function enregistrer_regles(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'buyit-together' ) );
		}
		check_admin_referer( 'bit_regles' );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce checked above via check_admin_referer(); each element is sanitized on the next lines.
		$brut   = isset( $_POST['regles'] ) && is_array( $_POST['regles'] ) ? wp_unslash( $_POST['regles'] ) : [];
		$propre = [];

		foreach ( $brut as $r ) {
			$termes = isset( $r['termes'] ) ? array_filter( array_map( 'sanitize_text_field', (array) $r['termes'] ) ) : [];
			$prods  = isset( $r['produits'] ) ? array_filter( array_map( 'absint', (array) $r['produits'] ) ) : [];
			if ( $termes && $prods ) {
				$propre[] = [ 'termes' => array_values( $termes ), 'produits' => array_values( $prods ) ];
			}
		}

		update_option( 'bit_regles', $propre, true ); // lue à chaque fiche produit

		wp_safe_redirect( admin_url( 'admin.php?page=buyit-together&onglet=fiche&regles=1' ) );
		exit;
	}

	// ----------------------------------------------------- attribution en masse --

	/**
	 * Ajoute ou remplace les ventes croisées WooCommerce sur un ensemble de produits
	 * désignés par catégorie, étiquette ou sélection directe.
	 *
	 * Les valeurs précédentes sont sauvegardées afin de permettre une annulation
	 * complète : aucune donnée n'est perdue.
	 */
	public static function appliquer_masse(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'buyit-together' ) );
		}
		check_admin_referer( 'bit_masse' );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce checked above via check_admin_referer(); each element is sanitized on the next lines.
		$termes  = isset( $_POST['cibles_termes'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['cibles_termes'] ) ) : [];
		$directs = isset( $_POST['cibles_produits'] ) ? array_filter( array_map( 'absint', (array) $_POST['cibles_produits'] ) ) : [];
		$croises = isset( $_POST['croises'] ) ? array_filter( array_map( 'absint', (array) $_POST['croises'] ) ) : [];
		$mode    = ( isset( $_POST['mode'] ) && 'remplacer' === $_POST['mode'] ) ? 'remplacer' : 'ajouter';

		if ( ! $croises || ( ! $termes && ! $directs ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=buyit-together&onglet=panier&masse=vide' ) );
			exit;
		}

		// --- Cibles issues des taxonomies -----------------------------------
		$cibles = $directs;
		foreach ( $termes as $terme ) {
			[ $taxonomie, $id ] = array_pad( explode( ':', $terme, 2 ), 2, '' );
			if ( ! $taxonomie || ! $id ) {
				continue;
			}
			$trouves = get_posts( [
				'post_type'      => 'product',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- one term lookup, not a repeated meta query; there is no lighter way to list a whole taxonomy term's products.
				'tax_query'      => [ [
					'taxonomy' => $taxonomie,
					'field'    => 'term_id',
					'terms'    => (int) $id,
				] ],
			] );
			$cibles = array_merge( $cibles, $trouves );
		}

		$cibles = array_unique( array_map( 'intval', $cibles ) );
		if ( ! $cibles ) {
			wp_safe_redirect( admin_url( 'admin.php?page=buyit-together&onglet=panier&masse=0' ) );
			exit;
		}

		// --- Application, avec sauvegarde ------------------------------------
		$sauvegarde = [];
		$modifies   = 0;

		foreach ( $cibles as $cible ) {
			// Un produit ne se propose pas lui-même.
			$ajout = array_values( array_diff( $croises, [ $cible ] ) );
			if ( ! $ajout ) {
				continue;
			}

			$actuel = get_post_meta( $cible, '_crosssell_ids', true );
			$actuel = is_array( $actuel ) ? array_map( 'intval', $actuel ) : [];

			$nouveau = ( 'remplacer' === $mode )
				? $ajout
				: array_values( array_unique( array_merge( $actuel, $ajout ) ) );

			if ( $nouveau === $actuel ) {
				continue; // rien à changer
			}

			$sauvegarde[ $cible ] = $actuel;
			update_post_meta( $cible, '_crosssell_ids', $nouveau );
			$modifies++;
		}

		if ( $sauvegarde ) {
			self::empiler_sauvegarde( __( 'Bulk assignment', 'buyit-together' ), $sauvegarde );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=buyit-together&onglet=panier&masse=' . $modifies ) );
		exit;
	}

	/**
	 * Empile une sauvegarde plutôt que de l'écraser.
	 *
	 * Deux fonctionnalités modifient les ventes croisées ; une option unique
	 * signifiait que la seconde opération détruisait le moyen d'annuler la première.
	 * On conserve donc un historique, borné pour ne pas gonfler indéfiniment.
	 */
	private static function empiler_sauvegarde( string $libelle, array $donnees ): void {
		$historique   = self::historique();
		$historique[] = [
			'libelle' => $libelle,
			'date'    => current_time( 'mysql' ),
			'donnees' => $donnees,
		];

		$max = (int) apply_filters( 'bit_historique_max', 10 );
		if ( count( $historique ) > $max ) {
			$historique = array_slice( $historique, -$max );
		}

		update_option( 'bit_historique', $historique, false );
	}

	/** Historique des modifications de ventes croisées, de la plus ancienne à la plus récente. */
	private static function historique(): array {
		$h = get_option( 'bit_historique', [] );
		return is_array( $h ) ? array_values( $h ) : [];
	}

	/** Restaure les ventes croisées telles qu'elles étaient avant la dernière opération. */
	public static function annuler_masse(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'buyit-together' ) );
		}
		check_admin_referer( 'bit_annuler' );

		$historique = self::historique();
		$derniere   = array_pop( $historique );
		$restaures  = 0;

		if ( $derniere && ! empty( $derniere['donnees'] ) ) {
			foreach ( $derniere['donnees'] as $produit => $ancien ) {
				if ( empty( $ancien ) ) {
					delete_post_meta( (int) $produit, '_crosssell_ids' );
				} else {
					update_post_meta( (int) $produit, '_crosssell_ids', array_map( 'intval', (array) $ancien ) );
				}
				$restaures++;
			}
		}

		update_option( 'bit_historique', array_values( $historique ), false );
		delete_transient( 'bit_analyse' );

		wp_safe_redirect( admin_url( 'admin.php?page=buyit-together&onglet=panier&annule=' . $restaures ) );
		exit;
	}

	public static function recalcul_manuel(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'buyit-together' ) );
		}
		check_admin_referer( 'bit_recalcul' );

		self::recalculer();

		wp_safe_redirect( admin_url( 'admin.php?page=buyit-together&onglet=analyse&recalcul=1' ) );
		exit;
	}
}

// L'extension lit directement les tables HPOS : sans cette déclaration,
// WooCommerce la signale comme incompatible et refuse d'activer la fonction.
add_action( 'before_woocommerce_init', static function (): void {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
			'custom_order_tables', __FILE__, true
		);
	}
} );

add_action( 'plugins_loaded', [ 'BuyIt_Together', 'init' ] );
register_activation_hook( __FILE__, [ 'BuyIt_Together', 'activation' ] );
register_deactivation_hook( __FILE__, [ 'BuyIt_Together', 'desactivation' ] );
