<?php

// An ephemeral CA and leaf let the real Rust HTTPS client verify the canonical
// intake hostname through a local CONNECT proxy. No TLS bypass is used.
function ffeWireTlsFixture()
{
    $dir = sys_get_temp_dir() . '/php-ffe-tls-' . bin2hex(random_bytes(8));
    if (!mkdir($dir, 0700)) throw new RuntimeException('cannot create TLS fixture');
    $config = $dir . '/openssl.cnf';
    file_put_contents($config, "[req]\ndistinguished_name=dn\n[dn]\nCN=FFE fixture\n"
        . "[ca_ext]\nbasicConstraints=critical,CA:TRUE\nkeyUsage=critical,keyCertSign,cRLSign\nsubjectKeyIdentifier=hash\n"
        . "[leaf_ext]\nbasicConstraints=critical,CA:FALSE\nkeyUsage=critical,digitalSignature,keyEncipherment\n"
        . "extendedKeyUsage=serverAuth\nsubjectAltName=DNS:event-platform-intake.example.invalid\n");
    $options = array('config' => $config, 'private_key_bits' => 2048, 'digest_alg' => 'sha256');
    $caKey = openssl_pkey_new($options);
    $caCsr = openssl_csr_new(array('commonName' => 'FFE fixture CA'), $caKey, $options);
    $ca = openssl_csr_sign($caCsr, null, $caKey, 1, $options + array('x509_extensions' => 'ca_ext'), 1);
    $key = openssl_pkey_new($options);
    $csr = openssl_csr_new(array('commonName' => 'event-platform-intake.example.invalid'), $key, $options);
    $cert = openssl_csr_sign($csr, $ca, $caKey, 1, $options + array('x509_extensions' => 'leaf_ext'), 2);
    if (!$ca || !$cert) throw new RuntimeException('cannot generate fixture certificates');
    openssl_x509_export($ca, $caPem);
    openssl_x509_export($cert, $certPem);
    openssl_pkey_export($key, $keyPem, null, $options);
    file_put_contents($dir . '/ca.pem', $caPem);
    file_put_contents($dir . '/server.pem', $certPem . $keyPem);
    return $dir;
}
