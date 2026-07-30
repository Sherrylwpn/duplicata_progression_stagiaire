<?php
/**
 * config.php
 * Fichier central : à inclure (require_once) dans chaque page qui a besoin
 * de la base de données, de la session, ou de la protection CSRF.
 */

// ── Session sécurisée ──
// On démarre la session ici, une seule fois, pour toute l'application.
if (session_status() === PHP_SESSION_NONE) {
    session_start([
        'cookie_httponly' => true, // le cookie de session n'est pas accessible en JS
        'cookie_samesite' => 'Lax',
    ]);
}

// ── Paramètres de connexion à la base de données ──
define('DB_HOST', 'localhost');
define('DB_PORT', '3307');
define('DB_NAME', 'staginf');
define('DB_USER', 'root');
define('DB_PASS', ''); // vide par défaut sous XAMPP

/**
 * Retourne une connexion PDO unique (réutilisée si déjà ouverte).
 */
function getDB(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        try {
            $pdo = new PDO(
                "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4",
                DB_USER,
                DB_PASS
            );
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            die("Erreur de connexion à la base de données : " . $e->getMessage());
        }
    }

    return $pdo;
}

// ── Protection CSRF ──

/**
 * Génère (si besoin) et retourne le jeton CSRF de la session en cours.
 * À placer dans un champ hidden de chaque formulaire POST.
 */
function csrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Vérifie le jeton CSRF envoyé en POST. Arrête l'exécution s'il est invalide.
 */
function verifyCsrf(): void
{
    $tokenEnvoye = $_POST['csrf_token'] ?? '';
    $tokenAttendu = $_SESSION['csrf_token'] ?? '';

    if ($tokenAttendu === '' || !hash_equals($tokenAttendu, $tokenEnvoye)) {
        die("Erreur de sécurité : jeton CSRF invalide. Merci de recharger la page et réessayer.");
    }
}

// ── Contrôle d'accès ──

/**
 * Indique si un utilisateur est actuellement connecté.
 */
function isLoggedIn(): bool
{
    return !empty($_SESSION['user_id']);
}

// ── Expiration de session (correction 3.14) ──
//
// logged_at était enregistré mais jamais relu : une session restait valable
// indéfiniment tant que le cookie existait. On introduit une expiration par
// INACTIVITÉ, contrôlée à chaque requête dans requireAuth().
define('SESSION_INACTIVITE_SECONDES', 1800); // 30 minutes sans requête → déconnexion

/**
 * Bloque l'accès à la page si l'utilisateur n'est pas connecté, si son compte
 * a été désactivé entre-temps, ou si sa session a expiré par inactivité.
 * À placer tout en haut des pages réservées (ajout / modification / suppression).
 */
function requireAuth(): void
{
    if (!isLoggedIn()) {
        header("Location: login.php");
        exit;
    }

    // Compte désactivé après la connexion (cf. correction 3.13) : on ne fait
    // confiance qu'à la valeur mémorisée en session au login ; un compte
    // réactivé nécessitera une nouvelle connexion pour le voir refléter ici,
    // ce qui est un compromis acceptable pour une application de ce volume.
    if (empty($_SESSION['actif'])) {
        session_unset();
        session_destroy();
        header("Location: login.php?desactive=1");
        exit;
    }

    $derniereActivite = $_SESSION['last_activity'] ?? null;
    if ($derniereActivite !== null && (time() - $derniereActivite) > SESSION_INACTIVITE_SECONDES) {
        session_unset();
        session_destroy();
        header("Location: login.php?expired=1");
        exit;
    }

    // Session valide : on horodate cette requête comme la dernière activité.
    $_SESSION['last_activity'] = time();
}

/**
 * Bloque l'accès à la page si l'utilisateur connecté n'a pas le rôle requis
 * (par ex. 'admin'). À appeler APRÈS requireAuth(). Prévu pour les futurs
 * écrans d'administration (gestion des comptes, catalogues) évoqués en 3.15 ;
 * aucune route n'utilise encore cette fonction dans la version actuelle.
 */
function requireRole(string $role): void
{
    if (($_SESSION['role'] ?? null) !== $role) {
        http_response_code(403);
        exit("Accès refusé : cette page nécessite le rôle « {$role} ».");
    }
}

// ── Préférence d'affichage (mode sombre) ──

/**
 * Retourne la classe CSS à appliquer sur <body> selon la préférence
 * de l'utilisateur connecté (mémorisée en session après le login).
 */
