<?php
// Copy to config.local.php and fill in.
return [
    'db_host'       => 'localhost',
    'db_name'       => 'store_ai',
    'db_user'       => 'your_db_user',
    'db_pass'       => 'your_db_password',
    'app_secret'    => 'put-a-long-random-string-here',
    // AI provider: 'anthropic' | 'openrouter' | 'openai'
    'ai_provider'   => 'openrouter',
    'ai_api_key'    => 'sk-or-...',                // enables photo scan + AI report chat
    'ai_model'      => 'google/gemini-2.5-flash',  // blank = provider default; must accept images for photo scan
    'timezone'      => 'Africa/Lagos',
    'cors_origin'   => '*',                   // or your site, e.g. https://store.example.com
];
