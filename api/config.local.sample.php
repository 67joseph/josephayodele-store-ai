<?php
// Copy to config.local.php and fill in.
return [
    'db_host'       => 'localhost',
    'db_name'       => 'store_ai',
    'db_user'       => 'your_db_user',
    'db_pass'       => 'your_db_password',
    'app_secret'    => 'put-a-long-random-string-here',
    'anthropic_key' => 'sk-ant-...',          // enables photo scan + AI report chat
    'ai_model'      => 'claude-sonnet-5-5',
    'timezone'      => 'Africa/Lagos',
    'cors_origin'   => '*',                   // or your site, e.g. https://store.example.com
];
