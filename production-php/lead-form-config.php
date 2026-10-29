<?php
/**
 * Configuration de l'envoi des formulaires de lead — HelloLead / Hellodevis.
 *
 * À FAIRE AVANT MISE EN PRODUCTION
 *   1. Vérifier l'adresse d'expédition (`from`) : elle doit appartenir à un domaine
 *      autorisé à envoyer (SPF/DKIM configurés, sinon les e-mails partent en spam).
 *   2. Passer `enabled` à true.
 *
 * Les destinataires sont déjà renseignés :
 *   responsable : cremy@hellolead.pro
 *   chef de département (Cc) : pbenateau@hellolead.pro
 *   adresse de réponse de la confirmation : pbenateau@hellolead.pro
 *
 * Pour expédier depuis le domaine hellolead.pro, utiliser le transport 'smtp'
 * ci-dessous avec les identifiants de la boîte (SPF/DKIM du domaine requis).
 *
 * Tant que `enabled` est à false, l'endpoint répond 503 « not_configured »
 * et le formulaire affiche un message d'indisponibilité avec une adresse de repli.
 *
 * @package HelloLead
 */

return array(

    /* ------------------------------------------------------------------
     * Interrupteur général
     * --------------------------------------------------------------- */
    'enabled' => false,

    /* ------------------------------------------------------------------
     * Transport d'envoi
     *   'mail'    => fonction mail() de PHP (par défaut, simple et suffisant
     *                sur la plupart des hébergements mutualisés)
     *   'wp_mail' => WordPress / PHPMailer (recommandé si le site tourne sous
     *                WordPress ou si un plugin SMTP est installé)
     *   'smtp'    => client SMTP natif (voir bloc « smtp » plus bas)
     *   'log'     => n'envoie rien, écrit les .eml dans logs/mail (recette)
     * --------------------------------------------------------------- */
    'transport' => 'mail',

    'timezone' => 'Europe/Paris',

    /* ------------------------------------------------------------------
     * Expéditeur et réponse
     * --------------------------------------------------------------- */
    'from' => array(
        'email' => 'pbenateau@hellolead.pro', // boîte réelle du domaine
        'name'  => 'HelloLead',
    ),

    // Adresse affichée dans l'e-mail de confirmation (« répondez-nous ici »).
    'reply_to' => array(
        'email' => 'pbenateau@hellolead.pro', // adresse de réponse de la confirmation
        'name'  => 'HelloLead',
    ),

    'site_url' => 'https://www.hellodevis.fr',

    /* ------------------------------------------------------------------
     * Destinataires par formulaire
     *   responsable : destinataire principal (To)
     *   chef        : chef de département, en copie (Cc)
     *   besoins     : liste blanche des besoins acceptés pour ce formulaire
     * --------------------------------------------------------------- */
    'forms' => array(

        'lead-assurances' => array(
            'label'       => 'Assurances pro',
            'responsable' => array('cremy@hellolead.pro'),
            'chef'        => array('pbenateau@hellolead.pro'),
            'besoins'     => array(
                'Assurances professionnelles I.A.R.D',
                'Assurances protection sociale Individuelle',
                'Assurances Protection sociale Collective',
                'Gestion de Patrimoine',
                "Autres types d'assurances B2B",
                "Autres types d'assurances B2C",
            ),
        ),

        'lead-assurances-rcs' => array(
            'label'       => 'Assurances pro (RCS)',
            'responsable' => array('cremy@hellolead.pro'),
            'chef'        => array('pbenateau@hellolead.pro'),
            'besoins'     => array(
                'Assurances professionnelles I.A.R.D',
                'Assurances protection sociale Individuelle',
                'Assurances Protection sociale Collective',
                'Gestion de Patrimoine',
                "Autres types d'assurances B2B",
                "Autres types d'assurances B2C",
            ),
        ),

        'lead-energies' => array(
            'label'       => 'Énergies pro',
            'responsable' => array('cremy@hellolead.pro'),   
            'chef'        => array('pbenateau@hellolead.pro'),
            'besoins'     => array(
                "Fourniture d'électricité",
                'Fourniture de gaz',
                'Contrats multi-sites / multi-compteurs',
                'Renouvellement de contrat',
                'Audit & optimisation énergétique',
                'Autres besoins énergies B2B',
                'Autres besoins énergies B2C',
            ),
        ),

        'lead-energies-rcs' => array(
            'label'       => 'Énergies pro (RCS)',
            'responsable' => array('cremy@hellolead.pro'),   
            'chef'        => array('pbenateau@hellolead.pro'),
            'besoins'     => array(
                "Fourniture d'électricité",
                'Fourniture de gaz',
                'Contrats multi-sites / multi-compteurs',
                'Renouvellement de contrat',
                'Audit & optimisation énergétique',
                'Autres besoins énergies B2B',
                'Autres besoins énergies B2C',
            ),
        ),

        // Repli si le formulaire envoyé n'est pas connu.
        'default' => array(
            'label'       => 'Contact',
            'responsable' => array('cremy@hellolead.pro'),                
            'chef'        => array(),
        ),
    ),

    /* ------------------------------------------------------------------
     * SMTP (uniquement si transport = 'smtp')
     * --------------------------------------------------------------- */
    'smtp' => array(
        'host'       => '',            // ex. smtp.hellolead.fr
        'port'       => 587,
        'encryption' => 'tls',         // tls | ssl | none
        'username'   => '',
        'password'   => '',
        'timeout'    => 10,
    ),

    // Charge wp-load.php pour utiliser wp_mail() (transport = 'wp_mail').
    'wp_load_path' => '',

    /* ------------------------------------------------------------------
     * Anti-spam
     * --------------------------------------------------------------- */
    'anti_spam' => array(
        'min_fill_seconds' => 1.5, // un formulaire rempli en moins de 1,5 s = robot
        'rate_window'      => 900, // fenêtre de limitation (secondes)
        'rate_max'         => 5,   // envois max par IP et par fenêtre
        'rate_day_max'     => 20,  // envois max par IP et par jour
        'dedupe_seconds'   => 600, // ignore un renvoi du même lead_id pendant 10 min
    ),

    /* ------------------------------------------------------------------
     * Journalisation (traçabilité RGPD + diagnostic des envois)
     * --------------------------------------------------------------- */
    'log_enabled' => true,
    'log_dir'     => __DIR__ . '/logs',
);
