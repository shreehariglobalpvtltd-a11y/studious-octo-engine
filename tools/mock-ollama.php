<?php
/**
 * tools/mock-ollama.php — a stand-in model server for the tests.
 *
 *     php -S 127.0.0.1:11555 tools/mock-ollama.php
 *
 * Speaks the Ollama API (/api/version, tags, show, ps, pull, chat) and the
 * OpenAI-compatible one (/v1/models, /v1/chat/completions) with simple
 * rules instead of a model, so the Agent Pro tests can run anywhere —
 * GitHub Actions, a laptop — without downloading gigabytes. It answers the
 * way a good model would for the test messages, fails on "MOCKFAIL", is
 * slow on "MOCKSLOW", and appends every request to
 *     sys_get_temp_dir()/mock-ollama-requests.jsonl
 * so a test can check what was sent (think:false, a JSON schema, images).
 *
 * This is test tooling. Real answers come from Ollama on the VPS.
 */
declare(strict_types=1);

$path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$raw  = (string) file_get_contents('php://input');
$in   = json_decode($raw, true) ?: [];
@file_put_contents(sys_get_temp_dir() . '/mock-ollama-requests.jsonl', json_encode([
    'path' => $path, 'model' => $in['model'] ?? null, 'think' => $in['think'] ?? null, 'format' => isset($in['format']) || isset($in['response_format']),
    'images' => mock_has_images($in), 'tools' => isset($in['tools']), 'auth' => $_SERVER['HTTP_AUTHORIZATION'] ?? '', 'at' => microtime(true),
]) . "\n", FILE_APPEND);

header('Content-Type: application/json');
switch ($path) {
    case '/api/version':
        out(['version' => '0.13.0-mock']);
    case '/api/tags':
        out(['models' => [
            ['name' => 'qwen3.5:4b', 'size' => 3400000000, 'details' => ['family' => 'qwen35', 'parameter_size' => '4B', 'quantization_level' => 'Q4_K_M']],
            ['name' => 'gemma4:e4b', 'size' => 9600000000, 'details' => ['family' => 'gemma4', 'parameter_size' => '8B', 'quantization_level' => 'Q4_K_M']],
        ]]);
    case '/api/show':
        out(['capabilities' => ($in['model'] ?? '') === 'gemma4:e4b' ? ['completion', 'vision', 'tools', 'audio'] : ['completion', 'vision', 'tools', 'thinking']]);
    case '/api/ps':
        out(['models' => [['name' => 'qwen3.5:4b', 'size_vram' => 0]]]);
    case '/api/pull':
        out(['status' => 'pulling manifest']);
    case '/api/chat':
        $msg = mock_answer($in);
        out(['model' => $in['model'] ?? '', 'message' => ['role' => 'assistant', 'content' => $msg], 'done' => true,
             'prompt_eval_count' => 420, 'eval_count' => 60]);
    case '/v1/models':
        out(['data' => [['id' => 'mock-openai']]]);
    case '/v1/chat/completions':
        out(['choices' => [['message' => ['role' => 'assistant', 'content' => mock_answer($in)]]], 'usage' => ['prompt_tokens' => 420, 'completion_tokens' => 60]]);
}
http_response_code(404);
out(['error' => 'unknown path ' . $path]);

function out(array $j): never
{
    echo json_encode($j, JSON_UNESCAPED_UNICODE);
    exit;
}

function mock_has_images(array $in): bool
{
    foreach ((array) ($in['messages'] ?? []) as $m) {
        if (!empty($m['images'])) {
            return true;
        }
        if (is_array($m['content'] ?? null)) {
            foreach ($m['content'] as $p) {
                if (($p['type'] ?? '') === 'image_url') {
                    return true;
                }
            }
        }
    }
    return false;
}

