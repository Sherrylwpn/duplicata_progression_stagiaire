<?php
require_once 'config.php';
requireAuth(); // Seuls les utilisateurs connectés peuvent accéder à ce formulaire

$erreur = '';
$succes = '';

// Mode édition si un id de STAGE est fourni (en GET pour l'affichage, en POST pour la soumission).
// Correction 3.4 : une fiche correspond désormais à un stage, pas directement à une personne
// (une personne peut avoir plusieurs stages).
$idEdition = filter_input(INPUT_POST, 'id_stage', FILTER_VALIDATE_INT)
    ?: filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

$pdo = getDB();

// ── Listes de référence chargées depuis la base (correction 3.9 : plus d'ENUM ni de tableaux PHP codés en dur) ──
$classesRef            = $pdo->query("SELECT id_classe, nom FROM classe_ref ORDER BY ordre, nom")->fetchAll();
$etablissementsRef     = $pdo->query("SELECT id_etablissement, nom FROM etablissement_ref ORDER BY nom")->fetchAll();
$competencesTechniques = $pdo->query("SELECT id_competence_technique, nom FROM competence_technique ORDER BY nom")->fetchAll();
$competencesHumaines   = $pdo->query("SELECT id_competence_humaine, nom FROM competence_humaine ORDER BY nom")->fetchAll();
$badges                = $pdo->query("SELECT id_badge, nom FROM badge ORDER BY nom")->fetchAll();

$nomsClasse        = array_column($classesRef, 'nom', 'id_classe');
$nomsEtablissement = array_column($etablissementsRef, 'nom', 'id_etablissement');
$nomsTech          = array_column($competencesTechniques, 'nom', 'id_competence_technique');
$nomsHumaine       = array_column($competencesHumaines, 'nom', 'id_competence_humaine');
$nomsBadge         = array_column($badges, 'nom', 'id_badge');

/**
 * Compare l'état "avant" et "après" d'un stage et retourne la liste des
 * changements sous forme de puces, pour alimenter le suivi des modifications
 * (journal_modifications.details).
 */
function construireDetailsModification(
    array $avant,
    array $general,
    array $notesTech,
    array $notesHumaine,
    array $notesBadge,
    ?float $note,
    array $nomsTech,
    array $nomsHumaine,
    array $nomsBadge,
    ?string $commentaireAvant = null,
    ?string $commentaireApres = null
): string {
    $puces = [];

    /**
     * Formate une valeur pour l'affichage dans le journal ("(vide)" plutôt
     * qu'une chaîne vide illisible).
     */
    $afficher = function ($valeur): string {
        $valeur = (string) ($valeur ?? '');
        return $valeur === '' ? '(vide)' : $valeur;
    };

    // ── Informations générales ──
    // Correction 3.16 : le journal indiquait auparavant seulement le NOM du champ
    // modifié ("Classe"), sans dire quelle était l'ancienne valeur ni la nouvelle.
    // On conserve désormais les deux, ce qui permet de reconstruire une évolution
    // ou de justifier une valeur précédente sans devoir recouper avec d'autres sources.
    $champsGeneraux = [
        'nom'           => 'Nom',
        'prenom'        => 'Prénom',
        'classe'        => 'Classe',
        'etablissement' => 'Établissement',
        'date_debut'    => 'Date de début',
        'date_fin'      => 'Date de fin',
    ];
    $champsModifies = [];
    foreach ($champsGeneraux as $champ => $label) {
        $ancienneValeur = $avant['general'][$champ] ?? null;
        $nouvelleValeur = $general[$champ] ?? null;
        if ((string) $ancienneValeur !== (string) $nouvelleValeur) {
            $champsModifies[] = $label . ' : ' . $afficher($ancienneValeur) . ' → ' . $afficher($nouvelleValeur);
        }
    }
    if (!empty($champsModifies)) {
        $puces[] = '-Informations générales : ' . implode(' ; ', $champsModifies);
    }

    /**
     * Compare un groupe d'évaluations (techniques, humaines ou badges) entre la
     * dernière séance enregistrée et la nouvelle saisie, et liste les éléments
     * modifiés avec leur niveau avant/après (correction 3.16).
     */
    $comparerGroupe = function (array $avantGroupe, array $apresGroupe, array $noms, int $max) {
        $modifies = [];
        $ids = array_unique(array_map('intval', array_merge(array_keys($avantGroupe), array_keys($apresGroupe))));
        foreach ($ids as $id) {
            $ancien = $avantGroupe[$id] ?? 0;
            $nouveau = isset($apresGroupe[$id]) ? (int) $apresGroupe[$id] : 0;
            $nouveau = ($nouveau >= 1 && $nouveau <= $max) ? $nouveau : 0;
            if ($ancien !== $nouveau) {
                $modifies[] = ($noms[$id] ?? ('#' . $id)) . ' : ' . $ancien . '→' . $nouveau;
            }
        }
        return $modifies;
    };

    $techModifiees = $comparerGroupe($avant['tech'], $notesTech, $nomsTech, 3);
    if (!empty($techModifiees)) {
        $puces[] = '-Compétence technique : ' . implode(', ', $techModifiees);
    }

    $humaineModifiees = $comparerGroupe($avant['humaine'], $notesHumaine, $nomsHumaine, 5);
    if (!empty($humaineModifiees)) {
        $puces[] = '-Compétence humaine : ' . implode(', ', $humaineModifiees);
    }

    $badgesModifies = $comparerGroupe($avant['badge'], $notesBadge, $nomsBadge, 3);
    if (!empty($badgesModifies)) {
        $puces[] = '-Badge : ' . implode(', ', $badgesModifies);
    }

    // ── Notation globale ──
    if ($avant['note'] !== $note) {
        $puces[] = '-Notation : ' . $afficher($avant['note']) . ' → ' . $afficher($note);
    }

    // ── Commentaire (correction 3.15 : le champ existait en base sans être
    // lu ni écrit nulle part ; il est désormais exposé dans le formulaire) ──
    if ((string) $commentaireAvant !== (string) $commentaireApres) {
        $puces[] = '-Commentaire modifié';
    }

    if (empty($puces)) {
        $puces[] = '-Nouvelle séance d\'évaluation (aucun changement de niveau détecté)';
    }

    return implode(', ', $puces);
}

