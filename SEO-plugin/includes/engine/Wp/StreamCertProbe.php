<?php
namespace HaloAudit\Wp;

use HaloAudit\CertProbe;

final class StreamCertProbe implements CertProbe
{
    public function daysLeft(string $host): ?int
    {
        // Peer verification is off on purpose: the expiry of an invalid certificate must still be readable.
        $context = stream_context_create(['ssl' => [
            'capture_peer_cert' => true,
            'verify_peer' => false,
            'verify_peer_name' => false,
            'SNI_enabled' => true,
            'peer_name' => $host,
        ]]);

        $socket = @stream_socket_client('ssl://' . $host . ':443', $errno, $error, 5, STREAM_CLIENT_CONNECT, $context);
        if ($socket === false) {
            return null;
        }
        $params = stream_context_get_params($socket);
        fclose($socket);

        $certificate = $params['options']['ssl']['peer_certificate'] ?? null;
        $info = $certificate ? openssl_x509_parse($certificate) : false;
        if (!$info || empty($info['validTo_time_t'])) {
            return null;
        }
        return (int) floor(($info['validTo_time_t'] - time()) / 86400);
    }
}
