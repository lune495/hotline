<?php

return [
    'host' => env('IMAP_HOST', 'pro3.mail.ovh.net'),
    'port' => env('IMAP_PORT', 993),
    'encryption' => env('IMAP_ENCRYPTION', 'ssl'),
    'username' => env('IMAP_USERNAME', 'chef.finance@chifaa.sn'),
    'password' => env('IMAP_PASSWORD'),
    'folder' => env('IMAP_FOLDER', 'INBOX'),
];
