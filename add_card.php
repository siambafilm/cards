<?php
/**
 * add_card.php — добавляет карточку в нужный JSON-файл.
 *
 * POST (JSON): { "lang": "latin"|"english", "front": "...", "back": "...", "tags": [...] }
 * Ответ: { "ok": true, "id": "...", "total": N }
 */

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Разрешён только POST'], JSON_UNESCAPED_UNICODE);
    exit;
}

$raw = file_get_contents('php://input');
$input = json_decode($raw, true);
if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['error' => 'Некорректный JSON'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Разрешённые языки → имена файлов. Не даём писать куда попало.
$allowed = [
    'latin'                              => 'latin.json',
    'english'                            => 'english.json',
    'anatomical_nouns'                   => 'anatomical_nouns.json',
    'anatomical_adjectives'              => 'anatomical_adjectives.json',
    'numbers'                            => 'numbers.json',
    'latin_prefixes_prepositions'        => 'latin_prefixes_prepositions.json',
    'greek_prefixes'                     => 'greek_prefixes.json',
    'colors'                             => 'colors.json',
    'animals'                            => 'animals.json',
    'clinical_adjectives'                => 'clinical_adjectives.json',
    'clinical_nouns'                     => 'clinical_nouns.json',
    'disease_term_elements'              => 'disease_term_elements.json',
    'diagnosis_treatment_term_elements'  => 'diagnosis_treatment_term_elements.json',
    'surgical_term_elements'             => 'surgical_term_elements.json',
    'greek_latin_equivalents_1'          => 'greek_latin_equivalents_1.json',
    'greek_latin_equivalents_2'          => 'greek_latin_equivalents_2.json',
    'drug_forms_solid_dry'               => 'drug_forms_solid_dry.json',
    'drug_forms_soft_semiliquid'         => 'drug_forms_soft_semiliquid.json',
    'drug_forms_liquid'                  => 'drug_forms_liquid.json',
    'prescription_terms'                 => 'prescription_terms.json',
];

$lang = isset($input['lang']) ? (string)$input['lang'] : '';
if (!isset($allowed[$lang])) {
    http_response_code(400);
    echo json_encode(['error' => 'Неизвестный язык: ' . $lang], JSON_UNESCAPED_UNICODE);
    exit;
}
$file = __DIR__ . '/langs/' . $allowed[$lang];

// ---- Проверка пароля ----
$passwordFile = __DIR__ . '/pAsS.txt';
if (!file_exists($passwordFile) || !is_readable($passwordFile)) {
    http_response_code(500);
    echo json_encode(['error' => 'Файл pAsS.txt не найден на сервере'], JSON_UNESCAPED_UNICODE);
    exit;
}
$expectedPassword = trim((string)file_get_contents($passwordFile));
$providedPassword = isset($input['password']) ? (string)$input['password'] : '';

if ($expectedPassword === '' || !hash_equals($expectedPassword, $providedPassword)) {
    http_response_code(403);
    echo json_encode(['error' => 'Неверный пароль'], JSON_UNESCAPED_UNICODE);
    exit;
}

$front = isset($input['front']) ? trim((string)$input['front']) : '';
$back  = isset($input['back'])  ? trim((string)$input['back'])  : '';

if ($front === '' || $back === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Поля front и back обязательны'], JSON_UNESCAPED_UNICODE);
    exit;
}
if (mb_strlen($front) > 2000 || mb_strlen($back) > 2000) {
    http_response_code(400);
    echo json_encode(['error' => 'Слишком длинно (макс. 2000 символов)'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!file_exists($file)) {
    @file_put_contents($file, "[]");
}

$fp = fopen($file, 'c+');
if (!$fp) {
    http_response_code(500);
    echo json_encode(['error' => 'Не удалось открыть ' . $allowed[$lang] . ' для записи'],
                     JSON_UNESCAPED_UNICODE);
    exit;
}
if (!flock($fp, LOCK_EX)) {
    fclose($fp);
    http_response_code(500);
    echo json_encode(['error' => 'Не удалось заблокировать файл'], JSON_UNESCAPED_UNICODE);
    exit;
}

$contents = stream_get_contents($fp);
$cards = json_decode($contents, true);
if (!is_array($cards)) $cards = [];

// Нормализация для сравнения: регистр не важен, лишние пробелы схлопываются.
$normalize = function (string $s): string {
    return mb_strtolower(preg_replace('/\s+/u', ' ', trim($s)));
};

$frontNorm = $normalize($front);
foreach ($cards as $existing) {
    $existingFront = isset($existing['front']) ? (string)$existing['front'] : '';
    if ($normalize($existingFront) === $frontNorm) {
        flock($fp, LOCK_UN);
        fclose($fp);
        http_response_code(409);
        echo json_encode(['error' => 'Такое слово уже есть в списке: ' . $existingFront],
                         JSON_UNESCAPED_UNICODE);
        exit;
    }
}

try {
    $id = bin2hex(random_bytes(8));
} catch (Exception $e) {
    $id = substr(md5(uniqid('', true)), 0, 16);
}

$newCard = ['id' => $id, 'front' => $front, 'back' => $back];

if (!empty($input['tags']) && is_array($input['tags'])) {
    $tags = array_values(array_filter(array_map(
        fn($t) => trim((string)$t),
        $input['tags']
    ), fn($t) => $t !== ''));
    if ($tags) $newCard['tags'] = $tags;
}

$cards[] = $newCard;

@copy($file, $file . '.bak');

ftruncate($fp, 0);
rewind($fp);
fwrite($fp, json_encode($cards, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
fflush($fp);
flock($fp, LOCK_UN);
fclose($fp);

echo json_encode(['ok' => true, 'id' => $id, 'total' => count($cards)], JSON_UNESCAPED_UNICODE);