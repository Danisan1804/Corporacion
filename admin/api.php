<?php
require_once __DIR__ . '/bootstrap.php';
date_default_timezone_set("America/Bogota");
header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: no-store");
header("Access-Control-Allow-Methods: GET, POST");
header("X-Content-Type-Options: nosniff");
header("Referrer-Policy: same-origin");
header("X-Frame-Options: DENY");

$configFile = __DIR__ . "/config.php";
if (!file_exists($configFile)) {
    http_response_code(500);
    echo json_encode(["error" => "Falta configurar config.php"]);
    exit();
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
    http_response_code(500);
    echo json_encode(["error" => "No se pudo conectar con la base de datos."]);
    exit();
}

// Compatibilidad con instalaciones que ya tenían la tabla creada antes de las fotos.
try {
    $columnCheck = $pdo->query("SHOW COLUMNS FROM meeting_attendance LIKE 'photo_path'");
    if (!$columnCheck->fetch()) {
        $pdo->exec("ALTER TABLE meeting_attendance ADD COLUMN photo_path VARCHAR(255) DEFAULT NULL AFTER present");
    }
} catch (Throwable $e) {
    // La migración SQL incluida en el proyecto queda como alternativa manual.
}

function out($data, $code = 200)
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit();
}
function input()
{
    return json_decode(file_get_contents("php://input"), true) ?: $_POST;
}
function clean($v, $max = 1000)
{
    return mb_substr(trim((string) ($v ?? "")), 0, $max);
}
function participant($p)
{
    $names = [
        1 => "Liderazgo personal y comunitario",
        2 => "Pensamiento emprendedor",
        3 => "Diseño y validación de proyectos",
        4 => "Marketing y transformación digital",
        5 => "Gestión, sostenibilidad y cierre",
    ];
    $p["module_name"] = $names[(int) $p["stage"]] ?? "Sin módulo";
    return $p;
}

function meetingIsClosed($meeting)
{
    $end = $meeting["end_time"] ?: $meeting["start_time"];
    return strtotime($meeting["meeting_date"] . " " . $end) < time();
}

function recalculateParticipantAttendance($pdo, $participantId)
{
    $moduleValues = [];
    for ($stage = 1; $stage <= 5; $stage++) {
        $q = $pdo->prepare(
            "SELECT COUNT(m.id) AS total,
                    COALESCE(SUM(CASE WHEN ma.present=1 THEN 1 ELSE 0 END),0) AS present
             FROM training_meetings m
             LEFT JOIN meeting_attendance ma
               ON ma.meeting_id=m.id AND ma.participant_id=?
             WHERE m.stage=?
               AND (m.meeting_date < CURDATE()
                    OR (m.meeting_date=CURDATE() AND COALESCE(m.end_time,m.start_time) <= CURTIME()))"
        );
        $q->execute([$participantId, $stage]);
        $stats = $q->fetch();
        $total = (int) $stats["total"];
        if ($total < 1) continue;
        $value = round(((int) $stats["present"] / $total) * 100, 2);
        $moduleValues[] = $value;
        $q = $pdo->prepare(
            "INSERT INTO module_attendance(participant_id,stage,attendance,marked_at)
             VALUES(?,?,?,?)
             ON DUPLICATE KEY UPDATE attendance=VALUES(attendance),marked_at=VALUES(marked_at)"
        );
        $q->execute([$participantId, $stage, $value, date("Y-m-d H:i:s")]);
    }
    $overall = count($moduleValues) ? round(array_sum($moduleValues) / count($moduleValues), 2) : 0;
    $q = $pdo->prepare("UPDATE participants SET attendance=?,updated_at=? WHERE id=?");
    $q->execute([$overall, date("Y-m-d H:i:s"), $participantId]);
    return $overall;
}

