# Newsletter Campaign Kit

## Fournisseurs d'envoi

- Brevo recommande: `NEWSLETTER_CAMPAIGN_KIT_BREVO_API_KEY`.
- Resend alternatif: `NEWSLETTER_CAMPAIGN_KIT_RESEND_API_KEY`.
- `wp_mail`: compatible avec le relais SMTP WordPress et l'environnement local.
- API HTTP generique et filtre externe: conserves pour les integrations sur mesure.

Les adaptateurs natifs utilisent des endpoints HTTPS fixes, une cle d'idempotence stable par livraison et des secrets injectes cote serveur, jamais enregistres dans WordPress.

Newsletter Campaign Kit est un plugin WordPress reutilisable pour les abonnements consentis, la desinscription tokenisee et la fondation des futures campagnes editoriales.

## Responsabilites

- Capturer les abonnements newsletter avec nonce et consentement.
- Exiger par defaut un double opt-in public: statut pending, lien HMAC expirable, cooldown de renvoi et activation atomique single-use.
- Limiter independamment les tentatives par empreinte reseau et par adresse, avec reponse publique neutre pour les contacts connus, pending ou suppressed.
- Stocker l'email, un email hash, un token de desinscription, source, consentement, IP hash et user-agent tronque.
- Permettre la desinscription publique par token opaque sans exposer l'email dans l'URL.
- Supporter le one-click unsubscribe RFC 8058 par POST idempotent et en-tetes `List-Unsubscribe`.
- Bloquer la reactivation publique des contacts explicitement `suppressed` et verifier leur statut avant chaque envoi.
- Fournir une premiere UI admin pour consulter, filtrer, changer le statut et exporter les abonnes.
- Paginer les abonnes et le journal cote SQL, avec tableaux responsives, filtres persistants et details d'audit nettoyes.
- Exporter sans troncature les abonnes par lots, ainsi que les listes, tags, segments, thematiques et rapports de campagne, avec neutralisation des formules CSV.
- Importer des abonnes par CSV avec mapping d'en-tetes, preview non mutative, rapport temporaire et application transactionnelle par ligne.
- Refuser les doublons du fichier, audiences inconnues, suppressions actives et reactivations sans option et consentement explicites.
- Creer des listes et tags de segmentation avec liaisons abonnes/listes/tags.
- Affecter ou retirer des abonnes aux listes et tags depuis l'administration.
- Construire des segments dynamiques `all`/`any` selon listes, tags, source et date d'inscription.
- Classer les campagnes avec des thematiques reutilisables.
- Permettre a chaque abonne de choisir ses thematiques dans un centre public protege par token et nonce.
- Exclure les opt-out thematiques et suppressions a la resolution d'audience puis juste avant le provider.
- Conserver une suppression durable par HMAC apres suppression ou re-import du contact, avec levee admin explicite sans reabonnement automatique.
- Creer des brouillons de campagnes avec sujet, contenu, cible editoriale et transitions serveur.
- Imposer une revue finale avant envoi ou programmation avec audience estimee, saisie exacte du titre, nonce et empreinte HMAC de la campagne et des destinataires.
- Rejeter sans effet de bord une confirmation devenue obsolete, puis figer l'audience dans la meme transaction que l'envoi immediat ou la programmation.
- Modifier uniquement les brouillons et dupliquer toute campagne vers un nouveau brouillon sans etat de livraison ni file d'envoi.
- Modifier, dupliquer, archiver et restaurer les segments avec estimation exacte de leur audience et verrou d'archivage lorsqu'une campagne non terminale les utilise.
- Creer, modifier, dupliquer, archiver et restaurer des templates editoriaux reutilisables.
- Installer une bibliotheque de modeles de depart sans ecraser les personnalisations administrateur.
- Encadrer chaque campagne dans un document email responsive avec preheader, identite du site et lien de preferences.
- Creer, modifier, dupliquer, archiver et restaurer des blocs editoriaux categorises, puis les inserer a la position du curseur dans les versions HTML et texte d'une campagne.
- Heriter d'un template dans une campagne tout en autorisant des surcharges explicites.
- Previsualiser les versions HTML et texte dans une page admin isolee par capability, nonce et CSP.
- Envoyer des emails `multipart/alternative` avec `AltBody` texte via le hook PHPMailer borne a l'appel `wp_mail`.
- Executer une queue batch avec verrou atomique, reprise des verrous expires et retry/backoff.
- Configurer la taille de batch, convertir les exceptions provider en retries et empecher le chevauchement du scheduler par verrou DB expirable.
- Programmer les campagnes dans le fuseau WordPress et les declencher chaque minute via WP-Cron.
- Conserver un heartbeat sans donnees personnelles et signaler les etats healthy, pending, late, failed ou unscheduled dans l'administration.
- Supprimer transactionnellement, par lots bornes, les contacts pending dont l'expiration depasse la retention configuree.
- Finaliser automatiquement les campagnes lorsque leur file ne contient plus de travail actif.
- Configurer `wp_mail`, le provider JSON HTTP generique ou un adaptateur externe via filtre WordPress.
- Envoyer au provider HTTP avec HTTPS obligatoire, Bearer secret cote serveur, corps HTML/texte et cle d'idempotence stable.
- Recevoir les bounces et complaints via un webhook REST signe HMAC, borne a cinq minutes et protege contre le rejeu.
- Afficher un reporting de livraison par campagne depuis la queue.
- Capturer une fois l'audience au premier envoi avec regles, libelles et IDs internes des destinataires, puis reutiliser ce snapshot immutable aux relances; apres effacement, l'ID devient une cle opaque propre au snapshot.
- Creer snapshot, membres et queue dans la meme transaction pour l'envoi immediat; pour une programmation, figer snapshot et membres a la confirmation puis creer la queue depuis cet instantane au declenchement WP-Cron.
- Journaliser les evenements sensibles newsletter: inscription, desinscription, statut, export, listes, tags et campagnes.
- Integrer les exports, effacements et le guide de confidentialite natifs de WordPress.
- Exposer aux integrations serveur l'abonnement correspondant a l'e-mail du compte, sans endpoint public de recherche.

