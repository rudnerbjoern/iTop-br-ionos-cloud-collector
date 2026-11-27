<?php

/** Read IONOS JSON APIs using verified TLS and destination-scoped bearer authentication. */

declare(strict_types=1);

namespace BR\IonosCloud\Collector;

use RuntimeException;

/** A redacted transport or collection failure suitable for a local scope report. */
final class InventoryError extends RuntimeException
{
    /** Keep provider response bodies and credentials out of exception messages. */
    public function __construct(public readonly string $reason, public readonly int $status = 0)
    {
        parent::__construct($reason);
    }
}

/** HTTPS GET client for the published IONOS origins selected in the resource plan. */
final class IonosApi
{
    /**
     * @param list<string> $allowedBases Exact, trusted API origins including any version prefix.
     * @param string $contract Optional explicit Cloud API v6 contract number.
     * @throws InventoryError If token, contract or runtime configuration is invalid.
     */
    public function __construct(
        #[\SensitiveParameter] private readonly string $token,
        private readonly array $allowedBases,
        private readonly int $timeout = 20,
        private readonly string $contract = '',
    ) {
        if ($token === '' || preg_match('/[^\x21-\x7E]/', $token)) {
            throw new InventoryError('invalid_token_format');
        }
        if ($contract !== '' && !ctype_digit($contract)) {
            throw new InventoryError('invalid_contract');
        }
        if (!extension_loaded('curl')) {
            throw new InventoryError('php_curl_required');
        }
    }

    /**
     * Resolve the configured bearer credential from the process first, then the local dotenv file.
     * @param string $variable Environment variable name, never the credential itself.
     */
    public static function tokenFromEnvironment(string $file, string $variable = 'IONOS_TOKEN'): string
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $variable)) {
            throw new InventoryError('invalid_token_variable');
        }
        $value = getenv($variable);
        if ($value !== false && $value !== '') {
            return $value;
        }
        $lines = is_readable($file) ? file($file, FILE_IGNORE_NEW_LINES) : false;
        foreach ($lines ?: [] as $line) {
            $line = preg_replace('/^\xEF\xBB\xBF/', '', trim($line));
            if (preg_match('/^(?:export\s+)?' . $variable . '\s*=\s*(.*)$/D', $line, $matches)) {
                $raw = trim($matches[1]);
                if (strlen($raw) >= 2 && in_array($raw[0], ['"', "'"], true) && $raw[0] === substr($raw, -1)) {
                    $raw = substr($raw, 1, -1);
                }
                return $raw;
            }
        }
        throw new InventoryError('token_missing');
    }

    /**
     * Fetch one JSON response. Redirects are disabled so credentials stay on the specified origin.
     * @param array<string, int|string> $query Documented query parameters.
     * @return array<string,mixed> Provider response. Only projected fields are persisted by Inventory.
     * @throws InventoryError On malformed JSON, TLS, HTTP, permission, or transport failures.
     */
    public function get(string $base, string $path, array $query): array
    {
        if (!in_array($base, $this->allowedBases, true) || !str_starts_with($base, 'https://')
            || !str_starts_with($path, '/') || str_starts_with($path, '//') || str_contains($path, '?')) {
            throw new InventoryError('invalid_destination');
        }
        $url = $base . $path . ($query ? '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986) : '');
        $headers = ['Accept: application/json', 'Authorization: Bearer ' . $this->token];
        if ($this->contract !== '' && $base === 'https://api.ionos.com/cloudapi/v6') {
            $headers[] = 'X-Contract-Number: ' . $this->contract;
        }
        for ($attempt = 0; $attempt < 3; ++$attempt) {
            $curl = curl_init($url);
            $body = '';
            $retryAfter = null;
            curl_setopt_array($curl, [
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_USERAGENT => 'br-ionos-cloud-collector/0.1',
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_CONNECTTIMEOUT => min(8, $this->timeout),
                CURLOPT_TIMEOUT => $this->timeout,
                CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$retryAfter): int {
                    if (stripos($line, 'Retry-After:') === 0) {
                        $value = trim(substr($line, 12));
                        $retryAfter = ctype_digit($value) ? (int) $value : max(0, (int) strtotime($value) - time());
                    }
                    return strlen($line);
                },
                CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body): int {
                    if (strlen($body) + strlen($chunk) > 32 * 1024 * 1024) {
                        return 0;
                    }
                    $body .= $chunk;
                    return strlen($chunk);
                },
            ]);
            if (PHP_OS_FAMILY === 'Windows' && defined('CURLSSLOPT_NATIVE_CA')) {
                curl_setopt($curl, CURLOPT_SSL_OPTIONS, CURLSSLOPT_NATIVE_CA);
            }
            $ok = curl_exec($curl);
            $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            $errno = curl_errno($curl);
            curl_close($curl);
            if ($ok === false) {
                throw new InventoryError('transport_error_' . $errno);
            }
            if (in_array($status, [429, 502, 503, 504], true) && $attempt < 2 && ($retryAfter ?? 0) <= 8) {
                sleep($retryAfter ?? (1 + $attempt));
                continue;
            }
            if ($status !== 200) {
                throw new InventoryError(match ($status) {
                    401 => 'unauthenticated', 403 => 'forbidden', 404 => 'not_found',
                    429 => 'rate_limited', default => 'http_error',
                }, $status);
            }
            try {
                $envelope = json_decode($body, false, 256, JSON_THROW_ON_ERROR);
                if (!is_object($envelope) || (property_exists($envelope, 'items') && !is_array($envelope->items))
                    || (property_exists($envelope, 'data') && !is_array($envelope->data))) {
                    throw new InventoryError('invalid_response_shape');
                }
                if (str_ends_with($path, '/networkloadbalancers')) {
                    foreach ($envelope->items ?? [] as $item) {
                        $properties = $item->properties ?? null;
                        if (!is_object($properties)) {
                            throw new InventoryError('invalid_load_balancer_properties');
                        }
                        foreach (['ips', 'lbPrivateIps'] as $field) {
                            // Preserve the difference between a JSON object and an authoritative empty array.
                            if (property_exists($properties, $field) && !is_array($properties->$field)) {
                                throw new InventoryError('invalid_load_balancer_addresses');
                            }
                        }
                    }
                }
                unset($envelope);
                $json = json_decode($body, true, 256, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                throw new InventoryError('invalid_json');
            }
            if (!is_array($json)) {
                throw new InventoryError('invalid_response');
            }
            return $json;
        }
        throw new InventoryError('retry_limit');
    }
}
