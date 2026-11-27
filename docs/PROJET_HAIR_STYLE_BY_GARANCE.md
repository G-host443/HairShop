# PROJET : HAIR_STYLE BY GARANCE
## Conception complète d'une plateforme web professionnelle de prestations de coiffure

Tu es un architecte logiciel senior, développeur full-stack PHP, expert UI/UX, spécialiste des plateformes de réservation et designer web spécialisé dans les sites de beauté, de coiffure et de bien-être.

Ta mission est de concevoir et développer une plateforme web professionnelle, moderne, élégante, entièrement personnalisable et dynamique pour une entreprise de coiffure appelée :

**Hair_style by Garance**

Le projet doit être conçu comme une véritable application web professionnelle, et non comme une simple page vitrine statique.

Le site doit être organisé en deux grands espaces distincts :

1. Un espace public destiné aux clients.
2. Un espace privé destiné à l'administrateur.

L'objectif est de permettre à Hair_style by Garance de présenter ses prestations, valoriser son savoir-faire, attirer de nouveaux clients, gérer les réservations et administrer entièrement son site depuis un tableau de bord moderne.

---

# PARTIE 0 — SITE DE RÉFÉRENCE

## Référence analysée

Site de référence : https://lemuseebeaute.netlify.app/ (Lemusée beauté, tresses et coiffures protectrices à domicile).

Ce site sert de **référence d'esprit, de parcours et d'ergonomie**. Il ne doit pas être copié : textes, logo, photos, nom et coordonnées restent ceux de Hair_style by Garance. Les éléments ci-dessous décrivent ce qu'il faut reprendre et comment l'adapter à une plateforme dynamique.

## Points forts à reprendre

1. **Une page d'accueil courte et directe**, organisée en une seule colonne : Accueil → Tarifs → Réalisations → Infos pratiques → Contact. Le menu fixe (sticky) renvoie vers ces ancres.
2. **Une grille de tarifs interactive** : la cliente choisit une coiffure, puis une taille (S à XL), puis une longueur. Le prix s'affiche aussitôt et un bouton « Réserver » apparaît avec la prestation déjà renseignée.
3. **Des finitions et un entretien en option**, ajoutés directement à la réservation (perles, nœuds, boucles, défaisage et shampoing).
4. **Des infos pratiques claires** : lieu, horaires jour par jour et règles à connaître avant de venir.
5. **Un contact centré sur WhatsApp**, complété par SMS, Instagram, Facebook et TikTok.
6. **Une ambiance chaleureuse et élégante** : tons crème, sable et doré, titres en serif raffiné et touches manuscrites.

## Ce que la plateforme doit faire en plus

Le site de référence est une page statique : ses tarifs sont écrits en dur dans le JavaScript et la réservation passe par Calendly ou WhatsApp. Hair_style by Garance doit reprendre le même parcours, mais avec :

- des tarifs, tailles, longueurs et options stockés dans PostgreSQL et modifiables depuis l'administration ;
- une réservation interne, avec gestion des créneaux et de la durée de chaque prestation ;
- WhatsApp conservé en canal de secours, avec un message pré-rempli.

---

# PARTIE 1 — ESPACE CLIENT

## 1. Identité visuelle et direction artistique

Créer une identité visuelle élégante, féminine, moderne, haut de gamme et professionnelle.

Le site doit évoquer :

- La beauté.
- Le soin.
- La confiance.
- L'élégance.
- Le professionnalisme.
- La créativité.
- L'expérience personnalisée.

### Style graphique souhaité

Utiliser une direction artistique inspirée des sites premium de salons de coiffure et de beauté.

Palette de couleurs par défaut, inspirée du site de référence :

| Rôle | Couleur | Hex indicatif |
|---|---|---|
| Fond principal | Crème | `#F6EFDF` |
| Fond secondaire / cartes | Blanc cassé | `#FAF8F5` |
| Fond alterné | Sable clair | `#EDE4D3` |
| Bordures, séparateurs | Sable | `#D9CFBF` |
| Accent clair | Doré pâle | `#F3D89B` |
| Accent principal (boutons, puces actives) | Doré | `#DFB05E` |
| Accent foncé (survol, liens) | Bronze | `#806217` |
| Texte secondaire | Brun taupe | `#6D5B54` |
| Texte principal | Brun chocolat | `#5A4A44` |
| Titres, pied de page | Noir chaud | `#17140F` |
| Touche optionnelle | Rose poudré | `#E4CFC4` |

La palette doit être entièrement personnalisable depuis l'administration. Les contrastes texte/fond doivent rester conformes WCAG AA.

