<?php
// Main page of AITU Lost & Found. Design: Miras, front end: Sanzhar,
// database connection, map (locations table) and photo upload: Alikhan.
// AITU floor plans: Yuujiso, github.com/Yuujiso/aitumap (MIT License).
session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict']);
session_start();
require 'db.php';

$logged_in = isset($_SESSION['user_id']);
$error     = '';
$uploadDir = __DIR__ . '/uploads';

// Safe output (no XSS)
function e($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

// Third table: every room and place of AITU (for the map)
$locMap = [];
$blocks = [];
foreach ($pdo->query('SELECT code, block, floor, room, name FROM locations ORDER BY code') as $l) {
    $locMap[$l['code']] = ['block' => $l['block'], 'floor' => (int)$l['floor'], 'room' => $l['room'], 'name' => $l['name']];
    $blocks[$l['block']] = true;
}
$blocks = array_keys($blocks);
sort($blocks);

// Add a new post (only for logged-in users)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $type  = $_POST['type'] ?? '';
    $text  = trim($_POST['description'] ?? '');
    $code  = $_POST['location'] ?? '';
    $locId = null;
    $photo = null;

    if (!$logged_in) {
        $error = 'Войдите, чтобы опубликовать объявление.';
    } elseif (!in_array($type, ['lost', 'found'], true)) {
        $error = 'Неверный тип объявления.';
    } elseif ($text === '' || mb_strlen($text) > 1000) {
        $error = 'Опишите предмет (до 1000 символов).';
    } else {
        // The place must exist in the locations table
        $stmt = $pdo->prepare('SELECT location_id FROM locations WHERE code = ?');
        $stmt->execute([$code]);
        $locId = $stmt->fetchColumn();
        if (!$locId) {
            $error = 'Выберите место на карте.';
        }
    }

    // Photo upload (multimedia): only real images, max 3 MB, random file name
    if (!$error && !empty($_FILES['photo']['name'])) {
        $f       = $_FILES['photo'];
        $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        $info    = ($f['error'] === UPLOAD_ERR_OK) ? @getimagesize($f['tmp_name']) : false;

        if ($f['error'] !== UPLOAD_ERR_OK) {
            $error = 'Не удалось загрузить фото.';
        } elseif ($f['size'] > 3 * 1024 * 1024) {
            $error = 'Фото должно быть не больше 3 МБ.';
        } elseif (!$info || !isset($allowed[$info['mime']])) {
            $error = 'Можно загрузить только JPG, PNG или WEBP.';
        } else {
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
            $photo = bin2hex(random_bytes(16)) . '.' . $allowed[$info['mime']];
            if (!move_uploaded_file($f['tmp_name'], $uploadDir . '/' . $photo)) {
                $error = 'Не удалось сохранить фото.';
                $photo = null;
            }
        }
    }

    if (!$error) {
        // Prepared statement: protection against SQL injection
        $stmt = $pdo->prepare('INSERT INTO items (user_id, type, description, location_id, photo)
                               VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$_SESSION['user_id'], $type, $text, $locId, $photo]);
        header('Location: index.php?tab=' . $type . '&posted=1'); // avoid double posting on refresh
        exit;
    }
}

