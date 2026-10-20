<?php
// Pluggable AI client. Providers: anthropic | openrouter | openai (or any OpenAI-compatible host via ai_base_url).
//
// Messages are provider-neutral: ['role' => 'user'|'assistant', 'content' => string | parts[]]
// where a part is ['type' => 'text', 'text' => ...] or ['type' => 'image', 'mime' => 'image/jpeg', 'data' => <base64>].

function ai_settings()
{
    $provider = strtolower((string) cfg('ai_provider', 'anthropic'));
    $defaults = [
        'anthropic'  => ['https://api.anthropic.com/v1', 'claude-sonnet-5-5'],
        'openrouter' => ['https://openrouter.ai/api/v1', 'google/gemini-2.5-flash'],
        'openai'     => ['https://api.openai.com/v1', 'gpt-4.1-mini'],
    ];
    if (!isset($defaults[$provider])) {
        abort(500, 'Unknown ai_provider "' . $provider . '". Use anthropic, openrouter or openai.');
    }
    $key = cfg('ai_api_key') ?: ($provider === 'anthropic' ? cfg('anthropic_key') : '');
    if (!$key) {
        abort(503, 'AI is not configured yet. Set ai_provider and ai_api_key in api/config.local.php.');
    }
    return [
        'provider' => $provider,
        'key'      => $key,
        'base'     => rtrim(cfg('ai_base_url') ?: $defaults[$provider][0], '/'),
        'model'    => cfg('ai_model') ?: $defaults[$provider][1],
    ];
}

function ai_http($url, $headers, $payload)
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => (int) cfg('ai_timeout', 90),
        CURLOPT_HTTPHEADER     => array_merge(['Content-Type: application/json'], $headers),
        CURLOPT_POSTFIELDS     => json_encode($payload),
    ]);
    $out = curl_exec($ch);
    $err = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($out === false) {
        abort(502, 'Could not reach the AI service: ' . $err);
    }
    $j = json_decode($out, true);
    if ($code >= 400 || !is_array($j)) {
        $msg = isset($j['error']['message']) ? $j['error']['message'] : 'AI request failed (' . $code . ')';
        abort(502, $msg);
    }
    return $j;
}

function ai_parts_anthropic($content)
{
    if (is_string($content)) {
        return $content;
    }
    $out = [];
    foreach ($content as $p) {
        $out[] = $p['type'] === 'image'
            ? ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $p['mime'], 'data' => $p['data']]]
            : ['type' => 'text', 'text' => $p['text']];
    }
    return $out;
}

function ai_parts_openai($content)
{
    if (is_string($content)) {
        return $content;
    }
    $out = [];
    foreach ($content as $p) {
        $out[] = $p['type'] === 'image'
            ? ['type' => 'image_url', 'image_url' => ['url' => 'data:' . $p['mime'] . ';base64,' . $p['data']]]
            : ['type' => 'text', 'text' => $p['text']];
    }
    return $out;
}

function ai_chat($system, $messages, $maxTokens = 1500)
{
    $s = ai_settings();

    if ($s['provider'] === 'anthropic') {
        $msgs = [];
        foreach ($messages as $m) {
            $msgs[] = ['role' => $m['role'], 'content' => ai_parts_anthropic($m['content'])];
        }
        $j = ai_http($s['base'] . '/messages', ['x-api-key: ' . $s['key'], 'anthropic-version: 2023-06-01'], [
            'model' => $s['model'], 'max_tokens' => $maxTokens, 'system' => $system, 'messages' => $msgs,
        ]);
        $text = '';
        foreach ((isset($j['content']) ? $j['content'] : []) as $blk) {
            if (isset($blk['type']) && $blk['type'] === 'text') {
                $text .= $blk['text'];
            }
        }
        return $text;
    }

    // OpenAI-style chat completions (OpenRouter, OpenAI, and compatible hosts)
    $msgs = [['role' => 'system', 'content' => $system]];
    foreach ($messages as $m) {
        $msgs[] = ['role' => $m['role'], 'content' => ai_parts_openai($m['content'])];
    }
    $headers = ['Authorization: Bearer ' . $s['key']];
    if ($s['provider'] === 'openrouter') {
        $headers[] = 'X-Title: Store-AI';
    }
    $payload = ['model' => $s['model'], 'messages' => $msgs];
    $payload[$s['provider'] === 'openai' ? 'max_completion_tokens' : 'max_tokens'] = $maxTokens;
    $j = ai_http($s['base'] . '/chat/completions', $headers, $payload);
    return isset($j['choices'][0]['message']['content']) ? (string) $j['choices'][0]['message']['content'] : '';
}