### Typographie

Combinaison par défaut, reprise de la référence :

- **Titres** : Cormorant Garamond (500, 600, italique 500), serif raffiné.
- **Textes et interface** : Jost (400, 500), sans-serif moderne et lisible.
- **Touches manuscrites** : Parisienne, réservée au nom de marque, aux sous-titres décoratifs ou aux signatures. Jamais pour les textes longs.
- Une hiérarchie visuelle claire.

Les polices doivent être modifiables depuis l'administration.

### Formes et composants

- Coins arrondis doux : de 8 à 18 px pour les cartes et images, boutons en forme de pilule (30 à 40 px).
- Boutons principaux pleins (doré), boutons secondaires « ghost » (contour seulement).
- Puces (chips) cliquables pour choisir une coiffure, une taille ou une longueur, avec un état actif bien visible.
- En-tête fixe (sticky) au défilement.
- Respect de `prefers-reduced-motion` : les animations et transitions sont désactivées quand l'utilisateur le demande.

### Expérience visuelle

Le site doit comporter :

- Des animations fluides.
- Des transitions élégantes.
- Des effets de survol.
- Des galeries d'images immersives.
- Des sections visuellement bien espacées.
- Des boutons d'action clairement identifiables.
- Une navigation intuitive.

Le design doit être responsive et parfaitement adapté aux ordinateurs, tablettes et smartphones.

---

## 2. Système de templates prédéfinis

Créer un système permettant de choisir entre plusieurs templates de site prédéfinis.

L'administrateur doit pouvoir sélectionner un template depuis son tableau de bord et l'appliquer au site sans modifier le code.

### Templates à prévoir

**Template 1 — Élégance Premium**

Design luxueux, minimaliste, avec de grandes images, une typographie raffinée et une présentation haut de gamme.

**Template 2 — Beauty Chic**

Design féminin, chaleureux, avec des couleurs douces, des formes arrondies et des animations délicates.

**Template 3 — Modern Hair Studio**

Design moderne, dynamique et audacieux, avec des sections asymétriques et des visuels impactants.

**Template 4 — Afro Hair & Natural Beauty** *(template par défaut)*

Design valorisant les cheveux naturels, les coiffures afro, les tresses et les styles protecteurs.

Ce template est directement inspiré du site de référence : page d'accueil en une colonne, palette crème et doré, typographies Cormorant Garamond, Jost et Parisienne, grille de tarifs interactive mise en avant juste après l'accueil. C'est le template activé à l'installation.

**Template 5 — Minimal Professional**

Design sobre, épuré et professionnel, mettant principalement en avant les prestations et les tarifs.

Chaque template doit posséder :

- Sa propre structure visuelle.
- Ses propres variantes de sections.
- Ses propres styles.
- Ses propres configurations de couleurs.
- Ses propres options de mise en page.

Les templates doivent partager le même système de contenu afin que le changement de template ne supprime aucune donnée.

Prévoir également un système de prévisualisation avant publication.

---

## 3. Page d'accueil dynamique

Créer une page d'accueil riche, immersive et entièrement administrable.

Elle doit pouvoir contenir les sections suivantes :

### Hero section

Une grande section d'introduction avec :

- Le nom Hair_style by Garance.
- Une phrase d'accroche personnalisable.
- Un court paragraphe qui liste les styles proposés (knotless, box twist, fulanis, bohemian, faux locs, bantu, nattes, tissage, coiffures enfants…).
- Une ligne de localisation (exemple : « À 5 min de la gare de … » ou « Au salon / à domicile »).
- Une image, une vidéo de fond ou le logo mis en valeur.
- Un bouton principal « Choisir ma coiffure et réserver », qui mène à la grille de tarifs.
- Un bouton secondaire « Découvrir nos prestations ».

Exemple de slogan :

« Sublimez votre beauté, révélez votre style. »

Exemple d'accroche dans l'esprit de la référence :

« Tresses et coiffures protectrices, faites avec soin. Choisissez votre coiffure, votre taille et votre longueur : le prix s'affiche, puis réservez votre créneau en un clic. »

### Tarifs interactifs

Section placée juste après l'accueil dans le template par défaut. Elle reprend le module de grille de tarifs décrit au chapitre 4.

### Infos pratiques

Section en trois blocs :

- **Où** : lieu de prestation (salon ou domicile de la coiffeuse). Si c'est un domicile, l'adresse exacte n'est jamais affichée publiquement : elle est envoyée dans l'e-mail ou le message de confirmation du rendez-vous.
- **Horaires** : affichés jour par jour à partir du module horaires, jours fermés compris.
- **À savoir avant de venir** : liste de règles modifiable (voir chapitre 5, « Règles et conditions »).

