<?php
declare(strict_types=1);

// Change these values for your rental server. The local Laragon defaults use
// MySQL's common root account; no password is stored in source control.
return [
    'host' => getenv('TRELLO_DB_HOST') ?: '127.0.0.1',
    'port' => getenv('TRELLO_DB_PORT') ?: '3306',
    'database' => getenv('TRELLO_DB_NAME') ?: 'trello2',
    'username' => getenv('TRELLO_DB_USER') ?: 'root',
    'password' => getenv('TRELLO_DB_PASSWORD') ?: '',
];
