<?php

// Modelled on Laminas\Uri\Uri::removePathDotSegments(). Global namespace, so
// strrpos() resolves at compile time and SCCP may evaluate it.
function remove_dot_segments_for_test($path)
{
    $output = '';
    while ($path) {
        switch (true) {
            case $path === '/..':
                $path = '/';
                $lastSlashPos = strrpos($output, '/', -1);
                if (false === $lastSlashPos) {
                    break;
                }
                $output = substr($output, 0, $lastSlashPos);
                break;
            case 0 === strpos($path, '/../'):
                $path = '/' . substr($path, 4);
                $lastSlashPos = strrpos($output, '/');
                if (false !== $lastSlashPos) {
                    $output = substr($output, 0, $lastSlashPos);
                }
                break;
            default:
                $slash = strpos($path, '/', 1);
                $seg = $slash === false ? $path : substr($path, 0, $slash);
                $output .= $seg;
                $path = substr($path, strlen($seg));
                break;
        }
    }
    return $output;
}
