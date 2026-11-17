<?php

declare(strict_types=1);

namespace OpinionPadi\Offers\Http;

/**
 * The default transport, built on ext-curl.
 */
final class CurlTransport implements TransportInterface
{
    public function send(string $method, string $url, array $headers, ?string $body, float $timeout): Response
    {
        $handle = curl_init();
        if ($handle === false) {
            throw new TransportException('Could not start a curl request.');
        }

        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name.': '.$value;
        }

        $responseHeaders = [];
        $timeoutMs = max(1, (int) round($timeout * 1000));

        curl_setopt_array($handle, [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT_MS => $timeoutMs,
            CURLOPT_CONNECTTIMEOUT_MS => $timeoutMs,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$responseHeaders): int {
                $length = strlen($line);
                $trimmed = trim($line);
                if (str_starts_with($trimmed, 'HTTP/')) {
                    // A new status line (after a 100 Continue, say): start over.
                    $responseHeaders = [];
                } elseif (str_contains($trimmed, ':')) {
                    [$name, $value] = explode(':', $trimmed, 2);
                    $responseHeaders[strtolower(trim($name))] = trim($value);
                }

                return $length;
            },
        ]);

        if ($body !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        }

        $result = curl_exec($handle);

        if ($result === false) {
            $errno = curl_errno($handle);
            $error = curl_error($handle);

            throw new TransportException(
                $error !== '' ? $error : 'The request failed.',
                $errno === CURLE_OPERATION_TIMEDOUT,
            );
        }

        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);

        return new Response($status, $responseHeaders, (string) $result);
    }
}
