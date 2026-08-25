# Audit complet — journal

Ouvert le 2026-08-25 sur la branche `main`. Mission : audit complet du dépôt
(commande `/audit-complet`, portée non restreinte).
Campagne précédente : aucun journal `AUDIT_PROGRESS*.md` antérieur trouvé. Deux
audits informels avaient été faits plus tôt dans la conversation qui a produit
ce dépôt (non journalisés au format de ce kit) — traités ici comme non faits :
tout est relu.

## État de référence (avant toute modification)

| Contrôle | Commande | Résultat initial |
|---|---|---|
| lint PHP | `docker run --rm -v "$PWD":/w -w /w php:8.4-cli sh -c 'php -l buyit-together.php && php -l uninstall.php'` | Aucune erreur de syntaxe sur les deux fichiers |
| Plugin Check (dépôt WordPress.org) | WP + WooCommerce + Storefront jetables (Docker isolé), `wp plugin check buyit-together` | Zéro remontée sur le code ; seul `.gitignore` signalé (`hidden_files`) — fichier de développement, exclu du paquet livré, connu et sans action |

Pas de suite de tests automatisés dans ce dépôt (pas de `composer.json`, pas de
`phpunit.xml`, pas de CI) — plugin WordPress mono-fichier. Les commandes
ci-dessus sont celles établies au fil de la session pour ce projet ; aucune
autre n'est documentée dans le README ni un CLAUDE.md local (absent).

Échecs préexistants et tests instables connus : aucun (pas de suite de tests).

## Lots

| # | Lot | Fichiers / méthodes | État | Constats |
|---|---|---|---|---|
| A | Fenêtre modale + Réglages (le plus récent, jamais audité formellement) | `css_modal`, `afficher_modal` (+JS), `modal_style`, `enregistrer_style_modal`, `onglet_reglages`, `mode_fiche`, `store_api_disponible`, `suggestions_pour`, `afficher`, `afficher_bloc` | clos | 1 corrigé, 2 écartées après falsification, 1 proposition |
| B | Gestionnaires d'écriture (`admin_post_*`) | `appliquer_recos`, `enregistrer_editeur`, `enregistrer_exclus`, `enregistrer_muets`, `enregistrer_mode`, `enregistrer_regles`, `appliquer_masse`, `annuler_masse`, `recalcul_manuel`, `empiler_sauvegarde`, `historique` | clos | aucun |
| C | Calcul et SQL direct | `paires_reelles`, `recalculer`, `analyser`, `repli_regles`, `produit_correspond`, `exclus`, `non_recommandes`, `regles`, `termes_disponibles` | clos | 2 corrigés |
| D | Écran d'administration (lecture/affichage) | `menu`, `page`, `lien_produit`, `notices`, `styles`, `onglet_analyse`, `onglet_fiche`, `onglet_panier`, `onglet_editeur` | clos | 1 corrigé |
| E | Cycle de vie et fichiers annexes | `init`, `activation`, `desactivation`, `uninstall.php`, `languages/*.po`, `readme.txt`/`README.md` (cohérence) | clos | 2 corrigés |

Ordre choisi : A et B en premier (code le plus récent et frontières de
confiance — nonce, capacité, entrées `$_POST`/`$_GET`, et pour A le nouveau
flux JS → Store API, jamais revu), puis C (SQL direct), puis D (lecture pure,
risque plus faible), puis E.

Tous les lots sont clos. Passe transversale faite (voir plus bas).
Fichiers exclus et pourquoi : `.wordpress-org/*` (visuels et gabarits du
dépôt WordPress.org, aucun code) ; `languages/*.mo` (binaire compilé, la
cohérence se vérifie sur le `.po`).

## Constats

### Lot A

#### [MINEUR] Docblock orphelin devant `css_modal()`

- **Statut** : CONFIRMÉ
- **Où** : `buyit-together.php`, entre `afficher_bloc()` (fin) et `css_modal()`
  (avant correction — lignes ~328-341)
- **Déclencheur** : lecture du code par un développeur entre `afficher_bloc()`
  et `css_modal()`
