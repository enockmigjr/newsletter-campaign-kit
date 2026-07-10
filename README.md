# Newsletter Campaign Kit

Newsletter Campaign Kit est un plugin WordPress reutilisable pour les abonnements consentis, la desinscription tokenisee et la fondation des futures campagnes editoriales.

## Responsabilites

- Capturer les abonnements newsletter avec nonce et consentement.
- Stocker l'email, un email hash, un token de desinscription, source, consentement, IP hash et user-agent tronque.
- Permettre la desinscription publique par token sans exposer l'email dans l'URL.
- Fournir une premiere UI admin pour consulter, filtrer, changer le statut et exporter les abonnes.
- Creer des listes et tags de segmentation avec liaisons abonnes/listes/tags.
- Creer des brouillons de campagnes avec sujet, contenu, cible editoriale et transitions serveur.
- Journaliser les evenements sensibles newsletter: inscription, desinscription, statut, export, listes, tags et campagnes.

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
- `{$wpdb->prefix}newsletter_campaign_audit`
- `{$wpdb->prefix}newsletter_campaign_campaigns`

## Options

- `newsletter_campaign_kit_version`

## Actions admin-post

- `admin_post_nopriv_newsletter_campaign_kit_subscribe`
- `admin_post_newsletter_campaign_kit_subscribe`
- `admin_post_nopriv_newsletter_campaign_kit_unsubscribe`
- `admin_post_newsletter_campaign_kit_unsubscribe`
- `admin_post_newsletter_campaign_kit_update_subscriber_status`
- `admin_post_newsletter_campaign_kit_export_subscribers`
- `admin_post_newsletter_campaign_kit_create_list`
- `admin_post_newsletter_campaign_kit_create_tag`
- `admin_post_newsletter_campaign_kit_create_campaign`
- `admin_post_newsletter_campaign_kit_transition_campaign`

## Verification minimale

1. Activer le plugin et verifier la table abonnes.
2. Tester inscription publique avec nonce et consentement.
3. Tester refus si nonce, email ou consentement manque.
4. Tester unsubscribe avec token valide, token invalide et second clic.
5. Tester que l'export CSV exige `newsletter_view_reports`.
6. Tester que le changement de statut exige la capability newsletter_manage_subscribers.
7. Verifier que la page Audit exige la capability newsletter_view_reports et ne stocke pas IP brute, token ou email dans le contexte.
8. Verifier que les campagnes exigent newsletter_create_campaigns et que les transitions d'envoi exigent newsletter_send_campaigns.

## Reste majeur

- Imports/exports avances de listes, tags et segments.
- Templates reutilisables avances et previsualisation email.
- Queue d'envoi batch avec retry/backoff.
- Provider abstraction SMTP/API.
- Reporting campagne.