/**
 * Charge l'état "avant" d'un stage (infos générales + niveaux de la dernière
 * évaluation) pour permettre la comparaison lors d'une modification.
 */
function chargerEtatStage(PDO $pdo, int $idStage, array $nomsClasse, array $nomsEtablissement): ?array
{
    $stmt = $pdo->prepare(
        "SELECT st.id_stage, st.id_classe, st.id_etablissement, st.date_debut, st.date_fin,
                s.id_stagiaire, s.nom, s.prenom
         FROM stage st JOIN stagiaire s ON s.id_stagiaire = st.id_stagiaire
         WHERE st.id_stage = ?"
    );
    $stmt->execute([$idStage]);
    $stage = $stmt->fetch();
    if (!$stage) {
        return null;
    }

    $general = [
        'nom'           => $stage['nom'],
        'prenom'        => $stage['prenom'],
        'classe'        => $nomsClasse[$stage['id_classe']] ?? '',
        'etablissement' => $nomsEtablissement[$stage['id_etablissement']] ?? '',
        'date_debut'    => $stage['date_debut'],
        'date_fin'      => $stage['date_fin'],
    ];

    $tech = $humaine = $badge = [];
    $note = null;
    $commentaire = null;

    // Correction 3.15 : `commentaire` existait en base (table evaluation) sans être
    // ni lu ni écrit nulle part dans l'application. On le charge désormais avec le
    // reste de l'état de la dernière évaluation.
    $stmt = $pdo->prepare("SELECT id_evaluation, note, commentaire FROM evaluation WHERE id_stage = ? ORDER BY date_evaluation DESC, id_evaluation DESC LIMIT 1");
    $stmt->execute([$idStage]);
    $derniere = $stmt->fetch();

    if ($derniere) {
        $note = $derniere['note'] !== null ? (float) $derniere['note'] : null;
        $commentaire = $derniere['commentaire'];
        $idEval = (int) $derniere['id_evaluation'];

        $stmt = $pdo->prepare("SELECT id_competence_technique, niveau FROM evaluation_competence_technique WHERE id_evaluation = ?");
        $stmt->execute([$idEval]);
        foreach ($stmt->fetchAll() as $row) {
            $tech[(int) $row['id_competence_technique']] = (int) $row['niveau'];
        }

        $stmt = $pdo->prepare("SELECT id_competence_humaine, niveau FROM evaluation_competence_humaine WHERE id_evaluation = ?");
        $stmt->execute([$idEval]);
        foreach ($stmt->fetchAll() as $row) {
            $humaine[(int) $row['id_competence_humaine']] = (int) $row['niveau'];
        }

        $stmt = $pdo->prepare("SELECT id_badge, niveau FROM evaluation_badge WHERE id_evaluation = ?");
        $stmt->execute([$idEval]);
        foreach ($stmt->fetchAll() as $row) {
            $badge[(int) $row['id_badge']] = (int) $row['niveau'];
        }
    }

    return [
        'id_stagiaire' => (int) $stage['id_stagiaire'],
        'general'      => $general,
        'tech'         => $tech,
        'humaine'      => $humaine,
        'badge'        => $badge,
        'note'         => $note,
        'commentaire'  => $commentaire,
    ];
}

// ── Capture de l'état AVANT modification (pour le suivi des modifications) ──
$avant = null;
if ($idEdition && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $avant = chargerEtatStage($pdo, $idEdition, $nomsClasse, $nomsEtablissement);
}

