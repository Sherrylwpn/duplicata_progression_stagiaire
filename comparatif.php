<?php
/**
 * comparatif.php
 * Compare l'évolution de tous les stagiaires dont la période de stage
 * chevauche celle du stage passé en paramètre (?id=...), pour repérer le
 * meilleur du groupe. Le "meilleur" est désigné par un score global sur 100
 * (moyenne à poids égal entre note globale, compétences techniques,
 * compétences humaines et badges — cf. calculerScoreGlobalStage() dans
 * config.php), calculé à partir de la dernière séance d'évaluation de chacun.
 *
 * Accessible depuis la fiche d'un stagiaire ("📊 Comparer avec la période"),
 * uniquement si ses dates de début ET de fin sont renseignées (sans les deux,
 * aucun chevauchement n'est calculable).
 */
require_once 'config.php';
requireAuth();

$idStage = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$idStage) {
    header("Location: index.php");
    exit;
}

$pdo = getDB();

$groupe = trouverStagesMemePeriode($pdo, $idStage);

// Le stage de référence doit exister et avoir ses deux dates renseignées
// (sinon trouverStagesMemePeriode() retourne un tableau vide, cf. config.php).
if (empty($groupe)) {
    $stmtRef = $pdo->prepare(
        "SELECT s.nom, s.prenom FROM stage st JOIN stagiaire s ON s.id_stagiaire = st.id_stagiaire WHERE st.id_stage = ?"
    );
    $stmtRef->execute([$idStage]);
    $stagiaireRef = $stmtRef->fetch();
    if (!$stagiaireRef) {
        header("Location: index.php");
        exit;
    }
}

// ── Totaux possibles (pour normaliser les scores, cf. index.php) ──
$totalTech  = (int) $pdo->query("SELECT COUNT(*) FROM competence_technique")->fetchColumn();
$totalHum   = (int) $pdo->query("SELECT COUNT(*) FROM competence_humaine")->fetchColumn();
$totalBadge = (int) $pdo->query("SELECT COUNT(*) FROM badge")->fetchColumn();

// ── Score + historique de notes de chaque stagiaire du groupe ──
$lignes = [];
$maxSeances = 0;
foreach ($groupe as $stage) {
    $idS = (int) $stage['id_stage'];
    $score = calculerScoreGlobalStage($pdo, $idS, $totalTech, $totalHum, $totalBadge);
    $historiqueNote = recupererHistoriqueNoteStage($pdo, $idS);
    $maxSeances = max($maxSeances, count($historiqueNote));

    $lignes[] = [
        'id_stage'      => $idS,
        'nom'           => $stage['nom'],
        'prenom'        => $stage['prenom'],
        'classe'        => $stage['classe'],
        'etablissement' => $stage['etablissement'],
        'date_debut'    => $stage['date_debut'],
        'date_fin'      => $stage['date_fin'],
        'score'         => $score,
        'historiqueNote' => $historiqueNote,
        'estReference'  => $idS === $idStage,
    ];
}

// ── Classement : les stages sans aucune évaluation (score null) sont
// relégués en fin de liste plutôt que traités comme un score de zéro,
// ce qui les pénaliserait injustement par rapport à un stagiaire simplement
// pas encore noté. ──
usort($lignes, function ($a, $b) {
    if ($a['score'] === null && $b['score'] === null) return 0;
    if ($a['score'] === null) return 1;
    if ($b['score'] === null) return -1;
    return $b['score']['score'] <=> $a['score']['score'];
});

// Rang du meilleur (premier stage noté dans la liste triée)
$meilleurIdStage = null;
foreach ($lignes as $ligne) {
    if ($ligne['score'] !== null) {
        $meilleurIdStage = $ligne['id_stage'];
        break;
    }
}

// ── Labels communs pour le graphique (numéro de séance, pas la date : les
// dates d'évaluation diffèrent d'un stagiaire à l'autre, un numéro de séance
// permet de superposer les courbes malgré tout) ──
$labelsSeances = [];
for ($i = 1; $i <= $maxSeances; $i++) {
    $labelsSeances[] = 'Séance ' . $i;
}

$courbesNote = [];
foreach ($lignes as $ligne) {
    if (empty($ligne['historiqueNote'])) {
        continue;
    }
    $valeurs = $ligne['historiqueNote'];
    // Complète avec des trous (null) jusqu'à $maxSeances pour aligner les courbes.
    while (count($valeurs) < $maxSeances) {
        $valeurs[] = null;
    }
    $courbesNote[] = [
        'nom'     => $ligne['prenom'] . ' ' . $ligne['nom'],
        'valeurs' => $valeurs,
    ];
}

