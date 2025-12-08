<?php
return [
    'imap' => [
        'mailbox' => '{imap.example.com:993/imap/ssl}INBOX',
        'sentMailbox' => '{imap.example.com:993/imap/ssl}Sent',
        'username' => 'bot@example.com',
        'password' => 'haslo-imap',
    ],
    'smtp' => [
        'host' => 'smtp.example.com',
        'port' => 587,
        'username' => 'bot@example.com',
        'password' => 'haslo-smtp',
        'from' => 'Bot <bot@example.com>',
    ],
    'openai' => [
        'api_key' => 'sk-XXXX',
        'model' => 'gpt-4o-mini',
    ],
];