// ── Traitement de la soumission ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $nom             = trim($_POST['nom'] ?? '');
    $prenom          = trim($_POST['prenom'] ?? '');
    $idClasse        = filter_input(INPUT_POST, 'classe', FILTER_VALIDATE_INT);
    $idEtablissement = filter_input(INPUT_POST, 'etablissement', FILTER_VALIDATE_INT);
    $dateDebut       = trim($_POST['date_debut'] ?? '');
    $dateFin         = trim($_POST['date_fin'] ?? '');

    // ── Nouvel établissement saisi à la volée (option "+ Ajouter…" du select) ──
    $nouvelEtablissement    = trim($_POST['etablissement_nouveau'] ?? '');
    $creerNouvelEtablissement = false;
    if (!$idEtablissement && $nouvelEtablissement !== '') {
        // Recherche insensible à la casse pour éviter de créer un doublon si
        // l'établissement existe déjà sous une casse légèrement différente.
        $stmt = $pdo->prepare("SELECT id_etablissement FROM etablissement_ref WHERE LOWER(nom) = LOWER(:nom)");
        $stmt->execute([':nom' => $nouvelEtablissement]);
        $existant = $stmt->fetchColumn();
        if ($existant) {
            $idEtablissement = (int) $existant;
        } else {
            $creerNouvelEtablissement = true;
        }
    }

    $notesTech    = $_POST['tech']    ?? []; // [id_competence_technique => niveau]
    $notesHumaine = $_POST['humaine'] ?? []; // [id_competence_humaine   => niveau]
    $notesBadge   = $_POST['badge']   ?? []; // [id_badge                => niveau]

    // Note globale /20 (facultative)
    $note = trim($_POST['note'] ?? '');
    $note = str_replace(',', '.', $note);

    // Commentaire de l'évaluateur (correction 3.15 : champ enfin exposé et utilisé)
    $commentaire = trim($_POST['commentaire'] ?? '');

    $classeValide        = $idClasse && isset($nomsClasse[$idClasse]);
    $etablissementValide = $creerNouvelEtablissement || ($idEtablissement && isset($nomsEtablissement[$idEtablissement]));

    if ($nom === '' || $prenom === '' || !$classeValide || !$etablissementValide) {
        $erreur = "Merci de remplir tous les champs des informations générales.";
    } elseif ($dateDebut !== '' && $dateFin !== '' && $dateFin < $dateDebut) {
        $erreur = "La date de fin de période ne peut pas être avant la date de début.";
    } elseif ($note !== '' && (!is_numeric($note) || (float) $note < 0 || (float) $note > 20)) {
        $erreur = "La notation doit être un nombre compris entre 0 et 20.";
    } else {
        try {
            $pdo->beginTransaction();

            // Création effective du nouvel établissement (faite dans la transaction :
            // si la suite échoue, l'établissement fraîchement créé est annulé aussi).
            if ($creerNouvelEtablissement) {
                $stmt = $pdo->prepare("INSERT INTO etablissement_ref (nom) VALUES (:nom)");
                $stmt->execute([':nom' => $nouvelEtablissement]);
                $idEtablissement = (int) $pdo->lastInsertId();
                $nomsEtablissement[$idEtablissement] = $nouvelEtablissement;
            }

            if ($idEdition) {
                // 1) Mise à jour de la personne et du stage existants
                $idStagiaire = $avant['id_stagiaire'] ?? null;
                if (!$idStagiaire) {
                    throw new Exception("Stage introuvable.");
                }

                $pdo->prepare("UPDATE stagiaire SET nom = :nom, prenom = :prenom WHERE id_stagiaire = :id")
                    ->execute([':nom' => $nom, ':prenom' => $prenom, ':id' => $idStagiaire]);

                $pdo->prepare(
                    "UPDATE stage SET id_classe = :classe, id_etablissement = :etablissement,
                     date_debut = :date_debut, date_fin = :date_fin WHERE id_stage = :id"
                )->execute([
                    ':classe'        => $idClasse,
                    ':etablissement' => $idEtablissement,
                    ':date_debut'    => $dateDebut !== '' ? $dateDebut : null,
                    ':date_fin'      => $dateFin !== '' ? $dateFin : null,
                    ':id'            => $idEdition,
                ]);
                $idStage = $idEdition;
            } else {
                // 1) Création de la personne puis du stage
                $stmt = $pdo->prepare("INSERT INTO stagiaire (nom, prenom) VALUES (:nom, :prenom)");
                $stmt->execute([':nom' => $nom, ':prenom' => $prenom]);
                $idStagiaire = $pdo->lastInsertId();

                $stmt = $pdo->prepare(
                    "INSERT INTO stage (id_stagiaire, id_classe, id_etablissement, date_debut, date_fin)
                     VALUES (:id_stagiaire, :classe, :etablissement, :date_debut, :date_fin)"
                );
                $stmt->execute([
                    ':id_stagiaire'  => $idStagiaire,
                    ':classe'        => $idClasse,
                    ':etablissement' => $idEtablissement,
                    ':date_debut'    => $dateDebut !== '' ? $dateDebut : null,
                    ':date_fin'      => $dateFin !== '' ? $dateFin : null,
                ]);
                $idStage = $pdo->lastInsertId();
            }

            // 2) Nouvelle séance d'évaluation (correction 3.1) : on ne remplace plus jamais
            // la précédente, on crée une ligne datée si au moins une note a été saisie,
            // ce qui permet de comparer plusieurs étapes du stage dans le temps.
            $auMoinsUneNote = ($note !== '') || ($commentaire !== '');
            if (!$auMoinsUneNote) {
                foreach ([$notesTech, $notesHumaine, $notesBadge] as $groupe) {
                    foreach ($groupe as $niveau) {
                        if ((int) $niveau >= 1) {
                            $auMoinsUneNote = true;
                            break 2;
                        }
                    }
                }
            }

            if ($auMoinsUneNote) {
                $stmt = $pdo->prepare(
                    "INSERT INTO evaluation (id_stage, id_evaluateur, note, commentaire) VALUES (:id_stage, :id_evaluateur, :note, :commentaire)"
                );
                $stmt->execute([
                    ':id_stage'      => $idStage,
                    ':id_evaluateur' => $_SESSION['user_id'] ?? null,
                    ':note'          => $note !== '' ? (float) $note : null,
                    ':commentaire'   => $commentaire !== '' ? $commentaire : null,
                ]);
                $idEvaluation = (int) $pdo->lastInsertId();

                $insererNiveaux = function (array $notes, string $table, string $colonne, int $max) use ($pdo, $idEvaluation) {
                    $stmt = $pdo->prepare(
                        "INSERT INTO {$table} (id_evaluation, {$colonne}, niveau) VALUES (:id_evaluation, :id_item, :niveau)"
                    );
                    foreach ($notes as $idItem => $niveau) {
                        $niveau = (int) $niveau;
                        if ($niveau >= 1 && $niveau <= $max) {
                            $stmt->execute([
                                ':id_evaluation' => $idEvaluation,
                                ':id_item'       => (int) $idItem,
                                ':niveau'        => $niveau,
                            ]);
                        }
                    }
                };

                $insererNiveaux($notesTech, 'evaluation_competence_technique', 'id_competence_technique', 3);
                $insererNiveaux($notesHumaine, 'evaluation_competence_humaine', 'id_competence_humaine', 5);
                $insererNiveaux($notesBadge, 'evaluation_badge', 'id_badge', 3);
            }

            // ── Construction du détail précis des changements pour le suivi des modifications ──
            $details = '';
            if ($idEdition && $avant) {
                $details = construireDetailsModification(
                    $avant,
                    [
                        'nom'           => $nom,
                        'prenom'        => $prenom,
                        'classe'        => $nomsClasse[$idClasse] ?? '',
                        'etablissement' => $nomsEtablissement[$idEtablissement] ?? '',
                        'date_debut'    => $dateDebut !== '' ? $dateDebut : null,
                        'date_fin'      => $dateFin !== '' ? $dateFin : null,
                    ],
                    $notesTech,
                    $notesHumaine,
                    $notesBadge,
                    $note !== '' ? (float) $note : null,
                    $nomsTech,
                    $nomsHumaine,
                    $nomsBadge,
                    $avant['commentaire'] ?? null,
                    $commentaire !== '' ? $commentaire : null
                );
            }

            // Correction 3.5 : la journalisation est effectuée AVANT le commit, dans la
            // même transaction, afin que la fiche et sa trace d'audit réussissent ou
            // échouent ensemble.
            logAction(
                $idEdition ? 'modification' : 'creation',
                (int) $idStage,
                $nom . ' ' . $prenom,
                $details
            );

            $pdo->commit();

            // Correction 3.19 : on ne continue plus à rendre la réponse du POST
            // (ce qui exposait à une double soumission au moindre F5, et faisait
            // dépendre le retour utilisateur d'un setTimeout JavaScript). On stocke
            // un message flash en session et on redirige immédiatement en GET vers
            // la même fiche : la page se recharge alors depuis l'état réellement
            // commité en base, et un rechargement ultérieur ne renvoie plus le formulaire.
            $_SESSION['flash_succes'] = $idEdition
                ? "Stagiaire modifié avec succès."
                : "Stagiaire enregistré avec succès.";
            // On redirige désormais systématiquement vers l'accueil après
            // l'affichage du message, que ce soit une création ou une modification.
            $_SESSION['flash_redirection_accueil'] = true;
            header("Location: formulaire_stagiaires.php?id=" . (int) $idStage);
            exit;
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            // Correction (revue fichier par fichier) : ne jamais renvoyer le message
            // d'exception brut à l'utilisateur (peut révéler des détails du schéma ou
            // de la requête). Le détail complet part dans les logs serveur.
            error_log('formulaire_stagiaires.php : erreur enregistrement stage — ' . $e->getMessage());
            $erreur = "Une erreur est survenue lors de l'enregistrement. Merci de réessayer.";
        }
    }
}