- **Conséquence** : le commentaire (API REST du panier, interception en phase
  de capture, `stopImmediatePropagation()`) décrit sans ambiguïté le
  comportement JS d'`afficher_modal()`, mais était accolé à `css_modal()` — un
  simple générateur de chaîne CSS sans rapport avec l'ajout au panier.
  `afficher_modal()`, la fonction réellement décrite, n'avait plus aucun
  docblock. Aucun impact d'exécution ; seulement un risque de contresens pour
  la maintenance (même classe de défaut déjà rencontrée et corrigée plus tôt
  dans le développement de ce fichier).
- **Preuve** : `grep -n "private static function afficher_modal\|private static function css_modal"`
  → lignes 350 et 434 ; `sed -n '425,434p'` confirmait qu'aucune ligne de
  documentation ne précédait la déclaration d'`afficher_modal()`.
- **Falsification** : pas de garde-fou pertinent (commentaire, pas de
  comportement) ; pas impossible par construction (visible textuellement au
  bon endroit) ; pas intentionnel — rien dans le contenu du bloc ne décrit ce
  que fait `css_modal()`.
- **Correctif** : bloc déplacé au-dessus de `afficher_modal()` ; `css_modal()`
  garde son propre docblock, resté en place.
- **Risque du correctif** : nul (commentaire seul, aucun changement de
  comportement).

#### Hypothèse écartée — quantité `NaN` dans le formulaire d'ajout

- **Statut** : écartée après falsification (pas un défaut)
- **Où** : `var quantite = parseInt( donnees.get('quantity') || '1', 10 );`
  dans le gestionnaire `submit` principal (~ligne 626)
- **Hypothèse initiale** : un champ quantité présent mais vide donnerait
  `NaN`, sérialisé en `null` par `JSON.stringify` dans le corps envoyé à la
  Store API.
- **Falsification** : `ajouter()` (ligne 563) construit le corps de la requête
  avec `quantity: quantite || 1`. `NaN` est *falsy* en JavaScript, donc
  `NaN || 1` vaut `1` — le repli existe déjà, en aval de l'endroit où il était
  cherché. Aucun défaut.

#### Hypothèse écartée — nonce partagé entre boutons « Ajouter » du modal

- **Statut** : écartée après falsification (pas un défaut)
- **Où** : fermeture `nonce` (ligne 542) lue par `nonceCourant()` (ligne 556),
  utilisée à la fois par le gestionnaire `submit` principal et par chaque
  bouton `.bit-modal__ajouter` du modal (ligne 667)
- **Hypothèse initiale** : deux clics rapprochés sur deux boutons « Ajouter »
  différents liraient le même nonce en cache avant qu'aucune réponse ne l'ait
  fait tourner ; le second appel échouerait sur un nonce déjà consommé.
- **Falsification** : les nonces WordPress (`wp_verify_nonce`) ne sont pas à
  usage unique — ils restent valides sur toute leur fenêtre de vie (deux
  tranches de 12h) ; la rotation ici n'est qu'une optimisation de fraîcheur,
  pas un mécanisme anti-rejeu à usage unique. Deux requêtes utilisant la même
  valeur de nonce, encore valide, sont toutes deux acceptées côté serveur.
  Aucun défaut de nonce.
