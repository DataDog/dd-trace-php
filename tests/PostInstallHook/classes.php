<?php

namespace DDTrace\Tests\PostInstallHook;

class CliInput
{
    private const VALID_PHP_VERSIONS = ['5.6', '7.0', '7.1', '7.2', '7.3'/*, '7.4'*/];
    private const VALID_SAPIS = ['apache2handler', 'fpm-fcgi'];

    private $phpVersion;
    private $sapi;

    public function __construct()
    {
        $options = getopt('', ['php-version:', 'sapi:']);
        if (false === $options || !isset($options['php-version'], $options['sapi'])) {
            printf(
                'Usage: php %s --php-version [PHP VERSION] --sapi [SAPI NAME]%s',
                $_SERVER['PHP_SELF'],
                PHP_EOL
            );
            printf(
                '  --php-version    %s%s',
                implode(', ', self::VALID_PHP_VERSIONS),
                PHP_EOL
            );
            printf(
                '  --sapi           %s%s',
                implode(', ', self::VALID_SAPIS),
                PHP_EOL
            );
            exit(1);
        }
        $this->phpVersion = $options['php-version'];
        $this->sapi = $options['sapi'];

        if (!in_array($this->phpVersion, self::VALID_PHP_VERSIONS, true)) {
            printf(
                'Please specify a valid target PHP version: %s%s',
                implode(', ', self::VALID_PHP_VERSIONS),
                PHP_EOL
            );
            exit(1);
        }
        if (!in_array($this->sapi, self::VALID_SAPIS, true)) {
            printf(
                'Please specify a valid target SAPI: %s%s',
                implode(', ', self::VALID_SAPIS),
                PHP_EOL
            );
            exit(1);
        }
    }

    public function phpVersion(): string
    {
        return $this->phpVersion;
    }

    public function sapi(): string
    {
        return $this->sapi;
    }
}

class Request
{
    private $input;
    private $url;

    public function __construct(CliInput $input)
    {
        $this->input = $input;

        $host = getenv('TARGET_HOST') ?: 'localhost';
        $port = 'fpm-fcgi' === $this->input->sapi() ? '80' : '81';
        $port .= str_replace('.', '', $this->input->phpVersion());
        $this->url = 'http://' . $host . ':' . $port;
    }

    public function url(): string
    {
        return $this->url;
    }

    public function send(): Response
    {
        return new Response($this->input, $this);
    }
}

class Response
{
    private $status;
    private $input;

    public function __construct(CliInput $input, Request $request)
    {
        $this->input = $input;

        $verbose = fopen('php://temp', 'w+b');
        $ch = curl_init($request->url());
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
        curl_setopt($ch, CURLOPT_VERBOSE, true);
        curl_setopt($ch, CURLOPT_STDERR, $verbose);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json']);
        $response = curl_exec($ch);
        if (!$response) {
            $errorCode = curl_errno($ch);
            $error = curl_error($ch);
            curl_close($ch);

            rewind($verbose);
            printf(
                '[%s; %s] Unexpected response from "%s": (%d) %s {%s}%s',
                $this->input->phpVersion(),
                $this->input->sapi(),
                $request->url(),
                $errorCode,
                $error,
                stream_get_contents($verbose),
                PHP_EOL
            );
            exit(1);
        }
        curl_close($ch);
        $this->status = json_decode($response, true);
        if (
            !$this->status
            || !isset($this->status['sapi'], $this->status['php_version'], $this->status['ddtrace_installed'])
        ) {
            printf(
                '[%s; %s] Unexpected payload from "%s": %s%s',
                $this->input->phpVersion(),
                $this->input->sapi(),
                $request->url(),
                var_export($response, true),
                PHP_EOL
            );
            exit(1);
        }
        $this->assertSameFromKey('sapi', $input->sapi());
        $this->assertSameFromKey('php_version', $input->phpVersion());
    }

    public function assertSameFromKey($key, $expected): void
    {
        if ($expected !== $this->status[$key]) {
            printf(
                '[%s; %s] Unexpected response for "%s"; expected "%s"; actual "%s"%s',
                $this->input->phpVersion(),
                $this->input->sapi(),
                $key,
                var_export($expected, true),
                var_export($this->status[$key], true),
                PHP_EOL
            );
            exit(1);
        }
    }
}