// ── Message flash après redirection (correction 3.19, POST/Redirect/GET) ──
$redirectionAccueil = false;
if ($_SERVER['REQUEST_METHOD'] === 'GET' && !empty($_SESSION['flash_succes'])) {
    $succes = $_SESSION['flash_succes'];
    $redirectionAccueil = !empty($_SESSION['flash_redirection_accueil']);
    unset($_SESSION['flash_succes'], $_SESSION['flash_redirection_accueil']);
}

// ── Préparation des données à afficher dans le formulaire ──
// Correction 3.6 : en cas d'erreur de validation après un POST, on réaffiche les
// valeurs SAISIES par l'utilisateur (jamais des tableaux vides), et on conserve le
// mode édition. Ce n'est qu'au premier chargement (GET) qu'on va lire la base.
$stagiaireData          = null;
$notesTechExistantes    = [];
$notesHumaineExistantes = [];
$notesBadgeExistantes   = [];
$noteExistante          = null;
$commentaireExistant    = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Réaffichage fidèle de la saisie, qu'elle ait échoué ou réussi.
    $stagiaireData = [
        'nom'              => $_POST['nom'] ?? '',
        'prenom'           => $_POST['prenom'] ?? '',
        'id_classe'        => filter_input(INPUT_POST, 'classe', FILTER_VALIDATE_INT) ?: null,
        'id_etablissement' => filter_input(INPUT_POST, 'etablissement', FILTER_VALIDATE_INT) ?: null,
        'date_debut'       => $_POST['date_debut'] ?? '',
        'date_fin'         => $_POST['date_fin'] ?? '',
        'etablissement_raw'      => $_POST['etablissement'] ?? '',
        'etablissement_nouveau'  => $_POST['etablissement_nouveau'] ?? '',
    ];
    foreach (($_POST['tech'] ?? []) as $id => $niveau) {
        $notesTechExistantes[(int) $id] = (int) $niveau;
    }
    foreach (($_POST['humaine'] ?? []) as $id => $niveau) {
        $notesHumaineExistantes[(int) $id] = (int) $niveau;
    }
    foreach (($_POST['badge'] ?? []) as $id => $niveau) {
        $notesBadgeExistantes[(int) $id] = (int) $niveau;
    }
    $noteExistante = $_POST['note'] ?? '';
    $commentaireExistant = $_POST['commentaire'] ?? '';

    // Si l'enregistrement a réussi, on repart d'une saisie propre pour la prochaine
    // évaluation mais on garde les informations générales à l'écran.
    if ($succes !== '') {
        $notesTechExistantes = $notesHumaineExistantes = $notesBadgeExistantes = [];
        $noteExistante = null;
        $commentaireExistant = null;
    }
} elseif ($idEdition) {
    // Premier chargement du formulaire en mode édition : on lit l'état actuel en base.
    $etat = chargerEtatStage($pdo, $idEdition, $nomsClasse, $nomsEtablissement);
    if (!$etat) {
        http_response_code(404);
        exit('Stagiaire introuvable.');
    }
    $stagiaireData = [
        'nom'              => $etat['general']['nom'],
        'prenom'           => $etat['general']['prenom'],
        'id_classe'        => array_search($etat['general']['classe'], $nomsClasse, true) ?: null,
        'id_etablissement' => array_search($etat['general']['etablissement'], $nomsEtablissement, true) ?: null,
        'date_debut'       => $etat['general']['date_debut'],
        'date_fin'         => $etat['general']['date_fin'],
    ];
    $notesTechExistantes    = $etat['tech'];
    $notesHumaineExistantes = $etat['humaine'];
    $notesBadgeExistantes   = $etat['badge'];
    $noteExistante          = $etat['note'];
    $commentaireExistant    = $etat['commentaire'];
}

// ── Lien vers la page dédiée à l'évolution des compétences (evolution.php) ──
// On vérifie juste qu'il existe au moins une séance d'évaluation pour ce stage,
// pour savoir si le lien "Voir l'évolution" a un sens à afficher ; le calcul
// détaillé (quelles compétences ont varié, etc.) vit désormais dans evolution.php.
$aDesEvaluations = false;
if ($idEdition) {
    $stmt = $pdo->prepare("SELECT 1 FROM evaluation WHERE id_stage = ? LIMIT 1");
    $stmt->execute([$idEdition]);
    $aDesEvaluations = (bool) $stmt->fetchColumn();
}

