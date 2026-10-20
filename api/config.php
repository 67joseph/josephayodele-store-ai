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
    // WhatsApp (Meta Cloud API) — see README
    'wa_token'           => getenv('WA_TOKEN') ?: '',            // permanent access token
    'wa_phone_number_id' => getenv('WA_PHONE_NUMBER_ID') ?: '',
    'wa_verify_token'    => getenv('WA_VERIFY_TOKEN') ?: '',     // any secret string you also enter in Meta's webhook setup
    'wa_app_secret'      => getenv('WA_APP_SECRET') ?: '',       // Meta app secret (verifies webhook signatures)
    'wa_business_number' => getenv('WA_BUSINESS_NUMBER') ?: '',  // the number users chat with, e.g. 2348012345678
    'wa_rate_per_min'    => (int) (getenv('WA_RATE_PER_MIN') ?: 40),  // max messages per number per minute
    'wa_api_base'        => getenv('WA_API_BASE') ?: '',         // blank = https://graph.facebook.com/v21.0
    'timezone'      => getenv('APP_TIMEZONE') ?: 'Africa/Lagos',
    'cors_origin'   => getenv('CORS_ORIGIN') ?: '*',
    'debug'         => false,
];

if (is_file(__DIR__ . '/config.local.php')) {
    $cfg = array_merge($cfg, require __DIR__ . '/config.local.php');
}

return $cfg;