function bodyClass(): string
{
    return !empty($_SESSION['mode_sombre']) ? 'dark-mode' : '';
}

// ── Journal des modifications ──

/**
 * Enregistre une action (création / modification / suppression) effectuée
 * par l'utilisateur actuellement connecté sur une fiche de stage.
 *
 * IMPORTANT (cf. rapport d'audit, point 3.5) : cette fonction doit être appelée
 * AVANT le commit() de la transaction métier, avec la même connexion $pdo
 * (getDB() retourne toujours la même instance), afin que la modification et sa
 * trace dans le journal réussissent ou échouent ensemble. Ne jamais l'appeler
 * après un commit() déjà effectué.
 */
function logAction(string $action, ?int $idStage, string $nomStagiaire, string $details = ''): void
{
    $pdo = getDB();
    $stmt = $pdo->prepare(
        "INSERT INTO journal_modifications (id_user, nom_user, action, id_stage, nom_stagiaire, details)
         VALUES (:id_user, :nom_user, :action, :id_stage, :nom_stagiaire, :details)"
    );
    $stmt->execute([
        ':id_user'       => $_SESSION['user_id'] ?? null,
        ':nom_user'      => $_SESSION['user_nom'] ?? 'Inconnu',
        ':action'        => $action,
        ':id_stage'      => $idStage,
        ':nom_stagiaire' => $nomStagiaire,
        ':details'       => $details !== '' ? $details : null,
    ]);
}

// ── Changement de mot de passe (logique partagée) ──

/**
 * Valide puis applique un changement de mot de passe pour un utilisateur donné.
 * Centralise les règles utilisées à la fois par l'ancienne page parametres.php
 * et par l'endpoint AJAX securite_compte.php, pour n'avoir qu'un seul endroit
 * à corriger si une règle change.
 *
 * @return array{succes: bool, message: string}
 */
function changerMotDePasse(int $userId, string $ancien, string $nouveau, string $confirme): array
{
    $pdo  = getDB();
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $utilisateur = $stmt->fetch();

    if (!$utilisateur || !password_verify($ancien, $utilisateur['mot_de_passe'])) {
        return ['succes' => false, 'message' => "L'ancien mot de passe est incorrect."];
    }
    if (mb_strlen($nouveau) < 8) {
        return ['succes' => false, 'message' => "Le nouveau mot de passe doit contenir au moins 8 caractères."];
    }
    if ($nouveau !== $confirme) {
        return ['succes' => false, 'message' => "La confirmation ne correspond pas au nouveau mot de passe."];
    }
    if ($nouveau === $ancien) {
        return ['succes' => false, 'message' => "Le nouveau mot de passe doit être différent de l'ancien."];
    }

    $hash = password_hash($nouveau, PASSWORD_DEFAULT);
    $pdo->prepare("UPDATE users SET mot_de_passe = ? WHERE id = ?")->execute([$hash, $userId]);

    return ['succes' => true, 'message' => 'Mot de passe mis à jour avec succès.'];
}

/**
 * Réinitialise le mot de passe d'un AUTRE compte administrateur, sans exiger
 * l'ancien mot de passe : c'est précisément le scénario visé (un des deux
 * admins a oublié le sien). L'appelant doit avoir déjà vérifié que
 * l'utilisateur courant a bien le rôle 'admin' avant d'appeler cette fonction.
 *
 * Restrictions volontaires pour ne pas devenir un contournement générique du
 * changement de mot de passe normal :
 * - impossible de cibler son propre compte (utiliser changerMotDePasse() à la place) ;
 * - la cible doit elle-même avoir le rôle 'admin'.
 *
 * @return array{succes: bool, message: string}
 */
