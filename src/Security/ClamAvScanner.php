<?php

declare(strict_types=1);

namespace MonkeysLegion\Files\Security;

use MonkeysLegion\Files\Exception\VirusScanException;

/**
 * ClamAV virus scanner via Unix socket or TCP.
 */
class ClamAvScanner implements VirusScannerInterface
{
    private string $socketPath;
    private ?string $host;
    private ?int $port;
    private int $timeout;
    private int $chunkSize;

    public function __construct(
        string $socketPath = '/var/run/clamav/clamd.ctl',
        ?string $host = null,
        ?int $port = null,
        int $timeout = 30,
        int $chunkSize = 8192
    ) {
        $this->socketPath = $socketPath;
        $this->host = $host;
        $this->port = $port;
        $this->timeout = $timeout;
        $this->chunkSize = $chunkSize;
    }

    public function scan(string $path): ScanResult
    {
        if (!file_exists($path)) {
            throw new VirusScanException("File not found: {$path}");
        }

        $start = microtime(true);
        
        $socket = $this->connect();
        
        try {
            // Use SCAN command for local files (faster)
            if ($this->isLocalSocket()) {
                $response = $this->sendCommand($socket, "SCAN {$path}");
            } else {
                // Stream the file for remote scanning
                $stream = fopen($path, 'rb');
                if (!$stream) {
                    throw new VirusScanException("Cannot open file: {$path}");
                }
                
                try {
                    return $this->scanStream($stream);
                } finally {
                    fclose($stream);
                }
            }
            
            $scanTime = microtime(true) - $start;
            
            return $this->parseResponse($response, $scanTime);
        } finally {
            $this->disconnect($socket);
        }
    }

    public function scanStream($stream): ScanResult
    {
        if (!is_resource($stream)) {
            throw new VirusScanException('Invalid stream provided');
        }

        $start = microtime(true);
        
        $socket = $this->connect();
        
        try {
            // Start INSTREAM command
            $this->sendRaw($socket, "zINSTREAM\0");
            
            // Send file in chunks
            while (!feof($stream)) {
                $chunk = fread($stream, $this->chunkSize);
                
                if ($chunk === false) {
                    break;
                }
                
                $size = strlen($chunk);
                
                if ($size > 0) {
                    // Send chunk size (4-byte big-endian)
                    $this->sendRaw($socket, pack('N', $size));
                    $this->sendRaw($socket, $chunk);
                }
            }
            
            // Send zero-length chunk to signal end
            $this->sendRaw($socket, pack('N', 0));
            
            // Read response
            $response = $this->readResponse($socket);
            
            $scanTime = microtime(true) - $start;
            
            return $this->parseResponse($response, $scanTime);
        } finally {
            $this->disconnect($socket);
        }
    }

    public function isAvailable(): bool
    {
        try {
            $socket = $this->connect();
            $response = $this->sendCommand($socket, 'PING');
            $this->disconnect($socket);
            
            return trim($response) === 'PONG';
        } catch (\Throwable) {
            return false;
        }
    }

    public function getName(): string
    {
        return 'ClamAV';
    }

    /**
     * Get ClamAV version.
     */
    public function getVersion(): ?string
    {
        try {
            $socket = $this->connect();
            $response = $this->sendCommand($socket, 'VERSION');
            $this->disconnect($socket);
            
            return trim($response);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Reload virus definitions.
     */
    public function reload(): bool
    {
        try {
            $socket = $this->connect();
            $response = $this->sendCommand($socket, 'RELOAD');
            $this->disconnect($socket);
            
            return str_contains($response, 'RELOADING');
        } catch (\Throwable) {
            return false;
        }
    }

    private function connect()
    {
        if ($this->host !== null && $this->port !== null) {
            $socket = @fsockopen($this->host, $this->port, $errno, $errstr, $this->timeout);
        } else {
            $socket = @fsockopen("unix://{$this->socketPath}", -1, $errno, $errstr, $this->timeout);
        }

        if (!$socket) {
            throw new VirusScanException("Cannot connect to ClamAV: {$errstr}");
        }

        stream_set_timeout($socket, $this->timeout);
        
        return $socket;
    }

    private function disconnect($socket): void
    {
        if (is_resource($socket)) {
            fclose($socket);
        }
    }

    private function sendCommand($socket, string $command): string
    {
        $this->sendRaw($socket, "n{$command}\n");
        return $this->readResponse($socket);
    }

    private function sendRaw($socket, string $data): void
    {
        $written = fwrite($socket, $data);
        
        if ($written === false) {
            throw new VirusScanException('Failed to send data to ClamAV');
        }
    }

    private function readResponse($socket): string
    {
        $response = '';
        
        while (!feof($socket)) {
            $line = fgets($socket, 4096);
            
            if ($line === false) {
                break;
            }
            
            $response .= $line;
            
            // Check for end of response
            if (str_contains($line, "\0") || preg_match('/(OK|FOUND|ERROR)/', $line)) {
                break;
            }
        }
        
        return trim($response, "\0\n\r");
    }

    private function parseResponse(string $response, float $scanTime): ScanResult
    {
        // ClamAV response format: /path/to/file: OK or /path/to/file: Virus.Name FOUND
        if (str_ends_with($response, 'OK')) {
            return new ScanResult(
                isClean: true,
                scanner: $this->getName(),
                scanTime: $scanTime
            );
        }

        if (preg_match('/:\s*(.+)\s+FOUND$/i', $response, $matches)) {
            return new ScanResult(
                isClean: false,
                threat: trim($matches[1]),
                scanner: $this->getName(),
                scanTime: $scanTime
            );
        }

        if (str_contains($response, 'ERROR')) {
            throw new VirusScanException("ClamAV error: {$response}");
        }

        throw new VirusScanException("Unexpected ClamAV response: {$response}");
    }

    private function isLocalSocket(): bool
    {
        return $this->host === null || $this->port === null;
    }
}
