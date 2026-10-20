<?php
// Defaults come from environment variables; override in config.local.php (not committed).
$cfg = [
    'db_host'       => getenv('DB_HOST') ?: '127.0.0.1',
    'db_port'       => getenv('DB_PORT') ?: '3306',
    'db_name'       => getenv('DB_NAME') ?: 'store_ai',
    'db_user'       => getenv('DB_USER') ?: 'root',
    'db_pass'       => getenv('DB_PASS') ?: '',
    'app_secret'    => getenv('APP_SECRET') ?: 'change-me-to-a-long-random-string',
    'openai_key'    => getenv('OPENAI_API_KEY') ?: '',
    'ai_base_url'   => getenv('AI_BASE_URL') ?: 'https://api.openai.com/v1',
    // Must support image input. Check your available IDs at https://platform.openai.com/docs/models
    'ai_model'      => getenv('AI_MODEL') ?: 'gpt-4.1-mini',
    'timezone'      => getenv('APP_TIMEZONE') ?: 'Africa/Lagos',
    'cors_origin'   => getenv('CORS_ORIGIN') ?: '*',
    'debug'         => false,
];

if (is_file(__DIR__ . '/config.local.php')) {
    $cfg = array_merge($cfg, require __DIR__ . '/config.local.php');
}

return $cfg;