function reinitialiserMotDePasseAdmin(int $idAdminCourant, int $idCible, string $nouveau, string $confirme): array
{
    if ($idCible === $idAdminCourant) {
        return ['succes' => false, 'message' => "Utilisez le champ « Mon mot de passe » pour votre propre compte."];
    }

    $pdo  = getDB();
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$idCible]);
    $cible = $stmt->fetch();

    if (!$cible) {
        return ['succes' => false, 'message' => "Compte introuvable."];
    }
    if (($cible['role'] ?? '') !== 'admin') {
        return ['succes' => false, 'message' => "Seul le mot de passe d'un autre compte administrateur peut être réinitialisé de cette façon."];
    }
    if (mb_strlen($nouveau) < 8) {
        return ['succes' => false, 'message' => "Le nouveau mot de passe doit contenir au moins 8 caractères."];
    }
    if ($nouveau !== $confirme) {
        return ['succes' => false, 'message' => "La confirmation ne correspond pas au nouveau mot de passe."];
    }

    $hash = password_hash($nouveau, PASSWORD_DEFAULT);
    $pdo->prepare("UPDATE users SET mot_de_passe = ? WHERE id = ?")->execute([$hash, $idCible]);

    return ['succes' => true, 'message' => "Mot de passe de « {$cible['nom']} » réinitialisé avec succès."];
}

// ── Protection anti brute-force (stockée en base, indépendante de la session) ──
//
// Auparavant, le compteur d'échecs vivait dans $_SESSION : il suffisait de
// supprimer ses cookies (ou d'ouvrir une navigation privée) pour obtenir 5
// nouvelles tentatives. Le compteur est maintenant en base, rattaché à la
// fois au nom de compte tenté ET à l'adresse IP, ce qui rend le verrouillage
// effectif même en changeant de session.

define('LOGIN_MAX_TENTATIVES', 5);
define('LOGIN_VERROU_SECONDES', 20);

/**
 * Adresse IP du visiteur. Ne fait volontairement pas confiance aux en-têtes
 * X-Forwarded-For (falsifiables par le client) : à n'activer que si
 * l'application est placée derrière un proxy de confiance qui les positionne
 * lui-même.
 */