## Capabilities

- `newsletter_manage_subscribers`
- `newsletter_manage_lists`
- `newsletter_create_campaigns`
- `newsletter_send_campaigns`
- `newsletter_view_reports`
- `newsletter_manage_settings`

Les capabilities sont ajoutees aux administrateurs a l'activation/upgrade.

## Tables

- `{$wpdb->prefix}newsletter_campaign_subscribers`
- `{$wpdb->prefix}newsletter_campaign_lists`
- `{$wpdb->prefix}newsletter_campaign_tags`
- `{$wpdb->prefix}newsletter_campaign_subscriber_lists`
- `{$wpdb->prefix}newsletter_campaign_subscriber_tags`
- `{$wpdb->prefix}newsletter_campaign_segments`
- `{$wpdb->prefix}newsletter_campaign_topics`
- `{$wpdb->prefix}newsletter_campaign_subscriber_topics`
- `{$wpdb->prefix}newsletter_campaign_suppressions`
- `{$wpdb->prefix}newsletter_campaign_audit`
- `{$wpdb->prefix}newsletter_campaign_campaigns`
- `{$wpdb->prefix}newsletter_campaign_templates`
- `{$wpdb->prefix}newsletter_campaign_blocks`
- `{$wpdb->prefix}newsletter_campaign_queue`
- `{$wpdb->prefix}newsletter_campaign_audience_snapshots`
- `{$wpdb->prefix}newsletter_campaign_audience_snapshot_members`
- `{$wpdb->prefix}newsletter_campaign_provider_events`

## Options

- `newsletter_campaign_kit_version`
- `newsletter_campaign_kit_provider_settings`
- `newsletter_campaign_kit_scheduler_state`
- `newsletter_campaign_kit_maintenance_state`

Les options d'etat scheduler/maintenance ne contiennent que dates, duree, statuts et compteurs agreges. Elles ne stockent ni email, ni token, ni contenu de campagne.

