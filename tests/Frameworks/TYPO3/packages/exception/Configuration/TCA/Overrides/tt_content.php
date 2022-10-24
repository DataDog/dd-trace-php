<?php

defined('TYPO3') || die();

\TYPO3\CMS\Extbase\Utility\ExtensionUtility::registerPlugin(
    'Exception',
    'ThrowException',
    'Throw exception on page load'
);