/** The "model": rules that answer the test messages the way a good model would. */
function mock_answer(array $in): string
{
    $last = '';
    foreach ((array) ($in['messages'] ?? []) as $m) {
        if (($m['role'] ?? '') === 'user') {
            $last = is_array($m['content'] ?? null) ? (string) ($m['content'][0]['text'] ?? '') : (string) ($m['content'] ?? '');
        }
    }
    if (str_contains($last, 'MOCKFAIL')) {
        http_response_code(500);
        out(['error' => 'mock failure']);
    }
    if (str_contains($last, 'MOCKSLOW')) {
        sleep(3);
    }
    if (mock_has_images($in)) {
        return json_encode(['full_name' => 'Test Person Kumar', 'full_name_local' => '', 'guardian' => 'Test Father', 'dob' => '1994-03-12', 'dob_bs' => '',
            'gender' => 'Male', 'id_number' => '2345 6789 0124', 'address' => '12 Main Road, Navrangpura', 'district' => 'Ahmedabad',
            'state' => 'Gujarat', 'pincode' => '380009', 'expires' => ''], JSON_UNESCAPED_UNICODE);
    }
    $wantJson = isset($in['format']) || isset($in['response_format']);
    if (!$wantJson) {
        return 'MOCK reply';
    }
    // ApBrain puts the message after "Message:"; the benchmark sends it bare.
    $msg = str_contains($last, "Message:\n") ? substr($last, strrpos($last, "Message:\n") + 9) : $last;
    $sys = '';
    foreach ((array) ($in['messages'] ?? []) as $mm) {
        if (($mm['role'] ?? '') === 'system') { $sys .= (string) $mm['content']; }
    }
    // The app's own idea of today (its timezone), as a real model would read it from the prompt.
    $tomorrow = preg_match('/Tomorrow: (\d{4}-\d{2}-\d{2})/', $last, $m) ? $m[1]
        : (preg_match('/Today is (\d{4}-\d{2}-\d{2})/', $sys, $m) ? date('Y-m-d', strtotime($m[1] . ' +1 day')) : date('Y-m-d', strtotime('+1 day')));
    $props = (array) ($in['format']['properties'] ?? $in['response_format']['json_schema']['schema']['properties'] ?? []);
    $pickKey = array_key_exists('pickup', $props) ? 'pickup' : 'boarding';
    $ascii = strtr($msg, ['०' => '0', '१' => '1', '२' => '2', '३' => '3', '४' => '4', '५' => '5', '६' => '6', '७' => '7', '८' => '8', '९' => '9']);
    $l = mb_strtolower($ascii);
    $o = ['intent' => 'question'];
    if (preg_match('/\b(SHG-[A-Z0-9]+(?:-[A-Z0-9]+)+)\b/i', $msg, $p)) {
        $o['pnr'] = strtoupper($p[1]);
    }
    if (isset($o['pnr']) && preg_match('/(?:naam|name|नाम)\s+(?:sachyau\w*\s+)?([\p{L} ]{2,40}?)\s*(?:banau\w*|गर\w*|kar\w*)?$/iu', $msg, $nm)) {
        $o += ['intent' => 'edit', 'field' => 'name', 'value' => trim($nm[1])];
        $o['intent'] = 'edit';
        return json_encode($o, JSON_UNESCAPED_UNICODE);
    }
    if (isset($o['pnr']) && preg_match('/kaha|कहाँ|where|status/u', $l)) {
        $o['intent'] = 'status';
        return json_encode($o, JSON_UNESCAPED_UNICODE);
    }
    if (preg_match('/balance|wallet|paisa kati|kati paisa|बाँकी/u', $l)) {
        return json_encode(['intent' => 'balance']);
    }
    if (preg_match('/office|address|cin|ठेगाना/u', $l)) {
        return json_encode(['intent' => 'company']);
    }
    $seats = preg_match('/(\d{1,2})\s*(?:seat|सिट|सीट|jana|जना|log|लोग)/u', $l, $sm) ? (int) $sm[1] : 0;
    $phone = preg_match('/(\d{10})/', $ascii, $ph) ? $ph[1] : '';
    if ($seats > 0 || $phone !== '') {
        $o = ['intent' => 'book', 'seats' => max(1, $seats), 'phone' => $phone];
        if (preg_match('/bholi|भोलि|kal\b|कल|tomorrow/u', $l)) {
            $o['date'] = $tomorrow;
        }
        foreach (['mehsana' => 'Mehsana', 'मेहसाना' => 'Mehsana', 'surat' => 'Surat', 'अहमदाबाद' => 'Ahmedabad', 'ahmedabad' => 'Ahmedabad', 'vadodara' => 'Vadodara'] as $k => $town) {
            if (str_contains($l, $k)) {
                $o[$pickKey] = $town;
                break;
            }
        }
        if (preg_match_all('/\b([A-Z][a-z]+(?:\s+[A-Z][a-z]+)+)\b/', $msg, $nm)) {
            foreach ($nm[1] as $cand) {
                if (!preg_match('/^(Surat|Mehsana|Ahmedabad|Vadodara)$/', $cand)) {
                    $o['name'] = $cand;
                    break;
                }
            }
        }
        return json_encode($o, JSON_UNESCAPED_UNICODE);
    }
    if (preg_match('/kaha|कहाँ|where/u', $l)) {
        return json_encode(['intent' => 'status']);
    }
    return json_encode(['intent' => 'question', 'answer' => 'MOCK: हाम्रो अफिसमा सम्पर्क गर्नुहोस्।'], JSON_UNESCAPED_UNICODE);
}