function moduleProgress($pdo, $participantId = null)
{
    $names = [
        1 => "Liderazgo personal y comunitario",
        2 => "Pensamiento emprendedor",
        3 => "Diseño y validación de proyectos",
        4 => "Marketing y transformación digital",
        5 => "Gestión, sostenibilidad y cierre",
    ];
    $result = [];
    for ($stage = 1; $stage <= 5; $stage++) {
        $q = $pdo->prepare(
            "SELECT COUNT(*) AS total,
                    COALESCE(SUM(CASE WHEN meeting_date < CURDATE()
                       OR (meeting_date=CURDATE() AND start_time <= CURTIME())
                       THEN 1 ELSE 0 END),0) AS started,
                    COALESCE(SUM(CASE WHEN meeting_date < CURDATE()
                       OR (meeting_date=CURDATE() AND COALESCE(end_time,start_time) <= CURTIME())
                       THEN 1 ELSE 0 END),0) AS closed
             FROM training_meetings WHERE stage=?"
        );
        $q->execute([$stage]);
        $stats = $q->fetch();
        $total = (int) $stats["total"];
        $closed = (int) $stats["closed"];
        $started = (int) $stats["started"];
        $attendance = null;
        if ($participantId !== null) {
            $q = $pdo->prepare("SELECT attendance FROM module_attendance WHERE participant_id=? AND stage=? LIMIT 1");
            $q->execute([$participantId, $stage]);
            $value = $q->fetchColumn();
            $attendance = $value === false ? null : (float) $value;
        }
        $status = "Pendiente";
        if ($total > 0 && $closed === $total) $status = "Completado";
        elseif ($started > 0) $status = "En curso";
        $result[] = [
            "stage" => $stage,
            "name" => $names[$stage],
            "total_meetings" => $total,
            "closed_meetings" => $closed,
            "progress" => $total ? round(($closed / $total) * 100) : 0,
            "attendance" => $attendance,
            "status" => $status,
        ];
    }
    return $result;
}

function saveAttendancePhoto($participantId, $meetingId)
{
    if (empty($_FILES["photo"]) || $_FILES["photo"]["error"] !== UPLOAD_ERR_OK) {
        out(["error" => "Debes adjuntar una foto para registrar la asistencia."], 400);
    }
    $file = $_FILES["photo"];
    if ((int) $file["size"] > 5 * 1024 * 1024) {
        out(["error" => "La foto no puede superar los 5 MB."], 400);
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file["tmp_name"]);
    $extensions = ["image/jpeg" => "jpg", "image/png" => "png", "image/webp" => "webp"];
    if (!isset($extensions[$mime])) {
        out(["error" => "Solo se permiten fotos JPG, PNG o WEBP."], 400);
    }
    $directory = __DIR__ . "/uploads/attendance";
    if (!is_dir($directory) && !mkdir($directory, 0755, true)) {
        out(["error" => "No se pudo preparar el almacenamiento de la foto."], 500);
    }
    $filename = $participantId . "_" . $meetingId . "_" . bin2hex(random_bytes(8)) . "." . $extensions[$mime];
    $target = $directory . "/" . $filename;
    if (!move_uploaded_file($file["tmp_name"], $target)) {
        out(["error" => "No se pudo guardar la foto de asistencia."], 500);
    }
    return "uploads/attendance/" . $filename;
}

function participantSession()
{
    if (!isset($_SESSION["participant_id"])) {
        out(["error" => "Debes iniciar sesión como participante."], 401);
    }
    return (int) $_SESSION["participant_id"];
}

$route = trim($_GET["route"] ?? "", "/");
$method = $_SERVER["REQUEST_METHOD"];
if ($route === "health") {
    out(["ok" => true]);
}
if ($method === "GET" && $route === "csrf") {
    out(["token" => csrfToken()]);
}

