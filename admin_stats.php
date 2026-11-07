<?php
// admin_stats.php — statistics dashboard, admin role only
if (session_status() === PHP_SESSION_NONE) session_start();
if (($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: index.php');
    exit;
}
require 'db.php';

// run a query safely: if a column/table is missing, return null instead of crashing
function q($pdo, $sql) {
    try { return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC); }
    catch (Exception $ex) { return null; }
}
function one($pdo, $sql) {
    $r = q($pdo, $sql);
    return $r ? (int)array_values($r[0])[0] : 0;
}
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$totalUsers  = one($pdo, "SELECT COUNT(*) FROM users");
$aituUsers   = one($pdo, "SELECT COUNT(*) FROM users WHERE email LIKE '%@astanait.edu.kz'");
$activeUsers = one($pdo, "SELECT COUNT(DISTINCT user_id) FROM items");
$totalItems  = one($pdo, "SELECT COUNT(*) FROM items");
$lost        = one($pdo, "SELECT COUNT(*) FROM items WHERE type = 'lost'");
$found       = one($pdo, "SELECT COUNT(*) FROM items WHERE type = 'found'");
$withPhoto   = q($pdo, "SELECT COUNT(*) AS c FROM items WHERE photo IS NOT NULL AND photo <> ''");
$withPhoto   = $withPhoto ? (int)$withPhoto[0]['c'] : null;
$byStatus    = q($pdo, "SELECT status, COUNT(*) AS c FROM items GROUP BY status");
$byBlock     = q($pdo, "SELECT l.block AS block, COUNT(*) AS c FROM items i JOIN locations l ON i.location_id = l.location_id GROUP BY l.block ORDER BY c DESC LIMIT 6");
$last7       = one($pdo, "SELECT COUNT(*) FROM items WHERE created_at >= NOW() - INTERVAL 7 DAY");

function bars($rows, $labelKey) {
    if (!$rows) return '<p class="muted">Нет данных</p>';
    $max = max(array_column($rows, 'c')) ?: 1;
    $out = '';
    foreach ($rows as $r) {
        $w = round($r['c'] / $max * 100);
        $label = $r[$labelKey] === null || $r[$labelKey] === '' ? '—' : $r[$labelKey];
        $out .= '<div class="bar-row"><span class="bar-label">' . h($label) . '</span>'
              . '<div class="bar"><div class="fill" style="width:' . $w . '%"></div></div>'
              . '<span class="bar-val">' . (int)$r['c'] . '</span></div>';
    }
    return $out;
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Статистика · Бюро находок AITU</title>
<style>
  body{margin:0;font-family:"Segoe UI",Arial,sans-serif;background:#eef2f9;color:#1b2430}
  .wrap{max-width:900px;margin:30px auto;background:#fff;border-radius:16px;padding:28px;box-shadow:0 4px 20px rgba(0,0,0,.06)}
  h1{margin:0 0 4px;font-size:26px} .muted{color:#6b7785;font-size:14px}
  .top{display:flex;justify-content:space-between;align-items:center;margin-bottom:22px}
  .top a{color:#0071e3;text-decoration:none;font-weight:600}
  .grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:14px;margin-bottom:26px}
  .card{background:#f5f7fb;border-radius:12px;padding:16px}
  .num{font-size:34px;font-weight:700;color:#0e7c86} .lbl{font-size:14px;color:#4a5562}
  .red{color:#e4572e} .green{color:#2ba84a}
  h2{font-size:18px;margin:22px 0 10px}
  .bar-row{display:flex;align-items:center;gap:10px;margin:6px 0}
  .bar-label{width:110px;font-size:14px} .bar-val{width:36px;text-align:right;font-weight:600}
  .bar{flex:1;background:#eef2f9;border-radius:6px;height:18px;overflow:hidden}
  .fill{height:100%;background:#0e7c86;border-radius:6px}
</style>
</head>
<body>
<div class="wrap">
  <div class="top">
    <div><h1>📊 Статистика</h1><div class="muted">Доступно только администратору</div></div>
    <a href="index.php">← На главную</a>
  </div>

  <div class="grid">
    <div class="card"><div class="num"><?= $totalUsers ?></div><div class="lbl">Всего пользователей</div></div>
    <div class="card"><div class="num"><?= $aituUsers ?></div><div class="lbl">С почтой @astanait.edu.kz</div></div>
    <div class="card"><div class="num"><?= $activeUsers ?></div><div class="lbl">Активных (есть объявление)</div></div>
    <div class="card"><div class="num"><?= $totalItems ?></div><div class="lbl">Всего объявлений</div></div>
    <div class="card"><div class="num red"><?= $lost ?></div><div class="lbl">Потерянных</div></div>
    <div class="card"><div class="num green"><?= $found ?></div><div class="lbl">Найденных</div></div>
    <?php if ($withPhoto !== null): ?>
    <div class="card"><div class="num"><?= $withPhoto ?></div><div class="lbl">С фото</div></div>
    <?php endif; ?>
    <div class="card"><div class="num"><?= $last7 ?></div><div class="lbl">За последние 7 дней</div></div>
  </div>

  <h2>Потерянные и найденные</h2>
  <?= bars([['t'=>'Потерянные','c'=>$lost],['t'=>'Найденные','c'=>$found]], 't') ?>

  <?php if ($byBlock): ?>
  <h2>Где чаще всего теряют и находят (блок)</h2>
  <?= bars($byBlock, 'block') ?>
  <?php endif; ?>

  <?php if ($byStatus): ?>
  <h2>Объявления по статусу</h2>
  <?= bars($byStatus, 'status') ?>
  <?php endif; ?>
</div>
</body>
</html>
