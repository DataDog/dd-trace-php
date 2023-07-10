<?php

defined('TYPO3') || die();

call_user_func(static function (string $extensionKey) {
    \TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addStaticFile(
        $extensionKey,
        'Configuration/TypoScript',
        'Exception plugin'
    );
}, 'exception');