if ($route === "login" && $method === "POST") {
    if (!loginAttempt("admin")) out(["error" => "Demasiados intentos. Intenta nuevamente en 15 minutos."], 429);
    $d = input();
    $user = clean($d["username"] ?? "", 80);
    $pass = (string) ($d["password"] ?? "");
    $q = $pdo->prepare("SELECT * FROM admin_users WHERE username=? LIMIT 1");
    $q->execute([$user]);
    $admin = $q->fetch();
    if (!$admin || !password_verify($pass, $admin["password_hash"])) {
        loginAttempt("admin");
        out(["error" => "Usuario o contraseña incorrectos."], 401);
    }
    loginAttempt("admin", true);
    session_regenerate_id(true);
    csrfToken();
    $_SESSION["admin_id"] = $admin["id"];
    $_SESSION["admin_username"] = $admin["username"];
    out(["ok" => true, "username" => $admin["username"]]);
}
if ($route === "logout" && $method === "POST") {
    requireCsrfToken();
    $_SESSION = [];
    session_destroy();
    out(["ok" => true]);
}
if ($route === "me" && $method === "GET") {
    out([
        "authenticated" => isset($_SESSION["admin_id"]),
        "username" => $_SESSION["admin_username"] ?? null,
    ]);
}
if ($route === "participant-login" && $method === "POST") {
    if (!loginAttempt("participant")) out(["error" => "Demasiados intentos. Intenta nuevamente en 15 minutos."], 429);
    $d = input();
    $username = strtolower(clean($d["email"] ?? "", 180));
    // Evita que espacios agregados al copiar las credenciales hagan fallar el acceso.
    $password = trim((string) ($d["password"] ?? ""));
    $q = $pdo->prepare(
        "SELECT a.*,p.name,p.email,p.status,p.stage FROM participant_accounts a JOIN participants p ON p.id=a.participant_id WHERE LOWER(a.username)=? LIMIT 1",
    );
    $q->execute([$username]);
    $account = $q->fetch();
    if (!$account || !password_verify($password, $account["password_hash"])) {
        loginAttempt("participant");
        out(["error" => "Correo o contraseña incorrectos."], 401);
    }
    if (in_array($account["status"], ["Retirado", "Finalizado"], true)) {
        out(["error" => "Este acceso no está habilitado actualmente."], 403);
    }
    loginAttempt("participant", true);
    session_regenerate_id(true);
    csrfToken();
    $_SESSION["participant_id"] = (int) $account["participant_id"];
    $_SESSION["participant_email"] = $account["email"];
    $q = $pdo->prepare(
        "UPDATE participant_accounts SET last_login_at=? WHERE id=?",
    );
    $q->execute([date("Y-m-d H:i:s"), $account["id"]]);
    out(["ok" => true, "name" => $account["name"]]);
}
if ($route === "participant-logout" && $method === "POST") {
    requireCsrfToken();
    unset($_SESSION["participant_id"], $_SESSION["participant_email"]);
    out(["ok" => true]);
}
if ($route === "participant-me" && $method === "GET") {
    $participantId = participantSession();
    $q = $pdo->prepare("SELECT * FROM participants WHERE id=?");
    $q->execute([$participantId]);
    $p = $q->fetch();
    if (!$p) {
        out(["error" => "Participante no encontrado."], 404);
    }
    $q = $pdo->prepare(
        "SELECT stage,attendance,marked_at FROM module_attendance WHERE participant_id=? ORDER BY stage",
    );
    $q->execute([$participantId]);
    $attendance = $q->fetchAll();
    $q = $pdo->prepare(
        "SELECT id,stage,title,description,link,created_at FROM evidences WHERE participant_id=? ORDER BY created_at DESC",
    );
    $q->execute([$participantId]);
    $evidences = $q->fetchAll();
    $q = $pdo->prepare(
        "SELECT m.id,m.stage,m.title,m.meeting_date,m.start_time,m.end_time,m.location,m.notes,
                COALESCE(ma.present,0) AS present, ma.photo_path
         FROM training_meetings m
         LEFT JOIN meeting_attendance ma ON ma.meeting_id=m.id AND ma.participant_id=?
         WHERE m.stage=? ORDER BY m.meeting_date,m.start_time"
    );
    $q->execute([$participantId, (int) $p["stage"]]);
    $meetings = $q->fetchAll();
    foreach ($meetings as &$meeting) {
        $end = $meeting["end_time"] ?: $meeting["start_time"];
        $openAt = strtotime($meeting["meeting_date"] . " " . $end) - 1800;
        $closeAt = strtotime($meeting["meeting_date"] . " " . $end) + 1800;
        $now = time();
        $meeting["window_status"] = $meeting["present"] ? "Registrada" : ($now < $openAt ? "Próximamente" : ($now <= $closeAt ? "Abierta" : "Cerrada"));
        $meeting["photo_url"] = $meeting["photo_path"] ? "/admin/" . $meeting["photo_path"] : null;
        unset($meeting["photo_path"]);
    }
    out([
        "participant" => participant($p),
        "attendance" => $attendance,
        "evidences" => $evidences,
        "modules" => moduleProgress($pdo, $participantId),
        "meetings" => $meetings,
    ]);
}
if ($route === "participant/evidence" && $method === "POST") {
    $participantId = participantSession();
    $d = input();
    $stage = (int) ($d["stage"] ?? 0);
    $title = clean($d["title"] ?? "", 160);
    if ($stage < 1 || $stage > 5 || strlen($title) < 3) {
        out(["error" => "Módulo o nombre de evidencia no válido."], 400);
    }
    $q = $pdo->prepare("SELECT stage FROM participants WHERE id=?");
    $q->execute([$participantId]);
    $p = $q->fetch();
    if (!$p || $stage !== (int) $p["stage"]) {
        out(["error" => "Solo puedes entregar evidencia del módulo actual."], 400);
    }
    $q = $pdo->prepare(
        "INSERT INTO evidences(participant_id,stage,title,description,link,created_at) VALUES(?,?,?,?,?,?)",
    );
    $q->execute([
        $participantId,
        $stage,
        $title,
        clean($d["description"] ?? "", 1000),
        clean($d["link"] ?? "", 500),
        date("Y-m-d H:i:s"),
    ]);
    out(["ok" => true, "message" => "Evidencia guardada."], 201);
}
if ($route === "participant/attendance" && $method === "POST") {
    out(["error" => "Selecciona un encuentro y adjunta una foto para registrar la asistencia."], 400);
}
if ($route === "participant/evidence" && $method === "POST") {
    requireCsrfToken();
}
if (preg_match('#^participant/meetings/(\\d+)/attendance$#', $route, $m) && $method === "POST") {
    requireCsrfToken();
    $participantId = participantSession();
    $meetingId = (int) $m[1];
    $q = $pdo->prepare("SELECT * FROM training_meetings WHERE id=?");
    $q->execute([$meetingId]);
    $meeting = $q->fetch();
    if (!$meeting) out(["error" => "Encuentro no encontrado."], 404);
    $q = $pdo->prepare("SELECT stage FROM participants WHERE id=?");
    $q->execute([$participantId]);
    $p = $q->fetch();
    if (!$p || (int) $p["stage"] !== (int) $meeting["stage"]) {
        out(["error" => "Este encuentro no corresponde a tu módulo actual."], 400);
    }
    $end = $meeting["end_time"] ?: $meeting["start_time"];
    $openAt = strtotime($meeting["meeting_date"] . " " . $end) - 1800;
    $closeAt = strtotime($meeting["meeting_date"] . " " . $end) + 1800;
    if (time() < $openAt) out(["error" => "La asistencia se habilita 30 minutos antes de finalizar el encuentro."], 400);
    if (time() > $closeAt) out(["error" => "El plazo para registrar esta asistencia ya terminó."], 400);
    $q = $pdo->prepare("SELECT id FROM evidences WHERE participant_id=? AND stage=? LIMIT 1");
    $q->execute([$participantId, (int) $meeting["stage"]]);
    if (!$q->fetch()) out(["error" => "Primero debes registrar una evidencia del módulo."], 400);
    $photoPath = saveAttendancePhoto($participantId, $meetingId);
    $now = date("Y-m-d H:i:s");
    $q = $pdo->prepare(
        "INSERT INTO meeting_attendance(meeting_id,participant_id,present,photo_path,marked_at,marked_by)
         VALUES(?,?,1,?,?,NULL)
         ON DUPLICATE KEY UPDATE present=1,photo_path=VALUES(photo_path),marked_at=VALUES(marked_at),marked_by=NULL"
    );
    $q->execute([$meetingId, $participantId, $photoPath, $now]);
    $overall = recalculateParticipantAttendance($pdo, $participantId);
    out(["ok" => true, "attendance" => $overall, "message" => "Asistencia registrada con la foto."]);
}
// La inscripción pública no requiere sesión; se guarda como solicitud.
if ($method === "POST" && $route === "participants") {
    $ipHash = hash(
        "sha256",
        (string) ($_SERVER["REMOTE_ADDR"] ?? "unknown") .
            "|LEL-registration-limit",
    );
    $nowDate = date("Y-m-d H:i:s");
    $q = $pdo->prepare("SELECT * FROM registration_limits WHERE ip_hash=?");
    $q->execute([$ipHash]);
    $limit = $q->fetch();
    if (
        $limit &&
        time() - strtotime($limit["window_started"]) < 600 &&
        (int) $limit["attempts"] >= 5
    ) {
        out(
            [
                "error" =>
                    "Has enviado varias solicitudes. Intenta nuevamente en unos minutos.",
            ],
            429,
        );
    }
    if (!$limit) {
        $q = $pdo->prepare(
            "INSERT INTO registration_limits(ip_hash,window_started,attempts) VALUES(?,?,1)",
        );
        $q->execute([$ipHash, $nowDate]);
    } elseif (time() - strtotime($limit["window_started"]) >= 600) {
        $q = $pdo->prepare(
            "UPDATE registration_limits SET window_started=?,attempts=1 WHERE ip_hash=?",
        );
        $q->execute([$nowDate, $ipHash]);
    } else {
        $q = $pdo->prepare(
            "UPDATE registration_limits SET attempts=attempts+1 WHERE ip_hash=?",
        );
        $q->execute([$ipHash]);
    }
    $d = input();
    $name = clean($d["name"], 120);
    $email = strtolower(clean($d["email"], 180));
    if (strlen($name) < 3 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        out(["error" => "Nombre o correo no válido."], 400);
    }
    $q = $pdo->prepare(
        "SELECT name,status,rejection_reason FROM applications WHERE LOWER(email)=? OR LOWER(name)=? ORDER BY id DESC LIMIT 1",
    );
    $q->execute([$email, strtolower($name)]);
    $existing = $q->fetch();
    if ($existing) {
        if ($existing["status"] === "Pendiente") {
            out(
                [
                    "error" =>
                        "Ya existe una solicitud en revisión para esta cuenta.",
                ],
                409,
            );
        }
        if ($existing["status"] === "Rechazada") {
            out(
                [
                    "error" =>
                        "Esta solicitud fue rechazada. Motivo: " .
                        ($existing["rejection_reason"] ?:
                            "El administrador no indicó un motivo."),
                ],
                409,
            );
        }
        if ($existing["status"] === "Aprobada") {
            out(
                [
                    "error" =>
                        "Ya existe una solicitud aprobada con estos datos.",
                ],
                409,
            );
        }
    }
    $q = $pdo->prepare(
        "SELECT id FROM participants WHERE LOWER(email)=? OR LOWER(name)=? LIMIT 1",
    );
    $q->execute([$email, strtolower($name)]);
    if ($q->fetch()) {
        out(
            [
                "error" =>
                    "Ya existe un participante registrado con estos datos.",
            ],
            409,
        );
    }
    $now = date("Y-m-d H:i:s");
    $q = $pdo->prepare(
        "INSERT INTO applications(name,email,phone,document,motivation,status,rejection_reason,created_at,updated_at) VALUES(?,?,?,?,?,'Pendiente','',?,?)",
    );
    $q->execute([
        $name,
        $email,
        clean($d["phone"], 40),
        clean($d["document"], 40),
        clean($d["motivation"], 500),
        $now,
        $now,
    ]);
    $id = $pdo->lastInsertId();
    out(
        [
            "ok" => true,
            "message" => "Solicitud enviada. Queda pendiente de aprobación.",
            "id" => $id,
        ],
        201,
    );
}
if (!isset($_SESSION["admin_id"])) {
    out(["error" => "Debes iniciar sesión como administrador."], 401);
}
if ($method === "POST") requireCsrfToken();

