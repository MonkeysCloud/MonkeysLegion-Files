<?php

declare(strict_types=1);

namespace MonkeysLegion\Files\Security;

use MonkeysLegion\Files\Exception\VirusScanException;

/**
 * HTTP-based virus scanner for cloud scanning services.
 * 
 * Compatible with VirusTotal, MetaDefender, and similar APIs.
 */
class HttpVirusScanner implements VirusScannerInterface
{
    private string $apiUrl;
    private string $apiKey;
    private int $timeout;
    private array $headers;
    private string $provider;

    public const PROVIDER_VIRUSTOTAL = 'virustotal';
    public const PROVIDER_METADEFENDER = 'metadefender';
    public const PROVIDER_CUSTOM = 'custom';

    public function __construct(
        string $apiUrl,
        string $apiKey,
        string $provider = self::PROVIDER_CUSTOM,
        int $timeout = 60,
        array $headers = []
    ) {
        $this->apiUrl = rtrim($apiUrl, '/');
        $this->apiKey = $apiKey;
        $this->provider = $provider;
        $this->timeout = $timeout;
        $this->headers = $headers;
    }

    public function scan(string $path): ScanResult
    {
        if (!file_exists($path)) {
            throw new VirusScanException("File not found: {$path}");
        }

        $stream = fopen($path, 'rb');
        
        if (!$stream) {
            throw new VirusScanException("Cannot open file: {$path}");
        }

        $meta = stream_get_meta_data($stream);
        $uri = $meta['uri'];
        $stat = fstat($stream);
        
        if ($stat === false || $stat['size'] === 0) {
            fclose($stream);
            throw new VirusScanException("Cannot read file or file is empty: {$path}");
        }

        try {
            return $this->scanStream($stream);
        } finally {
            fclose($stream);
        }
    }

    public function scanStream($stream): ScanResult
    {
        $start = microtime(true);
        
        return match ($this->provider) {
            self::PROVIDER_VIRUSTOTAL => $this->scanVirusTotal($stream, $start),
            self::PROVIDER_METADEFENDER => $this->scanMetaDefender($stream, $start),
            default => $this->scanCustom($stream, $start),
        };
    }

    public function isAvailable(): bool
    {
        try {
            $ch = curl_init($this->apiUrl);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 5,
                CURLOPT_NOBODY => true,
            ]);
            curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            return $httpCode !== 0;
        } catch (\Throwable) {
            return false;
        }
    }

    public function getName(): string
    {
        return match ($this->provider) {
            self::PROVIDER_VIRUSTOTAL => 'VirusTotal',
            self::PROVIDER_METADEFENDER => 'MetaDefender',
            default => 'HTTP Scanner',
        };
    }

    private function scanVirusTotal($stream, float $start): ScanResult
    {
        // Upload file
        $uploadUrl = $this->apiUrl . '/files';
        
        $tempFile = stream_get_meta_data($stream)['uri'];
        
        $postFields = [
            'file' => $tempFile 
                ? new \CURLFile($tempFile)
                : new \CURLStringFile(stream_get_contents($stream), 'file'),
        ];

        $response = $this->makeRequest('POST', $uploadUrl, [
            'x-apikey' => $this->apiKey,
        ], $postFields);

        if (!isset($response['data']['id'])) {
            throw new VirusScanException('Invalid VirusTotal upload response');
        }

        // Poll for analysis result
        $analysisId = $response['data']['id'];
        $analysisUrl = $this->apiUrl . '/analyses/' . $analysisId;
        
        $maxAttempts = 30;
        $attempt = 0;
        
        while ($attempt < $maxAttempts) {
            $result = $this->makeRequest('GET', $analysisUrl, [
                'x-apikey' => $this->apiKey,
            ]);

            $status = $result['data']['attributes']['status'] ?? '';
            
            if ($status === 'completed') {
                $stats = $result['data']['attributes']['stats'] ?? [];
                $malicious = (int)($stats['malicious'] ?? 0) + (int)($stats['suspicious'] ?? 0);
                
                return new ScanResult(
                    isClean: $malicious === 0,
                    threat: $malicious > 0 ? "{$malicious} engines detected threat" : null,
                    scanner: $this->getName(),
                    scanTime: microtime(true) - $start,
                    metadata: $stats
                );
            }
            
            sleep(2);
            $attempt++;
        }

        throw new VirusScanException('VirusTotal analysis timed out');
    }

    private function scanMetaDefender($stream, float $start): ScanResult
    {
        $uploadUrl = $this->apiUrl . '/file';
        
        $content = stream_get_contents($stream);
        
        $response = $this->makeRequest('POST', $uploadUrl, [
            'apikey' => $this->apiKey,
            'Content-Type' => 'application/octet-stream',
        ], $content, true);

        if (!isset($response['data_id'])) {
            throw new VirusScanException('Invalid MetaDefender upload response');
        }

        // Poll for result
        $dataId = $response['data_id'];
        $resultUrl = $this->apiUrl . '/file/' . $dataId;
        
        $maxAttempts = 30;
        $attempt = 0;
        
        while ($attempt < $maxAttempts) {
            $result = $this->makeRequest('GET', $resultUrl, [
                'apikey' => $this->apiKey,
            ]);

            $progress = $result['scan_results']['progress_percentage'] ?? 0;
            
            if ($progress >= 100) {
                $scanResult = $result['scan_results']['scan_all_result_a'] ?? 'Unknown';
                $totalDetected = $result['scan_results']['total_detected_avs'] ?? 0;
                
                return new ScanResult(
                    isClean: $totalDetected === 0,
                    threat: $totalDetected > 0 ? $scanResult : null,
                    scanner: $this->getName(),
                    scanTime: microtime(true) - $start,
                    metadata: $result['scan_results'] ?? []
                );
            }
            
            sleep(2);
            $attempt++;
        }

        throw new VirusScanException('MetaDefender analysis timed out');
    }

    private function scanCustom($stream, float $start): ScanResult
    {
        $content = stream_get_contents($stream);
        
        $headers = array_merge($this->headers, [
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Content-Type' => 'application/octet-stream',
        ]);

        $response = $this->makeRequest('POST', $this->apiUrl, $headers, $content, true);

        $isClean = $response['clean'] ?? $response['is_clean'] ?? true;
        $threat = $response['threat'] ?? $response['virus'] ?? null;

        return new ScanResult(
            isClean: (bool) $isClean,
            threat: $threat,
            scanner: $this->getName(),
            scanTime: microtime(true) - $start,
            metadata: $response
        );
    }

    private function makeRequest(
        string $method,
        string $url,
        array $headers,
        $data = null,
        bool $rawBody = false
    ): array {
        $ch = curl_init($url);
        
        $curlHeaders = [];
        foreach ($headers as $key => $value) {
            $curlHeaders[] = "{$key}: {$value}";
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_HTTPHEADER => $curlHeaders,
            CURLOPT_CUSTOMREQUEST => $method,
        ]);

        if ($data !== null) {
            if ($rawBody) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
            } else {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
            }
        }

        $response = curl_exec($ch);
        $error = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($error) {
            throw new VirusScanException("HTTP request failed: {$error}");
        }

        if ($httpCode >= 400) {
            throw new VirusScanException("HTTP error {$httpCode}: {$response}");
        }

        $decoded = json_decode($response, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new VirusScanException('Invalid JSON response from scanner');
        }

        return $decoded;
    }
}
