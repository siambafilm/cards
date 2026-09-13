<?php
/**
 * add_card.php — добавляет карточку в cards.json.
 *
 * POST (JSON): { "front": "...", "back": "...", "tags": ["..."] }
 * Ответ: { "ok": true, "id": "...", "total": N }
 *    или { "error": "..." } с соответствующим HTTP-кодом.
 */

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

// Разрешаем только POST
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Разрешён только POST'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Читаем тело
$raw = file_get_contents('php://input');
$input = json_decode($raw, true);
if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['error' => 'Некорректный JSON в теле запроса'], JSON_UNESCAPED_UNICODE);
    exit;
}

$front = isset($input['front']) ? trim((string)$input['front']) : '';
$back  = isset($input['back'])  ? trim((string)$input['back'])  : '';

if ($front === '' || $back === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Поля front и back обязательны'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Лимит длины, чтобы не раздувать JSON
if (mb_strlen($front) > 2000 || mb_strlen($back) > 2000) {
    http_response_code(400);
    echo json_encode(['error' => 'Слишком длинный вопрос или ответ (макс. 2000 символов)'],
                     JSON_UNESCAPED_UNICODE);
    exit;
}

$file = __DIR__ . '/cards.json';

if (!file_exists($file)) {
    @file_put_contents($file, "[]");
}

$fp = fopen($file, 'c+');
if (!$fp) {
    http_response_code(500);
    echo json_encode(['error' => 'Не удалось открыть cards.json для записи. Проверьте права.'],
                     JSON_UNESCAPED_UNICODE);
    exit;
}

if (!flock($fp, LOCK_EX)) {
    fclose($fp);
    http_response_code(500);
    echo json_encode(['error' => 'Не удалось заблокировать файл'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Читаем текущее содержимое
$contents = stream_get_contents($fp);
$cards = json_decode($contents, true);
if (!is_array($cards)) {
    // Если файл пуст или повреждён — начинаем с пустого массива
    $cards = [];
}

// Генерируем id
try {
    $id = bin2hex(random_bytes(8)); // 16 hex-символов
} catch (Exception $e) {
    $id = substr(md5(uniqid('', true)), 0, 16);
}

$newCard = [
    'id'    => $id,
    'front' => $front,
    'back'  => $back,
];

// Теги — опционально
if (!empty($input['tags']) && is_array($input['tags'])) {
    $tags = array_values(array_filter(array_map(
        fn($t) => trim((string)$t),
        $input['tags']
    ), fn($t) => $t !== ''));
    if ($tags) $newCard['tags'] = $tags;
}

$cards[] = $newCard;

// Бэкап перед перезаписью
@copy($file, $file . '.bak');

// Перезаписываем атомарно
ftruncate($fp, 0);
rewind($fp);
$json = json_encode($cards, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
fwrite($fp, $json);
fflush($fp);
flock($fp, LOCK_UN);
fclose($fp);

echo json_encode([
    'ok'    => true,
    'id'    => $id,
    'total' => count($cards),
], JSON_UNESCAPED_UNICODE);