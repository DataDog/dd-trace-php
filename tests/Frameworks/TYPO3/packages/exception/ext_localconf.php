<?php

defined('TYPO3_MODE') || die();

\TYPO3\CMS\Extbase\Utility\ExtensionUtility::configurePlugin(
    'Exception',
    'ThrowException',
    [
        \Datadog\Exception\ExceptionController::class => 'show',
    ],
    [
        \Datadog\Exception\ExceptionController::class => 'show',
    ]
);