Les reglages provider contiennent aussi les drapeaux `one_click_enabled` et `dkim_confirmed`. Les en-tetes RFC 8058 ne sont emis que lorsque les deux sont actifs et que l'URL publique est en HTTPS. La signature DKIM doit couvrir `List-Unsubscribe` et `List-Unsubscribe-Post`; le plugin exige une confirmation explicite car `wp_mail()` ne permet pas de prouver cette couverture avant remise au transport.

Le provider HTTP lit `NEWSLETTER_CAMPAIGN_KIT_HTTP_ENDPOINT`, `NEWSLETTER_CAMPAIGN_KIT_HTTP_API_KEY`, `NEWSLETTER_CAMPAIGN_KIT_WEBHOOK_SECRET` et, facultativement, `NEWSLETTER_CAMPAIGN_KIT_HTTP_TIMEOUT`. Ces valeurs doivent etre injectees par `wp-config.php`, l'environnement ou le filtre `newsletter_campaign_kit_http_provider_config`; elles ne sont jamais stockees dans les options.

Les reglages publics bornent `double_opt_in_enabled`, la validite du lien (1-168 heures), le cooldown (1-1440 minutes), les tentatives (1-30) et leur fenetre (1-1440 minutes). Le token brut n'est present que dans l'email; la table abonnes conserve son HMAC, l'expiration, la date d'envoi et la date de confirmation.

Les reglages d'exploitation bornent le batch de queue (1-100), la retention des pending expires (1-90 jours) et le seuil de heartbeat tardif (2-60 minutes). Le nettoyage s'execute au plus une fois par heure et traite au maximum 200 contacts par passage.

Le endpoint `POST /wp-json/newsletter-campaign-kit/v1/provider-events` accepte un JSON `{ "id", "type", "email" }`, avec `type` egal a `bounce` ou `complaint`. Le provider signe exactement `timestamp.corps_brut` en HMAC-SHA256 dans `X-Newsletter-Signature` et fournit le timestamp Unix dans `X-Newsletter-Timestamp`.

## Actions admin-post

- `admin_post_nopriv_newsletter_campaign_kit_subscribe`
- `admin_post_newsletter_campaign_kit_subscribe`
- `admin_post_nopriv_newsletter_campaign_kit_confirm_subscription`
- `admin_post_newsletter_campaign_kit_confirm_subscription`
- `admin_post_nopriv_newsletter_campaign_kit_unsubscribe`
- `admin_post_newsletter_campaign_kit_unsubscribe`
- `admin_post_nopriv_newsletter_campaign_kit_preferences`
- `admin_post_newsletter_campaign_kit_preferences`
- `admin_post_nopriv_newsletter_campaign_kit_update_preferences`
- `admin_post_newsletter_campaign_kit_update_preferences`
- `admin_post_nopriv_newsletter_campaign_kit_confirm_unsubscribe`
- `admin_post_newsletter_campaign_kit_confirm_unsubscribe`
- `admin_post_newsletter_campaign_kit_update_subscriber_status`
- `admin_post_newsletter_campaign_kit_release_suppression`
- `admin_post_newsletter_campaign_kit_export_subscribers`
- `admin_post_newsletter_campaign_kit_operational_export`
- `admin_post_newsletter_campaign_kit_import_csv`
- `admin_post_newsletter_campaign_kit_create_list`
- `admin_post_newsletter_campaign_kit_create_tag`
- `admin_post_newsletter_campaign_kit_create_segment`
- `admin_post_newsletter_campaign_kit_update_segment`
- `admin_post_newsletter_campaign_kit_duplicate_segment`
- `admin_post_newsletter_campaign_kit_segment_status`
- `admin_post_newsletter_campaign_kit_create_topic`
- `admin_post_newsletter_campaign_kit_update_assignment`
- `admin_post_newsletter_campaign_kit_create_campaign`
- `admin_post_newsletter_campaign_kit_update_campaign`
- `admin_post_newsletter_campaign_kit_duplicate_campaign`
- `admin_post_newsletter_campaign_kit_save_template`
- `admin_post_newsletter_campaign_kit_template_action`
- `admin_post_newsletter_campaign_kit_save_block`
- `admin_post_newsletter_campaign_kit_block_action`
- `admin_post_newsletter_campaign_kit_preview`
- `admin_post_newsletter_campaign_kit_transition_campaign`
- `admin_post_newsletter_campaign_kit_schedule_campaign`
- `admin_post_newsletter_campaign_kit_process_queue`
- `admin_post_newsletter_campaign_kit_save_provider_settings`

