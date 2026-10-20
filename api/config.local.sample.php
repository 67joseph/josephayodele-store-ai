<?php
// Copy to config.local.php and fill in.
return [
    'db_host'       => 'localhost',
    'db_name'       => 'store_ai',
    'db_user'       => 'your_db_user',
    'db_pass'       => 'your_db_password',
    'app_secret'    => 'put-a-long-random-string-here',
    'openai_key'    => 'sk-...',              // enables photo scan + AI report chat
    'ai_model'      => 'gpt-4.1-mini',        // any vision-capable OpenAI chat model
    'timezone'      => 'Africa/Lagos',
    'cors_origin'   => '*',                   // or your site, e.g. https://store.example.com
];
