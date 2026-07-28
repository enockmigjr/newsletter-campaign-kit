# Guide d'administration Newsletter Campaign Kit

Ce guide décrit les écrans du plugin, leur rôle et le flux recommandé. Une campagne envoyée est volontairement immuable : pour la renvoyer ou la modifier, créez un nouveau brouillon depuis la campagne d'origine.

## Flux recommandé

1. Configurez le fournisseur dans **Newsletter > Settings** et envoyez un message de test.
2. Créez les listes, tags et thèmes utiles dans **Lists & segments**.
3. Collectez les abonnements publics ou importez un CSV avec une preuve de consentement.
4. Créez un template et, si nécessaire, des blocs réutilisables.
5. Rédigez une campagne, choisissez son audience et son thème.
6. Passez le brouillon à l'état `ready`, contrôlez la page de revue, puis envoyez ou programmez.
7. Suivez l'exécution dans **Queue**, les résultats dans **Reports** et les opérations sensibles dans **Audit**.

## Subscribers

Répertoire des contacts et de leur état de consentement.

- `pending` : attend la confirmation double opt-in.
- `subscribed` : peut recevoir un message si l'audience et le thème correspondent.
- `unsubscribed` : s'est désabonné.
- `suppressed` : bloqué durablement après plainte, bounce ou action administrative.

Les filtres, détails et exports servent à contrôler le consentement. Une suppression durable n'est jamais levée automatiquement par un réimport.

## Lists & segments

### Listes

Audience éditoriale explicite et stable. Un contact peut appartenir à plusieurs listes. Les listes peuvent être créées, modifiées, archivées, restaurées et supprimées uniquement lorsqu'elles ne sont plus référencées.

### Tags

Marqueurs internes pour organiser les contacts et construire des segments. Ils ne sont pas présentés comme préférences publiques. L'affectation groupée permet de sélectionner plusieurs abonnés et plusieurs listes/tags dans une seule opération.

### Segments dynamiques

Audience calculée à partir de règles : listes, tags, source d'acquisition et date d'inscription. Le mode `all` exige toutes les règles, le mode `any` au moins une. Le compteur d'audience est recalculé; il ne s'agit pas d'une copie figée avant la revue d'envoi.

### Thèmes

Préférences éditoriales visibles par l'abonné à l'inscription et dans son centre de préférences. Lorsqu'une campagne possède un thème, le plugin intersecte :

1. les contacts consentis;
2. la liste ou le segment choisi;
3. les abonnés ayant accepté ce thème;
4. les suppressions et désabonnements vérifiés juste avant le fournisseur.

Sans thème, tous les contacts consentis de l'audience choisie sont éligibles. Archiver un thème empêche son utilisation future sans modifier l'historique.

## Import CSV

L'import associe les colonnes du fichier aux champs email, statut, listes, tags et consentement. Utilisez d'abord **Preview only**, puis **Apply valid rows** après contrôle. Les doublons, audiences inconnues et réactivations sans preuve explicite sont refusés.

## Templates

Structure complète d'un email : sujet par défaut, préheader, version visuelle WYSIWYG et version texte. Un template peut être prévisualisé, dupliqué, archivé et restauré. Une campagne hérite du template puis peut surcharger ses valeurs.

## Blocks

Fragments éditoriaux réutilisables insérés dans une campagne : signature, appel à l'action, coordonnées ou contenu récurrent. L'éditeur visuel insère le bloc à la position du curseur. Les blocs ont leur propre cycle de modification, duplication et archivage.

## Campaigns

Le cycle principal est `draft` → `ready` → `scheduled` ou `sending` → `sent`.

- Un brouillon est modifiable.
- La revue finale recalcule les destinataires et exige la saisie exacte du titre.
- La confirmation fige un snapshot de l'audience et une empreinte de la version.
- Une campagne envoyée garde son contenu, son audience et ses métriques intacts.
- **Create new send** duplique une campagne terminale vers un nouveau brouillon modifiable.
- **Statistics** ouvre les rapports filtrés sur la campagne.

## Queue

Une ligne représente la livraison d'une campagne à un abonné.

- `pending` : attend son passage; **Retry now** avance sa prochaine tentative.
- `processing` : verrouillée par un worker.
- `sent` : acceptée par le transport, pas nécessairement remise en boîte.
- `failed` : cinq tentatives ont échoué; corrigez le fournisseur puis créez une nouvelle campagne si nécessaire.
- `paused` : suspendue avec la campagne.
- `cancelled` : annulée ou devenue inéligible.

Les détails exposent l'identifiant, la dernière mise à jour et l'erreur sans afficher l'adresse dans les journaux. Les actions unitaires ne réécrivent jamais une livraison déjà envoyée.

## Reports

Le bandeau global présente le volume, la santé de l'acquisition et l'engagement. Les filtres ne modifient que le tableau détaillé des campagnes afin de conserver des totaux globaux comparables.

- `Delivery rate` : messages acceptés par le transport / éléments en file.
- `Open rate` : ouvreurs uniques non automatisés / messages envoyés.
- `Click rate` : cliqueurs uniques / messages envoyés.
- `Click-to-open` : cliqueurs uniques / ouvreurs uniques.
- `Conversion` : action attribuée au dernier clic signé dans la fenêtre de 30 jours.

Les ouvertures peuvent être bloquées ou préchargées par les clients mail. Les bounces et plaintes ne sont attribués à une campagne que si le fournisseur fournit une preuve exploitable.

## Audit

Journal paginé des opérations sensibles : consentement, changements de statut, imports/exports, audiences, campagnes, file, fournisseur et erreurs. Les détails facilitent le diagnostic sans conserver les secrets du fournisseur.

## Settings

Choix du transport (`wp_mail`, Brevo, Resend, HTTP générique ou adaptateur externe), expéditeur, double opt-in, one-click unsubscribe, taille des lots, rétention et santé du scheduler. Les clés restent dans `wp-config.php` ou dans les variables d'environnement.

Resend est adapté à la configuration actuelle. Le test de fournisseur valide l'authentification et le rendu sans créer de campagne ni d'abonné.

