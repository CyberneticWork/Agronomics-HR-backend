<?php

namespace App\Services\Hikvision;

use App\Models\HikvisionDevice;
use Carbon\Carbon;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class HikvisionIsapiClient
{
    public function testConnection(HikvisionDevice $device): array
    {
        if ($this->shouldUseCloudBridge($device)) {
            return $this->cloudBridgeNotice($device, 'test');
        }

        $response = $this->request($device, 'GET', '/ISAPI/System/deviceInfo');

        if (!$response['ok']) {
            return $response;
        }

        $body = $response['body'];
        $info = [];

        if (is_array($body)) {
            $info = $body['DeviceInfo'] ?? $body;
        } elseif (is_string($body)) {
            $xml = @simplexml_load_string($body);
            if ($xml) {
                $info = json_decode(json_encode($xml), true);
            }
        }

        $model = $info['model'] ?? null;
        $serial = $info['serialNumber'] ?? null;

        if ($model || $serial) {
            $device->fill(array_filter([
                'model' => $model ?: $device->model,
                'serial_number' => $serial ?: $device->serial_number,
            ]))->save();
        }

        return [
            'ok' => true,
            'mode' => 'lan',
            'lan_mode' => true,
            'device_info' => $info,
            'message' => 'Connected successfully on office LAN'
                . ($model ? " · {$model}" : '')
                . ($serial ? " · SN {$serial}" : ''),
        ];
    }

    /**
     * Pull AcsEvent rows using Solar-proven major/minor combinations + TZ-aware window.
     */
    public function fetchAccessEvents(
        HikvisionDevice $device,
        string $startTime,
        string $endTime,
        int $position = 0,
        int $maxResults = 30,
        ?int $major = null,
        ?int $minor = null,
    ): array {
        $payload = [
            'AcsEventCond' => [
                'searchID' => 'hr-sync-' . $device->id . '-' . $major . '-' . $minor . '-' . time() . '-' . $position,
                'searchResultPosition' => $position,
                'maxResults' => $maxResults,
                'major' => $major ?? (int) config('hikvision.access_major_code', 5),
                'minor' => $minor ?? 0,
                'startTime' => $startTime,
                'endTime' => $endTime,
                'timeReverseOrder' => false,
            ],
        ];

        $response = $this->request($device, 'POST', '/ISAPI/AccessControl/AcsEvent?format=json', $payload);

        if (!$response['ok']) {
            return $response;
        }

        $body = $response['body'];
        $acs = is_array($body) ? ($body['AcsEvent'] ?? []) : [];
        $events = $acs['InfoList'] ?? [];

        if (isset($events['major']) || isset($events['employeeNoString']) || isset($events['employeeNo'])) {
            $events = [$events];
        }

        return [
            'ok' => true,
            'events' => is_array($events) ? array_values($events) : [],
            'num_of_matches' => (int) ($acs['numOfMatches'] ?? count($events)),
            'total_matches' => (int) ($acs['totalMatches'] ?? count($events)),
            'response_status' => $acs['responseStatusStrg'] ?? null,
        ];
    }

    /**
     * Multi-query pull like Solar DS-K1T320 (fingerprint minor 38, card, face, catch-all).
     */
    public function fetchAllAccessEvents(HikvisionDevice $device, Carbon $from, Carbon $to): array
    {
        if ($this->shouldUseCloudBridge($device)) {
            return $this->cloudBridgeNotice($device, 'sync');
        }

        $queries = config('hikvision.acs_event_queries', [
            ['major' => 5, 'minor' => 38],
            ['major' => 5, 'minor' => 1],
            ['major' => 5, 'minor' => 75],
            ['major' => 5, 'minor' => 76],
            ['major' => 0, 'minor' => 0],
        ]);

        $startTime = $this->isoLocal($from);
        $endTime = $this->isoLocal($to->copy()->addMinutes(15));

        $events = [];
        $seen = [];
        $errors = [];

        foreach ($queries as $q) {
            $position = 0;
            for ($page = 0; $page < 20; $page++) {
                $result = $this->fetchAccessEvents(
                    $device,
                    $startTime,
                    $endTime,
                    $position,
                    30,
                    (int) $q['major'],
                    (int) $q['minor']
                );

                if (!$result['ok']) {
                    $errors[] = $result['message'] ?? 'AcsEvent query failed';
                    break;
                }

                $rows = $result['events'] ?? [];
                foreach ($rows as $ev) {
                    if (!is_array($ev)) {
                        continue;
                    }
                    $key = ($ev['serialNo'] ?? 'x') . '|' . ($ev['time'] ?? $ev['dateTime'] ?? '') . '|'
                        . ($ev['employeeNoString'] ?? $ev['employeeNo'] ?? '');
                    if (isset($seen[$key])) {
                        continue;
                    }
                    $seen[$key] = true;
                    $events[] = $ev;
                }

                $num = (int) ($result['num_of_matches'] ?? count($rows));
                $total = (int) ($result['total_matches'] ?? 0);
                if (!$rows || ($result['response_status'] ?? '') === 'NO MATCH' || ($position + $num) >= $total) {
                    break;
                }
                $position += max($num, 1);
            }
        }

        usort($events, function ($a, $b) {
            $ta = strtotime($a['time'] ?? $a['dateTime'] ?? '0') ?: 0;
            $tb = strtotime($b['time'] ?? $b['dateTime'] ?? '0') ?: 0;
            return $ta <=> $tb;
        });

        return [
            'ok' => true,
            'mode' => 'lan',
            'lan_mode' => true,
            'events' => $events,
            'raw_events' => count($events),
            'errors' => array_values(array_unique($errors)),
        ];
    }

    public function configureWebhook(HikvisionDevice $device): array
    {
        $hostId = config('hikvision.http_host_id', 1);
        $url = $device->webhookUrl();

        $payload = [
            'HttpHostNotificationList' => [
                'HttpHostNotification' => [
                    [
                        'id' => $hostId,
                        'url' => $url,
                        'protocolType' => 'HTTP',
                        'parameterFormatType' => 'JSON',
                        'addressingFormatType' => 'hostname',
                        'portNo' => parse_url(config('app.url'), PHP_URL_PORT) ?: 80,
                        'httpAuthenticationMethod' => 'none',
                        'SubscribeEvent' => [
                            'eventMode' => 'all',
                            'EventList' => [
                                ['type' => 'AccessControllerEvent'],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $response = $this->request(
            $device,
            'PUT',
            '/ISAPI/Event/notification/httpHosts?format=json',
            $payload
        );

        if (!$response['ok']) {
            $xmlPayload = $this->buildHttpHostXml($hostId, $url);
            $response = $this->request(
                $device,
                'PUT',
                '/ISAPI/Event/notification/httpHosts/' . $hostId,
                $xmlPayload,
                'application/xml'
            );
        }

        return $response;
    }

    public function shouldUseCloudBridge(HikvisionDevice $device): bool
    {
        if ((string) config('hikvision.force_lan_mode') === '1') {
            return false;
        }
        if ((string) config('hikvision.force_cloud_bridge') === '1') {
            return true;
        }

        $ip = (string) $device->ip_address;
        if (!$this->isPrivateLanIp($ip)) {
            return false;
        }

        return !$this->backendSharesOfficeLan($ip);
    }

    public function backendSharesOfficeLan(string $deviceIp): bool
    {
        if ((string) config('hikvision.force_lan_mode') === '1') {
            return true;
        }
        if (!$this->isPrivateLanIp($deviceIp)) {
            return false;
        }

        foreach ($this->localIpv4s() as $local) {
            if ($this->sameOfficeNetwork($local, $deviceIp)) {
                return true;
            }
        }

        return false;
    }

    public function cloudBridgeNotice(HikvisionDevice $device, string $action): array
    {
        $stored = $device->eventLogs()->where('status', 'imported')->count();
        $last = $device->last_event_at
            ? ' Last punch: ' . $device->last_event_at->toDateTimeString() . '.'
            : '';

        $message = $action === 'sync'
            ? ($stored > 0
                ? "Time Card refreshed. {$stored} punch(es) already saved from the office bridge.{$last} Cloud ERP cannot read {$device->ip_address} directly — keep hikvision-bridge running."
                : "No punches yet. Cloud ERP cannot reach {$device->ip_address}. Run scripts/hikvision-bridge on an office PC.")
            : "Cloud ERP cannot ping {$device->ip_address}. That is normal. Fingerprints arrive via the office bridge.{$last}";

        return [
            'ok' => true,
            'mode' => 'cloud-bridge',
            'lan_mode' => false,
            'imported' => 0,
            'skipped' => 0,
            'stored' => $stored,
            'message' => $message,
            'punches_url' => $device->punchesUrl(),
            'webhook_url' => $device->webhookUrl(),
            'events' => [],
            'raw_events' => 0,
            'errors' => [],
        ];
    }

    /** Hikvision event windows need timezone offset (e.g. +05:30). */
    public function isoLocal(Carbon $d): string
    {
        return $d->format('Y-m-d\TH:i:sP');
    }

    private function isPrivateLanIp(string $ip): bool
    {
        $p = trim($ip);
        return (bool) preg_match('/^(192\.168\.|10\.|172\.(1[6-9]|2\d|3[0-1])\.|127\.0\.0\.1$|localhost$)/i', $p);
    }

    private function localIpv4s(): array
    {
        $out = [];
        foreach (gethostbynamel(gethostname()) ?: [] as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_RES_RANGE)) {
                $out[] = $ip;
            }
        }
        // Also scan common Windows/Linux interfaces via hostname resolution only —
        // SERVER_ADDR / REQUEST may be public when reverse-proxied.
        if (!empty($_SERVER['SERVER_ADDR']) && filter_var($_SERVER['SERVER_ADDR'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $out[] = $_SERVER['SERVER_ADDR'];
        }
        return array_values(array_unique($out));
    }

    private function sameOfficeNetwork(string $localIp, string $deviceIp): bool
    {
        $a = array_map('intval', explode('.', $localIp));
        $b = array_map('intval', explode('.', $deviceIp));
        if (count($a) !== 4 || count($b) !== 4) {
            return false;
        }
        if ($a[0] === 192 && $a[1] === 168 && $b[0] === 192 && $b[1] === 168) {
            return true;
        }
        if ($a[0] === 10 && $b[0] === 10) {
            return $a[1] === $b[1];
        }
        if ($a[0] === 172 && $b[0] === 172 && $a[1] >= 16 && $a[1] <= 31 && $b[1] >= 16 && $b[1] <= 31) {
            return $a[1] === $b[1];
        }
        return false;
    }

    private function buildHttpHostXml(int $hostId, string $url): string
    {
        $parsed = parse_url($url);
        $host = $parsed['host'] ?? '127.0.0.1';
        $port = $parsed['port'] ?? 80;
        $path = ($parsed['path'] ?? '/') . (isset($parsed['query']) ? '?' . $parsed['query'] : '');

        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<HttpHostNotification version="2.0" xmlns="http://www.isapi.org/ver20/XMLSchema">
  <id>{$hostId}</id>
  <url>{$path}</url>
  <protocolType>HTTP</protocolType>
  <parameterFormatType>JSON</parameterFormatType>
  <addressingFormatType>ipaddress</addressingFormatType>
  <ipAddress>{$host}</ipAddress>
  <portNo>{$port}</portNo>
  <httpAuthenticationMethod>none</httpAuthenticationMethod>
</HttpHostNotification>
XML;
    }

    private function request(HikvisionDevice $device, string $method, string $path, mixed $body = null, ?string $contentType = null): array
    {
        $password = $device->password;

        if (!$password) {
            return ['ok' => false, 'message' => 'Device password is missing or cannot be decrypted'];
        }

        $last = ['ok' => false, 'message' => 'Cannot reach device at '.$device->ip_address];

        foreach ($device->candidateBaseUrls() as $base) {
            $url = rtrim($base, '/').$path;
            $result = $this->sendRequest($device, $method, $url, $body, $contentType, $password);
            if ($result['ok'] ?? false) {
                return $result;
            }
            $last = $result;
            // Wrong password / locked admin — do not burn retries on alternate schemes.
            $status = (int) ($result['status'] ?? 0);
            if (in_array($status, [401, 403], true)) {
                return $result;
            }
        }

        return $last;
    }

    private function sendRequest(
        HikvisionDevice $device,
        string $method,
        string $url,
        mixed $body,
        ?string $contentType,
        string $password,
    ): array {
        try {
            $pending = Http::timeout(20)
                ->withOptions(['verify' => false, 'allow_redirects' => false])
                ->withDigestAuth($device->username, $password);

            if ($body !== null) {
                if ($contentType === 'application/xml') {
                    $response = $pending
                        ->withHeaders(['Content-Type' => 'application/xml'])
                        ->send($method, $url, ['body' => $body]);
                } elseif (is_array($body)) {
                    $response = $pending
                        ->acceptJson()
                        ->asJson()
                        ->send($method, $url, ['json' => $body]);
                } else {
                    $response = $pending->send($method, $url, ['body' => $body]);
                }
            } else {
                $response = $pending->send($method, $url);
            }

            // HTTP→HTTPS redirect means this base is wrong; try next candidate.
            if (in_array($response->status(), [301, 302, 307, 308], true)) {
                return [
                    'ok' => false,
                    'status' => $response->status(),
                    'message' => 'Device redirected from '.$url.' — trying HTTPS if available',
                ];
            }

            if (!$response->successful()) {
                Log::warning('Hikvision ISAPI request failed', [
                    'device_id' => $device->id,
                    'url' => $url,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                $hint = '';
                if ((int) $response->status() === 401) {
                    $hint = ' (check device web username/password; use Port 443 if the browser opens https://'.$device->ip_address.')';
                }

                return [
                    'ok' => false,
                    'status' => $response->status(),
                    'message' => 'Device returned HTTP '.$response->status().$hint,
                    'body' => $response->body(),
                ];
            }

            $responseBody = $response->body();
            $json = json_decode($responseBody, true);

            return [
                'ok' => true,
                'status' => $response->status(),
                'body' => $json ?? $responseBody,
            ];
        } catch (ConnectionException $e) {
            return [
                'ok' => false,
                'message' => 'Cannot reach '.$url.': '.$e->getMessage(),
            ];
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'message' => $e->getMessage(),
            ];
        }
    }
}
