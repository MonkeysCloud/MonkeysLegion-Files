<?php

declare(strict_types=1);

namespace MonkeysLegion\Files\Cdn;

use DateTimeInterface;
use MonkeysLegion\Files\Exception\ConfigurationException;

/**
 * CDN URL generator with support for signed URLs.
 * 
 * Generates CDN URLs with optional signatures for CloudFront, BunnyCDN,
 * KeyCDN, and generic HMAC-based signing.
 */
class CdnUrlGenerator
{
    private string $baseUrl;
    private ?string $signingKey;
    private int $defaultTtl;
    private string $provider;

    /**
     * CDN providers.
     */
    public const PROVIDER_CLOUDFRONT = 'cloudfront';
    public const PROVIDER_BUNNY = 'bunny';
    public const PROVIDER_KEYCDN = 'keycdn';
    public const PROVIDER_GENERIC = 'generic';

    public function __construct(
        string $baseUrl,
        ?string $signingKey = null,
        int $defaultTtl = 3600,
        string $provider = self::PROVIDER_GENERIC
    ) {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->signingKey = $signingKey;
        $this->defaultTtl = $defaultTtl;
        $this->provider = $provider;
    }

    /**
     * Generate a public CDN URL (no signing).
     */
    public function url(string $path): string
    {
        return $this->baseUrl . '/' . ltrim($path, '/');
    }

    /**
     * Generate a signed/temporary URL.
     */
    public function signedUrl(
        string $path,
        DateTimeInterface|int|null $expiration = null,
        array $options = []
    ): string {
        if ($this->signingKey === null) {
            throw new ConfigurationException('CDN signing key is not configured');
        }

        $expiration = $this->resolveExpiration($expiration);

        return match ($this->provider) {
            self::PROVIDER_CLOUDFRONT => $this->signCloudFront($path, $expiration, $options),
            self::PROVIDER_BUNNY => $this->signBunny($path, $expiration, $options),
            self::PROVIDER_KEYCDN => $this->signKeyCdn($path, $expiration, $options),
            default => $this->signGeneric($path, $expiration, $options),
        };
    }

    /**
     * Generate URL with cache-busting query parameter.
     */
    public function versionedUrl(string $path, string $version): string
    {
        $url = $this->url($path);
        $separator = str_contains($url, '?') ? '&' : '?';
        
        return $url . $separator . 'v=' . urlencode($version);
    }

    /**
     * Generate URL with transformation parameters (for image CDNs).
     */
    public function transformUrl(string $path, array $transforms): string
    {
        $url = $this->url($path);
        
        if (empty($transforms)) {
            return $url;
        }

        $params = [];
        
        foreach ($transforms as $key => $value) {
            if ($value !== null) {
                $params[] = $key . '=' . urlencode((string) $value);
            }
        }
        
        $separator = str_contains($url, '?') ? '&' : '?';
        
        return $url . $separator . implode('&', $params);
    }

    /**
     * Sign URL using CloudFront-style canned policy.
     */
    private function signCloudFront(string $path, int $expiration, array $options): string
    {
        $url = $this->url($path);
        $keyPairId = $options['key_pair_id'] ?? throw new ConfigurationException(
            'CloudFront requires key_pair_id option'
        );

        // For CloudFront, signing_key should be the private key path or content
        $privateKey = $this->signingKey;
        
        if (file_exists($privateKey)) {
            $privateKey = file_get_contents($privateKey);
        }

        // Create canned policy
        $policy = json_encode([
            'Statement' => [[
                'Resource' => $url,
                'Condition' => [
                    'DateLessThan' => [
                        'AWS:EpochTime' => $expiration,
                    ],
                ],
            ]],
        ], JSON_UNESCAPED_SLASHES);

        // Sign with RSA-SHA1
        $signature = '';
        openssl_sign($policy, $signature, $privateKey, OPENSSL_ALGO_SHA1);
        
        // CloudFront-safe base64
        $signature = strtr(base64_encode($signature), '+/=', '-_~');

        $separator = str_contains($url, '?') ? '&' : '?';
        
        return sprintf(
            '%s%sExpires=%d&Signature=%s&Key-Pair-Id=%s',
            $url,
            $separator,
            $expiration,
            $signature,
            $keyPairId
        );
    }