## Verification minimale

1. Activer le plugin et verifier la table abonnes.
2. Tester inscription publique avec nonce et consentement.
3. Tester refus si nonce, email ou consentement manque.
4. Tester unsubscribe avec token valide, token invalide et second clic.
5. Tester que l'export CSV exige `newsletter_view_reports`.
6. Tester que le changement de statut exige la capability newsletter_manage_subscribers.
7. Verifier que la page Audit exige la capability newsletter_view_reports et ne stocke pas IP brute, token ou email dans le contexte.
8. Verifier que les campagnes exigent newsletter_create_campaigns et que les transitions d'envoi exigent newsletter_send_campaigns.
9. Verifier que la queue exige newsletter_send_campaigns et retente avec backoff lorsqu'aucun provider n'est branche.
10. Verifier que le provider wp_mail exige newsletter_manage_settings pour ses reglages et n'enregistre aucun secret.
11. Verifier que les reports exigent newsletter_view_reports et n'inventent pas ouvertures/clics sans tracking.
12. Verifier que le hook `newsletter_campaign_kit_run_scheduled` est unique, traite une campagne echue et ne cree pas deux lignes pour un meme couple campagne/abonne.
13. Executer `php tests/schedule-date.php` pour valider les dates impossibles, passees et futures.
14. Executer `php tests/segment-engine.php` et verifier les modes all/any, les dates persistantes et les placeholders SQL.
15. Verifier qu'une campagne cible exactement les abonnes du segment et qu'un second enqueue ne duplique aucune ligne.
16. Executer `php tests/unsubscribe.php` pour valider jetons opaques, rotation, corps POST et en-tetes RFC 8058.
17. Executer `wp eval-file tests/runtime-unsubscribe.php` dans WordPress pour verifier endpoint POST, idempotence, suppression avant envoi et remise `wp_mail`.
18. Inspecter un email reel chez le provider afin de confirmer HTTPS, les deux en-tetes et leur couverture par la signature DKIM.
19. Executer `wp eval-file tests/runtime-preferences.php` pour verifier GET non mutatif, CSRF, preferences thematiques, fail-closed provider, suppression durable et outils Privacy.
20. Executer `wp eval-file tests/runtime-templates.php` pour verifier migration, sanitization, cycle de vie, interface admin, heritage campagne et `AltBody` remis a PHPMailer.
21. Executer `wp eval-file tests/runtime-lifecycle.php` pour verifier edition/verrouillage/duplication des campagnes, lifecycle des segments, volumes d'audience et garde d'archivage.
22. Verifier dans `runtime-preferences.php` que la lecture interne d'un abonnement est bornee a un e-mail valide.
23. Executer `wp eval-file tests/runtime-import.php` pour verifier preview, mapping, doublons, suppressions, consentement, reactivation, affectations et transactions par ligne.
24. Executer `wp eval-file tests/runtime-audience-snapshots.php` pour verifier immutabilite, idempotence, rollback, minimisation, cron et reporting admin.
25. Executer `wp eval-file tests/runtime-http-provider.php` pour verifier transport 2xx, erreurs normalisees, configuration fail-closed, HMAC, expiration, rejeu et suppression automatique.
26. Executer `wp eval-file tests/runtime-double-opt-in.php` pour verifier pending, HMAC, email multipart, cooldown, confirmation single-use, expiration, suppression et rate limits.
27. Executer `wp eval-file tests/runtime-double-opt-in-http.php` pour verifier nonce, ecriture, reponse neutre, livraison Mailpit et activation par le vrai lien HTTP.
28. Executer `wp eval-file tests/runtime-scheduler-operations.php` pour verifier retention pending, verrous, batch configure, exceptions provider et cinq etats de sante cron.
29. Executer `wp eval-file tests/runtime-campaign-confirmation.php` pour verifier titre exact, preuve d'audience obsolete, atomicite de l'envoi, reprise apres pause, audience programmee figee et ecran de revue admin.
30. Executer `wp eval-file tests/runtime-editorial-blocks.php` puis `node tests/campaign-blocks.js` pour verifier migration, sanitization, lifecycle, capability et insertion HTML/texte au curseur.
31. Executer `wp eval-file tests/runtime-advanced-exports.php` pour verifier exports audiences/campagnes, pagination complete des abonnes, UTF-8, anti-formule CSV, capability et nonce.
32. Executer `wp eval-file tests/runtime-admin-pagination.php` pour verifier pagination et filtres des abonnes, suppressions, audits, campagnes, modeles, blocs et file de livraison.

