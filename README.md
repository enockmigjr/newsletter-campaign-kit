# Newsletter Campaign Kit

Newsletter Campaign Kit est un plugin WordPress reutilisable pour les abonnements consentis, la desinscription tokenisee et la fondation des futures campagnes editoriales.

## Responsabilites

- Capturer les abonnements newsletter avec nonce et consentement.
- Stocker l'email, un email hash, un token de desinscription, source, consentement, IP hash et user-agent tronque.
- Permettre la desinscription publique par token opaque sans exposer l'email dans l'URL.
- Supporter le one-click unsubscribe RFC 8058 par POST idempotent et en-tetes `List-Unsubscribe`.
- Bloquer la reactivation publique des contacts explicitement `suppressed` et verifier leur statut avant chaque envoi.
- Fournir une premiere UI admin pour consulter, filtrer, changer le statut et exporter les abonnes.
- Creer des listes et tags de segmentation avec liaisons abonnes/listes/tags.
- Affecter ou retirer des abonnes aux listes et tags depuis l'administration.
- Construire des segments dynamiques `all`/`any` selon listes, tags, source et date d'inscription.
- Classer les campagnes avec des thematiques reutilisables.
- Permettre a chaque abonne de choisir ses thematiques dans un centre public protege par token et nonce.
- Exclure les opt-out thematiques et suppressions a la resolution d'audience puis juste avant le provider.
- Conserver une suppression durable par HMAC apres suppression ou re-import du contact, avec levee admin explicite sans reabonnement automatique.
- Creer des brouillons de campagnes avec sujet, contenu, cible editoriale et transitions serveur.
- Creer, modifier, dupliquer, archiver et restaurer des templates editoriaux reutilisables.
- Heriter d'un template dans une campagne tout en autorisant des surcharges explicites.
- Previsualiser les versions HTML et texte dans une page admin isolee par capability, nonce et CSP.
- Envoyer des emails `multipart/alternative` avec `AltBody` texte via le hook PHPMailer borne a l'appel `wp_mail`.
- Executer une queue batch avec verrou atomique, reprise des verrous expires et retry/backoff.
- Programmer les campagnes dans le fuseau WordPress et les declencher chaque minute via WP-Cron.
- Finaliser automatiquement les campagnes lorsque leur file ne contient plus de travail actif.
- Configurer un provider `wp_mail` ou un adaptateur externe via filtre WordPress.
- Afficher un reporting de livraison par campagne depuis la queue.
- Journaliser les evenements sensibles newsletter: inscription, desinscription, statut, export, listes, tags et campagnes.
- Integrer les exports, effacements et le guide de confidentialite natifs de WordPress.

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
- `{$wpdb->prefix}newsletter_campaign_queue`

## Options

- `newsletter_campaign_kit_version`
- `newsletter_campaign_kit_provider_settings`

Les reglages provider contiennent aussi les drapeaux `one_click_enabled` et `dkim_confirmed`. Les en-tetes RFC 8058 ne sont emis que lorsque les deux sont actifs et que l'URL publique est en HTTPS. La signature DKIM doit couvrir `List-Unsubscribe` et `List-Unsubscribe-Post`; le plugin exige une confirmation explicite car `wp_mail()` ne permet pas de prouver cette couverture avant remise au transport.

## Actions admin-post

- `admin_post_nopriv_newsletter_campaign_kit_subscribe`
- `admin_post_newsletter_campaign_kit_subscribe`
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
- `admin_post_newsletter_campaign_kit_create_list`
- `admin_post_newsletter_campaign_kit_create_tag`
- `admin_post_newsletter_campaign_kit_create_segment`
- `admin_post_newsletter_campaign_kit_create_topic`
- `admin_post_newsletter_campaign_kit_update_assignment`
- `admin_post_newsletter_campaign_kit_create_campaign`
- `admin_post_newsletter_campaign_kit_save_template`
- `admin_post_newsletter_campaign_kit_template_action`
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

## Hooks publics

- `newsletter_campaign_kit_consent_text`: personnalise le texte de consentement du projet integrateur.
- `newsletter_campaign_kit_suppression_reasons`: etend les motifs acceptes par les providers de bounce/complaint.
- `newsletter_campaign_kit_send_email`: branche un provider externe sans stocker ses secrets dans le plugin.
- `wp_privacy_personal_data_exporters` et `wp_privacy_personal_data_erasers`: exportent ou effacent les donnees identifiantes de l'abonne.

La suppression Privacy conserve seulement le HMAC d'une adresse lorsqu'une suppression active doit continuer a bloquer les remises. Le registre ne contient pas l'adresse brute. Sa levee place un contact encore present en statut `unsubscribed`; elle ne constitue jamais un consentement.

## Reste majeur

- Edition, duplication, archivage et preview du nombre de destinataires pour les segments.
- Imports/exports avances de listes, tags et segments.
- Edition et duplication completes des campagnes apres creation.
- Provider API externe avance avec secrets hors Git.
- Provider abstraction SMTP/API.
- Webhooks signes pour automatiser bounces et complaints vers le registre de suppression.
- Tracking ouvertures/clics et exports de reporting avances.

## References officielles

- [WordPress Plugin Handbook - Cron](https://developer.wordpress.org/plugins/cron/)
- [WordPress Code Reference - wp_schedule_event](https://developer.wordpress.org/reference/functions/wp_schedule_event/)
- [WordPress Code Reference - wp_clear_scheduled_hook](https://developer.wordpress.org/reference/functions/wp_clear_scheduled_hook/)
- [WordPress Code Reference - wp_mail](https://developer.wordpress.org/reference/functions/wp_mail/)
- [WordPress Plugin Handbook - Privacy](https://developer.wordpress.org/plugins/privacy/)
- [WordPress Personal Data Eraser](https://developer.wordpress.org/plugins/privacy/adding-the-personal-data-eraser-to-your-plugin/)
- [WordPress Nonces](https://developer.wordpress.org/apis/security/nonces/)
- [RFC 8058 - Signaling One-Click Functionality for List Email Headers](https://www.rfc-editor.org/rfc/rfc8058.html)
