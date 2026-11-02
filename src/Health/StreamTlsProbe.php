<?php

declare(strict_types=1);

namespace OvhDns\Health;

final class StreamTlsProbe implements TlsProbe
{
    public function inspect(Endpoint $endpoint, string $address, Budget $budget): TlsObservation
    {
        if (!PublicAddress::allowed($address)) throw new \RuntimeException('Private or special-use address blocked.');
        $verified = $this->connect($endpoint, $address, $budget, true);
        if ($verified !== null) return new TlsObservation($verified, true, $address);
        // A separate, unauthenticated inspection handshake preserves expiry evidence after verification fails.
        $certificate = $this->connect($endpoint, $address, $budget, false);
        if ($certificate === null) throw new \RuntimeException('TLS certificate could not be inspected.');
        return new TlsObservation($certificate, false, $address);
    }

    private function connect(Endpoint $endpoint, string $ip, Budget $budget, bool $verify): ?string
    {
        $timeout = $budget->timeout(3) / 4;
        $context = stream_context_create(['ssl' => ['peer_name' => $endpoint->host, 'SNI_enabled' => true,
            'verify_peer' => $verify, 'verify_peer_name' => $verify, 'capture_peer_cert' => true, 'allow_self_signed' => !$verify]]);
        $socket = @stream_socket_client('tcp://'.(str_contains($ip, ':') ? '['.$ip.']' : $ip).':'.$endpoint->port,
            $errno, $error, $timeout, STREAM_CLIENT_CONNECT, $context);
        if ($socket === false) throw new \RuntimeException('TLS connection did not complete.');
        try {
            stream_set_timeout($socket, (int) $timeout, (int) (($timeout - (int) $timeout) * 1e6));
            if ($endpoint->startTls) {
                if (!$this->smtpResponse($socket, '220', $budget)) throw new \RuntimeException('SMTP negotiation did not complete.');
                fwrite($socket, "EHLO zone-harbor.invalid\r\n");
                if (!$this->smtpResponse($socket, '250', $budget)) throw new \RuntimeException('SMTP negotiation did not complete.');
                fwrite($socket, "STARTTLS\r\n");
                if (!$this->smtpResponse($socket, '220', $budget)) throw new \RuntimeException('SMTP negotiation did not complete.');
            }
            if (@stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT) !== true) {
                if (stream_get_meta_data($socket)['timed_out']) throw new \RuntimeException('TLS handshake timed out.');
                return null;
            }
            $cert = stream_context_get_params($socket)['options']['ssl']['peer_certificate'] ?? null;
            return $cert instanceof \OpenSSLCertificate && openssl_x509_export($cert, $pem) ? $pem : null;
        } finally { fclose($socket); }
    }

    /** @param resource $socket */
    private function smtpResponse($socket, string $code, Budget $budget): bool
    {
        for ($i = 0; $i < 32; ++$i) {
            $timeout = $budget->timeout(1);
            stream_set_timeout($socket, (int) $timeout, (int) (($timeout - (int) $timeout) * 1e6));
            $line = fgets($socket, 1024);
            if ($line === false || !str_starts_with($line, $code)) return false;
            if (($line[3] ?? '') === ' ') return true;
            if (($line[3] ?? '') !== '-') return false;
        }
        return false;
    }
}
