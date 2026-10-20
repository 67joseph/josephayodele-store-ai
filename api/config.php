<?php
// Defaults come from environment variables; override in config.local.php (not committed).
$cfg = [
    'db_host'       => getenv('DB_HOST') ?: '127.0.0.1',
    'db_port'       => getenv('DB_PORT') ?: '3306',
    'db_name'       => getenv('DB_NAME') ?: 'store_ai',
    'db_user'       => getenv('DB_USER') ?: 'root',
    'db_pass'       => getenv('DB_PASS') ?: '',
    'app_secret'    => getenv('APP_SECRET') ?: 'change-me-to-a-long-random-string',
    'anthropic_key' => getenv('ANTHROPIC_API_KEY') ?: '',
    'ai_model'      => getenv('AI_MODEL') ?: 'claude-sonnet-5-5',
    'timezone'      => getenv('APP_TIMEZONE') ?: 'Africa/Lagos',
    'cors_origin'   => getenv('CORS_ORIGIN') ?: '*',
    'debug'         => false,
];

if (is_file(__DIR__ . '/config.local.php')) {
    $cfg = array_merge($cfg, require __DIR__ . '/config.local.php');
}

return $cfg;
