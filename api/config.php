<?php
// Defaults come from environment variables; override in config.local.php (not committed).
$cfg = [
    'db_host'       => getenv('DB_HOST') ?: '127.0.0.1',
    'db_port'       => getenv('DB_PORT') ?: '3306',
    'db_name'       => getenv('DB_NAME') ?: 'store_ai',
    'db_user'       => getenv('DB_USER') ?: 'root',
    'db_pass'       => getenv('DB_PASS') ?: '',
    'app_secret'    => getenv('APP_SECRET') ?: 'change-me-to-a-long-random-string',
    // AI: provider = anthropic | openrouter | openai. Photo scan needs a vision-capable model.
    'ai_provider'   => getenv('AI_PROVIDER') ?: 'anthropic',
    'ai_api_key'    => getenv('AI_API_KEY') ?: (getenv('ANTHROPIC_API_KEY') ?: ''),
    'ai_model'      => getenv('AI_MODEL') ?: '',      // blank = provider default
    'ai_base_url'   => getenv('AI_BASE_URL') ?: '',   // blank = provider default
    'timezone'      => getenv('APP_TIMEZONE') ?: 'Africa/Lagos',
    'cors_origin'   => getenv('CORS_ORIGIN') ?: '*',
    'debug'         => false,
];

if (is_file(__DIR__ . '/config.local.php')) {
    $cfg = array_merge($cfg, require __DIR__ . '/config.local.php');
}

return $cfg;