### Présentation du salon

Une section présentant l'activité, la philosophie et le savoir-faire de Hair_style by Garance.

### Prestations populaires

Afficher automatiquement les prestations les plus populaires ou sélectionnées par l'administrateur.

### Galerie de réalisations

Présenter les coiffures réalisées avec :

- Images.
- Descriptions.
- Catégories.
- Tags.
- Avant/après lorsque disponible.

### Présentation de la coiffeuse

Une section permettant de présenter Garance :

- Portrait.
- Biographie.
- Expérience.
- Spécialités.
- Philosophie professionnelle.

### Témoignages clients

Afficher les avis clients avec :

- Nom ou prénom.
- Note.
- Commentaire.
- Date.
- Photo facultative.

### Promotions

Afficher automatiquement les offres et promotions actives.

### Appel à l'action

Créer une section encourageant les visiteurs à prendre rendez-vous.

### Réseaux sociaux

Intégrer des liens vers les réseaux sociaux de l'entreprise, notamment Instagram, Facebook, TikTok et WhatsApp.

Toutes les sections de la page d'accueil doivent pouvoir être activées, désactivées, réorganisées et personnalisées depuis l'administration.

---

## 4. Catalogue des prestations

Créer un catalogue de prestations de coiffure dynamique.

Chaque prestation doit posséder :

- Un nom.
- Une description détaillée.
- Une catégorie.
- Une image.
- Un prix.
- Une durée estimée.
- Une éventuelle promotion.
- Des conditions particulières.
- Un statut actif ou inactif.

### Catégories de prestations

Prévoir des catégories personnalisables telles que :

- Tresses.
- Nattes.
- Coiffures afro.
- Braids.
- Crochet braids.
- Perruques.
- Pose et entretien.
- Coiffures événementielles.
- Soins capillaires.
- Coupes.
- Coloration.
- Coiffures pour enfants.

Les catégories ne doivent pas être limitées à cette liste.

L'administrateur doit pouvoir créer ses propres catégories.

### Grille de tarifs par taille et longueur (inspirée de la référence)

Dans le domaine des tresses, le prix dépend presque toujours de la **taille des tresses** et de la **longueur**. Le catalogue doit gérer deux types de tarification.

**Type A — Tarif matriciel (taille × longueur)**

Une coiffure (exemple : Knotless) possède des **variantes de taille**, et chaque variante a un prix par **longueur**.

- Tailles par défaut : **S** (fines), **M**, **L**, **XL** (épaisses). La liste est configurable.
- Longueurs par défaut, de la plus courte à la plus longue : Nuque, Épaules, Omoplates, Attache du soutien-gorge, Mi-dos, Taille, Hanches, Coccyx, Classique, Mi-cuisse. La liste est configurable et ordonnée.
- Chaque cellule taille × longueur peut valoir :
  - un **prix** en euros ;
  - **« sur devis »** (cas fréquent pour les longueurs Classique et Mi-cuisse) ;
  - **non proposé** : la longueur n'est alors pas affichée pour cette taille.
