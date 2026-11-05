<?php

namespace App\Http\Controllers;

use App\Models\HikvisionDevice;
use App\Models\HikvisionEventLog;
use App\Models\HikvisionSyncRequest;
use App\Services\Hikvision\HikvisionAttendanceService;
use App\Services\Hikvision\HikvisionExcelImportService;
use App\Services\Hikvision\HikvisionIsapiClient;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class HikvisionController extends Controller
{
    private const MAX_BRIDGE_SYNC_DAYS = 62;

    public function __construct(
        private HikvisionIsapiClient $isapiClient,
        private HikvisionAttendanceService $attendanceService,
        private HikvisionExcelImportService $excelImportService,
    ) {
    }

    public function index()
    {
        $devices = HikvisionDevice::with('company:id,name')
            ->orderBy('name')
            ->get()
            ->map(fn (HikvisionDevice $d) => $this->formatDevice($d));

        return response()->json(['data' => $devices]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:120',
            'model' => 'nullable|string|max:120',
            'serial_number' => 'nullable|string|max:120',
            'ip_address' => 'required|ip',
            'port' => 'nullable|integer|min:1|max:65535',
            'username' => 'required|string|max:64',
            'password' => 'required|string|max:128',
            'company_id' => 'nullable|exists:companies,id',
            'is_active' => 'boolean',
            'webhook_enabled' => 'boolean',
            'polling_enabled' => 'boolean',
        ]);

        $device = new HikvisionDevice($validated);
        $device->password = $validated['password'];
        $device->port = $validated['port'] ?? 80;
        $device->save();

        return response()->json([
            'message' => 'Hikvision device saved',
            'data' => $this->formatDevice($device->fresh('company')),
        ], 201);
    }

    public function update(Request $request, int $id)
    {
        $device = HikvisionDevice::findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|string|max:120',
            'model' => 'nullable|string|max:120',
            'serial_number' => 'nullable|string|max:120',
            'ip_address' => 'sometimes|ip',
            'port' => 'nullable|integer|min:1|max:65535',
            'username' => 'sometimes|string|max:64',
            'password' => 'nullable|string|max:128',
            'company_id' => 'nullable|exists:companies,id',
            'is_active' => 'boolean',
            'webhook_enabled' => 'boolean',
            'polling_enabled' => 'boolean',
        ]);

        $device->fill($validated);

        if (!empty($validated['password'])) {
            $device->password = $validated['password'];
        }

        $device->save();

        return response()->json([
            'message' => 'Device updated',
            'data' => $this->formatDevice($device->fresh('company')),
        ]);
    }

    public function destroy(int $id)
    {
        HikvisionDevice::findOrFail($id)->delete();

        return response()->json(['message' => 'Device removed']);
    }

    public function testConnection(int $id)
    {
        $device = HikvisionDevice::findOrFail($id);
        $result = $this->isapiClient->testConnection($device);

        if (!($result['ok'] ?? false)) {
            $device->update(['last_error' => $result['message'] ?? 'Connection failed']);
            return response()->json($result, 422);
        }

        $device->update(['last_error' => null, 'last_sync_at' => now()]);

        return response()->json($result);
    }

    public function syncNow(int $id, Request $request)
    {
        $device = HikvisionDevice::findOrFail($id);

        $validated = $request->validate([
            'from_date' => 'nullable|date',
            'to_date' => 'nullable|date',
            'lookback_minutes' => 'nullable|integer|min:30|max:43200',
        ]);

        if ($this->isapiClient->shouldUseCloudBridge($device)) {
            return $this->queueBridgeSync($device, $validated, $request);
        }

        $from = isset($validated['from_date'])
            ? \Carbon\Carbon::parse($validated['from_date'])->startOfDay()
            : now()->subMinutes((int) ($validated['lookback_minutes'] ?? (60 * 24 * 7)));
        $to = isset($validated['to_date'])
            ? \Carbon\Carbon::parse($validated['to_date'])->endOfDay()
            : now();

        $result = $this->attendanceService->syncDevice($device, $from, $to);

        if (!($result['ok'] ?? false)) {
            $message = $result['message']
                ?? (($result['errors'][0] ?? null) ?: 'Sync failed');

            return response()->json([
                'message' => $message,
                'data' => $result,
            ], 422);
        }

        return response()->json([
            'message' => $result['message'] ?? 'Sync completed',
            'data' => $result,
        ]);
    }

    /** Create sync-queue tables if a live host has not run migrate yet (avoids Sync Now HTTP 503). */
    private function ensureSyncRequestTables(): void
    {
        if (!Schema::hasTable('hikvision_sync_requests') && Schema::hasTable('hikvision_devices')) {
            Schema::create('hikvision_sync_requests', function ($table) {
                $table->id();
                $table->foreignId('device_id')->constrained('hikvision_devices')->cascadeOnDelete();
                $table->date('from_date');
                $table->date('to_date');
                $table->string('status', 20)->default('pending');
                $table->unsignedBigInteger('requested_by')->nullable();
                $table->unsignedInteger('device_punches')->default(0);
                $table->unsignedInteger('imported')->default(0);
                $table->unsignedInteger('skipped')->default(0);
                $table->text('message')->nullable();
                $table->text('errors')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->timestamps();
                $table->index(['device_id', 'status']);
            });
        }

        if (Schema::hasTable('hikvision_devices') && !Schema::hasColumn('hikvision_devices', 'agent_last_seen_at')) {
            Schema::table('hikvision_devices', function ($table) {
                $table->timestamp('agent_last_seen_at')->nullable();
            });
        }
    }

    /** Cloud HR cannot reach the device, so the office bridge picks this range up on its next poll. */
    private function queueBridgeSync(HikvisionDevice $device, array $validated, Request $request)
    {
        $this->ensureSyncRequestTables();
        if (!Schema::hasTable('hikvision_sync_requests')) {
            return response()->json([
                'message' => 'Sync queue table missing. Run "php artisan migrate" on the Hilburn API, then try Sync Now again.',
            ], 422);
        }

        $to = isset($validated['to_date']) ? Carbon::parse($validated['to_date'])->startOfDay() : today();
        $from = isset($validated['from_date']) ? Carbon::parse($validated['from_date'])->startOfDay() : $to->copy()->subDays(6);
        if ($to->gt(today())) {
            $to = today();
        }
        if ($from->gt($to)) {
            return response()->json(['message' => 'From date must be on or before To date.'], 422);
        }
        if ($from->diffInDays($to) > self::MAX_BRIDGE_SYNC_DAYS) {
            return response()->json(['message' => 'Pick at most '.self::MAX_BRIDGE_SYNC_DAYS.' days per sync.'], 422);
        }

        $syncRequest = HikvisionSyncRequest::where('device_id', $device->id)
            ->whereIn('status', ['pending', 'running'])
            ->whereDate('from_date', $from)
            ->whereDate('to_date', $to)
            ->first()
            ?? HikvisionSyncRequest::create([
                'device_id' => $device->id,
                'from_date' => $from->toDateString(),
                'to_date' => $to->toDateString(),
                'status' => 'pending',
                'requested_by' => optional($request->user())->id,
            ]);

        $range = $from->toDateString() === $to->toDateString()
            ? $from->toDateString()
            : $from->toDateString().' to '.$to->toDateString();

        return response()->json([
            'message' => $device->agentOnline()
                ? "Sync for {$range} sent to the office PC. It starts within about a minute."
                : "Sync for {$range} is queued, but the office PC has not checked in recently. Make sure the fingerprint bridge PC is on — it will run as soon as it connects.",
            'data' => [
                'queued' => true,
                'mode' => 'cloud-bridge',
                'request' => $syncRequest,
                'agent_online' => $device->agentOnline(),
                'agent_last_seen_at' => $device->agent_last_seen_at,
            ],
        ], 202);
    }

    public function syncRequests(int $id)
    {
        $device = HikvisionDevice::findOrFail($id);
        $this->ensureSyncRequestTables();
        $requests = Schema::hasTable('hikvision_sync_requests')
            ? $device->syncRequests()->latest('id')->limit(10)->get()
            : collect();

        return response()->json([
            'data' => $requests,
            'agent_online' => $device->agentOnline(),
            'agent_last_seen_at' => $device->agent_last_seen_at,
        ]);
    }

    /** Office bridge: heartbeat + queued date ranges to re-read from the device. */
    public function agentSyncRequests(Request $request, string $token)
    {
        $device = HikvisionDevice::where('webhook_token', $token)->first();
        if (!$device) {
            return response()->json(['message' => 'Unknown device'], 404);
        }
        if (!$this->hikvisionSecretOk($request)) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }
        $this->ensureSyncRequestTables();
        if (!Schema::hasTable('hikvision_sync_requests')) {
            $device->forceFill(['agent_last_seen_at' => now()])->save();

            return response()->json(['data' => []]);
        }

        $device->forceFill(['agent_last_seen_at' => now()])->save();

        HikvisionSyncRequest::where('device_id', $device->id)
            ->where('status', 'running')
            ->where('started_at', '<', now()->subHour())
            ->update([
                'status' => 'failed',
                'message' => 'The office PC stopped before this sync finished. Click Sync Now again.',
                'finished_at' => now(),
            ]);

        $pending = HikvisionSyncRequest::where('device_id', $device->id)
            ->where('status', 'pending')
            ->orderBy('id')
            ->limit(3)
            ->get(['id', 'from_date', 'to_date']);

        return response()->json(['data' => $pending]);
    }

    public function agentSyncRequestUpdate(Request $request, string $token, int $requestId)
    {
        $device = HikvisionDevice::where('webhook_token', $token)->first();
        if (!$device) {
            return response()->json(['message' => 'Unknown device'], 404);
        }
        if (!$this->hikvisionSecretOk($request)) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $validated = $request->validate([
            'status' => 'required|in:running,done,failed',
            'device_punches' => 'nullable|integer|min:0',
            'imported' => 'nullable|integer|min:0',
            'skipped' => 'nullable|integer|min:0',
            'message' => 'nullable|string|max:2000',
            'errors' => 'nullable|array|max:50',
            'errors.*' => 'string|max:500',
        ]);

        $syncRequest = HikvisionSyncRequest::where('device_id', $device->id)->findOrFail($requestId);
        $syncRequest->fill($validated);
        if ($validated['status'] === 'running') {
            $syncRequest->started_at = now();
        } else {
            $syncRequest->finished_at = now();
        }
        $syncRequest->save();

        $device->forceFill(['agent_last_seen_at' => now()])->save();

        return response()->json(['message' => 'OK']);
    }

    public function configureWebhook(int $id)
    {
        $device = HikvisionDevice::findOrFail($id);
        $result = $this->isapiClient->configureWebhook($device);

        if (!($result['ok'] ?? false)) {
            return response()->json([
                'message' => $result['message'] ?? 'Failed to configure webhook on device',
                'webhook_url' => $device->webhookUrl(),
                'punches_url' => $device->punchesUrl(),
                'manual_setup_required' => true,
            ], 422);
        }

        return response()->json([
            'message' => 'Webhook configured on device',
            'webhook_url' => $device->webhookUrl(),
            'punches_url' => $device->punchesUrl(),
        ]);
    }

    public function webhook(Request $request, string $token)
    {
        $device = HikvisionDevice::where('webhook_token', $token)->first();

        if (!$device) {
            return response()->json(['message' => 'Unknown device'], 404);
        }

        if (!$this->hikvisionSecretOk($request)) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $result = $this->attendanceService->handleWebhook($device, $request);

        return response()->json(['message' => 'OK', 'data' => $result]);
    }

    /** Office PC bridge posts punches here (Solar parity). */
    public function punches(Request $request, string $token)
    {
        $device = HikvisionDevice::where('webhook_token', $token)->first();

        if (!$device) {
            return response()->json(['message' => 'Unknown device'], 404);
        }

        if (!$device->is_active) {
            return response()->json(['message' => 'Device inactive'], 403);
        }

        if (!$this->hikvisionSecretOk($request)) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $result = $this->attendanceService->handleAgentPunches($device, $request);

        return response()->json(['message' => 'OK', 'data' => $result]);
    }

    /** Browser GET — confirms the live punches URL before the office bridge POSTs. */
    public function punchesPing(string $token)
    {
        $device = HikvisionDevice::where('webhook_token', $token)->first();

        if (!$device) {
            return response()->json(['message' => 'Unknown device'], 404);
        }

        return response()->json([
            'ok' => true,
            'device' => $device->name,
            'active' => (bool) $device->is_active,
            'message' => 'HR Hikvision punches URL is live. POST JSON { punches: [...] } from the office bridge.',
        ]);
    }

    public function agentConfig(int $id)
    {
        $device = HikvisionDevice::findOrFail($id);

        return response()->json([
            'data' => [
                'device' => [
                    'name' => $device->name,
                    'ip' => $device->ip_address,
                    'port' => $device->port,
                    'username' => $device->username,
                    'password' => $device->password,
                ],
                'cloud' => [
                    'public_api_url' => HikvisionDevice::publicApiBase(),
                    'publicApiUrl' => HikvisionDevice::publicApiBase(),
                    'punches_url' => $device->punchesUrl(),
                    'webhook_url' => $device->webhookUrl(),
                    'poll_interval_seconds' => (int) config('hikvision.poll_interval_minutes', 5) * 60,
                    'secret_header' => config('hikvision.webhook_secret'),
                ],
                'instructions' => [
                    'Install Node.js 18+ on an office PC that can ping the device IP.',
                    'Copy scripts/hikvision-bridge from this backend repo to that PC.',
                    'Create .env from .env.example using the values below (Punches URL + device password).',
                    'Run: npm install && npm start (or install-autostart.bat on Windows).',
                    'Device user ID must equal HR Attendance Employee No.',
                ],
                'lan_mode' => $this->isapiClient->backendSharesOfficeLan((string) $device->ip_address),
            ],
        ]);
    }

    public function setupGuide()
    {
        return response()->json([
            'data' => [
                'summary' => 'If HR runs on the same office LAN as the device, use Test / Sync Now. If HR is in the cloud, run the on-site hikvision-bridge.',
                'steps' => [
                    [
                        'step' => 1,
                        'title' => 'Match employee IDs on the device',
                        'detail' => 'On the Hikvision terminal, set each user ID = Employee Attendance No in HR.',
                    ],
                    [
                        'step' => 2,
                        'title' => 'Register the device in HR',
                        'detail' => 'Time Card → Hikvision panel → Add Device (name, LAN IP, port 80, admin password).',
                    ],
                    [
                        'step' => 3,
                        'title' => 'LAN sync (office server)',
                        'detail' => 'Click Test Connection, then Sync Now. Fingerprint events (major 5 / minor 38) import to Time Card.',
                    ],
                    [
                        'step' => 4,
                        'title' => 'Cloud ERP — office bridge',
                        'detail' => 'Click Agent config → Download office .env into scripts/hikvision-bridge on an office PC, then npm start / install-autostart.bat.',
                    ],
                    [
                        'step' => 5,
                        'title' => 'Test a punch',
                        'detail' => 'Finger on device → Sync Now (LAN) or wait ~1 minute (bridge) → refresh Time Card.',
                    ],
                ],
                'public_api_url' => HikvisionDevice::publicApiBase(),
            ],
        ]);
    }

    public function cloudBase()
    {
        $base = HikvisionDevice::publicApiBase();

        return response()->json([
            'public_api_url' => $base,
            'publicApiUrl' => $base,
            'updated_at' => now()->toIso8601String(),
        ]);
    }

    public function eventLogs(int $id, Request $request)
    {
        $device = HikvisionDevice::findOrFail($id);

        $logs = HikvisionEventLog::where('device_id', $device->id)
            ->orderByDesc('event_time')
            ->limit((int) $request->query('limit', 50))
            ->get();

        return response()->json(['data' => $logs]);
    }

    public function importExcel(Request $request)
    {
        $validated = $request->validate([
            'file' => 'required|file|mimes:xlsx,xls,csv',
            'company_id' => 'required|exists:companies,id',
            'from_date' => 'required|date',
            'to_date' => 'nullable|date|after_or_equal:from_date',
        ]);

        $path = $request->file('file')->getRealPath();
        $result = $this->excelImportService->import(
            $path,
            (int) $validated['company_id'],
            $validated['from_date'],
            $validated['to_date'] ?? null
        );

        return response()->json([
            'message' => 'Import completed',
            'data' => $result,
        ]);
    }

    private function formatDevice(HikvisionDevice $device): array
    {
        $lanMode = $this->isapiClient->backendSharesOfficeLan((string) $device->ip_address);

        return [
            'id' => $device->id,
            'name' => $device->name,
            'model' => $device->model,
            'serial_number' => $device->serial_number,
            'ip_address' => $device->ip_address,
            'port' => $device->port,
            'username' => $device->username,
            'company_id' => $device->company_id,
            'company_name' => $device->company->name ?? null,
            'is_active' => $device->is_active,
            'webhook_enabled' => $device->webhook_enabled,
            'polling_enabled' => $device->polling_enabled,
            'webhook_url' => $device->webhookUrl(),
            'punches_url' => $device->punchesUrl(),
            'lan_mode' => $lanMode,
            'cloud_note' => $lanMode
                ? 'HR backend is on the office LAN. Test and Sync talk directly to the device.'
                : 'HR looks cloud/remote. Keep hikvision-bridge running on an office PC so fingerprints reach Punches URL.',
            'last_sync_at' => $device->last_sync_at,
            'last_event_at' => $device->last_event_at,
            'agent_last_seen_at' => $device->agent_last_seen_at,
            'agent_online' => $device->agentOnline(),
            'last_error' => $device->last_error,
        ];
    }

    /**
     * URL token is the credential. A global env secret is optional.
     * Never 503 when HIKVISION_WEBHOOK_SECRET is unset — that blocked the office bridge.
     */
    private function hikvisionSecretOk(Request $request): bool
    {
        $secret = trim((string) config('hikvision.webhook_secret'));
        if ($secret === '') {
            return true;
        }

        return hash_equals($secret, (string) $request->header('X-Hikvision-Secret', ''));
    }
}