## Hooks publics

- `newsletter_campaign_kit_consent_text`: personnalise le texte de consentement du projet integrateur.
- `newsletter_campaign_kit_suppression_reasons`: etend les motifs acceptes par les providers de bounce/complaint.
- `newsletter_campaign_kit_send_email`: branche un provider externe sans stocker ses secrets dans le plugin.
- `newsletter_campaign_kit_http_provider_config`: injecte endpoint, cle API, secret webhook et timeout depuis la configuration serveur.
- `newsletter_campaign_kit_block_categories`: etend les categories bornees de la bibliotheque de blocs.
- `newsletter_campaign_kit_export_row_limit`: borne entre 100 et 50 000 les datasets operationnels charges en memoire; l'export HTTP des abonnes utilise une pagination streaming independante.
- `wp_privacy_personal_data_exporters` et `wp_privacy_personal_data_erasers`: exportent ou effacent les donnees identifiantes de l'abonne.

La suppression Privacy conserve seulement le HMAC d'une adresse lorsqu'une suppression active doit continuer a bloquer les remises. Le registre ne contient pas l'adresse brute. Les preuves de provider conservent une cle d'evenement opaque et sont dissociees de l'abonne efface. La levee d'une suppression place un contact encore present en statut `unsubscribed`; elle ne constitue jamais un consentement.

## Reste majeur

- Export avance des listes, tags et segments (l'import CSV des abonnes et de leurs affectations est operationnel).
- Adaptateurs supplementaires (Mailgun, Postmark ou SES) uniquement si le fournisseur d'hebergement retenu l'exige; Brevo et Resend sont deja natifs.
- Validation en staging avec un domaine expediteur, DKIM et identifiants reels du fournisseur retenu.
- Alertes externes, metriques provider et supervision distribuee des confirmations/abus lorsque l'hebergeur final est connu.
- Tracking ouvertures/clics avec consentement et statistiques associees.

## References officielles

- [WordPress Plugin Handbook - Cron](https://developer.wordpress.org/plugins/cron/)
- [WordPress Code Reference - wp_next_scheduled](https://developer.wordpress.org/reference/functions/wp_next_scheduled/)
- [WordPress Code Reference - wp_schedule_event](https://developer.wordpress.org/reference/functions/wp_schedule_event/)
- [WordPress Code Reference - wp_clear_scheduled_hook](https://developer.wordpress.org/reference/functions/wp_clear_scheduled_hook/)
- [WordPress Code Reference - wp_mail](https://developer.wordpress.org/reference/functions/wp_mail/)
- [WordPress Code Reference - wp_safe_remote_post](https://developer.wordpress.org/reference/functions/wp_safe_remote_post/)
- [WordPress REST API - Adding custom endpoints](https://developer.wordpress.org/rest-api/extending-the-rest-api/adding-custom-endpoints/)
- [WordPress Plugin Handbook - Privacy](https://developer.wordpress.org/plugins/privacy/)
- [WordPress Personal Data Eraser](https://developer.wordpress.org/plugins/privacy/adding-the-personal-data-eraser-to-your-plugin/)
- [WordPress Nonces](https://developer.wordpress.org/apis/security/nonces/)
- [RFC 8058 - Signaling One-Click Functionality for List Email Headers](https://www.rfc-editor.org/rfc/rfc8058.html)