    /**
     * Sign URL using BunnyCDN token authentication.
     */
    private function signBunny(string $path, int $expiration, array $options): string
    {
        $url = $this->url($path);
        $parsedUrl = parse_url($url);
        
        $signaturePath = $parsedUrl['path'] ?? '/';
        
        // Optional: include user IP for additional security
        $userIp = $options['ip'] ?? null;
        
        // Build hash base
        $hashableBase = $this->signingKey . $signaturePath . $expiration;
        
        if ($userIp) {
            $hashableBase .= $userIp;
        }
        
        // Generate token
        $token = hash('sha256', $hashableBase);
        $token = strtr(base64_encode(hex2bin($token)), '+/', '-_');
        $token = rtrim($token, '=');

        $separator = str_contains($url, '?') ? '&' : '?';
        $tokenUrl = $url . $separator . 'token=' . $token . '&expires=' . $expiration;
        
        if ($userIp) {
            $tokenUrl .= '&token_path=' . urlencode($signaturePath);
        }
        
        return $tokenUrl;
    }

    /**
     * Sign URL using KeyCDN URL tokens.
     */
    private function signKeyCdn(string $path, int $expiration, array $options): string
    {
        $url = $this->url($path);
        $parsedUrl = parse_url($url);
        
        $signaturePath = $parsedUrl['path'] ?? '/';
        
        // KeyCDN token format
        $token = md5($this->signingKey . $signaturePath . $expiration);

        $separator = str_contains($url, '?') ? '&' : '?';
        
        return sprintf(
            '%s%st=%s&e=%d',
            $url,
            $separator,
            $token,
            $expiration
        );
    }

    /**
     * Sign URL using generic HMAC-SHA256.
     */
    private function signGeneric(string $path, int $expiration, array $options): string
    {
        $url = $this->url($path);
        
        // Build the string to sign
        $stringToSign = $path . "\n" . $expiration;
        
        // Generate HMAC signature
        $signature = hash_hmac('sha256', $stringToSign, $this->signingKey);
        
        // URL-safe base64
        $signature = rtrim(strtr(base64_encode(hex2bin($signature)), '+/', '-_'), '=');

        $separator = str_contains($url, '?') ? '&' : '?';
        
        return sprintf(
            '%s%sexpires=%d&signature=%s',
            $url,
            $separator,
            $expiration,
            $signature
        );
    }

    /**
     * Verify a generic signed URL.
     */
    public function verifySignature(string $url): bool
    {
        if ($this->signingKey === null) {
            return false;
        }

        $parsedUrl = parse_url($url);
        
        if (!isset($parsedUrl['query'])) {
            return false;
        }

        parse_str($parsedUrl['query'], $params);
        
        $expires = (int) ($params['expires'] ?? 0);
        $signature = $params['signature'] ?? '';
        
        // Check expiration
        if ($expires < time()) {
            return false;
        }
        
        // Rebuild signature
        $path = $parsedUrl['path'] ?? '/';
        $stringToSign = $path . "\n" . $expires;
        $expectedSignature = hash_hmac('sha256', $stringToSign, $this->signingKey);
        $expectedSignature = rtrim(strtr(base64_encode(hex2bin($expectedSignature)), '+/', '-_'), '=');
        
        return hash_equals($expectedSignature, $signature);
    }

    /**
     * Resolve expiration to Unix timestamp.
     */
    private function resolveExpiration(DateTimeInterface|int|null $expiration): int
    {
        if ($expiration === null) {
            return time() + $this->defaultTtl;
        }

        if ($expiration instanceof DateTimeInterface) {
            return $expiration->getTimestamp();
        }

        // Treat as seconds from now if small, otherwise as timestamp
        if ($expiration < 1000000000) {
            return time() + $expiration;
        }

        return $expiration;
    }

    /**
     * Get the base URL.
     */
    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    /**
     * Get the provider.
     */
    public function getProvider(): string
    {
        return $this->provider;
    }

    /**
     * Check if signing is configured.
     */
    public function canSign(): bool
    {
        return $this->signingKey !== null;
    }
}