function getClientIp(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

/**
 * Enregistre une tentative de connexion échouée pour ce nom de compte / IP.
 */
function enregistrerEchecConnexion(string $identifiant, string $adresseIp): void
{
    $pdo = getDB();
    // Correction : l'horodatage est désormais posé explicitement avec
    // l'horloge PHP (date('Y-m-d H:i:s')) plutôt que de laisser MySQL le
    // remplir tout seul (DEFAULT CURRENT_TIMESTAMP). Si le fuseau horaire de
    // PHP (date.timezone dans php.ini) et celui du serveur MySQL diffèrent,
    // ce qui est un cas fréquent sous XAMPP, la comparaison entre l'heure
    // stockée et time() dans estVerrouille()/secondesAvantDeverrouillage()
    // devient fausse — un verrou de 5 ou 10 secondes pouvait ainsi se
    // traduire, en pratique, par plusieurs milliers de secondes d'attente.
    // En écrivant nous-mêmes l'heure avec la même horloge que celle utilisée
    // pour la relire, ce décalage disparaît complètement.
    $pdo->prepare(
        "INSERT INTO login_attempts (identifiant, adresse_ip, date_tentative) VALUES (:identifiant, :ip, :date_tentative)"
    )->execute([
        ':identifiant'     => $identifiant,
        ':ip'              => $adresseIp,
        ':date_tentative'  => date('Y-m-d H:i:s'),
    ]);

    // Ménage occasionnel des anciennes tentatives (plus d'un jour) pour éviter
    // que la table ne grossisse indéfiniment. Peu coûteux vu le volume attendu.
    $pdo->prepare("DELETE FROM login_attempts WHERE date_tentative < :seuil")
        ->execute([':seuil' => date('Y-m-d H:i:s', time() - 86400)]);
}

/**
 * Supprime les tentatives échouées après une connexion réussie.
 */
function viderEchecsConnexion(string $identifiant, string $adresseIp): void
{
    $pdo = getDB();
    $pdo->prepare("DELETE FROM login_attempts WHERE identifiant = :identifiant OR adresse_ip = :ip")
        ->execute([':identifiant' => $identifiant, ':ip' => $adresseIp]);
}

/**
 * Indique si ce nom de compte OU cette adresse IP a atteint la limite
 * d'échecs de connexion sur la fenêtre de verrouillage.
 */
function estVerrouille(string $identifiant, string $adresseIp): bool
{
    $pdo    = getDB();
    $depuis = date('Y-m-d H:i:s', time() - LOGIN_VERROU_SECONDES);
    $stmt   = $pdo->prepare(
        "SELECT COUNT(*) FROM login_attempts
         WHERE (identifiant = :identifiant OR adresse_ip = :ip)
           AND date_tentative >= :depuis"
    );
    $stmt->execute([':identifiant' => $identifiant, ':ip' => $adresseIp, ':depuis' => $depuis]);

    return (int) $stmt->fetchColumn() >= LOGIN_MAX_TENTATIVES;
}

/**
 * Nombre de secondes restantes avant déverrouillage (0 si déjà déverrouillé).
 */
function secondesAvantDeverrouillage(string $identifiant, string $adresseIp): int
{
    $pdo  = getDB();
    $stmt = $pdo->prepare(
        "SELECT date_tentative FROM login_attempts
         WHERE identifiant = :identifiant OR adresse_ip = :ip
         ORDER BY date_tentative DESC
         LIMIT 1 OFFSET :offset"
    );
    $stmt->bindValue(':identifiant', $identifiant);
    $stmt->bindValue(':ip', $adresseIp);
    $stmt->bindValue(':offset', LOGIN_MAX_TENTATIVES - 1, PDO::PARAM_INT);
    $stmt->execute();
    $ligne = $stmt->fetch();

    if (!$ligne) {
        return 0;
    }

    // Correction : utilise la constante LOGIN_VERROU_SECONDES plutôt qu'un
    // nombre codé en dur, pour n'avoir qu'un seul endroit à modifier si la
    // durée du verrou change à nouveau.
    $finVerrou = strtotime($ligne['date_tentative']) + LOGIN_VERROU_SECONDES;
    return max(0, $finVerrou - time());
}

// ── Évolution des compétences / badges / notation (graphiques) ──

/**
 * Calcule, pour un stage donné, l'évolution de chaque compétence technique,
 * humaine, badge et de la note globale au fil des séances d'évaluation
 * successives — mais ne conserve QUE les items dont le niveau a réellement
 * varié entre au moins deux séances (une compétence toujours notée pareil
 * n'apporte rien à représenter sous forme de courbe).
 *
 * Factorisée ici car utilisée à la fois par evolution.php (page dédiée) et par
 * formulaire_stagiaires.php (graphiques inclus dans la fiche imprimable), pour
 * éviter que les deux implémentations ne divergent au fil des correctifs.
 *
 * @return array{
 *   labels: string[],
 *   technique: array<int, array{nom: string, valeurs: array<int, int|null>}>,
 *   humaine: array<int, array{nom: string, valeurs: array<int, int|null>}>,
 *   badge: array<int, array{nom: string, valeurs: array<int, int|null>}>,
 *   note: array<int, float|null>
 * }
 */
function calculerEvolutionStage(PDO $pdo, int $idStage, array $nomsTech, array $nomsHumaine, array $nomsBadge): array
{
    $stmtSeances = $pdo->prepare(
        "SELECT id_evaluation, date_evaluation, note FROM evaluation WHERE id_stage = ? ORDER BY date_evaluation ASC, id_evaluation ASC"
    );
    $stmtSeances->execute([$idStage]);
    $seances    = $stmtSeances->fetchAll();
    $idsSeances = array_column($seances, 'id_evaluation');
    $labels = array_map(
        fn($s) => $s['date_evaluation'] ? date('d/m/Y', strtotime($s['date_evaluation'])) : '—',
        $seances
    );

    /**
     * Construit, pour une table de niveaux donnée, la liste des items dont le
     * niveau a varié entre au moins deux séances, chacun avec sa série de
     * valeurs alignée sur $idsSeances (null = non noté lors de cette
     * séance-là, pour que la courbe laisse un trou plutôt que de redescendre
     * artificiellement à zéro).
     */
    $construireGroupe = function (string $table, string $colonneId, array $noms) use ($pdo, $idsSeances): array {
        if (empty($idsSeances)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($idsSeances), '?'));
        $stmt = $pdo->prepare("SELECT id_evaluation, {$colonneId} AS id_item, niveau FROM {$table} WHERE id_evaluation IN ({$placeholders})");
        $stmt->execute($idsSeances);

        $parItem = [];
        foreach ($stmt->fetchAll() as $ligne) {
            $parItem[(int) $ligne['id_item']][(int) $ligne['id_evaluation']] = (int) $ligne['niveau'];
        }

        $resultat = [];
        foreach ($parItem as $idItem => $valeursParSeance) {
            $valeursUniques = array_unique(array_values($valeursParSeance));
            if (count($valeursParSeance) < 2 || count($valeursUniques) < 2) {
                continue; // pas assez de points, ou toujours la même note : on n'affiche pas
            }
            $serie = [];
            foreach ($idsSeances as $idSeance) {
                $serie[] = $valeursParSeance[$idSeance] ?? null;
            }
            $resultat[] = ['nom' => $noms[$idItem] ?? ('Compétence #' . $idItem), 'valeurs' => $serie];
        }
        return $resultat;
    };

    $technique = $construireGroupe('evaluation_competence_technique', 'id_competence_technique', $nomsTech);
    $humaine   = $construireGroupe('evaluation_competence_humaine', 'id_competence_humaine', $nomsHumaine);
    $badge     = $construireGroupe('evaluation_badge', 'id_badge', $nomsBadge);

    // Note globale : même principe, on ne la trace que si elle a varié.
    $valeursNote  = array_column($seances, 'note');
    $notesConnues = array_filter($valeursNote, fn($v) => $v !== null);
    $note = (count(array_unique($notesConnues)) >= 2)
        ? array_map(fn($v) => $v !== null ? (float) $v : null, $valeursNote)
        : [];

    return [
        'labels'    => $labels,
        'technique' => $technique,
        'humaine'   => $humaine,
        'badge'     => $badge,
        'note'      => $note,
    ];
}