$formatDateAffichage = fn(?string $d) => $d ? date('d/m/Y', strtotime($d)) : '—';
$referenceNomComplet = isset($stagiaireRef)
    ? $stagiaireRef['prenom'] . ' ' . $stagiaireRef['nom']
    : (function () use ($lignes, $idStage) {
        foreach ($lignes as $l) {
            if ($l['id_stage'] === $idStage) return $l['prenom'] . ' ' . $l['nom'];
        }
        return '';
    })();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Comparatif de période — <?= htmlspecialchars($referenceNomComplet) ?></title>
  <link rel="stylesheet" href="style.css">
  <?php if (!empty($courbesNote)): ?>
  <script src="chart.min.js"></script>
  <?php endif; ?>
  <style>
    /* Styles propres au comparatif : tableau de classement + mise en avant du
       meilleur stagiaire du groupe. Le reste (form-col, fiche-empty, submit-btn…)
       réutilise les classes existantes de style.css pour rester cohérent. */
    .comparatif-table { width: 100%; border-collapse: collapse; font-size: 0.95em; }
    .comparatif-table th, .comparatif-table td { padding: 10px 12px; text-align: left; border-bottom: 1px solid #e0d9e3; }
    .comparatif-table th { font-weight: 600; }
    .comparatif-row-meilleur { background: rgba(240, 165, 0, 0.12); font-weight: 600; }
    .comparatif-row-reference td:first-child { border-left: 3px solid #3d1550; padding-left: 9px; }
    .comparatif-medaille { margin-right: 6px; }
    .comparatif-score-vide { color: #9a8fa3; font-style: italic; }
    .comparatif-score { font-variant-numeric: tabular-nums; }
    .comparatif-chart-wrap { position: relative; height: 340px; margin-top: 10px; }
  </style>
</head>
<body class="<?= bodyClass() ?>">
  <header class="header">
    <a href="formulaire_stagiaires.php?id=<?= (int) $idStage ?>" class="back-btn">&larr; Retour à la fiche</a>
    <h1>Comparatif de période</h1>
  </header>

  <main class="content">
    <div class="form-grid">
      <?php if (empty($lignes)): ?>
        <section class="form-col" style="grid-column: 1 / -1;">
          <p class="fiche-empty">
            Aucun autre stagiaire n'a une période chevauchant celle de
            <?= htmlspecialchars($referenceNomComplet) ?>.
          </p>
        </section>
      <?php else: ?>

        <!-- Classement -->
        <section class="form-col" style="grid-column: 1 / -1;">
          <h3>Classement — stages sur une période qui se chevauche</h3>
          <p class="fiche-empty" style="margin-bottom:14px;">
            Score global sur 100, moyenne à poids égal entre la note, les
            compétences techniques, les compétences humaines et les badges
            (calculé à partir de la dernière séance d'évaluation de chacun).
            Les stagiaires pas encore évalués apparaissent en bas, sans score.
          </p>
          <div style="overflow-x:auto;">
            <table class="comparatif-table">
              <thead>
                <tr>
                  <th>Stagiaire</th>
                  <th>Établissement</th>
                  <th>Période</th>
                  <th>Score global</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($lignes as $ligne): ?>
                  <?php
                    $estMeilleur = $ligne['id_stage'] === $meilleurIdStage;
                    $classesLigne = trim(
                        ($estMeilleur ? 'comparatif-row-meilleur ' : '') .
                        ($ligne['estReference'] ? 'comparatif-row-reference' : '')
                    );
                  ?>
                  <tr class="<?= $classesLigne ?: '' ?>">
                    <td>
                      <?php if ($estMeilleur): ?><span class="comparatif-medaille">🏆</span><?php endif; ?>
                      <?= htmlspecialchars($ligne['prenom'] . ' ' . $ligne['nom']) ?>
                      <?= $ligne['estReference'] ? ' <small>(cette fiche)</small>' : '' ?>
                    </td>
                    <td><?= htmlspecialchars($ligne['etablissement']) ?></td>
                    <td><?= htmlspecialchars($formatDateAffichage($ligne['date_debut'])) ?> → <?= htmlspecialchars($formatDateAffichage($ligne['date_fin'])) ?></td>
                    <td class="comparatif-score">
                      <?php if ($ligne['score'] !== null): ?>
                        <?= htmlspecialchars((string) $ligne['score']['score']) ?>/100
                      <?php else: ?>
                        <span class="comparatif-score-vide">Pas encore évalué</span>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </section>

        <?php if (!empty($courbesNote)): ?>
        <!-- Évolution comparée de la note globale -->
        <section class="form-col" style="grid-column: 1 / -1;">
          <h3>Évolution de la note globale</h3>
          <p class="fiche-empty" style="margin-bottom:10px;">
            Une courbe par stagiaire, alignée par numéro de séance (les dates
            d'évaluation diffèrent d'un stagiaire à l'autre).
          </p>
          <div class="comparatif-chart-wrap">
            <canvas id="comparatifChartNote"></canvas>
          </div>
        </section>
        <?php endif; ?>

      <?php endif; ?>
    </div>
  </main>

  <?php if (!empty($courbesNote)): ?>
  <script>
    (function() {
      if (typeof Chart === 'undefined') return;

      const labels = <?= json_encode($labelsSeances) ?>;
      const palette = ['#3d1550', '#b8862e', '#2f8f45', '#6a2f86', '#d9573f', '#1f6b8f', '#8a5aa8', '#c9a227'];
      const items = <?= json_encode($courbesNote) ?>;

      const datasets = items.map(function(item, i) {
        const couleur = palette[i % palette.length];
        return {
          label: item.nom,
          data: item.valeurs,
          borderColor: couleur,
          backgroundColor: couleur + '22',
          spanGaps: true,
          tension: 0.35,
          fill: false,
          borderWidth: 2.5,
          pointBackgroundColor: couleur,
          pointBorderColor: '#fff',
          pointBorderWidth: 1.5,
          pointRadius: 4,
          pointHoverRadius: 6,
        };
      });

      new Chart(document.getElementById('comparatifChartNote'), {
        type: 'line',
        data: { labels: labels, datasets: datasets },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          scales: {
            y: { min: 0, max: 20, ticks: { stepSize: 5 } },
            x: { ticks: { maxRotation: 0, autoSkip: true, maxTicksLimit: 8 } }
          },
          plugins: {
            legend: { display: true, position: 'bottom', labels: { boxWidth: 10, font: { size: 11 } } },
            tooltip: {
              callbacks: {
                label: function(item) {
                  const val = item.parsed.y === null ? 'Non notée' : (item.parsed.y + '/20');
                  return item.dataset.label + ' : ' + val;
                }
              }
            }
          }
        }
      });
    })();
  </script>
  <?php endif; ?>
</body>
</html>