<?php
require_once __DIR__ . "/bootstrap.php";
if (!isset($_SESSION["admin_id"])) {
    header("Location: admin.html");
    exit();
}
$configFile = __DIR__ . "/config.php";
if (!file_exists($configFile)) {
    exit("Falta configurar config.php");
}
$c = require $configFile;
try {
    $pdo = new PDO(
        "mysql:host={$c["host"]};dbname={$c["db"]};charset={$c["charset"]}",
        $c["user"],
        $c["pass"],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ],
    );
} catch (Throwable $e) {
    exit("No se pudo conectar con la base de datos.");
}
$names = [
    1 => "Liderazgo personal y comunitario",
    2 => "Pensamiento emprendedor",
    3 => "Diseño y validación de proyectos",
    4 => "Marketing y transformación digital",
    5 => "Gestión, sostenibilidad y cierre",
];
$rows = $pdo
    ->query(
        "SELECT p.*,COUNT(e.id) evidence_count FROM participants p LEFT JOIN evidences e ON e.participant_id=p.id GROUP BY p.id ORDER BY p.stage,p.name",
    )
    ->fetchAll();
$total = count($rows);
$graduated = count(array_filter($rows, fn($p) => $p["status"] === "Graduado"));
$pending = count(array_filter($rows, fn($p) => $p["status"] === "Pendiente"));
$esc = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, "UTF-8");
?><!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Informes · LEL</title><style>*{box-sizing:border-box}body{margin:0;background:#f4f7fb;color:#243b53;font-family:Arial,sans-serif}.wrap{max-width:1100px;margin:0 auto;padding:32px 22px}.head{display:flex;justify-content:space-between;align-items:center;gap:20px;margin-bottom:22px}.brand{font-size:28px;font-weight:800;color:#102a43}.muted{color:#627d98;font-size:13px}.actions{display:flex;gap:8px}button,a{border:0;border-radius:8px;padding:11px 14px;text-decoration:none;font-weight:700;cursor:pointer;background:#1976d2;color:#fff}.secondary{background:#e7f0fa;color:#145a9c}.card{background:#fff;border:1px solid #d9e2ec;border-radius:14px;padding:20px;margin-bottom:18px}.summary{display:flex;gap:42px;border-bottom:1px solid #d9e2ec;padding-bottom:18px;margin-bottom:10px}.summary b{display:block;font-size:28px;color:#102a43}.summary span{font-size:12px;color:#627d98}.table{width:100%;border-collapse:collapse;font-size:13px}.table th,.table td{text-align:left;padding:12px 8px;border-bottom:1px solid #edf2f7}.table th{font-size:11px;color:#627d98;text-transform:uppercase}.pill{padding:5px 9px;border-radius:99px;background:#e8f7f0;color:#19704e;font-size:11px;font-weight:700}.pending{background:#fff4d6;color:#8b6200}@media print{.actions{display:none!important}body{background:#fff}.wrap{padding:0}.card{border:0;padding:0}.head p{display:none}}
</style></head><body><main class="wrap"><div class="head"><div><div class="brand">LEL</div><h1>Informe del programa</h1><p class="muted">Generado con la información actual de la base de datos.</p></div><div class="actions"><a class="secondary" href="admin.html">Volver al dashboard</a><button onclick="window.print()">Imprimir / PDF</button></div></div><section class="card"><div class="summary"><div><b><?= $total ?></b><span>inscritos</span></div><div><b><?= $graduated ?></b><span>graduados</span></div><div><b><?= $pending ?></b><span>pendientes de revisión</span></div></div><table class="table"><thead><tr><th>Participante</th><th>Módulo</th><th>Asistencia</th><th>Evidencias</th><th>Estado</th></tr></thead><tbody><?php
if (
    !$rows
): ?><tr><td colspan="5">Todavía no hay participantes.</td></tr><?php endif;
foreach ($rows as $p): ?><tr><td><?= $esc(
    $p["name"],
) ?><br><small class="muted"><?= $esc(
    $p["email"],
) ?></small></td><td>Módulo <?= $esc(
    $p["stage"],
) ?><br><small class="muted"><?= $esc(
    $names[(int) $p["stage"]] ?? "",
) ?></small></td><td><?= $esc($p["attendance"]) ?>%</td><td><?= $esc(
    $p["evidence_count"],
) ?></td><td><span class="pill <?= $p["status"] === "Pendiente"
    ? "pending"
    : "" ?>"><?= $esc($p["status"]) ?></span></td></tr><?php endforeach;
?></tbody></table></section></main></body></html>
