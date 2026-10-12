<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'TYPO3 Statistics',
    'description' => 'Änderungsstatistik pro Seitenbaum (Multiroot): letzte Änderung und Anzahl Änderungen pro Monat',
    'category' => 'be',
    'state' => 'beta',
    'version' => '0.1.0',
    'constraints' => [
        'depends' => [
            'typo3' => '13.4.0-13.4.99',
        ],
    ],
];
