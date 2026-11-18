<?php
/**
 * AI Endpoint & Model Scanner - local cURL proxy for OpenAI-compatible APIs.
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function send_json($payload, $status = 200)
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function extract_reply_text($decoded)
{
    if (!isset($decoded['choices'][0]['message']['content'])) {
        return '';
    }
    $content = $decoded['choices'][0]['message']['content'];
    if (is_string($content)) {
        return $content;
    }
    if (is_array($content)) {
        $parts = array();
        foreach ($content as $part) {
            if (isset($part['text']) && is_string($part['text'])) {
                $parts[] = $part['text'];
            }
        }
        return implode("\n", $parts);
    }
    return '';
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    send_json(array('success' => false, 'error' => 'Gunakan metode POST.'), 405);
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    send_json(array('success' => false, 'error' => 'Body request harus berupa JSON yang valid.'), 400);
}

$action = isset($input['action']) && is_string($input['action']) ? trim($input['action']) : '';
$baseUrl = isset($input['base_url']) && is_string($input['base_url']) ? rtrim(trim($input['base_url']), '/') : '';
$apiKey = isset($input['api_key']) && is_string($input['api_key']) ? trim($input['api_key']) : '';
$model = isset($input['model']) && is_string($input['model']) ? trim($input['model']) : '';
$prompt = isset($input['prompt']) && is_string($input['prompt']) ? trim($input['prompt']) : 'Reply with OK only.';
$baseUrl = rtrim($baseUrl, '/');
$urlParts = parse_url($baseUrl);

if (!in_array($action, array('scan', 'test_model', 'chat'), true)) {
    send_json(array('success' => false, 'error' => 'Action tidak dikenal.'), 400);
}
if (!$urlParts || empty($urlParts['scheme']) || empty($urlParts['host']) ||
    !in_array(strtolower($urlParts['scheme']), array('http', 'https'), true) ||
    !preg_match('#^https?://#i', $baseUrl) ||
    isset($urlParts['user']) || isset($urlParts['pass'])) {
    send_json(array('success' => false, 'error' => 'Base URL harus berupa URL HTTP atau HTTPS yang valid.'), 400);
}
if (in_array($action, array('test_model', 'chat'), true) && $model === '') {
    send_json(array('success' => false, 'error' => 'ID model wajib diisi.'), 400);
}

$endpoint = $baseUrl . ($action === 'scan' ? '/models' : '/chat/completions');
$headers = array('Accept: application/json', 'Content-Type: application/json');
if ($apiKey !== '') {
    $headers[] = 'Authorization: Bearer ' . $apiKey;
}
$options = array(
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => $headers,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
    CURLOPT_TIMEOUT => 25,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_USERAGENT => 'AI-Endpoint-Model-Scanner/1.0'
);
if ($action === 'test_model' || $action === 'chat') {
    $messages = array();
    if ($action === 'chat' && isset($input['messages']) && is_array($input['messages'])) {
        foreach ($input['messages'] as $message) {
            if (is_array($message) && isset($message['role'], $message['content']) &&
                in_array($message['role'], array('user', 'assistant', 'system'), true) && is_string($message['content'])) {
                $messages[] = array('role' => $message['role'], 'content' => substr($message['content'], 0, 12000));
            }
        }
        if (count($messages) > 40) {
            $messages = array_slice($messages, -40);
        }
    }
    if ($action === 'test_model') {
        $messages = array(array('role' => 'user', 'content' => $prompt));
    }
    if (count($messages) === 0) {
        send_json(array('success' => false, 'error' => 'Minimal satu pesan chat wajib diisi.'), 400);
    }
    $options[CURLOPT_POST] = true;
    $options[CURLOPT_POSTFIELDS] = json_encode(array(
        'model' => $model,
        'messages' => $messages,
        'temperature' => 0.2,
        'max_tokens' => 512
    ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

$curl = curl_init($endpoint);
if ($curl === false) {
    send_json(array('success' => false, 'error' => 'Gagal menginisialisasi cURL.'), 500);
}
curl_setopt_array($curl, $options);
$startedAt = microtime(true);
$responseBody = curl_exec($curl);
$latencyMs = round((microtime(true) - $startedAt) * 1000, 2);
$curlError = $responseBody === false ? curl_error($curl) : '';
$httpCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
curl_close($curl);

$decoded = is_string($responseBody) ? json_decode($responseBody, true) : null;
$rawResponse = is_string($responseBody)
    ? (json_last_error() === JSON_ERROR_NONE ? $decoded : $responseBody)
    : null;
$success = $curlError === '' && $httpCode >= 200 && $httpCode < 300;
$result = array(
    'success' => $success,
    'action' => $action,
    'model' => $model,
    'http_code' => $httpCode,
    'latency_ms' => $latencyMs,
    'reply_text' => in_array($action, array('test_model', 'chat'), true) && is_array($decoded) ? extract_reply_text($decoded) : '',
    'raw_response' => $rawResponse
);
if ($curlError !== '') {
    $result['error'] = 'cURL: ' . $curlError;
} elseif (!$success) {
    $result['error'] = 'Endpoint mengembalikan HTTP ' . $httpCode . '.';
}
if ($action === 'scan') {
    $data = is_array($decoded) && isset($decoded['data']) && is_array($decoded['data'])
        ? $decoded['data']
        : (is_array($decoded) && isset($decoded['models']) && is_array($decoded['models']) ? $decoded['models'] : array());
    $models = array();
    foreach ($data as $item) {
        if (is_string($item) && $item !== '') {
            $models[] = array('id' => $item, 'name' => $item);
        } elseif (is_array($item)) {
            $id = isset($item['id']) ? (string) $item['id'] : (isset($item['name']) ? (string) $item['name'] : '');
            if ($id !== '') {
                $models[] = array('id' => $id, 'name' => isset($item['name']) ? (string) $item['name'] : $id);
            }
        }
    }
    $result['models'] = $models;
    if ($success && count($models) === 0) {
        $result['success'] = false;
        $result['error'] = 'Respons berhasil diterima, tetapi tidak berisi daftar model (data[] atau models[]).';
    }
}
send_json($result);