- **Note non actionnable** : deux ajouts réellement concurrents partagent un
  risque générique de perte d'écriture au niveau de la session WooCommerce —
  mais ce risque existe identiquement avec deux clics natifs rapides sur des
  formulaires WooCommerce standard (double-clic, deux onglets) ; ce n'est pas
  un défaut introduit par ce plugin. Une parade (désactivation mutuelle de
  tous les boutons « Ajouter » pendant qu'un appel est en vol) coûterait en
  confort d'usage pour un risque non spécifique à ce code : non corrigé, à
  reconsidérer seulement si un signalement réel apparaît.

#### [MAJEUR] « Voir le produit » d'une taille différente de « Ajouter »

- **Statut** : CONFIRMÉ (signalé par l'utilisateur, capture du site réel à l'appui)
- **Où** : `css_modal()`, règles `.bit-modal__ajouter, .bit-modal__voir, …`
- **Déclencheur** : une suggestion de type produit variable — elle ne peut pas
  s'ajouter en un clic (il faut choisir les options), donc `afficher_modal()`
  rend un `<a class="bit-modal__voir">` au lieu d'un
  `<button class="bit-modal__ajouter">`.
- **Conséquence** : sur le thème réel du site (Flatsome), le lien ne s'étirait
  pas comme le bouton malgré `width: 100%` sur les deux, produisant une fiche
  visiblement plus étroite que ses voisines dans la même rangée.
- **Preuve** : capture du site réel (16h03) ; largeur du lien nettement
  inférieure à celle des trois boutons de la même rangée.
- **Falsification** : pas de garde-fou ailleurs (aucune autre règle ne fixe
  cette largeur) ; cas non impossible (produits variables courants dans un
  catalogue de pièces) ; non intentionnel (le CSS visait explicitement
  l'égalité, cf. correctif 1.3.2).
- **Correctif** : largeur fixe `width: 118px !important` (au lieu d'un
  étirement à 100 % dépendant du type d'élément), plus `!important` sur
  `box-sizing`, `display`, `font-size` et `padding` — les propriétés dont
  dépend la taille rendue. Un thème hôte ne peut plus les reprendre.
- **Validation** : mesure réelle en navigateur (Chrome sans interface, CDP) sur
  le balisage produit par le plugin, avec 3 produits simples + 1 variable :
  **une seule largeur (118 px) et une seule hauteur (27,81 px)** pour les
  quatre, et aucun défilement (ni liste, ni fenêtre).
- **Risque du correctif** : `!important` empêche aussi une personnalisation
  volontaire par CSS du thème — acceptable ici, c'est précisément l'objet de la
  règle, et l'apparence reste réglable par l'écran Réglages.

#### [MINEUR] Deux commentaires de traduction concurrents sur une même entrée

- **Statut** : CONFIRMÉ
- **Où** : appels `_n()` de `afficher_modal()` (« %d item in your cart. ») et de
  l'onglet éditeur (« %d selected »)
- **Déclencheur** : génération du gabarit de traduction
  (`wp i18n make-pot`)
- **Conséquence** : les deux appels de chaque paire portaient des commentaires
  « (singular form) » / « (plural form) » différents alors qu'ils extraient une
  seule et même entrée (seul l'argument `$number` diffère, il ne fait pas
  partie de la clé). L'outil émettait un avertissement et ne pouvait retenir
  qu'un commentaire, arbitrairement — donc une consigne trompeuse pour les
  traducteurs.
- **Preuve** : `wp i18n make-pot` →
  `Warning: The string "%d item in your cart." has 2 different translator comments.`
  (idem pour « %d selected »).
- **Falsification** : pas de garde-fou (l'avertissement était bien émis) ; cas
  non impossible (il se produisait à chaque génération) ; non intentionnel (le
  commentaire visait à aider, pas à contredire).
- **Correctif** : un seul commentaire, identique sur les deux appels de chaque
  paire.
- **Validation** : `wp i18n make-pot` repasse sans aucun avertissement.

#### [MINEUR] Texte d'exemple non traduisible dans l'aperçu des réglages

- **Statut** : CONFIRMÉ
- **Où** : `onglet_reglages()`, panneau d'aperçu
- **Déclencheur** : ouverture de l'onglet Réglages par un administrateur dont
  WordPress n'est pas en anglais
- **Conséquence** : la ligne d'exemple « 1 item in your cart. » était écrite en
  dur en anglais, au milieu d'un écran par ailleurs entièrement traduit —
  l'aperçu mentait donc sur le rendu réel, qui lui est traduit.
- **Preuve** : chaîne littérale dans le balisage, absente du gabarit `.pot`
  généré avant correction.
- **Falsification** : pas de garde-fou ; cas non impossible (tout site non
  anglophone) ; non intentionnel — le reste de l'écran est traduit, et la vraie
  fenêtre utilise bien `_n()` pour cette même ligne.
- **Correctif** : réutilisation de la même paire `_n()` que la fenêtre réelle,
  donc un seul msgid partagé pour les traducteurs.

#### [MINEUR] Journal d'audit livrable dans le paquet

- **Statut** : CONFIRMÉ
- **Où** : racine de l'extension (`AUDIT_PROGRESS.md`), et absence de
  `.distignore`
- **Déclencheur** : `wp plugin check buyit-together`
- **Conséquence** : `AUDIT_PROGRESS.md` (ce journal) et `.gitignore` sont des
  fichiers de développement qui se seraient retrouvés dans le paquet livré à
  l'utilisateur final. Aucun risque d'exécution, mais du bruit dans une
  extension publiée.
- **Preuve** : Plugin Check →
  `unexpected_markdown_file` sur `AUDIT_PROGRESS.md`, `hidden_files` sur
  `.gitignore`.
- **Falsification** : pas de garde-fou (aucun `.distignore` n'existait) ; cas
  non impossible (c'est l'état du dépôt) ; non intentionnel.
- **Correctif** : ajout d'un `.distignore` listant les fichiers de
  développement (`.git`, `.gitignore`, `.distignore`, `.DS_Store`, `*.zip`,
  `AUDIT_PROGRESS.md`, `README.md`, `.wordpress-org`).
- **Note** : les deux remontées de Plugin Check subsistent tant qu'on l'exécute
  sur le dossier de travail — c'est attendu ; elles portent sur des fichiers
  désormais exclus du paquet.

### Lot C

#### [MAJEUR] Sur un site en stockage classique, tout le calcul renvoie zéro en silence

- **Statut** : CONFIRMÉ (reproduit en environnement de test)
- **Où** : `paires_reelles()` — requête sur `{prefix}wc_orders`
- **Déclencheur** : un site WooCommerce resté au stockage classique des
  commandes (`wp_posts`), c'est-à-dire tout site n'ayant pas migré vers HPOS.
- **Conséquence** : la table `wc_orders` existe mais reste vide. La requête
  aboutit sans erreur et ne remonte rien. L'extension conclut « pas assez de
  commandes », affiche zéro partout et ne propose aucune suggestion — alors
  que le site peut avoir des milliers de ventes. Une réponse fausse et
  silencieuse, sans le moindre signal permettant de comprendre pourquoi.
- **Preuve** : dans l'environnement jetable, bascule en stockage classique puis
  création de 3 vraies commandes complétées contenant chacune la même paire
  (produits 13 et 15, donc 3 achats communs, au-dessus du seuil) :
  `posts shop_order : 3`, `lignes wc_orders : 0`, et le plugin répond
  `commandes vues par le plugin : 0`, `paires trouvées : 0`,
  `recalculer() renvoie : 0`.
- **Falsification** : aucun garde-fou ailleurs — `grep` sur HPOS/`OrderUtil`
  ne trouvait que la déclaration de compatibilité, et `notices()` ne
  contenait aucun contrôle d'environnement ; cas non impossible (le stockage
  classique reste pris en charge par WooCommerce, et reproduit ici) ; pas
  pleinement intentionnel — le README documente HPOS comme prérequis, mais
  documenter un prérequis n'est pas le détecter : déclarer la compatibilité
  `custom_order_tables` dit à WooCommerce « je fonctionne avec HPOS », pas
  « je l'exige ». Le défaut n'est pas de lire HPOS, c'est de ne rien dire
  quand il est absent.
- **Correctif** : ajout de `stockage_hpos()`
  (`OrderUtil::custom_orders_table_usage_is_enabled()`, avec repli prudent à
  `true` si l'API WooCommerce est absente, pour ne pas alarmer à tort), et
  d'un avertissement non masquable dans l'écran d'administration expliquant
  la cause et le chemin du réglage à changer.
- **Validation** : les deux sens vérifiés en environnement réel — stockage
  classique → `stockage_hpos()` renvoie `false` et l'avertissement s'affiche ;
  HPOS réactivé → `true` et aucun avertissement. Pas de faux positif.
- **Risque du correctif** : nul sur le calcul (aucune ligne du chemin de
  calcul modifiée) ; purement additif côté affichage.
- **Reste ouvert** : lire aussi les commandes du stockage classique serait une
  fonctionnalité à part entière, hors du périmètre d'un audit — voir « En
  attente de décision ».

#### [MAJEUR] Un recalcul qui ne trouve rien laissait en place les anciens chiffres

- **Statut** : CONFIRMÉ (reproduit en environnement de test)
- **Où** : `recalculer()`, sortie anticipée `if ( ! $compte ) { return 0; }`
- **Déclencheur** : un recalcul dont le calcul ne remonte aucune paire — parce
  que les commandes sont sorties de la fenêtre de 365 jours, que tous les
  produits concernés ont été exclus, ou (cas le plus vicieux) à cause du
  constat précédent.
- **Conséquence** : la sortie anticipée saute à la fois la purge des
  associations et la mise à jour de `bit_dernier_calcul`. Les fiches produit
  continuent donc d'afficher des suggestions tirées de données qui n'existent
  plus, et l'écran d'administration continue d'annoncer l'ancien bilan. Un
  administrateur qui clique « Recalculer maintenant » ne voit aucune erreur et
  retrouve son écran inchangé : rien ne lui indique que le calcul n'a rien
  trouvé.
- **Preuve** : produit 13 porteur de `_bit_compagnons = [15,17]` et
  `bit_dernier_calcul` fixé à `2020-01-01 / 42 produits / 999 commandes`,
  puis `recalculer()` sans aucune commande dans la fenêtre → renvoie `0`,
  `_bit_compagnons` vaut toujours `[15,17]`, et `bit_dernier_calcul` affiche
  toujours `42 produits / 999 commandes`.
- **Falsification** : aucun autre chemin de purge — `activation()` et
  `desactivation()` ne touchent que la tâche planifiée, et `uninstall.php` ne
  s'exécute qu'à la suppression de l'extension ; cas non impossible (reproduit
  ici) ; intentionnalité **partielle** — préserver les associations plutôt que
  de tout effacer sur un calcul vide est une prudence défendable (une panne
  passagère de base ne devrait pas détruire les données). En revanche, laisser
  `bit_dernier_calcul` intact n'est défendable sous aucune lecture : le calcul
  a bien tourné et a produit zéro, l'écran ne devrait pas continuer à annoncer
  42 produits.
- **Correctif** : option (b), retenue par le propriétaire du projet le
  2026-08-25. `bit_dernier_calcul` est désormais écrit même quand le calcul ne
  trouve rien (date du jour, 0 produit, et le nombre de commandes réellement
  vues) ; les associations déjà calculées sont laissées en place. Le nombre de
  commandes est conservé parce qu'il porte une information : « 0 produit
  associé, à partir de 12 commandes » dit que les commandes existent mais ne
  contiennent aucune paire, là où « 0 sur 0 » dit qu'il n'y a rien à analyser.
- **Pourquoi ne pas purger** : un résultat vide vient plus souvent d'une cause
  passagère ou externe — fenêtre d'analyse dépassée, produits exclus, stockage
  des commandes illisible (constat précédent) — que d'une disparition réelle
  des données. Effacer sur cette seule base détruirait un travail que seul un
  nouveau calcul réussi peut reconstituer.
- **Validation** : le scénario d'origine rejoué à l'identique — produit 13
  portant `[15,17]` et bilan figé à `2020-01-01 / 42 produits / 999 commandes`,
  puis `recalculer()` sans aucune commande → renvoie `0`, associations
  **inchangées** (`[15,17]`), bilan **mis à jour**
  (`2026-08-25 / 0 produit / 0 commande`).
  Non-régression du chemin nominal vérifiée séparément : 3 vraies commandes
  HPOS contenant la paire 13+15, avec une association périmée `17` posée
  d'avance → `recalculer()` renvoie `2`, l'association périmée est bien purgée
  (13 ne garde que `[15]`), la réciproque est écrite (15 → `[13]`), et le
  bilan indique `2 produits / 3 paniers`.
- **Risque du correctif** : nul sur le chemin nominal, inchangé et vérifié.
  Sur le chemin vide, l'écran affiche désormais un bilan à zéro là où il
  montrait l'ancien — c'est précisément l'objet du correctif.

#### [PROPOSITION] Pas de pré-remplissage des couleurs fixes depuis la détection automatique

- **Statut** : PROPOSITION (amélioration, pas un défaut)
- **Où** : `onglet_reglages()`, bascule de la case « Assortir automatiquement
  aux couleurs du thème »
- **Description** : quand on décoche la case, les trois champs couleur
  repartent des dernières valeurs enregistrées (ou des valeurs par défaut)
  plutôt que de la couleur détectée en direct par le JS d'auto-détection.
  Comportement cohérent et sûr, seulement un peu surprenant à l'usage.
- **Falsification** : `enregistrer_style_modal()` / `modal_style()`
  conditionnent toujours la lecture des couleurs sur `couleurs_personnalisees` ;
  aucune valeur d'un champ désactivé ne peut fuiter dans les réglages
  enregistrés. Confirmé sans risque. Non corrigé — changement de confort,
  hors du périmètre « corriger directement ».

### Lot D

#### [MINEUR] Lien « Relancer l'analyse » sans jeton

- **Statut** : CONFIRMÉ
- **Où** : `onglet_analyse()` — `self::analyser( isset( $_GET['analyser'] ) )`,
  et le lien correspondant plus bas dans la même méthode
- **Déclencheur** : n'importe quelle page tierce faisant charger à un
  administrateur connecté l'URL
  `admin.php?page=buyit-together&onglet=analyse&analyser=1`
- **Conséquence** : le paramètre force `analyser( true )`, qui contourne le
  cache d'une heure et relance une agrégation sur tout l'historique des
  commandes (jointure sur trois tables, 365 jours). Aucun jeton, aucune
  limitation : l'opération peut être déclenchée en boucle. L'action reste en
  lecture seule — aucune donnée du site n'est modifiée — donc l'impact se
  limite à la charge serveur.
- **Preuve** : le paramètre était lu sans aucune vérification, alors que
  l'autre opération lourde de l'écran (`recalcul_manuel`) passe, elle, par
  `admin_post` avec `check_admin_referer( 'bit_recalcul' )` — l'incohérence
  entre les deux chemins est visible dans le code.
- **Falsification** : pas de garde-fou ailleurs (aucun `check_admin_referer`
  ni contrôle de capacité sur ce chemin autre que l'accès à la page) ; cas non
  impossible (simple URL) ; non intentionnel — le chemin jumeau est protégé,
  celui-ci ne l'était pas.
- **Correctif** : le lien est passé par `wp_nonce_url( …, 'bit_analyser' )` et
  le forçage n'a lieu que si `wp_verify_nonce()` réussit. Un lien périmé ou
  sans jeton retombe simplement sur l'analyse en cache — aucune erreur
  affichée à l'utilisateur.
- **Validation** : vérifié dans les deux sens en environnement réel — sans
  jeton, `analyser()` renvoie le contenu du cache témoin ; avec un jeton
  valide, elle renvoie une analyse fraîchement calculée.
- **Risque du correctif** : un signet enregistré sur l'ancienne URL cesse de
  forcer le recalcul. Dégradation silencieuse et sans erreur, jugée
  préférable au déclenchement non protégé.

#### Échappement — vérifié, aucun défaut

Balayage de tout le code d'affichage (`page`, `notices`, `lien_produit`, les
quatre onglets, `styles`) : chaque entrée `$_GET` est assainie à la lecture
(`sanitize_key`, `absint`, `sanitize_text_field` + `wp_unslash`, ou comparaison
stricte), et Plugin Check — qui exécute les sondes `WordPress.Security.EscapeOutput`,
`NonceVerification` et `PreparedSQL` (leur exécution est attestée par les
`phpcs:ignore` ciblés présents dans le fichier) — ne remonte rien sur le code.

### Lot E

#### [MINEUR] Docblock périmé contredisant le code

- **Statut** : CONFIRMÉ
- **Où** : au-dessus de `activation()`
- **Déclencheur** : lecture du code
- **Conséquence** : deux docblocks empilés, dont le premier affirmait qu'à
  l'activation « on amorce la règle la plus évidente des données : le kit
  d'outils… » — alors que le second, juste en dessous, dit l'inverse (« Rien
  n'est pré-rempli ») et que le code ne fait que programmer la tâche
  planifiée. Documentation résiduelle d'une fonctionnalité retirée, plus
  trompeuse que le docblock orphelin du Lot A puisqu'elle énonce le contraire
  du comportement réel.
- **Preuve** : les deux blocs se suivaient immédiatement avant la déclaration ;
  le corps de `activation()` ne contient qu'un `wp_schedule_event()`.
- **Falsification** : pas de garde-fou (commentaire) ; pas impossible (visible
  textuellement) ; non intentionnel — les deux blocs se contredisent, ils ne
  peuvent pas être vrais tous les deux.
- **Correctif** : suppression du bloc périmé ; celui qui décrit le
  comportement réel est conservé.

#### [MINEUR] FAQ trompeuse sur le stockage des commandes

- **Statut** : CONFIRMÉ
- **Où** : `readme.txt`, entrée « Does it work with High-Performance Order
  Storage? »
- **Déclencheur** : lecture de la FAQ par un commerçant en stockage classique
- **Conséquence** : la réponse était « Yes. It reads orders from the HPOS
  tables directly. » — elle confirme que HPOS fonctionne mais ne dit pas qu'il
  est **requis**. Un commerçant qui n'utilise pas HPOS en conclut que tout va
  bien, soit exactement l'inverse de la réalité : il tombe alors sur le
  constat MAJEUR du Lot C sans aucun moyen de faire le lien.
- **Preuve** : le comportement réel a été établi empiriquement au Lot C
  (3 commandes réelles, 0 vue par l'extension).
- **Falsification** : la question posée était bien celle de la compatibilité,
  et la réponse y répondait littéralement — mais elle laissait sans réponse
  la seule question qui compte pour le lecteur concerné. Non intentionnel :
  `README.md` documente déjà HPOS comme prérequis, la FAQ était juste restée
  en retard.
- **Correctif** : réponse réécrite pour dire que HPOS est requis, ce qui se
  produit sinon, et où se trouve le réglage.

#### Désinstallation — vérifiée, aucun défaut

Recoupement automatique des options réellement écrites par l'extension contre
celles nettoyées par `uninstall.php` : les huit options (`bit_regles`,
`bit_exclus`, `bit_non_recommandes`, `bit_historique`, `bit_dernier_calcul`,
`bit_mode_fiche`, `bit_modal_style`, transient `bit_analyse`) et les deux
métadonnées (`_bit_compagnons`, `_bit_manuel`) sont couvertes. `_crosssell_ids`
est délibérément épargnée — c'est une donnée WooCommerce, pas une donnée de
l'extension, et le fichier le documente.

`bit_sans_suggestions` est supprimée par `uninstall.php` sans être jamais
écrite ni lue ailleurs. **Non retenu comme défaut** : nettoyer une option
laissée par une version antérieure est une pratique légitime, et
`delete_option()` sur une option absente est sans effet. La retirer risquerait
de perdre un nettoyage réel pour les sites qui montent de version.

#### Cycle de vie — vérifié, aucun défaut

`register_activation_hook` et `register_deactivation_hook` sont tous deux
enregistrés ; `desactivation()` retire bien la tâche planifiée, et `init()`
la reprogramme si elle manque (filet de sécurité si le crochet d'activation
n'a pas joué).

## Passe transversale

- **Cohérence des versions** : `buyit-together.php` (en-tête `Version`) et
  `readme.txt` (`Stable tag`) sont tous deux à 1.1.1. Aucune constante de
  version interne à synchroniser (il n'y en a pas).
- **Cohérence des traductions** : gabarit `.pot` régénéré après les
  modifications ; les six langues (fr, es, de, it, pt, nl) sont à 170/173.
  Les 3 chaînes non traduites sont le nom de l'extension, son URI et l'auteur,
  qui ne doivent pas l'être. Le nouvel avertissement de stockage a été traduit
  dans les six langues et son chargement réel vérifié dans WordPress.
- **Contrôles finaux** : `php -l` sans erreur sur les deux fichiers PHP ;
  `wp plugin check` sans aucune remontée sur le code ; `wp i18n make-pot` sans
  avertissement.

## Corrections appliquées

| Constat | Modification | Validée par | Résultat |
|---|---|---|---|
| Docblock orphelin devant `css_modal()` | Déplacement du docblock d'`afficher_modal()` vers sa déclaration ; `css_modal()` garde le sien | `php -l buyit-together.php` (Docker `php:8.4-cli`) | Aucune erreur de syntaxe après correction |
| « Voir le produit » d'une taille différente de « Ajouter » | Largeur fixe `118px !important` sur les deux, + `!important` sur `box-sizing`, `display`, `font-size`, `padding` | Mesure réelle en navigateur (Chrome sans interface, CDP) sur le balisage du plugin, 3 produits simples + 1 variable | Une seule largeur (118 px) et une seule hauteur (27,81 px) pour les 4 ; aucun défilement |
| Commentaires de traduction concurrents | Un seul commentaire par paire `_n()` | `wp i18n make-pot` | Plus aucun avertissement (2 auparavant) |
| Texte d'exemple non traduisible dans l'aperçu | Réutilisation de la paire `_n()` de la fenêtre réelle | Gabarit `.pot` régénéré | La chaîne apparaît désormais dans le gabarit, msgid partagé |
| Journal d'audit livrable dans le paquet | Ajout d'un `.distignore` | `wp plugin check buyit-together` | Zéro remontée sur le code ; les 2 restantes portent sur des fichiers désormais exclus du paquet |
| Stockage classique → tout à zéro en silence | `stockage_hpos()` + avertissement non masquable dans l'écran d'admin | Bascule réelle du site de test dans les deux sens | Stockage classique → avertissement affiché ; HPOS → aucun. Pas de faux positif |
| Recalcul vide laissant les anciens chiffres | Option (b) : `bit_dernier_calcul` mis à jour, associations préservées | Scénario d'origine rejoué + non-régression du chemin nominal (3 commandes réelles) | Chemin vide : associations inchangées, bilan à jour. Chemin nominal : purge et écriture correctes, inchangé |
| Lien « Relancer l'analyse » sans jeton | `wp_nonce_url()` + `wp_verify_nonce()`, repli silencieux sur le cache | Test des deux sens en environnement réel | Sans jeton → cache ; avec jeton → analyse fraîche |
| Docblock périmé contredisant `activation()` | Suppression du bloc périmé | `php -l` | Aucune erreur de syntaxe |
| FAQ trompeuse sur le stockage | Réponse réécrite : HPOS est requis, avec le chemin du réglage | Relecture | Cohérente avec le comportement établi empiriquement |
| (hors constat — demande utilisateur) Extension monolingue | Traductions es_ES, de_DE, it_IT, pt_PT, nl_NL ajoutées + gabarit `.pot` livré | Chargement réel via `load_textdomain()` dans WordPress, singulier et pluriel vérifiés par langue | 169/172 chaînes traduites par langue (les 3 restantes — nom, URI, auteur — ne doivent pas l'être) ; les 6 langues se chargent et rendent les bonnes formes |

## En attente de décision

- **Lire aussi les commandes en stockage classique ?** L'avertissement ajouté
  dit désormais la vérité, mais l'extension reste inutilisable sur un tel site.
  Prendre en charge les deux stockages est une fonctionnalité à part entière
  (seconde requête sur `wp_posts`/`wp_postmeta`), hors du périmètre d'un audit.
  À arbitrer selon la cible : un site publié sur WordPress.org rencontrera des
  boutiques en stockage classique.
- **Rien n'est commité ni poussé** — conformément au garde-fou de la mission.
  Les modifications ci-dessus sont dans le dossier de travail uniquement, et la
  version est passée à 1.1.1 (`buyit-together.php`, `readme.txt`), après
  renumérotation demandée par le propriétaire du projet : la progression
  1.2.0 → 1.3.x était jugée trop rapide, et rien n'ayant jamais été publié
  (aucune étiquette Git, pas de dépôt WordPress.org), renuméroter était sans
  risque pour les installations existantes.
- **Choix des langues ajoutées** : espagnol, allemand, italien, portugais,
  néerlandais — les principaux marchés WooCommerce européens en complément du
  français existant. À valider ou à étendre (polonais, roumain… ?) selon la
  clientèle visée.
- **Portugais** : traduit en portugais européen (`pt_PT`). Le brésilien
  (`pt_BR`) est un fichier distinct dans WordPress et représente un marché bien
  plus grand — à ajouter si pertinent.
- **Tutoiement** : les traductions es/de/pt/nl tutoient l'utilisateur, comme
  le fait WordPress dans ces langues. À confirmer si une adresse plus formelle
  est préférée.
- **Environnement de test** : le gabarit produit de WooCommerce ne se rend plus
  dans le conteneur jetable (aucun `form.cart` sur la page produit, alors que
  le crochet `woocommerce_after_single_product_summary` se déclenche bien). La
  vérification des boutons a donc été faite sur le balisage réel du plugin
  servi en page statique — ce qui teste exactement le CSS en cause. Le
  parcours complet « clic sur Ajouter → Store API → fenêtre » n'a pas pu être
  rejoué de bout en bout dans cette session ; il l'avait été lors des versions
  précédentes.

## Non couvert

- Le parcours complet « clic sur Ajouter → Store API → fenêtre » n'a pas pu
  être rejoué de bout en bout : le gabarit produit de WooCommerce ne se rend
  plus dans le conteneur jetable. La vérification des boutons a été faite sur
  le balisage réel produit par le plugin, servi en page statique — ce qui
  teste exactement le CSS en cause, mais pas l'enchaînement réseau.
- Aucun test de charge : les requêtes d'agrégation ont été lues et jugées sur
  leur forme, pas mesurées sur un gros catalogue.
- `.wordpress-org/*` (visuels du dépôt) et `languages/*.mo` (binaires
  compilés) : exclus dès le départ, sans code à auditer.
