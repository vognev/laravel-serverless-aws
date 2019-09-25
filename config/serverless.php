<?php

return [
    'storage' => storage_path('serverless'),
    'php' => [
        'modules' => ['default'],
        'presets' => [
            'default' => [
                'curl',
                'dom',
                'fileinfo',
                'filter',
                'ftp',
                'hash',
                'iconv',
                'intl',
                'json',
                'mbstring',
                'openssl',
                'opcache',
                'pdo_mysql',
                'readline',
                'session',
                'simplexml',
                'sockets',
                'tokenizer',
                'zip',
            ]
        ]
    ]
];