// ── Données pour la fiche imprimable (bouton "Imprimer") ──
// On réutilise exactement la même fonction que evolution.php (factorisée dans
// config.php) pour que les courbes imprimées soient identiques à celles
// consultables à l'écran.
$evolutionImpression = $idEdition
    ? calculerEvolutionStage($pdo, $idEdition, $nomsTech, $nomsHumaine, $nomsBadge)
    : null;
$aDesGraphiquesAImprimer = $evolutionImpression && (
    !empty($evolutionImpression['technique'])
    || !empty($evolutionImpression['humaine'])
    || !empty($evolutionImpression['badge'])
    || !empty($evolutionImpression['note'])
);

/**
 * Génère un groupe d'étoiles SVG interactives. Cliquer sur une étoile déjà
 * sélectionnée l'efface (retour à "non évalué"), et son input caché est envoyé
 * avec le formulaire.
 */
function renderStarInput(string $name, int $max, int $value): string
{
    $value = max(0, min($max, $value));
    $html = '<div class="stars-input-wrap" data-max="' . $max . '">';
    $html .= '<div class="stars-input">';
    for ($i = 1; $i <= $max; $i++) {
        $filled  = $i <= $value;
        $couleur = $filled ? '#f0a500' : '#e2e2e2';
        $html .= '<svg class="star" data-value="' . $i . '" width="20" height="20" viewBox="0 0 24 24" tabindex="0" role="button" aria-pressed="' . ($filled ? 'true' : 'false') . '" aria-label="Note ' . $i . ' sur ' . $max . '" style="fill:' . $couleur . ';">'
               . '<polygon points="12,2 15,9 22,9 16.5,13.5 18.5,21 12,17 5.5,21 7.5,13.5 2,9 9,9"></polygon>'
               . '</svg>';
    }
    $html .= '</div>';
    $html .= '<input type="hidden" class="rating-input" name="' . htmlspecialchars($name) . '" value="' . $value . '">';
    $html .= '</div>';
    return $html;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= $idEdition ? 'Modifier le stagiaire' : 'Nouveau stagiaire' ?></title>
  <link rel="stylesheet" href="style.css">
  <style>
    /* Illustration de badge, ajoutée à côté du nom dans la colonne Badges
       (cf. demande d'ajout des illustrations) : icône ronde de petite taille,
       alignée avec le texte, sans casser la mise en page existante des .skill-item. */
    .skill-name-badge { display: inline-flex; align-items: center; gap: 8px; }
    .badge-icon { border-radius: 50%; object-fit: cover; flex-shrink: 0; }
    .print-badge-icon { border-radius: 50%; object-fit: cover; vertical-align: middle; margin-right: 6px; }
  </style>
  <?php if ($aDesGraphiquesAImprimer): ?>
  <!-- Chart.js n'est chargé que si la fiche imprimable aura au moins un graphique
       à afficher (mêmes conditions que dans evolution.php). Fichier hébergé en
       local (chart.min.js, à la racine du site) : pas de dépendance à un CDN. -->
  <script src="chart.min.js"></script>
  <?php endif; ?>
</head>
<body class="<?= bodyClass() ?>">
  <div id="toast" class="toast"></div>

  <header class="header">
    <a href="index.php" class="back-btn">&larr; Retour</a>
    <h1><?= $idEdition ? 'Modifier le stagiaire' : 'Nouveau stagiaire' ?></h1>
  </header>

  <main class="content">
    <?php if ($erreur !== ''): ?>
      <div class="alert-error"><?= htmlspecialchars($erreur) ?></div>
    <?php endif; ?>

    <form id="ficheForm" method="POST" action="formulaire_stagiaires.php<?= $idEdition ? '?id=' . (int) $idEdition : '' ?>">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
      <?php if ($idEdition): ?>
        <input type="hidden" name="id_stage" value="<?= (int) $idEdition ?>">
      <?php endif; ?>

      <div class="form-grid">

        <!-- Colonne 1 : Informations générales -->
        <section class="form-col">
          <h3>Informations générales</h3>

          <div class="field">
            <label for="nom">Nom</label>
            <input type="text" id="nom" name="nom" value="<?= htmlspecialchars($stagiaireData['nom'] ?? '') ?>" required>
          </div>
          <div class="field">
            <label for="prenom">Prénom</label>
            <input type="text" id="prenom" name="prenom" value="<?= htmlspecialchars($stagiaireData['prenom'] ?? '') ?>" required>
          </div>
          <div class="field">
            <label for="classe">Classe</label>
            <select id="classe" name="classe" required>
              <option value="" disabled <?= empty($stagiaireData['id_classe']) ? 'selected' : '' ?>>Sélectionner...</option>
              <?php foreach ($classesRef as $optClasse): ?>
                <option value="<?= (int) $optClasse['id_classe'] ?>" <?= ((int) ($stagiaireData['id_classe'] ?? 0) === (int) $optClasse['id_classe']) ? 'selected' : '' ?>><?= htmlspecialchars($optClasse['nom']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field">
            <label for="etablissement">Établissement</label>
            <select id="etablissement" name="etablissement" required>
              <option value="" disabled <?= (empty($stagiaireData['id_etablissement']) && ($stagiaireData['etablissement_raw'] ?? '') !== '__autre__') ? 'selected' : '' ?>>Sélectionner...</option>
              <?php foreach ($etablissementsRef as $optEtab): ?>
                <option value="<?= (int) $optEtab['id_etablissement'] ?>" <?= ((int) ($stagiaireData['id_etablissement'] ?? 0) === (int) $optEtab['id_etablissement']) ? 'selected' : '' ?>><?= htmlspecialchars($optEtab['nom']) ?></option>
              <?php endforeach; ?>
              <option value="__autre__" <?= (($stagiaireData['etablissement_raw'] ?? '') === '__autre__') ? 'selected' : '' ?>>+ Ajouter un nouvel établissement…</option>
            </select>
            <input type="text" id="etablissementNouveau" name="etablissement_nouveau"
                   placeholder="Nom du nouvel établissement"
                   value="<?= htmlspecialchars($stagiaireData['etablissement_nouveau'] ?? '') ?>"
                   style="margin-top:8px;display:none;">
          </div>
          <div class="field">
            <label>Période</label>
            <div class="date-range">
              <input type="date" name="date_debut" value="<?= htmlspecialchars($stagiaireData['date_debut'] ?? '') ?>">
              <span class="date-range-sep">→</span>
              <input type="date" name="date_fin" value="<?= htmlspecialchars($stagiaireData['date_fin'] ?? '') ?>">
            </div>
          </div>

          <?php if (!$idEdition): ?>
          <!-- En création, seules les informations générales sont saisissables :
               la notation (compétences, badges, note, commentaire) n'a de sens
               qu'une fois le stage créé, donc uniquement disponible en modification
               (cf. demande : créer d'abord, noter/modifier ensuite uniquement). -->
          <div class="fiche-form-actions" style="margin-top:20px;">
            <button type="submit" form="ficheForm" class="submit-btn">Enregistrer le stagiaire</button>
          </div>
          <?php endif; ?>
        </section>

        <?php if ($idEdition): ?>
        <!-- Colonne 2 : Compétences techniques -->
        <section class="form-col">
          <h3>Compétences techniques</h3>
          <p class="fiche-empty" style="margin-bottom:10px;">Cette saisie crée une nouvelle séance d'évaluation datée : l'historique des séances précédentes est conservé.</p>
          <div class="skill-list">
            <?php foreach ($competencesTechniques as $comp):
              $val = $notesTechExistantes[(int) $comp['id_competence_technique']] ?? 0;
            ?>
              <div class="skill-item">
                <span class="skill-name"><?= htmlspecialchars($comp['nom']) ?></span>
                <?= renderStarInput('tech[' . (int) $comp['id_competence_technique'] . ']', 3, $val) ?>
              </div>
            <?php endforeach; ?>
            <?php if (empty($competencesTechniques)): ?>
              <p class="fiche-empty">Aucune compétence technique définie.</p>
            <?php endif; ?>
          </div>
        </section>

        <!-- Colonne 3 : Compétences humaines -->
        <section class="form-col">
          <h3>Compétences humaines</h3>
          <div class="skill-list">
            <?php foreach ($competencesHumaines as $comp):
              $val = $notesHumaineExistantes[(int) $comp['id_competence_humaine']] ?? 0;
            ?>
              <div class="skill-item">
                <span class="skill-name"><?= htmlspecialchars($comp['nom']) ?></span>
                <?= renderStarInput('humaine[' . (int) $comp['id_competence_humaine'] . ']', 5, $val) ?>
              </div>
            <?php endforeach; ?>
            <?php if (empty($competencesHumaines)): ?>
              <p class="fiche-empty">Aucune compétence humaine définie.</p>
            <?php endif; ?>
          </div>
        </section>

        <!-- Colonne 4 : Badges -->
        <section class="form-col">
          <h3>Badges</h3>
          <div class="skill-list">
            <?php foreach ($badges as $badgeItem):
              $val = $notesBadgeExistantes[(int) $badgeItem['id_badge']] ?? 0;
              $imgBadge = urlImageBadge($badgeItem['nom']);
            ?>
              <div class="skill-item">
                <span class="skill-name skill-name-badge">
                  <?php if ($imgBadge): ?>
                    <img src="<?= htmlspecialchars($imgBadge) ?>" alt="" class="badge-icon" width="28" height="28">
                  <?php endif; ?>
                  <?= htmlspecialchars($badgeItem['nom']) ?>
                </span>
                <?= renderStarInput('badge[' . (int) $badgeItem['id_badge'] . ']', 3, $val) ?>
              </div>
            <?php endforeach; ?>
            <?php if (empty($badges)): ?>
              <p class="fiche-empty">Aucun badge défini.</p>
            <?php endif; ?>
          </div>

          <div class="field" style="margin-top:20px;padding-top:16px;border-top:1px solid #e0d9e3;">
            <label for="note">Notation globale (/20)</label>
            <input type="number" id="note" name="note" min="0" max="20" step="0.5"
                   placeholder="Ex : 15.5"
                   value="<?= htmlspecialchars($noteExistante !== null ? (string) $noteExistante : '') ?>">
          </div>

          <!-- Correction 3.15 : ce champ existait déjà en base (evaluation.commentaire)
               mais n'était affiché nulle part. Il permet de justifier une note ou de noter
               une observation qualitative pour la séance d'évaluation en cours. -->
          <div class="field" style="margin-top:16px;">
            <label for="commentaire">Commentaire de l'évaluateur (facultatif)</label>
            <textarea id="commentaire" name="commentaire" rows="3"
                      placeholder="Observations sur cette séance d'évaluation…"
                      style="width:100%;resize:vertical;font:inherit;padding:8px;box-sizing:border-box;"
            ><?= htmlspecialchars($commentaireExistant ?? '') ?></textarea>
          </div>

          <div class="fiche-form-actions">
            <?php if ($aDesEvaluations): ?>
              <a href="evolution.php?id=<?= (int) $idEdition ?>" class="submit-btn submit-btn-secondary">Voir l'évolution</a>
            <?php endif; ?>
            <?php if (!empty($stagiaireData['date_debut']) && !empty($stagiaireData['date_fin'])): ?>
              <!-- Comparatif avec les autres stagiaires dont la période se chevauche
                   (cf. demande de comparaison "même période"). Nécessite les deux
                   dates renseignées, sinon aucun chevauchement n'est calculable. -->
              <a href="comparatif.php?id=<?= (int) $idEdition ?>" class="submit-btn submit-btn-secondary">📊 Comparer avec la période</a>
            <?php endif; ?>
            <button type="button" id="btnImprimerFiche" class="submit-btn submit-btn-secondary">🖨️ Imprimer la fiche du stagiaire</button>
            <button type="submit" form="ficheForm" class="submit-btn">Enregistrer les modifications</button>
          </div>
        </section>

        <?php endif; // fin des colonnes 2-4, réservées à la modification ?>

      </div>
    </form>

    <?php if ($idEdition): ?>
    <?php
      // ═══════════════════════════════════════════════════════════════
      // SECTION IMPRIMABLE — jamais visible à l'écran (voir .print-only
      // dans style.css), seulement au moment de l'impression. Construite à
      // partir des mêmes données que le formulaire ci-dessus (informations
      // générales, niveaux actuels, commentaire) et des mêmes graphiques
      // d'évolution que evolution.php.
      // ═══════════════════════════════════════════════════════════════
      $nomClasseImpression        = $nomsClasse[$stagiaireData['id_classe'] ?? 0] ?? '—';
      $nomEtablissementImpression = $nomsEtablissement[$stagiaireData['id_etablissement'] ?? 0] ?? '—';
      $formatDateImpression = fn($d) => $d ? (new DateTime($d))->format('d/m/Y') : '—';
    ?>
    <div class="print-only" id="printArea">
      <header class="print-header">
        <h1>Fiche de suivi — Stagiaire</h1>
        <p class="print-date">Document généré le <?= htmlspecialchars((new DateTime())->format('d/m/Y')) ?></p>
      </header>

      <section class="print-section">
        <h2>Informations générales</h2>
        <table class="print-table">
          <tr><th>Nom</th><td><?= htmlspecialchars($stagiaireData['nom'] ?? '') ?></td>
              <th>Prénom</th><td><?= htmlspecialchars($stagiaireData['prenom'] ?? '') ?></td></tr>
          <tr><th>Classe</th><td><?= htmlspecialchars($nomClasseImpression) ?></td>
              <th>Établissement</th><td><?= htmlspecialchars($nomEtablissementImpression) ?></td></tr>
          <tr><th>Période du stage</th>
              <td colspan="3"><?= htmlspecialchars($formatDateImpression($stagiaireData['date_debut'] ?? null)) ?> au <?= htmlspecialchars($formatDateImpression($stagiaireData['date_fin'] ?? null)) ?></td></tr>
          <?php if ($noteExistante !== null && $noteExistante !== ''): ?>
          <tr><th>Notation globale</th><td colspan="3"><?= htmlspecialchars((string) $noteExistante) ?> / 20</td></tr>
          <?php endif; ?>
        </table>
      </section>

      <?php if (!empty($notesTechExistantes) || !empty($notesHumaineExistantes)): ?>
      <div class="print-competences-grid">
        <?php if (!empty($notesTechExistantes)): ?>
        <section class="print-section">
          <h2>Compétences techniques évaluées</h2>
          <table class="print-table print-table-competences">
            <thead><tr><th>Compétence</th><th>Niveau</th></tr></thead>
            <tbody>
              <?php foreach ($notesTechExistantes as $id => $niveau): ?>
                <tr><td><?= htmlspecialchars($nomsTech[$id] ?? ('#' . $id)) ?></td><td><?= (int) $niveau ?> / 3</td></tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </section>
        <?php endif; ?>

        <?php if (!empty($notesHumaineExistantes)): ?>
        <section class="print-section">
          <h2>Compétences humaines évaluées</h2>
          <table class="print-table print-table-competences">
            <thead><tr><th>Compétence</th><th>Niveau</th></tr></thead>
            <tbody>
              <?php foreach ($notesHumaineExistantes as $id => $niveau): ?>
                <tr><td><?= htmlspecialchars($nomsHumaine[$id] ?? ('#' . $id)) ?></td><td><?= (int) $niveau ?> / 5</td></tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </section>
        <?php endif; ?>
      </div>
      <?php endif; ?>

      <?php if (!empty($notesBadgeExistantes)): ?>
      <section class="print-section">
        <h2>Badges obtenus</h2>
        <div class="print-badges">
          <?php foreach ($notesBadgeExistantes as $id => $niveau): ?>
            <?php $imgBadgeImpression = urlImageBadge($nomsBadge[$id] ?? ''); ?>
            <span class="print-badge">
              <?php if ($imgBadgeImpression): ?>
                <img src="<?= htmlspecialchars($imgBadgeImpression) ?>" alt="" class="print-badge-icon" width="20" height="20">
              <?php else: ?>
                🏅
              <?php endif; ?>
              <?= htmlspecialchars($nomsBadge[$id] ?? ('#' . $id)) ?> <small>(<?= (int) $niveau ?>/3)</small>
            </span>
          <?php endforeach; ?>
        </div>
      </section>
      <?php endif; ?>

      <?php if (!empty($commentaireExistant)): ?>
      <section class="print-section">
        <h2>Commentaire de l'évaluateur</h2>
        <p class="print-commentaire"><?= nl2br(htmlspecialchars($commentaireExistant)) ?></p>
      </section>
      <?php endif; ?>


      <?php endif; ?>
    </div>
  </main>

  <script>
    // Affiche le champ de saisie libre quand "+ Ajouter un nouvel établissement…"
    // est sélectionné, et le rend obligatoire dans ce cas uniquement.
    (function() {
      const etabSelect = document.getElementById('etablissement');
      const etabNouveau = document.getElementById('etablissementNouveau');
      if (!etabSelect || !etabNouveau) return;

      function syncEtabNouveau() {
        const estNouveau = etabSelect.value === '__autre__';
        etabNouveau.style.display = estNouveau ? 'block' : 'none';
        etabNouveau.required = estNouveau;
        if (!estNouveau) etabNouveau.value = '';
      }

      etabSelect.addEventListener('change', syncEtabNouveau);
      syncEtabNouveau();
    })();

    // Étoiles interactives (SVG) — clic pour fixer la note, second clic sur la même
    // étoile ou bouton "Non évalué" pour l'effacer (correction 3.7), survol pour prévisualiser.
    document.querySelectorAll('.stars-input-wrap').forEach(wrap => {
      const container = wrap.querySelector('.stars-input');
      const stars = Array.from(container.querySelectorAll('.star'));
      const hiddenInput = wrap.querySelector('.rating-input');
      let currentRating = hiddenInput ? (parseInt(hiddenInput.value, 10) || 0) : 0;

      function paint(n) {
        stars.forEach((s, i) => {
          s.style.fill = i < n ? '#f0a500' : '#e2e2e2';
        });
      }

      // Correction 3.20 : aria-pressed ne doit refléter que la note VALIDÉE,
      // pas la prévisualisation au survol (sinon un lecteur d'écran annoncerait
      // un état qui n'a pas réellement été choisi).
      function syncAria(n) {
        stars.forEach((s, i) => {
          s.setAttribute('aria-pressed', i < n ? 'true' : 'false');
        });
      }

      function setRating(n) {
        currentRating = n;
        if (hiddenInput) hiddenInput.value = currentRating;
        paint(currentRating);
        syncAria(currentRating);
      }

      stars.forEach((star, index) => {
        star.addEventListener('mouseenter', () => paint(index + 1));

        star.addEventListener('click', () => {
          setRating(currentRating === index + 1 ? 0 : index + 1);
        });

        star.addEventListener('keydown', (e) => {
          if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            setRating(currentRating === index + 1 ? 0 : index + 1);
          }
        });
      });

      container.addEventListener('mouseleave', () => paint(currentRating));

      paint(currentRating);
    });

    <?php if ($aDesGraphiquesAImprimer): ?>
    // ── Graphiques de la fiche imprimable ──
    // Rendus une fois au chargement de la page, dans des <canvas> de taille
    // FIXE (responsive: false) : contrairement aux graphiques d'evolution.php,
    // ceux-ci ne sont jamais affichés à l'écran (cf. .print-chart-canvas dans
    // style.css), ils servent uniquement de source pour générer les images
    // insérées dans la fiche au moment de l'impression (cf. plus bas).
    (function() {
      if (typeof Chart === 'undefined') return;

      const labels = <?= json_encode($labelsSeances ?? []) ?>;
      const palette = ['#3d1550', '#b8862e', '#2f8f45', '#6a2f86', '#d9573f', '#1f6b8f', '#8a5aa8', '#c9a227'];

      function tracer(idCanvas, items, max, suffixe) {
        const canvas = document.getElementById(idCanvas);
        if (!canvas || !items || items.length === 0) return;
        const ctx = canvas.getContext('2d');

        const datasets = items.map(function(item, i) {
          const couleur = palette[i % palette.length];
          return {
            label: item.nom,
            data: item.valeurs,
            borderColor: couleur,
            backgroundColor: couleur + '22',
            spanGaps: true,
            tension: 0.35,
            fill: items.length === 1,
            borderWidth: 2.5,
            pointBackgroundColor: couleur,
            pointBorderColor: '#fff',
            pointBorderWidth: 1.5,
            pointRadius: 4,
          };
        });

        new Chart(canvas, {
          type: 'line',
          data: { labels: labels, datasets: datasets },
          options: {
            responsive: false, // taille fixe (width/height du <canvas>), indépendante de l'affichage écran
            animation: false,  // rendu immédiat : le canvas doit être prêt dès le chargement de la page
            scales: {
              y: { min: 0, max: max, ticks: { stepSize: max <= 5 ? 1 : 5 } }
            },
            plugins: {
              legend: { display: datasets.length > 1, position: 'bottom', labels: { boxWidth: 10, font: { size: 11 } } }
            }
          }
        });
      }

      <?php if (!empty($evolutionImpression['note'])): ?>
      tracer('printChartNote', [{ nom: 'Note globale', valeurs: <?= json_encode($evolutionImpression['note']) ?> }], 20, '/20');
      <?php endif; ?>
      <?php if (!empty($evolutionImpression['technique'])): ?>
      tracer('printChartTech', <?= json_encode(array_map(fn($i) => ['nom' => $i['nom'], 'valeurs' => $i['valeurs']], $evolutionImpression['technique'])) ?>, 3, '/3');
      <?php endif; ?>
      <?php if (!empty($evolutionImpression['humaine'])): ?>
      tracer('printChartHumaine', <?= json_encode(array_map(fn($i) => ['nom' => $i['nom'], 'valeurs' => $i['valeurs']], $evolutionImpression['humaine'])) ?>, 5, '/5');
      <?php endif; ?>
      <?php if (!empty($evolutionImpression['badge'])): ?>
      tracer('printChartBadge', <?= json_encode(array_map(fn($i) => ['nom' => $i['nom'], 'valeurs' => $i['valeurs']], $evolutionImpression['badge'])) ?>, 3, '/3');
      <?php endif; ?>
    })();
    <?php endif; ?>

    <?php if ($idEdition): ?>
    // ── Bouton "Imprimer la fiche du stagiaire" ──
    (function() {
      const btn = document.getElementById('btnImprimerFiche');
      if (!btn) return;

      btn.addEventListener('click', function() {
        // Convertit chaque graphique (canvas, jamais affiché à l'écran) en image
        // PNG et l'insère dans la fiche imprimable : c'est l'image qui sera
        // effectivement imprimée (cf. règles @media print de style.css qui
        // masquent le <canvas> et affichent le <img> uniquement à l'impression).
        // Cette conversion évite les soucis de rendu de <canvas> propres à
        // certains navigateurs/imprimantes lors de l'impression directe.
        document.querySelectorAll('#printArea .print-chart-block').forEach(function(bloc) {
          const canvas = bloc.querySelector('canvas.print-chart-canvas');
          const img = bloc.querySelector('img.print-chart-img');
          if (canvas && img) {
            img.src = canvas.toDataURL('image/png');
          }
        });

        window.print();
      });
    })();
    <?php endif; ?>

    <?php if ($succes !== ''): ?>
    // Affiche un petit popup de confirmation
    (function() {
      const toast = document.getElementById('toast');
      toast.textContent = <?= json_encode($succes) ?>;
      toast.classList.add('show');
      <?php if ($redirectionAccueil): ?>
      // Création réussie : on laisse le temps de lire le message, puis on
      // repart directement sur l'accueil (pas besoin de cliquer sur "Retour").
      setTimeout(function() {
        window.location.href = 'index.php';
      }, 1400);
      <?php else: ?>
      setTimeout(function() {
        toast.classList.remove('show');
      }, 2500);
      <?php endif; ?>
    })();
    <?php endif; ?>
  </script>
</body>
</html>