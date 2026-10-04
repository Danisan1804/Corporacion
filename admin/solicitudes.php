<?php
require_once __DIR__ . "/bootstrap.php";
if (!isset($_SESSION["admin_id"])) {
    header("Location: admin.html");
    exit();
}
$c = require __DIR__ . "/config.php";
$pdo = new PDO(
    "mysql:host={$c["host"]};dbname={$c["db"]};charset={$c["charset"]}",
    $c["user"],
    $c["pass"],
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ],
);
$rows = $pdo
    ->query(
        "SELECT * FROM participants WHERE status='Pendiente' ORDER BY created_at DESC",
    )
    ->fetchAll();
$e = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, "UTF-8");
?><!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Solicitudes LEL</title><style>body{font-family:Arial;background:#f4f7fb;color:#243b53;margin:0}.wrap{max-width:950px;margin:auto;padding:32px 20px}.head{display:flex;justify-content:space-between;align-items:center}.brand{font-size:28px;font-weight:800;color:#102a43}a,button{border:0;border-radius:8px;padding:10px 13px;text-decoration:none;background:#1976d2;color:#fff;font-weight:700;cursor:pointer}.card{background:#fff;border:1px solid #d9e2ec;border-radius:14px;padding:20px;margin-top:20px}.request{border:1px solid #d9e2ec;border-radius:10px;padding:16px;margin:15px 0}.data{display:grid;grid-template-columns:repeat(2,1fr);gap:8px}.data div{padding:9px;background:#f8fafc;font-size:13px}.data b{display:block;color:#627d98;font-size:10px;text-transform:uppercase}.motivation{margin-top:12px;padding:12px;background:#eef7ff;font-size:13px}.reject{background:#c0392b;margin-left:8px}.muted{color:#627d98;font-size:13px}@media(max-width:650px){.head{display:block}.data{grid-template-columns:1fr}}</style></head><body><main class="wrap"><div class="head"><div><div class="brand">LEL</div><h1>Solicitudes de inscripción</h1><p class="muted">Revisa los datos e intereses antes de decidir.</p></div><div><a href="admin.html">Dashboard</a> <a href="informes.php">Informes</a></div></div><section class="card"><p><b><?= count(
    $rows,
) ?></b> solicitudes pendientes.</p><?php
foreach ($rows as $p): ?><article class="request" id="request-<?= $p[
    "id"
] ?>"><h2><?= $e($p["name"]) ?></h2><p class="muted">Recibida: <?= $e(
    $p["created_at"],
) ?></p><div class="data"><div><b>Correo</b><?= $e(
    $p["email"],
) ?></div><div><b>Teléfono</b><?= $e(
    $p["phone"],
) ?></div><div><b>Documento</b><?= $e(
    $p["document"],
) ?></div><div><b>Estado</b><?= $e(
    $p["status"],
) ?></div></div><div class="motivation"><b>Interés:</b><br><?= $e(
    $p["motivation"],
) ?></div><p><button onclick="decide(<?= $p[
    "id"
] ?>,'approve')">Aprobar ingreso</button><button class="reject" onclick="decide(<?= $p[
    "id"
] ?>,'reject')">Rechazar</button></p></article><?php endforeach;
if (!$rows): ?><p class="muted">No hay solicitudes pendientes.</p><?php endif;
?></section></main><script>async function decide(id,decision){if(!confirm(decision==='approve'?'¿Aprobar esta solicitud?':'¿Rechazar esta solicitud?'))return;let reason='';if(decision==='reject'){reason=prompt('Escribe el motivo del rechazo:','');if(!reason||reason.trim().length<5){alert('El motivo debe tener mínimo 5 caracteres.');return}}const r=await fetch('api.php?route=participants/'+id+'/'+decision,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({reason})});const d=await r.json();if(!r.ok){alert(d.error||'No se pudo actualizar');return}location.reload()}</script></body></html>