if ($method === "GET" && $route === "applications") {
    $rows = $pdo
        ->query("SELECT * FROM applications ORDER BY created_at DESC")
        ->fetchAll();
    out(["applications" => $rows]);
}
if ($method === "GET" && $route === "participants") {
    $rows = $pdo
        ->query("SELECT * FROM participants ORDER BY created_at DESC")
        ->fetchAll();
    out(["participants" => array_map("participant", $rows)]);
}
if ($method === "GET" && $route === "dashboard") {
    $total = (int) $pdo
        ->query("SELECT COUNT(*) FROM participants")
        ->fetchColumn();
    $active = (int) $pdo
        ->query(
            "SELECT COUNT(*) FROM participants WHERE status IN ('Matriculado','Activo')",
        )
        ->fetchColumn();
    $graduated = (int) $pdo
        ->query("SELECT COUNT(*) FROM participants WHERE status='Graduado'")
        ->fetchColumn();
    $pending = (int) $pdo
        ->query("SELECT COUNT(*) FROM applications WHERE status='Pendiente'")
        ->fetchColumn();
    $low = (int) $pdo
        ->query(
            "SELECT COUNT(*) FROM participants WHERE attendance<80 AND attendance>0",
        )
        ->fetchColumn();
    $meetingsDone = (int) $pdo
        ->query(
            "SELECT COUNT(*) FROM training_meetings
             WHERE meeting_date < CURDATE()
                OR (meeting_date=CURDATE() AND COALESCE(end_time,start_time) <= CURTIME())",
        )
        ->fetchColumn();
    $st = [];
    for ($i = 1; $i <= 5; $i++) {
        $q = $pdo->prepare("SELECT COUNT(*) FROM participants WHERE stage=?");
        $q->execute([$i]);
        $st[(string) $i] = (int) $q->fetchColumn();
    }
    out([
        "total" => $total,
        "active" => $active,
        "graduated" => $graduated,
        "pending" => $pending,
        "low_attendance" => $low,
        "meetings_done" => $meetingsDone,
        "by_stage" => $st,
        "module_summary" => moduleProgress($pdo),
    ]);
}
if ($method === "GET" && $route === "report") {
    $rows = $pdo
        ->query("SELECT * FROM participants ORDER BY stage,name")
        ->fetchAll();
    foreach ($rows as &$row) {
        $row = participant($row);
        $q = $pdo->prepare(
            "SELECT COUNT(*) FROM evidences WHERE participant_id=?",
        );
        $q->execute([$row["id"]]);
        $row["evidence_count"] = (int) $q->fetchColumn();
    }
    out([
        "generated_at" => date("c"),
        "total" => count($rows),
        "participants" => $rows,
    ]);
}
if ($method === "GET" && $route === "meetings") {
    $meetings = $pdo->query(
        "SELECT m.*, COUNT(CASE WHEN ma.present=1 THEN 1 END) AS present_count,
                COUNT(ma.participant_id) AS marked_count
         FROM training_meetings m
         LEFT JOIN meeting_attendance ma ON ma.meeting_id=m.id
         GROUP BY m.id ORDER BY m.meeting_date DESC, m.start_time DESC"
    )->fetchAll();
    foreach ($meetings as &$meeting) {
        $q = $pdo->prepare(
            "SELECT p.id,p.name,p.email,COALESCE(ma.present,0) AS present,
                    CASE WHEN ma.participant_id IS NULL THEN 0 ELSE 1 END AS marked,
                    ma.photo_path
             FROM participants p
             LEFT JOIN meeting_attendance ma ON ma.participant_id=p.id AND ma.meeting_id=?
             WHERE p.status NOT IN ('Retirado','Finalizado') ORDER BY p.name"
        );
        $q->execute([(int) $meeting["id"]]);
        $meeting["participants"] = $q->fetchAll();
        foreach ($meeting["participants"] as &$member) {
            $member["photo_url"] = $member["photo_path"] ? "/admin/" . $member["photo_path"] : null;
            unset($member["photo_path"]);
        }
    }
    out(["meetings" => $meetings]);
}
if ($method === "POST" && $route === "meetings") {
    $d = input();
    $title = clean($d["title"] ?? "", 160);
    $stage = (int) ($d["stage"] ?? 1);
    $date = clean($d["meeting_date"] ?? "", 10);
    $start = clean($d["start_time"] ?? "", 5);
    $end = clean($d["end_time"] ?? "", 5);
    if (strlen($title) < 3 || $stage < 1 || $stage > 5 || !preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $date) || !preg_match('/^\\d{2}:\\d{2}$/', $start) || !preg_match('/^\\d{2}:\\d{2}$/', $end)) {
        out(["error" => "Completa el título, módulo, fecha, hora de inicio y hora de cierre del encuentro."], 400);
    }
    $q = $pdo->prepare(
        "INSERT INTO training_meetings(title,stage,meeting_date,start_time,end_time,location,notes,created_by,created_at) VALUES(?,?,?,?,?,?,?,?,?)"
    );
    $q->execute([
        $title, $stage, $date, $start, $end,
        clean($d["location"] ?? "", 180), clean($d["notes"] ?? "", 1000),
        $_SESSION["admin_id"], date("Y-m-d H:i:s")
    ]);
    out(["ok" => true, "id" => $pdo->lastInsertId()], 201);
}
if (preg_match('#^meetings/(\\d+)/attendance$#', $route, $m) && $method === "POST") {
    $d = input();
    $meetingId = (int) $m[1];
    $participantId = (int) ($d["participant_id"] ?? 0);
    $present = !empty($d["present"]) ? 1 : 0;
    $q = $pdo->prepare("SELECT id FROM training_meetings WHERE id=?");
    $q->execute([$meetingId]);
    if (!$q->fetch()) out(["error" => "Encuentro no encontrado."], 404);
    $q = $pdo->prepare("SELECT id FROM participants WHERE id=?");
    $q->execute([$participantId]);
    if (!$q->fetch()) out(["error" => "Participante no encontrado."], 404);
    $q = $pdo->prepare(
        "INSERT INTO meeting_attendance(meeting_id,participant_id,present,marked_at,marked_by) VALUES(?,?,?,?,?)
         ON DUPLICATE KEY UPDATE present=VALUES(present),marked_at=VALUES(marked_at),marked_by=VALUES(marked_by)"
    );
    $q->execute([$meetingId, $participantId, $present, date("Y-m-d H:i:s"), $_SESSION["admin_id"]]);
    $attendance = recalculateParticipantAttendance($pdo, $participantId);
    out(["ok" => true, "attendance" => $attendance]);
}
if (
    preg_match('#^participants/(\d+)/evidence$#', $route, $m) &&
    $method === "GET"
) {
    $q = $pdo->prepare(
        "SELECT * FROM evidences WHERE participant_id=? ORDER BY created_at DESC",
    );
    $q->execute([(int) $m[1]]);
    out(["evidences" => $q->fetchAll()]);
}
if (
    preg_match('#^participants/(\d+)/evidence$#', $route, $m) &&
    $method === "POST"
) {
    $d = input();
    $pid = (int) $m[1];
    $q = $pdo->prepare("SELECT stage FROM participants WHERE id=?");
    $q->execute([$pid]);
    $p = $q->fetch();
    if (!$p) {
        out(["error" => "Participante no encontrado."], 404);
    }
    $title = clean($d["title"], 160);
    if (strlen($title) < 3) {
        out(["error" => "Escribe el nombre de la evidencia."], 400);
    }
    $q = $pdo->prepare(
        "INSERT INTO evidences(participant_id,stage,title,description,link,created_at) VALUES(?,?,?,?,?,?)",
    );
    $q->execute([
        $pid,
        $p["stage"],
        $title,
        clean($d["description"], 1000),
        clean($d["link"], 500),
        date("Y-m-d H:i:s"),
    ]);
    out(["ok" => true], 201);
}
if (preg_match('#^participants/(\d+)/reset-password$#', $route, $m) && $method === "POST") {
    $pid = (int) $m[1];
    $q = $pdo->prepare("SELECT p.email,a.id FROM participants p JOIN participant_accounts a ON a.participant_id=p.id WHERE p.id=?");
    $q->execute([$pid]);
    $account = $q->fetch();
    if (!$account) out(["error" => "La cuenta del participante no existe."], 404);
    $temporaryPassword = "LEL-" . strtoupper(bin2hex(random_bytes(4)));
    $q = $pdo->prepare("UPDATE participant_accounts SET password_hash=? WHERE id=?");
    $q->execute([password_hash($temporaryPassword, PASSWORD_DEFAULT), $account["id"]]);
    out(["ok" => true, "email" => $account["email"], "temporary_password" => $temporaryPassword]);
}
if (preg_match('#^participants/(\d+)$#', $route, $m) && $method === "POST") {
    $d = input();
    $pid = (int) $m[1];
    $q = $pdo->prepare("SELECT * FROM participants WHERE id=?");
    $q->execute([$pid]);
    $p = $q->fetch();
    if (!$p) {
        out(["error" => "Participante no encontrado."], 404);
    }
    $stage = isset($d["stage"]) ? (int) $d["stage"] : (int) $p["stage"];
    if ($stage > (int) $p["stage"]) {
        $progress = moduleProgress($pdo, $pid);
        $currentProgress = $progress[(int) $p["stage"] - 1] ?? null;
        if (!$currentProgress || $currentProgress["status"] !== "Completado") {
            out(["error" => "El módulo actual todavía no está completado según las fechas de sus encuentros."], 400);
        }
        $q = $pdo->prepare(
            "SELECT COUNT(*) FROM evidences WHERE participant_id=? AND stage=?",
        );
        $q->execute([$pid, $p["stage"]]);
        if (!(int) $q->fetchColumn()) {
            out(
                [
                    "error" =>
                        "Antes de avanzar registra al menos una evidencia del módulo actual.",
                ],
                400,
            );
        }
    }
    $allowed = [
        "stage",
        "status",
        "attendance",
        "deliverables_done",
        "deliverables_total",
    ];
    $set = [];
    $vals = [];
    foreach ($allowed as $key) {
        if (array_key_exists($key, $d)) {
            $set[] = "$key=?";
            $vals[] = $d[$key];
        }
    }
    if (!$set) {
        out(["error" => "No hay cambios para guardar."], 400);
    }
    $set[] = "updated_at=?";
    $vals[] = date("Y-m-d H:i:s");
    $vals[] = $pid;
    $q = $pdo->prepare(
        "UPDATE participants SET " . implode(",", $set) . " WHERE id=?",
    );
    $q->execute($vals);
    $q = $pdo->prepare("SELECT * FROM participants WHERE id=?");
    $q->execute([$pid]);
    out(["participant" => participant($q->fetch())]);
}
if (
    preg_match('#^applications/(\d+)/(approve|reject)$#', $route, $m) &&
    $method === "POST"
) {
    $d = input();
    $applicationId = (int) $m[1];
    $q = $pdo->prepare("SELECT * FROM applications WHERE id=?");
    $q->execute([$applicationId]);
    $application = $q->fetch();
    if (!$application || $application["status"] !== "Pendiente") {
        out(["error" => "La solicitud no está disponible para revisión."], 404);
    }
    $reason = $m[2] === "reject" ? clean($d["reason"] ?? "", 1000) : "";
    if ($m[2] === "reject" && strlen($reason) < 5) {
        out(["error" => "Escribe el motivo del rechazo."], 400);
    }
    if ($m[2] === "reject") {
        $q = $pdo->prepare(
            "UPDATE applications SET status='Rechazada',rejection_reason=?,reviewed_at=?,updated_at=? WHERE id=?",
        );
        $q->execute([$reason, date("Y-m-d H:i:s"), date("Y-m-d H:i:s"), $applicationId]);
        out(["ok" => true, "status" => "Rechazada"]);
    }
    $pdo->beginTransaction();
    try {
        $now = date("Y-m-d H:i:s");
        $temporaryPassword = "LEL-" . strtoupper(bin2hex(random_bytes(4)));
        $q = $pdo->prepare(
            "INSERT INTO participants(name,email,phone,document,motivation,stage,status,rejection_reason,created_at,updated_at) VALUES(?,?,?,?,?,1,'Matriculado','',?,?)",
        );
        $q->execute([
            $application["name"],
            $application["email"],
            $application["phone"],
            $application["document"],
            $application["motivation"],
            $now,
            $now,
        ]);
        $participantId = (int) $pdo->lastInsertId();
        $q = $pdo->prepare(
            "INSERT INTO participant_accounts(participant_id,username,password_hash,created_at) VALUES(?,?,?,?)",
        );
        $q->execute([
            $participantId,
            strtolower($application["email"]),
            password_hash($temporaryPassword, PASSWORD_DEFAULT),
            $now,
        ]);
        $q = $pdo->prepare(
            "UPDATE applications SET status='Aprobada',reviewed_at=?,updated_at=? WHERE id=?",
        );
        $q->execute([$now, $now, $applicationId]);
        $pdo->commit();
        out([
            "ok" => true,
            "status" => "Aprobada",
            "temporary_password" => $temporaryPassword,
            "email" => $application["email"],
        ]);
    } catch (Throwable $e) {
        $pdo->rollBack();
        out(["error" => "No se pudo crear el participante."], 500);
    }
}
out(["error" => "Ruta no encontrada."], 404);
