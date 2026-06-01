<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'Powermail Export',
    'description' => 'Custom templates and TypoScript for Powermail export task mails',
    'category' => 'plugin',
    'author' => 'Steffen Scheibe',
    'author_email' => 'mail@mobasoft.de',
    'author_company' => 'Mobasoft',
    'state' => 'stable',
    'version' => '1.0.0',
    'constraints' => [
        'depends' => [
            'typo3' => '12.4.0-12.4.99',
            'powermail' => '12.3.0-12.99.99',
        ],
        'conflicts' => [],
        'suggests' => [],
    ],
];