- Chaque variante peut avoir sa propre photo et une note (exemple : « Avec mèches Xpression », « Avec knotless braids à l'arrière »).
- Chaque coiffure peut afficher une alerte (exemple : « Si knotless à l'arrière, un rajout de 15 € est appliqué »).

**Type B — Tarif simple (liste de prix)**

Une rubrique (exemple : Bantu styles, Enfants, Variétés) contient une liste d'éléments, chacun avec :

- un nom ;
- un prix, ou « sur devis » ;
- un indicateur **prix fixe** (« 60 € ») ou **prix de départ** (« à partir de 60 € ») ;
- une photo facultative.

Chaque rubrique peut aussi avoir une note d'en-tête (exemple : « Pour les enfants âgés de 4 à 10 ans », « Prix de départ »).

**Affichage public de la grille**

1. Une rangée de puces avec les coiffures, triées par ordre alphabétique ou dans l'ordre choisi par l'administrateur.
2. Pour une coiffure à tarif matriciel : puces de tailles, puis liste des longueurs avec leur prix. Toucher une longueur la sélectionne.
3. La photo de la variante sélectionnée s'affiche à côté.
4. Un récapitulatif affiche la prestation choisie, les options et le total estimé, avec un bouton « Réserver » qui lance le parcours de réservation déjà renseigné.
5. Le tout doit être utilisable au doigt sur smartphone.

**Exemples de rubriques relevées sur la référence** (à utiliser comme données de démonstration, avec des prix fictifs ou ceux fournis par Garance) :

- Tarif matriciel : Knotless, Knotless twist, Bohemian, Bohemian French curl, Box braids French curl, Box twist, Fulanis, Fulanis nattes collées, Fulanis vanille, Semi Fulani braids, Semi Fulani twist.
- Tarif simple : Nattes sans rajouts, Micro / mini braids, Faux locs, Bantu styles (locs, knots, braids), Autres braids (piqué lâché, écailles, nattes à l'ancienne), Enfants (4 à 10 ans), Variétés (tissage, semi tissage, chignon, barrel twist, crochet, coco twist, invisible locs, free style), Entretien.

### Options et suppléments

Prévoir un système d'options rattachées aux prestations.

**Finitions** (une seule au choix) :

- Perles : +10 € (perles apportées par la cliente).
- Nœuds : +10 €.
- Boucles : offertes.

**Entretien en complément** (un seul au choix, facultatif) :

- Défaire braids + shampoing.
- Défaire locs + shampoing.
- Shampoing simple.

**Autres suppléments** :

- Mèches fournies par la coiffeuse, sur demande, avec supplément.
- Rajout lié à une variante (exemple : knotless à l'arrière, +15 €).

Règles :

- L'administrateur choisit quelles options sont proposées pour chaque catégorie ou prestation. Par exemple, la rubrique Entretien n'a pas de finition.
- Chaque option possède un nom, un prix (0 = offert), un groupe (finition, entretien, supplément) et une règle de choix (une seule ou plusieurs dans le groupe).
- Le total estimé et la réservation enregistrée incluent les options choisies.

### Détail d'une prestation

Chaque prestation doit disposer d'une page détaillée comprenant :

- Une galerie d'images.
- Une description complète.
- Les avantages.
- Les étapes de réalisation.
- Le prix.
- La durée.
- Les recommandations.
- Les prestations complémentaires.
- Un bouton de réservation.

---

## 5. Système de réservation en ligne

Créer un système de réservation professionnel.

Le client doit pouvoir réserver une prestation sans avoir besoin de contacter directement la coiffeuse.

### Parcours de réservation

Étape 1 : Choisir une prestation, puis sa taille, sa longueur et ses options (finition, entretien). Si la cliente vient de la grille de tarifs, cette étape est déjà remplie.

Étape 2 : Choisir une date.

Étape 3 : Consulter les horaires disponibles.

Étape 4 : Choisir un créneau.

Étape 5 : Renseigner ses informations.

Étape 6 : Confirmer la réservation.

### Informations client

Collecter :

- Nom.
- Prénom.
- Téléphone.
- Adresse e-mail.
- Prestation choisie, avec sa taille, sa longueur et ses options.
- Prix estimé (ou mention « sur devis »).
- Date.
- Heure.
- Commentaire facultatif.

### Prestations « sur devis »

Quand le prix d'une combinaison est « sur devis », la réservation est enregistrée en statut **En attente**. La coiffeuse confirme ensuite le prix et le créneau. La cliente voit clairement que le prix sera confirmé plus tard.

### Règles et conditions

Les règles affichées avant la confirmation sont configurables depuis l'administration. Valeurs par défaut reprises de la référence :

- Venir avec les cheveux propres et démêlés.
- Annulation au moins 48 h avant le rendez-vous. Le délai est un paramètre et sert aussi à bloquer l'annulation en ligne une fois dépassé.
- Mèches fournies sur demande, avec supplément.
- Une finition au choix : perles +10 € (à apporter par la cliente), nœuds +10 €, ou boucles offertes.
- Longueurs Classique et Mi-cuisse : prix sur devis.

La cliente doit cocher qu'elle a lu ces règles avant de confirmer.

### Canal de secours WhatsApp

Si la réservation en ligne est désactivée, ou pour toute question, chaque bouton « Réserver » peut ouvrir WhatsApp avec un message pré-rempli contenant la prestation, la taille, la longueur et les options (lien `https://wa.me/<numéro>?text=...`). Ce mode s'active depuis les paramètres.

### Gestion des disponibilités

Le système doit automatiquement :

- Afficher les créneaux disponibles.
- Empêcher les doubles réservations.
- Respecter la durée des prestations.
- Prendre en compte les horaires de travail.
- Prendre en compte les jours de fermeture.
- Gérer les pauses.
- Gérer les congés.
- Gérer les réservations déjà enregistrées.

### Statuts de réservation

Prévoir les statuts suivants :

- En attente.
- Confirmée.
- En cours.
- Terminée.
- Annulée.
- Refusée.
- Absence du client.

### Notifications

Prévoir un système de notifications permettant d'informer le client :

- Lors de la création d'une réservation.
- Lors de la confirmation.
- Lors d'une modification.
- Lors d'une annulation.
- Avant le rendez-vous.

Prévoir des notifications par e-mail et une possibilité d'intégration WhatsApp.

---

## 6. Espace personnel du client

Créer un espace client permettant de :

- Créer un compte.
- Se connecter.
- Modifier ses informations.
- Consulter ses réservations.
- Annuler une réservation selon les conditions définies.
- Modifier un rendez-vous.
- Consulter son historique.
- Ajouter des prestations en favoris.
- Consulter ses factures ou reçus si cette fonctionnalité est activée.

Prévoir également la possibilité de réserver sans créer de compte.

---

## 7. Galerie et portfolio

Créer un portfolio professionnel.

Les réalisations doivent être organisées par catégories.

Fonctionnalités :

- Galerie d'images.
- Filtres par catégorie.
- Recherche.
- Affichage en grille.
- Affichage en mosaïque.
- Visualisation en plein écran.
- Descriptions.
- Tags.
- Mise en avant de certaines réalisations.

L'administrateur doit pouvoir gérer facilement toutes les images depuis son interface.

---

## 8. Blog et conseils capillaires

Créer une section éditoriale permettant de publier des articles.

Exemples :

- Comment entretenir ses tresses ?
- Les meilleures coiffures pour cheveux naturels.
- Comment protéger ses cheveux pendant la nuit ?
- Les tendances coiffure de la saison.
- Conseils pour préparer ses cheveux avant une prestation.

Chaque article doit posséder :

- Un titre.
- Une image de couverture.
- Un contenu riche.
- Une catégorie.
- Des tags.
- Une date de publication.
- Un statut brouillon ou publié.
- Une URL personnalisée.

Prévoir un éditeur de contenu riche permettant d'intégrer des images, des titres, des listes, des liens et des vidéos.

---

## 9. Pages complémentaires

Créer les pages suivantes :

- À propos.
- Nos prestations.
- Galerie.
- Blog.
- Contact.
- FAQ.
- Conditions générales.
- Politique de confidentialité.
- Politique d'annulation.
- Mentions légales.

Toutes ces pages doivent être administrables.

---

## 10. Contact et communication

Créer une page de contact comprenant :

- Un formulaire de contact.
- Le numéro de téléphone.
- L'adresse e-mail.
- L'adresse physique si applicable.
- Les horaires.
- Une carte géographique si une adresse est renseignée.
- Les réseaux sociaux.
- Un bouton WhatsApp.

Hiérarchie des boutons, reprise de la référence :

- **WhatsApp** en bouton principal (plein), avec un message d'accueil pré-rempli.
- **SMS** (lien `sms:`), **Instagram**, **Facebook** et **TikTok** en boutons secondaires (contour).
- Une phrase d'invitation, par exemple : « Écrivez-moi avec la coiffure, la taille et la longueur souhaitées. Je vous réponds avec les disponibilités. »

Si la coiffeuse travaille à domicile, l'adresse exacte et la carte ne sont pas affichées publiquement : seule une zone approximative est indiquée (exemple : « à 7 min à pied de la gare de … »).

Les informations de contact doivent être modifiables depuis l'administration.

---

# PARTIE 2 — ESPACE ADMINISTRATEUR

## 1. Authentification et sécurité

Créer un espace d'administration sécurisé accessible depuis une URL dédiée.

Exemple :

/admin

Prévoir :

- Connexion.
- Déconnexion.
- Mot de passe sécurisé.
- Réinitialisation du mot de passe.
- Protection contre les tentatives répétées.
- Sessions sécurisées.
- Gestion des rôles.
- Journalisation des actions importantes.

Les mots de passe doivent être stockés avec les fonctions de hachage sécurisées de PHP.

Ne jamais stocker les mots de passe en clair.

---

## 2. Tableau de bord administrateur

Créer un dashboard moderne affichant :

- Nombre total de réservations.
- Réservations du jour.
- Réservations en attente.
- Réservations confirmées.
- Chiffre d'affaires estimé.
- Prestations les plus populaires.
- Nombre de clients.
- Nouveaux messages.
- Statistiques de fréquentation si disponibles.

Prévoir des graphiques interactifs.

Les données doivent être récupérées dynamiquement depuis PostgreSQL.

---

## 3. Gestion des réservations

Créer une interface de gestion complète des rendez-vous.

Fonctionnalités :

- Affichage en liste.
- Affichage en calendrier.
- Filtrage par date.
- Filtrage par prestation.
- Filtrage par statut.
- Recherche par client.
- Confirmation.
- Annulation.
- Modification.
- Ajout manuel d'un rendez-vous.
- Gestion des absences.
- Export des réservations.

Le calendrier doit permettre de visualiser les journées, semaines et mois.

Prévoir une interface simple et rapide à utiliser.

---

## 4. Gestion des prestations

L'administrateur doit pouvoir :

- Ajouter une prestation.
- Modifier une prestation.
- Supprimer une prestation.
- Activer ou désactiver une prestation.
- Modifier le prix.
- Modifier la durée.
- Ajouter des images.
- Associer une catégorie.
- Définir des prestations complémentaires.
- Définir les conditions de réservation.
- Choisir le type de tarification (matriciel taille × longueur ou liste simple).
- Gérer les listes de tailles et de longueurs.
- Remplir la grille de prix dans un tableau éditable (prix, « sur devis » ou non proposé pour chaque cellule).
- Associer une photo et une note à chaque variante de taille.
- Gérer les options : finitions, entretien et suppléments.
- Définir une durée par variante ou par longueur, pour que le moteur de réservation bloque le bon créneau.

Créer une interface de gestion intuitive.

---

## 5. Gestion des catégories

Permettre de :

- Créer des catégories.
- Modifier les catégories.
- Supprimer les catégories.
- Ajouter une image.
- Modifier l'ordre d'affichage.
- Activer ou désactiver une catégorie.

---

## 6. Gestion du contenu du site

Créer un véritable CMS intégré.

L'administrateur doit pouvoir modifier les contenus sans toucher au code.

### Gestion des pages

Permettre de :

- Créer des pages.
- Modifier des pages.
- Supprimer des pages.
- Modifier les titres.
- Modifier les textes.
- Modifier les images.
- Modifier les URLs.
- Publier ou dépublier.

### Gestion des sections

Chaque page doit être composée de sections dynamiques.

Exemples :

- Hero.
- Présentation.
- Galerie.
- Services.
- Témoignages.
- FAQ.
- Appel à l'action.
- Contact.

L'administrateur doit pouvoir :

- Ajouter une section.
- Supprimer une section.
- Modifier son contenu.
- Changer son type.
- Réorganiser les sections par glisser-déposer.
- Modifier son apparence.
- Choisir sa visibilité.

---

## 7. Constructeur visuel de site

Créer un système de personnalisation avancé.

L'administrateur doit pouvoir personnaliser le site à travers une interface visuelle.

### Personnalisation générale

Permettre de modifier :

- Le logo.
- Le favicon.
- Les couleurs.
- Les polices.
- Les tailles de titres.
- Les boutons.
- Les bordures.
- Les espacements.
- Les arrière-plans.
- Les animations.
- Les styles de cartes.

### Personnalisation des templates

L'administrateur doit pouvoir :

- Sélectionner un template.
- Prévisualiser le template.
- Modifier les couleurs du template.
- Modifier les images.
- Modifier les sections.
- Choisir la disposition des éléments.
- Publier le résultat.

### Prévisualisation

Créer un mode prévisualisation permettant de voir les modifications avant leur publication.

Prévoir deux modes :

- Prévisualisation ordinateur.
- Prévisualisation mobile.

Les modifications doivent être enregistrées en brouillon avant publication.

---

## 8. Gestion des médias

Créer une bibliothèque de médias.

Fonctionnalités :

- Importation d'images.
- Importation de vidéos.
- Prévisualisation.
- Suppression.
- Renommage.
- Organisation par dossiers ou catégories.
- Recherche.
- Gestion des formats.
- Compression et optimisation des images.

Prévoir des contrôles sur les types de fichiers et leur taille.

---

## 9. Gestion des clients

Créer un module de gestion des clients.

L'administrateur doit pouvoir :

- Consulter les clients.
- Rechercher un client.
- Modifier ses informations.
- Consulter son historique de réservation.
- Ajouter des notes internes.
- Consulter les prestations déjà réalisées.

Respecter les règles de confidentialité et limiter l'accès aux données personnelles.

---

## 10. Gestion des témoignages

Créer un module de gestion des avis.

Fonctionnalités :

- Consulter les avis.
- Approuver les avis.
- Refuser les avis.
- Modifier leur visibilité.
- Mettre en avant certains témoignages.

Les avis doivent être modérés avant leur publication.

---

## 11. Gestion des promotions

Créer un module de promotions.

Permettre de :

- Créer une promotion.
- Définir un pourcentage de réduction.
- Définir un prix promotionnel.
- Définir une période.
- Associer une promotion à une prestation.
- Activer ou désactiver une promotion.

Les promotions expirées ne doivent plus être affichées automatiquement.

---

## 12. Gestion du blog

Créer un module de gestion des articles.

Fonctionnalités :

- Création d'articles.
- Modification.
- Suppression.
- Gestion des catégories.
- Gestion des tags.
- Gestion des images.
- Éditeur riche.
- Publication immédiate.
- Publication programmée si possible.
- Gestion des brouillons.

---

## 13. Gestion des horaires

Créer un module de configuration des horaires.

L'administrateur doit pouvoir définir :

- Les jours de travail.
- Les heures d'ouverture.
- Les heures de fermeture.
- Les pauses.
- Les jours de repos.
- Les congés.
- Les périodes exceptionnelles.
- Les créneaux indisponibles.

Ces paramètres doivent être utilisés directement par le moteur de réservation.

Exemple de configuration (format relevé sur la référence) :

- Jeudi à lundi : 10h – 19h.
- Mardi : 10h – 15h.
- Mercredi : fermé.

Les horaires affichés publiquement dans les infos pratiques sont générés à partir de ce module. Ils ne sont jamais saisis une seconde fois.

---

## 14. Configuration générale

Créer une page de paramètres regroupant :

- Nom de l'entreprise.
- Logo.
- Description.
- Adresse.
- Téléphone.
- E-mail.
- Réseaux sociaux.
- Devise.
- Fuseau horaire.
- Langue.
- Type de lieu (salon ou domicile) et zone affichée publiquement.
- Numéro WhatsApp (format international) et message pré-rempli.
- Réservation en ligne activée ou bascule vers WhatsApp.
- Délai minimum d'annulation (48 h par défaut).
- Paramètres de réservation.
- Paramètres de notification.
- Paramètres SEO.
- Paramètres de sécurité.

---

# PARTIE 3 — ARCHITECTURE TECHNIQUE

## 1. Technologies obligatoires

Le projet doit utiliser :

- PHP 8.2 ou supérieur.
- PostgreSQL.
- HTML5.
- CSS3.
- JavaScript moderne.
- Bootstrap 5 ou Tailwind CSS.
- AJAX ou Fetch API.
- PDO pour l'accès à PostgreSQL.

Utiliser Composer pour gérer les dépendances PHP.

Prévoir une architecture MVC propre.

Ne pas créer un projet constitué d'un seul fichier PHP contenant toute la logique.

---

## 2. Architecture MVC

Organiser le projet de manière professionnelle.

Structure indicative :

app/
    Controllers/
    Models/
    Services/
    Repositories/
    Middleware/
    Validators/
    Helpers/
    Views/

config/
    database.php
    app.php
    mail.php

public/
    index.php
    assets/
        css/
        js/
        images/
        uploads/

routes/
    web.php
    admin.php
    api.php

storage/
    logs/
    cache/

database/
    migrations/
    seeders/

templates/
    elegance-premium/
    beauty-chic/
    modern-hair-studio/
    afro-natural/
    minimal-professional/

resources/
    views/
    components/

vendor/

Prévoir également un fichier .env pour les paramètres sensibles.

---

## 3. Base de données PostgreSQL

Concevoir une base de données relationnelle cohérente.

Prévoir au minimum les tables suivantes :

- users
- roles
- permissions
- user_roles
- services
- service_categories
- service_images
- service_sizes (tailles : S, M, L, XL…)
- service_lengths (longueurs ordonnées : Nuque → Mi-cuisse)
- service_variants (variante d'une prestation : taille, photo, note, alerte)
- service_prices (prix par variante × longueur : montant, sur devis, prix de départ)
- service_options (finitions, entretien, suppléments)
- service_option_links (options proposées par prestation ou catégorie)
- bookings
- booking_options (options choisies pour une réservation)
- booking_rules (règles « à savoir avant de venir »)
- booking_statuses
- customers
- customer_notes
- reviews
- promotions
- promotion_services
- pages
- page_sections
- templates
- template_settings
- site_settings
- media
- blog_posts
- blog_categories
- blog_tags
- blog_post_tags
- opening_hours
- blocked_dates
- blocked_slots
- notifications
- contact_messages
- audit_logs

Prévoir les relations, clés étrangères, contraintes et index nécessaires.

Utiliser des migrations versionnées.

Ne pas créer de colonnes inutiles ou redondantes.

Les réservations doivent être protégées contre les conflits de créneaux, y compris lorsque plusieurs clients réservent simultanément.

Utiliser les mécanismes de transactions PostgreSQL et des contraintes adaptées pour garantir la cohérence.

---

## 4. Sécurité

Mettre en place :

- Protection contre les injections SQL.
- Requêtes préparées PDO.
- Protection CSRF.
- Échappement des sorties HTML.
- Validation côté serveur.
- Validation côté client.
- Protection contre les attaques XSS.
- Gestion sécurisée des sessions.
- Contrôle des permissions.
- Limitation des tentatives de connexion.
- Contrôle des uploads.
- Protection des routes administratives.
- Journalisation des erreurs.

Ne jamais faire confiance uniquement aux validations JavaScript.

---

## 5. SEO et performances

Prévoir :

- URLs propres.
- Métadonnées dynamiques.
- Titres SEO.
- Descriptions SEO.
- Open Graph.
- Sitemap XML.
- Robots.txt.
- Images optimisées.
- Lazy loading.
- Cache lorsque pertinent.
- Chargement rapide.
- Structure HTML sémantique.

Le site doit être optimisé pour les moteurs de recherche et les appareils mobiles.

---

# PARTIE 4 — EXIGENCES DE QUALITÉ

Le projet doit respecter les principes suivants :

1. Le code doit être propre, structuré et maintenable.
2. Les fonctionnalités doivent être réellement opérationnelles.
3. Les données doivent provenir de PostgreSQL.
4. Le contenu du site doit être dynamique.
5. Le système de templates doit être réellement fonctionnel.
6. Le changement de template ne doit pas supprimer les contenus.
7. L'administration doit être accessible aux utilisateurs non techniques.
8. L'interface doit être moderne et professionnelle.
9. Les formulaires doivent être validés côté serveur.
10. Le site doit être responsive.
11. Les erreurs doivent être gérées proprement.
12. Le projet doit pouvoir être installé sur un serveur PHP classique compatible PostgreSQL.

Ne pas produire uniquement des maquettes HTML.

Ne pas créer de boutons sans fonctionnalité.

Ne pas utiliser de données statiques pour simuler les fonctionnalités principales.

Ne pas laisser les fonctionnalités importantes sous forme de TODO.

---

# PARTIE 5 — LIVRABLES ATTENDUS

Je veux que tu réalises le projet progressivement, avec une approche professionnelle.

Fournis :

1. Une analyse fonctionnelle complète.
2. Une architecture technique détaillée.
3. Le schéma de la base PostgreSQL.
4. Les migrations SQL ou PHP.
5. La structure complète du projet.
6. Le système d'authentification.
7. L'espace client.
8. L'espace administrateur.
9. Le système de réservation.
10. Le système de templates.
11. Le CMS dynamique.
12. Le système de gestion des médias.
13. Les pages publiques.
14. Les scripts JavaScript nécessaires.
15. Les fichiers CSS.
16. Les données initiales de démonstration : catalogue de tresses avec grilles taille × longueur, rubriques à prix simple, options (finitions, entretien), horaires et règles, sur le modèle du site de référence.
17. Un fichier .env.example.
18. Un README.md avec les instructions d'installation.
19. Les instructions de déploiement.
20. Les tests des fonctionnalités principales.

---

# MÉTHODE DE TRAVAIL

Avant de générer le code :

1. Analyse le projet dans son ensemble.
2. Définis les modules et leurs dépendances.
3. Propose l'architecture technique.
4. Définis le schéma PostgreSQL.
5. Définis la structure des templates.
6. Définis le système de personnalisation.
7. Présente un plan de développement par étapes.

Ensuite, développe le projet module par module.

Pour chaque module :

- Explique brièvement son rôle.
- Présente les fichiers concernés.
- Génère le code complet.
- Explique les dépendances.
- Indique les commandes d'installation.
- Vérifie la cohérence avec les modules précédents.

Ne réécris pas inutilement les fichiers déjà générés.

Maintiens une cohérence stricte entre les routes, les contrôleurs, les modèles, les vues et la base de données.

Si la réponse devient trop longue, arrête-toi à la fin d'un module cohérent et indique précisément le prochain fichier ou module à développer.

Le résultat final doit être une plateforme professionnelle, élégante, riche en contenu, dynamique, personnalisable et prête à évoluer pour **Hair_style by Garance**.

L'expérience client doit être agréable et intuitive.

L'expérience administrateur doit être puissante, simple et complète.

Le design doit donner immédiatement une impression de qualité, de confiance et de professionnalisme.