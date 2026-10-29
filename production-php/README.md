# Relais e-mail PHP — optionnel

Ce dossier n'est **pas utilisé** par le formulaire : les 4 pages fonctionnent
en JavaScript pur (`provider: 'emailjs'`, repli `formsubmit`), sans serveur.

Les adresses y sont déjà renseignées : `ifeminformatique@gmail.com` (expéditeur
des trois e-mails et adresse de réponse de la confirmation) et
`manajahelvin@gmail.com` (responsable destinataire).

Il est conservé uniquement pour le cas où un envoi transactionnel côté serveur
deviendrait nécessaire (fonction `mail()`, SMTP, `wp_mail()`, journalisation,
purge RGPD).

## Activation

1. Copier `lead-form-mail.php` et `lead-form-config.php` à la racine du site.
2. Renseigner les adresses dans `lead-form-config.php`, puis `enabled => true`.
3. Dans la page, en tête du script du formulaire :

   ```js
   window.HL_LEAD_CONFIG = { provider: 'endpoint', endpoint: '/lead-form-mail.php', /* … */ };
   ```

## Vérifications

```bash
php tests/lead-form-mail.test.php        # 109 assertions, aucun réseau
php lead-form-mail.php --doctor          # configuration
php lead-form-mail.php --purge=180       # purge RGPD (cron)
```

Documentation détaillée : `../FORMULAIRE-LEAD.md`, § 8.