// Read posts from the database (mins = how many minutes ago, counted by MySQL)
function get_items($pdo, $type) {
    $stmt = $pdo->prepare('SELECT i.description, i.created_at, i.photo, u.full_name,
                                  l.code, l.block, l.floor, l.room, l.name,
                                  TIMESTAMPDIFF(MINUTE, i.created_at, NOW()) AS mins
                           FROM items i
                           JOIN users u ON u.user_id = i.user_id
                           LEFT JOIN locations l ON l.location_id = i.location_id
                           WHERE i.type = ? AND i.status = ?
                           ORDER BY i.created_at DESC');
    $stmt->execute([$type, 'open']);
    return $stmt->fetchAll();
}
$lostItems  = get_items($pdo, 'lost');
$foundItems = get_items($pdo, 'found');

$tab    = (($_GET['tab'] ?? '') === 'found') ? 'found' : 'lost';
$posted = isset($_GET['posted']);

// "5 мин назад" instead of the exact date
function ago($mins, $date) {
    $m = (int)$mins;
    if ($m < 1)     return 'только что';
    if ($m < 60)    return $m . ' мин назад';
    if ($m < 1440)  return floor($m / 60) . ' ч назад';
    if ($m < 10080) return floor($m / 1440) . ' дн назад';
    return date('d.m.Y', strtotime($date));
}

// First letter of the name for the avatar
function initial($name) {
    $name = trim((string)$name);
    return $name === '' ? '?' : mb_strtoupper(mb_substr($name, 0, 1));
}

// "C1.3.267 · 2 этаж" or "Столовая · C1.3 · 1 этаж"
function place($l) {
    if (empty($l['block'])) return '';
    if (!empty($l['room'])) return $l['code'] . ' · ' . $l['floor'] . ' этаж';
    return $l['name'] . ' · ' . $l['block'] . ' · ' . $l['floor'] . ' этаж';
}

// Floor plans (SVG files in the map folder)
function floor_svg($n) {
    $f = __DIR__ . '/map/floor' . $n . '.svg';
    return is_file($f) ? file_get_contents($f) : '<p class="empty">Нет файла map/floor' . $n . '.svg</p>';
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Бюро находок · AITU</title>
    <link rel="icon" href="data:image/jpeg;base64,/9j/4AAQSkZJRgABAQAAAQABAAD/2wCEAAQFBQkGCQkJCQkKCAkICgsLCgoLCwwKCwoLCgwMDAwNDQwMDAwMDw4PDAwNDw8PDw0OERERDhEQEBETERMREQ0BBAQECAYIBwgIBwgGCAYICAgHBwgICQcHBwcHCQoJCAgICAkKCQgIBggICQkJCgoJCQoICQgKCgoKCg4QDg4Od//CABEIAWgBaAMBIgACEQEDEQH/xADzAAEAAgMBAAAAAAAAAAAAAAAABQYCAwQBEAABAAUECwsICQUAAAAAAAACAAEDBBIFFiAyBxEiRVNkcoSjxOMQExUwM0JSVJGy0ggUIUZigoWSBiMxNEBzosLwQWFxseIRAAADBAgFAwUBAAAAAAAAAAABAhEUIFIHMUVihKHE4QMQEyEwBBJBFSIyQHJCEgABAgIFBg0EAQQDAAAAAAABAgMAEQQSQFGBFCExMnKxEBMVIDNBUmGRodHS8AUwNHEiI3CCwVNikhMBAAECBAMHBQEBAAAAAAAAAREAITFBUWFx8PEQIDBAgZGhULHB0eFwgP/aAAwDAQACAQMCAAACv4AAAAAAAAAAAAAAAAAAAAAFAv8AQK/X7+LBYAAAAAAAAAAAAAAAAAAAAAFAv9Ar9fv4sFgAAAAAAPOfF0jIAAAAANXjaPQAAAAACgX+gV+v38WCwAAAAAMcYPU28Xjkynuusz/VjvG4AAAMfGFf2c/H7Iy9X68k817OrwPQAAACgX+gV+v38WCwAAAANGuD0M9Ry5B4btO/JPbNW3uxDIAB5CS8Bz+6hy+gbpyu57fLM5Ovr8DIAAAoF/oFfr9/FgsAAA8PY/TGc3vvhzegCRyaZvL3sxDYAAAa9jxX+azw/L7wDR6B7Lw7NaUPL9mPozAAKBf6BX6/fxYLAAB5CzcZpRI48gDKazapI7MQzAAAAAARcVaOPn9g2WPL6A6+TuzTOR3Yh6AUC/0Cv1+/iwWAAACGj7RBcnvJs2TmLX0nX4GQAAAAAAADng7Jr0q06dXLltnsNvXiG0AAoF/oFfr9/FgsAAADRv04onLgcWXe4B3uAd7gHe4B3uAd7gHe4B3uAd7gHe4B3uAdvfBz23zrHV4AAAoF/oFfr9/FgsAAADTu04q4I/IAAAAAAAAAABPQM9v86x1+AAAKBf6BX6/fxYLAAAA07tOKuCPyAAAAAAAAAAAT0DPb/OsdfgAACgX+gV+v38WCwAAANO7Tirgj8gAAAAAAAAAAE9Az2/zrHX4AAAoF/oFfr9/FgsAAADTu04q4I/IAAAAAAAAAABPQM9v86x1+AAAKBf6BX6/fxYLAAAA07tOKucfZr4Msd0V1eutwjuccR6sbk68RrqOS5PPcCP3QOxZc+XRikXL746Udt9dmnk4/U7PQM9s86x1+AAAKBf6BX6/fxYLAAAA07tOKuYZo/KJ17unb5H792Jjzd2R1uPs1+8UHZozZ50d3N06/cYmX5/Ub7I8+fnNl26TRjI+PY/Lftedk9Az2TrHX4AAAoF/oFfr9/FgsAAADTu04q4I/IAAAAAAAAAABPQM9v86x1+AAAKBf6BX6/fxYLAAAA07tOKuCPyAAAAAAAAAAAT0DPb/OsdfgAACgX+gV+v8AQgF0m59ACfQAn0AJ/KvbcVoFb3gAAAAAAAAAAOTrrvdhKIBMa59ACfQAn0AJ/niJeqwMQLhOAAANurbh7aBVugAAAAAAAAAABXbFXZDDkE5pAAAS8RL1KCiBbZ0AABt1bcPbQKt0AAAAAAAAAAAK7Yq7IYcgnNIAACXiJepQUQLbOgAANurPH21I5Xd8ijhIo4SKOEijhIo4SKOEijhIo4SKOEijhIo4SNdkYruw0CW1AAAJeIl6lBRAts6AAAAAAAAAAAAAAAAAAAAAl4iXqUFEJdYLBEJcRCXEQlxEJcRCXEQlxEJcRCXEQlxEJcRCXEQlxEJcRCXEQlxEJcRCXEQlxEJcRCXEQlxES5WIH//aAAwDAQACAQMCAAAh8888888888888888888888o8888888888888888888888o8888888O888888s8888888o888888TAl88886Djc88888o88888HhBEC888yBBxC8888o8888gBBAB8888gJBBAJ888o888nBBSC8888888oBBAW88o8888nXC888888888DMb888o8888+CCCCCCCCCCCC08888o8888+BBBBBBBBBBBBV8888o8888+BBBBBBBBBBBBV8888o8888+BBBBBBBBBBBBV8888o8888+BBBBBBBBBBBBV8888o8888+WiAGJGJakKEWV8888o8888+DD1dJzCCtDAPV8888o8888+BBBBBBBBBBBBV8888o8888+BBBBBBBBBBBBV88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">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; }
        body { background: linear-gradient(180deg, #eaf1fb 0%, #f4f5f7 320px); color: #222;
               display: flex; flex-direction: column; align-items: center;
               padding: 40px 20px; min-height: 100vh; }
        .container { background: #ffffff; width: 100%; max-width: 560px; padding: 28px;
                     border-radius: 16px; box-shadow: 0 6px 24px rgba(0,0,0,0.07); height: fit-content; }

        /* Header */
        .header { text-align: center; margin-bottom: 18px; }
        .logo { width: 96px; height: 96px; border-radius: 18px; box-shadow: 0 4px 14px rgba(0,0,0,0.12); }
        h1 { font-size: 22px; font-weight: 700; margin-top: 8px; }
        .tagline { font-size: 13px; color: #888; margin-top: 4px; }

        /* Instruction */
        .help { margin-bottom: 16px; border: 1px solid #e6e6d4; background: #fafaf3; border-radius: 10px;
                padding: 10px 14px; font-size: 14px; }
        .help summary { cursor: pointer; font-weight: 600; color: #6b6b3a; list-style: none; }
        .help summary::-webkit-details-marker { display: none; }
        .help summary::after { content: " ▾"; }
        .help[open] summary::after { content: " ▴"; }
        .help ol { margin: 10px 0 8px 20px; color: #444; line-height: 1.5; }
        .help li { margin-bottom: 4px; }
        .pdf-link { display: inline-block; margin-top: 4px; padding: 6px 12px; background: #4444b0; color: #fff;
                    text-decoration: none; border-radius: 8px; font-size: 13px; }
        .pdf-link:hover { background: #36369a; }

        .userbar { display: flex; justify-content: space-between; align-items: center; gap: 10px;
                   font-size: 14px; color: #666; margin-bottom: 16px; padding: 10px 14px;
                   background: #f7f8fa; border-radius: 10px; }
        .userbar a { color: #0071e3; text-decoration: none; font-weight: 500; }
        .userbar a:hover { text-decoration: underline; }

        /* Messages */
        .error   { color: #b00020; background: #fdecee; padding: 10px 14px; border-radius: 10px;
                   font-size: 14px; margin-bottom: 12px; }
        .success { color: #1e7b3c; background: #e8f7ed; padding: 10px 14px; border-radius: 10px;
                   font-size: 14px; margin-bottom: 12px; animation: fadeIn .3s ease; }

        /* Tabs */
        .tabs { display: flex; gap: 8px; margin-bottom: 20px; background: #f1f3f5;
                padding: 4px; border-radius: 12px; }
        .tab-btn { flex: 1; padding: 10px; border: none; background: transparent; font-size: 14px;
                   font-weight: 500; color: #666; border-radius: 8px; cursor: pointer; transition: all 0.2s ease; }
        .tab-btn:hover { color: #222; }
        .tab-btn.active { background: #ffffff; color: #222; box-shadow: 0 2px 8px rgba(0,0,0,0.06); }
        .count { display: inline-block; min-width: 20px; color: #fff; font-size: 12px;
                 padding: 1px 7px; border-radius: 10px; margin-left: 4px; }
        .count-lost  { background: #e5484d; }
        .count-found { background: #30a46c; }

        .section { display: none; }
        .section.active { display: block; animation: fadeIn .25s ease; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(6px); } to { opacity: 1; transform: none; } }

        /* Form */
        .subtitle { color: #666; font-size: 14px; margin-bottom: 10px; }
        .label { display: block; font-size: 13px; font-weight: 600; color: #555; margin: 14px 0 6px; }
        textarea { width: 100%; height: 90px; padding: 12px; border: 1px solid #e1e3e6; border-radius: 10px;
                   font-size: 15px; resize: vertical; outline: none; transition: border-color .2s, box-shadow .2s; }
        textarea:focus, .room:focus { border-color: #0071e3; box-shadow: 0 0 0 3px rgba(0,113,227,0.12); outline: none; }

        /* Map picker button */
        .pick-btn { width: 100%; padding: 11px; border: 1px dashed #4444b0; background: #f3f3fd; color: #4444b0;
                    border-radius: 10px; font-size: 14px; font-weight: 600; cursor: pointer; }
        .pick-btn:hover { background: #e9e9fb; }
        .picked { margin-top: 8px; font-size: 13px; color: #4444b0; font-weight: 600; }

        /* Map window (AITU floor plans by Yuujiso, MIT) */
        .modal { position: fixed; inset: 0; background: rgba(20, 24, 33, .55); display: none;
                 align-items: center; justify-content: center; padding: 16px; z-index: 50; }
        .modal.open { display: flex; animation: fadeIn .2s ease; }
        .modal-box { background: #fff; border-radius: 16px; width: 100%; max-width: 1000px; max-height: 95vh;
                     overflow: auto; padding: 18px; box-shadow: 0 10px 40px rgba(0,0,0,.25); }
        .modal-head { display: flex; justify-content: space-between; align-items: center; gap: 10px; margin-bottom: 10px; }
        .modal-head h2 { font-size: 18px; }
        .close { border: none; background: #f1f3f5; width: 34px; height: 34px; border-radius: 50%; font-size: 18px; cursor: pointer; }
        .floor-tabs { display: flex; gap: 6px; }
        .floor-tabs button, .halls button { border: 1px solid #d6dde6; background: #fff; padding: 6px 14px;
                     border-radius: 20px; font-size: 13px; cursor: pointer; }
        .floor-tabs button.sel { background: #222; border-color: #222; color: #fff; }
        .halls button:hover, .floor-tabs button:hover { border-color: #0071e3; }
        .map-scroll { overflow-x: auto; border: 1px solid #e1e3e6; border-radius: 12px; background: #fff; margin-top: 10px; }
        .map-scroll .floor-plan { min-width: 640px; display: none; padding: 6px; }
        .map-scroll .floor-plan.active { display: block; }
        .map-hint { font-size: 13px; color: #666; margin-top: 10px; }
        .halls { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; margin-top: 8px; font-size: 13px; color: #666; }
        .map-foot { display: flex; justify-content: space-between; align-items: center; gap: 10px; margin-top: 12px; flex-wrap: wrap; }
        .map-info { font-weight: 700; color: #b45309; font-size: 15px; }
        .done { padding: 10px 22px; border: none; border-radius: 10px; background: #4444b0; color: #fff;
                font-weight: 600; cursor: pointer; }
        .done:disabled { opacity: .4; cursor: default; }
        .credit { font-size: 11px; color: #999; margin-top: 10px; }
        .credit a { color: #999; }
        .modal.view .pick-only { display: none; }
        .aitu-map{width:100%;height:auto;display:block}
        .aitu-map .bg{fill:#dfe4eb;stroke:#8a94a3}
        .aitu-map .label-huge{fill:#8a94a3!important}
        .aitu-map .label,.aitu-map .label-white{fill:#fff!important}
        .aitu-map .map-groups-walls path,.aitu-map .map-groups-walls polyline,.aitu-map .map-groups-walls polygon,.aitu-map .map-groups-walls rect,.aitu-map .map-groups-walls line{stroke:#8a94a3;fill:#dfe4eb}
        .aitu-map .map-groups-void path,.aitu-map .map-groups-void polyline,.aitu-map .map-groups-void polygon,.aitu-map .map-groups-void rect,.aitu-map .map-groups-void line{stroke:#8a94a3;fill:#fff}
        .aitu-map .map-groups-rooms g line,.aitu-map .map-groups-rooms g polygon,.aitu-map .map-groups-rooms g polyline{stroke:#8a94a3;fill:#4da2ff}
        .aitu-map .map-groups-rooms g path{fill:#fff!important;stroke:#fff;stroke-width:.1;pointer-events:none}
        .aitu-map .map-groups-rooms-vk g line,.aitu-map .map-groups-rooms-vk g polygon,.aitu-map .map-groups-rooms-vk g polyline{stroke:#8a94a3;fill:#61a166}
        .aitu-map .map-groups-rooms-vk g path{fill:#fff!important;pointer-events:none}
        .aitu-map .map-groups-gym path,.aitu-map .map-groups-gym line,.aitu-map .map-groups-gym polygon,.aitu-map .map-groups-gym polyline{stroke:#8a94a3;fill:#4da2ff}
        .aitu-map .map-groups-gym-pole path,.aitu-map .map-groups-gym-pole line,.aitu-map .map-groups-gym-pole polygon{stroke:#fff;stroke-width:.75;fill:none!important}
        .aitu-map .map-groups-coworking-atameken path,.aitu-map .map-groups-coworking-atameken polygon,.aitu-map .map-groups-coworking-atameken polyline{stroke:#8a94a3;fill:#61a166}
        .aitu-map .map-groups-techs polygon,.aitu-map .map-groups-techs polyline,.aitu-map .map-groups-escapes polygon,.aitu-map .map-groups-escapes polyline{stroke:#8a94a3;fill:#c9ced6;pointer-events:none}
        .aitu-map .map-groups-wcs polyline{stroke:#8a94a3;fill:#ac7ab6;pointer-events:none}
        .aitu-map .map-groups-stairs,.aitu-map .map-groups-stairs-fill{fill:#fff;pointer-events:none}
        .aitu-map .map-groups-stairs polygon,.aitu-map .map-groups-stairs polyline,.aitu-map .map-groups-stairs-fill polygon,.aitu-map .map-groups-stairs-fill polyline{stroke:#8a94a3}
        .aitu-map .map-groups-stairs g path:nth-child(1),.aitu-map .map-groups-stairs-fill g path:nth-child(1),.aitu-map .map-groups-techs-icon path:nth-child(1),.aitu-map .map-groups-wcs-icon path:nth-child(1){fill:#007aff}
        .aitu-map .map-groups-stairs g path:nth-child(2),.aitu-map .map-groups-stairs-fill g path:nth-child(2),.aitu-map .map-groups-techs-icon path:nth-child(2),.aitu-map .map-groups-wcs-icon path:nth-child(2),.aitu-map .map-groups-escapes-icon path:nth-child(2){fill:#fff}
        .aitu-map .map-groups-escapes-icon path:nth-child(1){fill:#009245}
        .aitu-map [data-loc]{cursor:pointer}
        .aitu-map [data-loc]:hover polygon,.aitu-map [data-loc]:hover polyline{opacity:.75}
        .aitu-map [data-loc].sel polygon,.aitu-map [data-loc].sel polyline,.aitu-map [data-loc].sel line{fill:#f59e0b!important;stroke:#b45309!important;stroke-width:2px}
        .aitu-map [data-loc="C1.1.OPEN_SPACE"] path { pointer-events: auto; }
        .aitu-map [data-loc].sel polygon, .aitu-map [data-loc].sel polyline { animation: pulse 1.2s ease-in-out infinite; }
        @keyframes pulse { 50% { opacity: .55; } }

        /* Photo */
        .file { display: block; width: 100%; padding: 10px; border: 1px dashed #c3cad5; border-radius: 10px;
                background: #fbfcfe; font-size: 13px; cursor: pointer; }
        .preview { display: none; margin-top: 8px; max-width: 100%; max-height: 180px; border-radius: 10px; }

        .btn-submit { width: 100%; margin-top: 14px; padding: 12px; color: #fff; border: none;
                      border-radius: 10px; font-size: 15px; font-weight: 600; cursor: pointer;
                      transition: background-color .2s, transform .1s, opacity .2s; }
        .btn-lost  { background-color: #0071e3; }
        .btn-lost:hover  { background-color: #005bb5; }
        .btn-found { background-color: #34c759; }
        .btn-found:hover { background-color: #2db14e; }
        .btn-submit:active { transform: scale(0.99); }
        .btn-submit:disabled { opacity: .6; cursor: wait; }

        .login-note { background: #f9fafb; border: 1px solid #ebebeb; padding: 12px 16px;
                      border-radius: 10px; font-size: 14px; color: #666; }
        .login-note a { color: #0071e3; }

        /* Search and block filter */
        .search { width: 100%; margin-top: 18px; padding: 10px 14px 10px 36px; border: 1px solid #e1e3e6;
                  border-radius: 10px; font-size: 14px; outline: none;
                  background: #fff url("data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%23999' stroke-width='2'><circle cx='11' cy='11' r='7'/><line x1='21' y1='21' x2='16.65' y2='16.65'/></svg>") no-repeat 12px center; }
        .search:focus { border-color: #0071e3; }
        .chips { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 10px; }
        .chip { border: 1px solid #d6dde6; background: #fff; padding: 5px 12px; border-radius: 20px;
                font-size: 13px; cursor: pointer; }
        .chip.sel { background: #222; border-color: #222; color: #fff; }

        /* List of posts */
        ul { list-style: none; margin-top: 14px; display: flex; flex-direction: column; gap: 10px; }
        li { display: flex; gap: 12px; align-items: flex-start; background: #f9fafb; border: 1px solid #ebebeb;
             padding: 12px 14px; border-radius: 10px; font-size: 15px; line-height: 1.4; word-break: break-word;
             transition: transform .15s, box-shadow .15s; }
        li:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,0.08); }
        #lost-section li  { border-left: 4px solid #e5484d; }
        #found-section li { border-left: 4px solid #30a46c; }
        .avatar { flex-shrink: 0; display: flex; align-items: center; justify-content: center;
                  width: 34px; height: 34px; border-radius: 50%; color: #fff; font-weight: 600; font-size: 15px; }
        #lost-section .avatar  { background: #e5484d; }
        #found-section .avatar { background: #30a46c; }
        .post { flex: 1; min-width: 0; }
        .thumb { display: block; margin-top: 8px; width: 100%; max-height: 200px; object-fit: cover; border-radius: 8px; }
        .place { display: block; width: fit-content; margin-top: 8px; border: none; cursor: pointer; font-size: 12px; font-weight: 600; color: #4444b0;
                 background: #ededfa; padding: 3px 9px; border-radius: 20px; }
        .place:hover { background: #dcdcf7; }
        .meta { display: block; margin-top: 6px; font-size: 12px; color: #888; }

        .empty { text-align: center; color: #999; font-size: 14px; padding: 24px 0; }
        .empty span { display: block; font-size: 36px; margin-bottom: 6px; }
        .no-results { display: none; }

        footer { margin-top: 20px; font-size: 12px; color: #999; text-align: center; }

        /* Wider screen: posts in two columns */
        @media (min-width: 900px) {
            .container { max-width: 820px; }
            ul { display: grid; grid-template-columns: 1fr 1fr; }
        }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <img class="logo" src="data:image/jpeg;base64,/9j/4AAQSkZJRgABAQAAAQABAAD/2wCEAAQFBQkGCQkJCQkKCAkICgsLCgoLCwwKCwoLCgwMDAwNDQwMDAwMDw4PDAwNDw8PDw0OERERDhEQEBETERMREQ0BBAQECAYIBwgIBwgGCAYICAgHBwgICQcHBwcHCQoJCAgICAkKCQgIBggICQkJCgoJCQoICQgKCgoKCg4QDg4Od//CABEIAWgBaAMBIgACEQEDEQH/xADzAAEAAgMBAAAAAAAAAAAAAAAABQYCAwQBEAABAAUECwsICQUAAAAAAAACAAEDBBIFFiAyBxEiRVNkcoSjxOMQExUwM0JSVJGy0ggUIUZigoWSBiMxNEBzosLwQWFxseIRAAADBAgFAwUBAAAAAAAAAAABAhEUIFIHMUVihKHE4QMQEyEwBBJBFSIyQHJCEgABAgIFBg0EAQQDAAAAAAABAgMAEQQSQFGBFCExMnKxEBMVIDNBUmGRodHS8AUwNHEiI3CCwVNikhMBAAECBAMHBQEBAAAAAAAAAREAITFBUWFx8PEQIDBAgZGhULHB0eFwgP/aAAwDAQACAQMCAAACv4AAAAAAAAAAAAAAAAAAAAAFAv8AQK/X7+LBYAAAAAAAAAAAAAAAAAAAAAFAv9Ar9fv4sFgAAAAAAPOfF0jIAAAAANXjaPQAAAAACgX+gV+v38WCwAAAAAMcYPU28Xjkynuusz/VjvG4AAAMfGFf2c/H7Iy9X68k817OrwPQAAACgX+gV+v38WCwAAAANGuD0M9Ry5B4btO/JPbNW3uxDIAB5CS8Bz+6hy+gbpyu57fLM5Ovr8DIAAAoF/oFfr9/FgsAAA8PY/TGc3vvhzegCRyaZvL3sxDYAAAa9jxX+azw/L7wDR6B7Lw7NaUPL9mPozAAKBf6BX6/fxYLAAB5CzcZpRI48gDKazapI7MQzAAAAAARcVaOPn9g2WPL6A6+TuzTOR3Yh6AUC/0Cv1+/iwWAAACGj7RBcnvJs2TmLX0nX4GQAAAAAAADng7Jr0q06dXLltnsNvXiG0AAoF/oFfr9/FgsAAADRv04onLgcWXe4B3uAd7gHe4B3uAd7gHe4B3uAd7gHe4B3uAdvfBz23zrHV4AAAoF/oFfr9/FgsAAADTu04q4I/IAAAAAAAAAABPQM9v86x1+AAAKBf6BX6/fxYLAAAA07tOKuCPyAAAAAAAAAAAT0DPb/OsdfgAACgX+gV+v38WCwAAANO7Tirgj8gAAAAAAAAAAE9Az2/zrHX4AAAoF/oFfr9/FgsAAADTu04q4I/IAAAAAAAAAABPQM9v86x1+AAAKBf6BX6/fxYLAAAA07tOKucfZr4Msd0V1eutwjuccR6sbk68RrqOS5PPcCP3QOxZc+XRikXL746Udt9dmnk4/U7PQM9s86x1+AAAKBf6BX6/fxYLAAAA07tOKuYZo/KJ17unb5H792Jjzd2R1uPs1+8UHZozZ50d3N06/cYmX5/Ub7I8+fnNl26TRjI+PY/Lftedk9Az2TrHX4AAAoF/oFfr9/FgsAAADTu04q4I/IAAAAAAAAAABPQM9v86x1+AAAKBf6BX6/fxYLAAAA07tOKuCPyAAAAAAAAAAAT0DPb/OsdfgAACgX+gV+v8AQgF0m59ACfQAn0AJ/KvbcVoFb3gAAAAAAAAAAOTrrvdhKIBMa59ACfQAn0AJ/niJeqwMQLhOAAANurbh7aBVugAAAAAAAAAABXbFXZDDkE5pAAAS8RL1KCiBbZ0AABt1bcPbQKt0AAAAAAAAAAAK7Yq7IYcgnNIAACXiJepQUQLbOgAANurPH21I5Xd8ijhIo4SKOEijhIo4SKOEijhIo4SKOEijhIo4SNdkYruw0CW1AAAJeIl6lBRAts6AAAAAAAAAAAAAAAAAAAAAl4iXqUFEJdYLBEJcRCXEQlxEJcRCXEQlxEJcRCXEQlxEJcRCXEQlxEJcRCXEQlxEJcRCXEQlxEJcRCXEQlxES5WIH//aAAwDAQACAQMCAAAh8888888888888888888888o8888888888888888888888o8888888O888888s8888888o888888TAl88886Djc88888o88888HhBEC888yBBxC8888o8888gBBAB8888gJBBAJ888o888nBBSC8888888oBBAW88o8888nXC888888888DMb888o8888+CCCCCCCCCCCC08888o8888+BBBBBBBBBBBBV8888o8888+BBBBBBBBBBBBV8888o8888+BBBBBBBBBBBBV8888o8888+BBBBBBBBBBBBV8888o8888+WiAGJGJakKEWV8888o8888+DD1dJzCCtDAPV8888o8888+BBBBBBBBBBBBV8888o8888+BBBBBBBBBBBBV88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" alt="Lost & Found logo">
        <h1>Бюро находок AITU</h1>
        <p class="tagline">Потеряли или нашли вещь в университете? Напишите здесь.</p>
    </div>

    <details class="help">
        <summary>📄 Как пользоваться сайтом</summary>
        <ol>
            <li>Сайт помогает студентам находить потерянные вещи в университете.</li>
            <li>Откройте вкладку <b>«Потерянные»</b>, опишите вещь, выберите на карте AITU кабинет, где вы её потеряли, и нажмите <b>«Опубликовать»</b>.</li>
            <li>Откройте вкладку <b>«Найденные»</b>, опишите вещь, отметьте на карте, где нашли, добавьте фото и нажмите <b>«Опубликовать»</b>. Так владелец сможет её найти.</li>
        </ol>
        <a class="pdf-link" href="instruction.pdf" target="_blank">Открыть инструкцию (PDF)</a>
    </details>

    <div class="userbar">
        <?php if ($logged_in): ?>
            <span>👤 Вы вошли как <b><?= e($_SESSION['name'] ?? '') ?></b></span>
           <?php if (($_SESSION['role'] ?? '') === 'admin'): ?><a href="admin_stats.php">📊 Статистика</a> · <?php endif; ?><a href="login.php?logout=1">Выйти</a>
        <?php else: ?>
            <span>Вы не вошли</span>
            <span><a href="login.php">Войти</a> · <a href="register.php">Регистрация</a></span>
        <?php endif; ?>
    </div>

    <?php if ($error): ?><p class="error">⚠️ <?= e($error) ?></p><?php endif; ?>
    <?php if ($posted): ?><p class="success">✅ Объявление опубликовано</p><?php endif; ?>

    <div class="tabs">
        <button class="tab-btn <?= $tab === 'lost' ? 'active' : '' ?>" onclick="switchTab('lost')">
            Потерянные <span class="count count-lost"><?= count($lostItems) ?></span>
        </button>
        <button class="tab-btn <?= $tab === 'found' ? 'active' : '' ?>" onclick="switchTab('found')">
            Найденные <span class="count count-found"><?= count($foundItems) ?></span>
        </button>
    </div>

    <?php foreach (['lost' => $lostItems, 'found' => $foundItems] as $type => $items): ?>
    <div id="<?= $type ?>-section" class="section <?= $tab === $type ? 'active' : '' ?>">
        <?php if ($logged_in): ?>
            <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="type" value="<?= $type ?>">
                <input type="hidden" name="location" value="">

                <p class="subtitle"><?= $type === 'lost' ? '🔴 Опишите потерянный предмет' : '🟢 Опишите найденный предмет' ?></p>
                <textarea name="description" maxlength="1000" required
                    placeholder="<?= $type === 'lost' ? 'Например: Потерял синий кошелек...' : 'Например: Нашел ключи от машины...' ?>"></textarea>

                <span class="label">📍 <?= $type === 'lost' ? 'Где потеряли?' : 'Где нашли?' ?></span>
                <button type="button" class="pick-btn" onclick="openPicker(this.form)">🗺️ Выбрать место на карте AITU</button>
                <p class="picked">Место не выбрано</p>

                <span class="label">📷 Фото предмета (необязательно, JPG/PNG/WEBP до 3 МБ)</span>
                <input class="file" type="file" name="photo" accept="image/jpeg,image/png,image/webp">
                <img class="preview" alt="Предпросмотр фото">

                <button class="btn-submit <?= $type === 'lost' ? 'btn-lost' : 'btn-found' ?>" type="submit">Опубликовать</button>
            </form>
        <?php else: ?>
            <p class="login-note"><a href="login.php">Войдите</a>, чтобы опубликовать объявление. Смотреть список можно без входа.</p>
        <?php endif; ?>

        <?php if (empty($items)): ?>
            <p class="empty"><span>📭</span>Пока нет объявлений</p>
        <?php else: ?>
            <input class="search" type="text" placeholder="Поиск по объявлениям..." oninput="applyFilters(this.parentElement)">
            <div class="chips">
                <button type="button" class="chip sel" data-block="">Все блоки</button>
                <?php foreach ($blocks as $name): ?>
                    <button type="button" class="chip" data-block="<?= e($name) ?>"><?= e($name) ?></button>
                <?php endforeach; ?>
            </div>
            <ul>
                <?php foreach ($items as $it): ?>
                    <li data-block="<?= e($it['block'] ?? '') ?>">
                        <span class="avatar"><?= e(initial($it['full_name'])) ?></span>
                        <div class="post">
                            <?= e($it['description']) ?>
                            <?php if (!empty($it['photo'])): ?>
                                <a href="uploads/<?= e($it['photo']) ?>" target="_blank">
                                    <img class="thumb" src="uploads/<?= e($it['photo']) ?>" alt="Фото предмета" loading="lazy">
                                </a>
                            <?php endif; ?>
                            <?php if (place($it) !== ''): ?>
                                <button type="button" class="place" onclick="openView('<?= e($it['code']) ?>')" title="Показать на карте">📍 <?= e(place($it)) ?></button>
                            <?php endif; ?>
                            <span class="meta"><?= e($it['full_name']) ?> · <?= e(ago($it['mins'], $it['created_at'])) ?></span>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
            <p class="empty no-results">Ничего не найдено</p>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
</div>

<!-- Map window: AITU floor plans (Yuujiso, github.com/Yuujiso/aitumap, MIT License) -->
<div class="modal" id="mapModal" onclick="if (event.target === this) closeMap()">
    <div class="modal-box">
        <div class="modal-head">
            <h2 id="mapTitle">Карта AITU</h2>
            <div class="floor-tabs">
                <button type="button" data-floor="1">1 этаж</button>
                <button type="button" data-floor="2">2 этаж</button>
                <button type="button" data-floor="3">3 этаж</button>
            </div>
            <button type="button" class="close" onclick="closeMap()" aria-label="Закрыть">✕</button>
        </div>
        <p class="map-hint pick-only">Нажмите на кабинет или зал. Кабинеты подписаны так же, как в университете.</p>
        <div class="map-scroll">
            <?php for ($n = 1; $n <= 3; $n++): ?>
                <div class="floor-plan" data-floor="<?= $n ?>"><?= floor_svg($n) ?></div>
            <?php endfor; ?>
        </div>
        <div class="halls pick-only">
            Не в кабинете? Коридор блока:
            <?php foreach ($blocks as $name): ?>
                <button type="button" data-hall="<?= e($name) ?>"><?= e($name) ?></button>
            <?php endforeach; ?>
        </div>
        <div class="map-foot">
            <span class="map-info" id="mapInfo">Ничего не выбрано</span>
            <button type="button" class="done pick-only" id="mapDone" disabled>Готово</button>
        </div>
        <p class="credit">Схема этажей: <a href="https://github.com/Yuujiso/aitumap" target="_blank">Yuujiso / aitumap</a> (MIT License)</p>
    </div>
</div>

<footer>AITU Lost &amp; Found · SE-2624 · 2026</footer>

<script>
    function switchTab(tab) {
        const buttons = document.querySelectorAll('.tab-btn');
        document.getElementById('lost-section').classList.toggle('active', tab === 'lost');
        document.getElementById('found-section').classList.toggle('active', tab === 'found');
        buttons[0].classList.toggle('active', tab === 'lost');
        buttons[1].classList.toggle('active', tab === 'found');
    }

    // Search + block filter for the list
    function applyFilters(section) {
        const q = section.querySelector('.search').value.toLowerCase();
        const chip = section.querySelector('.chip.sel');
        const block = chip ? chip.dataset.block : '';
        let shown = 0;
        section.querySelectorAll('li').forEach(li => {
            const ok = li.textContent.toLowerCase().includes(q) && (block === '' || li.dataset.block === block);
            li.style.display = ok ? '' : 'none';
            if (ok) shown++;
        });
        section.querySelector('.no-results').style.display = shown === 0 ? 'block' : 'none';
    }
    document.querySelectorAll('.chips').forEach(box => {
        box.querySelectorAll('.chip').forEach(c => c.addEventListener('click', () => {
            box.querySelectorAll('.chip').forEach(x => x.classList.remove('sel'));
            c.classList.add('sel');
            applyFilters(box.parentElement);
        }));
    });

    // ===== Map of AITU =====
    const LOCS   = <?= json_encode($locMap, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const modal  = document.getElementById('mapModal');
    const info   = document.getElementById('mapInfo');
    const done   = document.getElementById('mapDone');
    let activeForm = null;
    let current = null;
    let curFloor = 1;

    function label(code) {
        const l = LOCS[code];
        if (!l) return '';
        return l.room ? code + ' · ' + l.floor + ' этаж' : l.name + ' · ' + l.block + ' · ' + l.floor + ' этаж';
    }
    function showFloor(n) {
        curFloor = Number(n);
        modal.querySelectorAll('.floor-plan').forEach(p => p.classList.toggle('active', p.dataset.floor == n));
        modal.querySelectorAll('.floor-tabs button').forEach(b => b.classList.toggle('sel', b.dataset.floor == n));
    }
    function selectLoc(code) {
        modal.querySelectorAll('[data-loc].sel').forEach(x => x.classList.remove('sel'));
        current = LOCS[code] ? code : null;
        const el = modal.querySelector('[data-loc="' + code + '"]');
        if (el) el.classList.add('sel');
        info.textContent = current ? '📍 ' + label(current) : 'Ничего не выбрано';
        done.disabled = !current;
    }
    function openMap(view) {
        modal.classList.add('open');
        modal.classList.toggle('view', view);
        document.body.style.overflow = 'hidden';
    }
    function closeMap() {
        modal.classList.remove('open');
        document.body.style.overflow = '';
    }
    function openPicker(form) {
        activeForm = form;
        document.getElementById('mapTitle').textContent = 'Выберите место';
        const code = form.querySelector('[name=location]').value;
        showFloor(code && LOCS[code] ? LOCS[code].floor : curFloor);
        selectLoc(code);
        openMap(false);
    }
    function openView(code) {
        if (!LOCS[code]) return;
        document.getElementById('mapTitle').textContent = 'Где это было';
        showFloor(LOCS[code].floor);
        selectLoc(code);
        openMap(true);
        const el = modal.querySelector('[data-loc="' + code + '"]');
        if (el) setTimeout(() => el.scrollIntoView({ block: 'nearest', inline: 'center' }), 50);
    }

    modal.querySelectorAll('.floor-tabs button').forEach(b => b.addEventListener('click', () => showFloor(b.dataset.floor)));
    modal.querySelectorAll('.aitu-map [data-loc]').forEach(el => el.addEventListener('click', () => {
        if (!modal.classList.contains('view')) selectLoc(el.dataset.loc);
    }));
    modal.querySelectorAll('[data-hall]').forEach(b => b.addEventListener('click', () => {
        selectLoc(b.dataset.hall + '.F' + curFloor + '.HALL');
    }));
    done.addEventListener('click', () => {
        if (!current || !activeForm) return;
        activeForm.querySelector('[name=location]').value = current;
        activeForm.querySelector('.picked').textContent = '📍 ' + label(current);
        closeMap();
    });
    document.addEventListener('keydown', ev => { if (ev.key === 'Escape') closeMap(); });

    // ===== Photo preview and submit for each form =====
    document.querySelectorAll('form').forEach(form => {
        const file = form.querySelector('[name=photo]');
        const preview = form.querySelector('.preview');
        file.addEventListener('change', () => {
            if (!file.files[0]) { preview.style.display = 'none'; return; }
            const r = new FileReader();
            r.onload = ev => { preview.src = ev.target.result; preview.style.display = 'block'; };
            r.readAsDataURL(file.files[0]);
        });

        // Check the place, then block the button so the post is not sent twice
        form.addEventListener('submit', ev => {
            if (!form.querySelector('[name=location]').value) {
                ev.preventDefault();
                form.querySelector('.picked').textContent = '⚠️ Выберите место на карте';
                openPicker(form);
                return;
            }
            const b = form.querySelector('.btn-submit');
            b.disabled = true;
            b.textContent = 'Публикуем...';
        });
    });
</script>
</body>
<script type="text/javascript">
(function(d, t) {
var v = d.createElement(t), s = d.getElementsByTagName(t)[0];
v.onload = function() {
window.voiceflow.chat.load({
verify: { projectID: '6aeee99f0204cd0b79ab9b5a' },
url: 'https://general-runtime.voiceflow.com',
voice: {
url: 'https://runtime-api.voiceflow.com'
}
});
}
v.src = 'https://cdn.voiceflow.com/widget-next/bundle.mjs'; v.type = 'text/javascript'; s.parentNode.insertBefore(v, s);
})(document, 'script');
</script>
</html>