// ── Archivage des stages ──
//
// Un stage archivé n'apparaît plus dans la liste principale (index.php) mais
// reste consultable/désarchivable depuis archive.php. Deux façons d'y arriver :
// automatique (toute année de stage déjà révolue), ou manuelle (bouton
// "Archiver" sur la fiche, quelle que soit l'année).
//
// ⚠️ Nécessite deux colonnes sur la table `stage` (absentes par défaut) :
//     ALTER TABLE stage ADD COLUMN archive TINYINT(1) NOT NULL DEFAULT 0;
//     ALTER TABLE stage ADD COLUMN archive_manuel TINYINT(1) NOT NULL DEFAULT 0;
// À exécuter une seule fois (phpMyAdmin, ou tout client SQL) avant la première
// utilisation de ces fonctions.
//
// `archive_manuel` sert à empêcher un piège sinon inévitable : sans lui, un
// stage d'une année révolue qu'on désarchive à la main se ferait ré-archiver
// tout seul dès le prochain chargement de page (le balayage automatique ne
// fait aucune différence entre "jamais archivé" et "désarchivé exprès"). Ce
// drapeau, posé à chaque action manuelle (archiver OU désarchiver), indique
// "un humain a déjà décidé pour ce stage" : le balayage automatique ignore
// alors ces lignes et n'agit plus que sur les stages jamais touchés.

/**
 * Archive automatiquement tous les stages dont l'année de la date de début est
 * déjà révolue (strictement antérieure à l'année en cours), qui ne sont pas
 * déjà archivés, ET que personne n'a désarchivés manuellement entre-temps
 * (cf. `archive_manuel` ci-dessus). Ex. : en 2027, un stage débuté en 2025 ou
 * 2026 est archivé ; un stage débuté en 2027 (l'année en cours) ne l'est pas
 * encore.
 *
 * Appelée à chaque chargement de index.php et archive.php : une simple requête
 * UPDATE, assez légère pour ne pas nécessiter de tâche planifiée (cron).
 */
function archiverAnneesRevolues(PDO $pdo): void
{
    $anneeCourante = (int) date('Y');
    $pdo->prepare(
        "UPDATE stage SET archive = 1
         WHERE archive = 0 AND archive_manuel = 0 AND YEAR(date_debut) < :annee"
    )->execute([':annee' => $anneeCourante]);
}

/**
 * Archive manuellement un stage précis, quelle que soit son année (bouton
 * "Archiver" de la fiche détaillée).
 */
function archiverStage(PDO $pdo, int $idStage): void
{
    $pdo->prepare("UPDATE stage SET archive = 1, archive_manuel = 1 WHERE id_stage = ?")->execute([$idStage]);
}

/**
 * Retire un stage de l'archive (bouton "Désarchiver" de archive.php) : il
 * réapparaît dans la liste principale de index.php. Marqué `archive_manuel`
 * pour ne pas se faire ré-archiver tout seul au prochain balayage automatique
 * si son année est déjà révolue (cf. explication en tête de section).
 */
function desarchiverStage(PDO $pdo, int $idStage): void
{
    $pdo->prepare("UPDATE stage SET archive = 0, archive_manuel = 1 WHERE id_stage = ?")->execute([$idStage]);
}