<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

class HikvisionDevice extends Model
{
    protected $fillable = [
        'name',
        'model',
        'serial_number',
        'ip_address',
        'port',
        'username',
        'password_encrypted',
        'company_id',
        'is_active',
        'webhook_enabled',
        'polling_enabled',
        'webhook_token',
        'last_serial_no',
        'last_sync_at',
        'last_event_at',
        'agent_last_seen_at',
        'last_error',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'webhook_enabled' => 'boolean',
        'polling_enabled' => 'boolean',
        'last_serial_no' => 'integer',
        'last_sync_at' => 'datetime',
        'last_event_at' => 'datetime',
        'agent_last_seen_at' => 'datetime',
    ];

    protected $hidden = [
        'password_encrypted',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $device) {
            if (empty($device->webhook_token)) {
                $device->webhook_token = Str::random(48);
            }
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(company::class);
    }

    public function eventLogs(): HasMany
    {
        return $this->hasMany(HikvisionEventLog::class, 'device_id');
    }

    public function syncRequests(): HasMany
    {
        return $this->hasMany(HikvisionSyncRequest::class, 'device_id');
    }

    /** The office bridge checks for queued syncs every poll (default 60s). */
    public function agentOnline(): bool
    {
        return $this->agent_last_seen_at !== null && $this->agent_last_seen_at->gt(now()->subMinutes(5));
    }

    public function setPasswordAttribute(?string $password): void
    {
        if ($password !== null && $password !== '') {
            $this->attributes['password_encrypted'] = Crypt::encryptString($password);
        }
    }

    public function getPasswordAttribute(): ?string
    {
        if (empty($this->password_encrypted)) {
            return null;
        }

        try {
            return Crypt::decryptString($this->password_encrypted);
        } catch (\Throwable) {
            return null;
        }
    }

    public function baseUrl(): string
    {
        $port = (int) ($this->port ?: 80);
        $scheme = $port === 443 ? 'https' : 'http';

        return sprintf('%s://%s:%d', $scheme, $this->ip_address, $port);
    }

    /**
     * Bases to try for ISAPI. Many terminals advertise port 80 but only speak HTTPS on 443
     * (HTTP redirects / Digest breaks across the redirect).
     */
    public function candidateBaseUrls(): array
    {
        $ip = trim((string) $this->ip_address);
        $port = (int) ($this->port ?: 80);
        $urls = [$this->baseUrl()];

        if ($port === 80) {
            $urls[] = sprintf('https://%s:443', $ip);
        } elseif ($port !== 443) {
            $urls[] = sprintf('https://%s:%d', $ip, $port);
            $urls[] = sprintf('http://%s:%d', $ip, $port);
        }

        return array_values(array_unique(array_filter($urls)));
    }

    public static function publicApiBase(): string
    {
        $base = rtrim((string) config('app.url'), '/');
        $origin = preg_replace('#/index\.php$#i', '', $base) ?: $base;
        $host = strtolower((string) (parse_url($origin, PHP_URL_HOST) ?: ''));
        if ($host !== '' && str_contains($host, 'cyberneticde.site')) {
            return $origin.'/index.php';
        }

        return $base;
    }

    public function webhookUrl(): string
    {
        return self::frontControllerUrl('/api/hikvision/webhook/'.$this->webhook_token);
    }

    public function punchesUrl(): string
    {
        return self::frontControllerUrl('/api/hikvision/punches/'.$this->webhook_token);
    }

    public static function frontControllerUrl(string $apiPath): string
    {
        $apiPath = '/'.ltrim($apiPath, '/');
        $base = self::publicApiBase();
        if (preg_match('#/index\.php$#i', $base)) {
            return preg_replace('#/index\.php$#i', '', $base).'/index.php?__lr='.$apiPath;
        }

        return $base.$apiPath;
    }
}
