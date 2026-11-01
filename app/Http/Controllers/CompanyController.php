<?php

namespace App\Http\Controllers;

use App\Models\company;
use App\Services\CompanyHostScope;
use App\Services\CompanyProcessSettings;
use App\Services\CompanyThemeService;
use App\Services\CyberneticAdminAuth;
use App\Services\FirebaseStorageService;
use App\Services\GoogleDriveLogoService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class CompanyController extends Controller
{
    public function __construct(
        private CompanyThemeService $themeService,
        private CyberneticAdminAuth $cyberneticAuth,
        private GoogleDriveLogoService $driveLogos,
        private FirebaseStorageService $firebase,
    ) {
    }

    public function index(Request $request)
    {
        $cols = [
            'id', 'company_code', 'name', 'location', 'established',
        ];
        foreach ([
            'nopay_working_days', 'default_sports_fund_percentage',
            'slug', 'frontend_host', 'org_group', 'logo_url', 'theme_primary', 'theme_secondary', 'theme_accent', 'theme_json',
            'late_attendance_policy_enabled',
            'portal_active',
            'attendance_process',
            'process_config',
        ] as $col) {
            if (Schema::hasColumn('companies', $col)) {
                $cols[] = $col;
            }
        }

        $query = company::select($cols);
        $showAll = $request->boolean('all') && $this->cyberneticAuth->checkRequest($request);
        if (!$showAll) {
            $query = CompanyHostScope::apply($query, $request);
        }

        return response()->json($query->get());
    }

    /** Public branding for login / tenant host (no auth). */
    public function publicBranding(Request $request)
    {
        $host = strtolower(trim((string) $request->query('host', '')));
        $host = preg_replace('/^www\./', '', $host);
        $slug = strtolower(trim((string) $request->query('slug', '')));
        $cacheKey = 'public-branding:'.($host ?: 'local').':'.($slug ?: '-');
        $build = fn () => $this->buildPublicBrandingPayload($host, $slug);

        try {
            $payload = Cache::remember($cacheKey, 120, $build);
        } catch (\Throwable $e) {
            $payload = $build();
        }

        return response()->json(['data' => $payload]);
    }

    private function buildPublicBrandingPayload(string $host, string $slug): ?array
    {
        $localHosts = ['localhost', '127.0.0.1', '::1', ''];
        $query = company::query()->whereNull('deleted_at');
        $hasHostCol = Schema::hasColumn('companies', 'frontend_host');
        $hasActiveCol = Schema::hasColumn('companies', 'portal_active');
        $active = $hasActiveCol
            ? (clone $query)->where('portal_active', true)->orderByDesc('updated_at')->first()
            : null;
        $host = CompanyHostScope::normalizeHost($host);
        $hostCompany = null;
        if ($host !== '' && !in_array($host, $localHosts, true) && $hasHostCol) {
            // Stored hosts may include https:// or a trailing slash (e.g. "https://bdchrnew.cyberneticde.site/").
            $hostCompany = (clone $query)
                ->whereNotNull('frontend_host')
                ->orderByDesc('updated_at')
                ->get()
                ->first(fn ($row) => CompanyHostScope::normalizeHost((string) $row->frontend_host) === $host);
        }

        $company = $hostCompany ?: $active;
        if (!$company && $slug !== '' && Schema::hasColumn('companies', 'slug')) {
            $company = (clone $query)->whereRaw('LOWER(slug) = ?', [$slug])->first();
        }
        if (!$company) {
            // No active portal and no host match: use the company Cybernetic Admin last branded.
            $company = (clone $query)
                ->where(function ($q) {
                    $q->whereNotNull('logo_url')->where('logo_url', '!=', '');
                    if (Schema::hasColumn('companies', 'theme_primary')) {
                        $q->orWhere(fn ($t) => $t->whereNotNull('theme_primary')->where('theme_primary', '!=', ''));
                    }
                })
                ->orderByDesc('updated_at')
                ->first();
        }

        return $company ? $this->brandingPayload($company) : null;
    }

    /** Public image for login branding (works for Google Drive and local files). */
    public function publicLogo($id)
    {
        $company = company::find($id);
        if (!$company || !$company->logo_url) {
            abort(404);
        }

        if ($this->driveLogos->isDriveValue($company->logo_url)) {
            $image = $this->driveLogos->fetchImage($company->logo_url);
            if ($image) {
                return response($image['body'], 200)
                    ->header('Content-Type', $image['type'])
                    ->header('Cache-Control', 'public, max-age=3600');
            }
            abort(404);
        }

        return redirect()->away($company->logo_url);
    }

    public function activatePortal(Request $request, $id)
    {
        if (!$this->cyberneticAuth->checkRequest($request)) {
            return response()->json([
                'message' => 'Only Cybernetic Admin can activate a company portal brand.',
            ], 401);
        }

        $company = company::findOrFail($id);
        $this->makePortalActive($company);
        $this->forgetPublicBrandingCache($company);

        return response()->json($company->fresh());
    }

    public function store(Request $request)
    {
        if (!$this->cyberneticAuth->checkRequest($request)) {
            return response()->json([
                'message' => 'Only Cybernetic Admin can create companies and branding.',
            ], 401);
        }

        $validator = Validator::make($request->all(), [
            'company_code' => 'required|string|max:50',
            'name' => 'required|string|max:255',
            'location' => 'nullable|string|max:255',
            'established' => 'nullable|digits:4|integer|min:1900|max:' . (date('Y')),
            'nopay_working_days' => 'nullable|integer|min:1|max:31',
            'slug' => 'nullable|string|max:80',
            'frontend_host' => 'nullable|string|max:191',
            'org_group' => 'nullable|string|max:80',
            'logo_url' => 'nullable|string|max:1000',
            'theme_primary' => 'nullable|string|max:20',
            'theme_secondary' => 'nullable|string|max:20',
            'theme_accent' => 'nullable|string|max:20',
            'late_attendance_policy_enabled' => 'sometimes|boolean',
            'portal_active' => 'sometimes|boolean',
            'attendance_process' => 'sometimes|string|in:spm_standard,shift_roster',
            'process_config' => 'sometimes|array',
        ], [
            'company_code.required' => 'Company ID is required. Please enter a unique code (e.g. SPM-S, SPM-C).',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $validated = $this->normalizeCompanyPayload($validator->validated());

        $codeExists = company::where('company_code', $validated['company_code'])
            ->whereNull('deleted_at')
            ->exists();

        if ($codeExists) {
            return response()->json([
                'message' => 'Company ID already exists.',
                'errors' => ['company_code' => ['This Company ID is already in use. Please enter a unique code.']],
            ], 409);
        }

        $company = company::create($this->onlyExistingColumns($validated));
        if (!empty($validated['portal_active'])) {
            $this->makePortalActive($company);
        }
        $this->forgetPublicBrandingCache($company);
        return response()->json($company->fresh(), 201);
    }

    public function update(Request $request, $id)
    {
        $company = company::findOrFail($id);
        $cybernetic = $this->cyberneticAuth->checkRequest($request);

        $rules = [
            'location' => 'nullable|string|max:255',
            'established' => 'nullable|digits:4|integer|min:1900|max:' . (date('Y')),
            'nopay_working_days' => 'nullable|integer|min:1|max:31',
        ];
        if ($cybernetic) {
            $rules['company_code'] = 'required|string|max:50';
            $rules['name'] = 'required|string|max:255';
            $rules['slug'] = 'nullable|string|max:80';
            $rules['frontend_host'] = 'nullable|string|max:191';
            $rules['org_group'] = 'nullable|string|max:80';
            $rules['logo_url'] = 'nullable|string|max:1000';
            $rules['theme_primary'] = 'nullable|string|max:20';
            $rules['theme_secondary'] = 'nullable|string|max:20';
            $rules['theme_accent'] = 'nullable|string|max:20';
            $rules['late_attendance_policy_enabled'] = 'sometimes|boolean';
            $rules['portal_active'] = 'sometimes|boolean';
            $rules['attendance_process'] = 'sometimes|string|in:spm_standard,shift_roster';
            $rules['process_config'] = 'sometimes|array';
        } else {
            $rules['company_code'] = 'sometimes|string|max:50';
            $rules['name'] = 'sometimes|string|max:255';
        }

        $validator = Validator::make($request->all(), $rules, [
            'company_code.required' => 'Company ID is required.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $validated = $validator->validated();
        if (isset($validated['company_code'])) {
            $validated['company_code'] = strtoupper(trim($validated['company_code']));
        }
        if (isset($validated['name'])) {
            $validated['name'] = trim($validated['name']);
        }
        if (isset($validated['location'])) {
            $validated['location'] = trim($validated['location']);
        }

        if (!$request->exists('nopay_working_days')) {
            unset($validated['nopay_working_days']);
        } else {
            $validated['nopay_working_days'] = (int) $validated['nopay_working_days'];
            if ($validated['nopay_working_days'] < 1) {
                $validated['nopay_working_days'] = 30;
            }
        }

        if (!$cybernetic) {
            unset(
                $validated['slug'],
                $validated['frontend_host'],
                $validated['logo_url'],
                $validated['theme_primary'],
                $validated['theme_secondary'],
                $validated['theme_accent'],
                $validated['theme_json']
            );
        } else {
            $validated = $this->applyBrandingFields($validated, $company);
            if (Schema::hasColumn('companies', 'late_attendance_policy_enabled') && $request->exists('late_attendance_policy_enabled')) {
                $validated['late_attendance_policy_enabled'] = filter_var($request->input('late_attendance_policy_enabled'), FILTER_VALIDATE_BOOLEAN);
            }
            if (Schema::hasColumn('companies', 'portal_active') && $request->exists('portal_active')) {
                $validated['portal_active'] = filter_var($request->input('portal_active'), FILTER_VALIDATE_BOOLEAN);
            }
            if (Schema::hasColumn('companies', 'attendance_process') && $request->exists('attendance_process')) {
                $validated['attendance_process'] = CompanyProcessSettings::normalize($request->input('attendance_process'));
            }
            if (Schema::hasColumn('companies', 'process_config') && $request->exists('process_config')) {
                $incoming = $request->input('process_config');
                $validated['process_config'] = is_array($incoming)
                    ? array_merge((array) ($company->process_config ?? []), $incoming)
                    : $company->process_config;
            }
        }

        if (isset($validated['company_code'])) {
            $codeExists = company::where('company_code', $validated['company_code'])
                ->where('id', '!=', $company->id)
                ->whereNull('deleted_at')
                ->exists();
            if ($codeExists) {
                return response()->json([
                    'message' => 'Company ID already exists.',
                    'errors' => ['company_code' => ['This Company ID is already in use. Please enter a unique code.']],
                ], 409);
            }
        }

        $company->update($this->onlyExistingColumns($validated));
        if (!empty($validated['portal_active'])) {
            $this->makePortalActive($company->fresh());
        }
        $this->forgetPublicBrandingCache($company);
        return response()->json($company->fresh());
    }

    public function uploadLogo(Request $request, $id)
    {
        if (!$this->cyberneticAuth->checkRequest($request)) {
            return response()->json(['message' => 'Only Cybernetic Admin can upload company logos.'], 401);
        }

        $company = company::findOrFail($id);
        $request->validate([
            'logo' => 'nullable|file|mimes:png,jpg,jpeg,webp,svg|max:4096',
            'logo_url' => 'nullable|string|max:1000',
        ]);

        $url = $this->driveLogos->normalizeStoredUrl($request->input('logo_url'));
        $theme = null;
        if ($request->hasFile('logo')) {
            $file = $request->file('logo');
            $uploaded = null;
            if ($this->firebase->isConfigured()) {
                $uploaded = $this->firebase->upload($file, 'hr/company-logos');
            } else {
                throw new \RuntimeException('Firebase Storage is not configured. Company logos must be uploaded to Firebase.');
            }
            $url = $uploaded;
            $theme = $this->themeService->themeFromLogoFile($file->getRealPath());
        }

        if (!$url) {
            return response()->json(['message' => 'Choose a logo file or paste a Google Drive link.'], 422);
        }

        $payload = ['logo_url' => $url];
        if ($theme) {
            $payload['theme_primary'] = $theme['primary'];
            $payload['theme_secondary'] = $theme['secondary'];
            $payload['theme_accent'] = $theme['accent'];
            $payload['theme_json'] = $theme;
        }
        $company->update($payload);
        $this->forgetPublicBrandingCache($company);

        return response()->json([
            'message' => 'Logo saved',
            'data' => $company->fresh(),
            'theme' => $theme,
        ]);
    }

    public function destroy($id)
    {
        $company = company::findOrFail($id);
        $company->delete();
        $this->forgetPublicBrandingCache($company);
        return response()->json(['message' => 'Deleted'], 204);
    }

    private function normalizeCompanyPayload(array $validated): array
    {
        $validated['company_code'] = strtoupper(trim($validated['company_code']));
        $validated['name'] = trim($validated['name']);
        $validated['location'] = isset($validated['location']) ? trim($validated['location']) : null;
        $validated['nopay_working_days'] = (int) ($validated['nopay_working_days'] ?? 30);
        if ($validated['nopay_working_days'] < 1) {
            $validated['nopay_working_days'] = 30;
        }
        if (Schema::hasColumn('companies', 'late_attendance_policy_enabled') && array_key_exists('late_attendance_policy_enabled', $validated)) {
            $validated['late_attendance_policy_enabled'] = filter_var($validated['late_attendance_policy_enabled'], FILTER_VALIDATE_BOOLEAN);
        }
        if (Schema::hasColumn('companies', 'portal_active') && array_key_exists('portal_active', $validated)) {
            $validated['portal_active'] = filter_var($validated['portal_active'], FILTER_VALIDATE_BOOLEAN);
        }
        if (Schema::hasColumn('companies', 'attendance_process')) {
            $validated['attendance_process'] = CompanyProcessSettings::normalize($validated['attendance_process'] ?? CompanyProcessSettings::SPM_STANDARD);
        }
        if (Schema::hasColumn('companies', 'process_config') && array_key_exists('process_config', $validated) && !is_array($validated['process_config'])) {
            unset($validated['process_config']);
        }

        return $this->applyBrandingFields($validated, null);
    }

    private function applyBrandingFields(array $validated, ?company $existing): array
    {
        if (!Schema::hasColumn('companies', 'slug')) {
            unset(
                $validated['slug'],
                $validated['frontend_host'],
                $validated['logo_url'],
                $validated['theme_primary'],
                $validated['theme_secondary'],
                $validated['theme_accent'],
                $validated['theme_json']
            );
            return $validated;
        }

        $code = $validated['company_code'] ?? $existing?->company_code ?? 'company';
        $slug = $this->themeService->slugFromCode((string) ($validated['slug'] ?? $existing?->slug ?? $code));
        $validated['slug'] = $slug;
        $validated['frontend_host'] = CompanyHostScope::normalizeHost(
            (string) ($validated['frontend_host'] ?? $existing?->frontend_host ?? $this->themeService->hostFromSlug($slug))
        );
        if (array_key_exists('org_group', $validated) || $existing === null) {
            $group = strtolower(trim((string) ($validated['org_group'] ?? $existing?->org_group ?? '')));
            $group = preg_replace('/[^a-z0-9_-]/', '', $group) ?: '';
            $validated['org_group'] = $group !== '' ? $group : null;
        }
        if (array_key_exists('logo_url', $validated) && $validated['logo_url']) {
            $validated['logo_url'] = $this->driveLogos->normalizeStoredUrl($validated['logo_url']);
        }

        foreach (['theme_primary', 'theme_secondary', 'theme_accent'] as $key) {
            if (!empty($validated[$key])) {
                $validated[$key] = $this->themeService->normalizeHex($validated[$key]) ?? $validated[$key];
            }
        }
        if (!empty($validated['theme_primary'])) {
            $validated['theme_json'] = array_filter([
                'primary' => $validated['theme_primary'] ?? null,
                'secondary' => $validated['theme_secondary'] ?? null,
                'accent' => $validated['theme_accent'] ?? null,
            ]);
        }

        return $validated;
    }

    private function forgetPublicBrandingCache(?company $company = null): void
    {
        $hosts = ['local', 'localhost', '127.0.0.1', '::1'];
        if (Schema::hasColumn('companies', 'frontend_host')) {
            foreach (company::query()->pluck('frontend_host') as $h) {
                $h = CompanyHostScope::normalizeHost((string) $h);
                if ($h !== '') {
                    $hosts[] = $h;
                }
            }
        }
        if ($company?->frontend_host) {
            $hosts[] = CompanyHostScope::normalizeHost((string) $company->frontend_host);
        }
        $slugs = ['-'];
        if (Schema::hasColumn('companies', 'slug')) {
            foreach (company::query()->pluck('slug') as $s) {
                $s = strtolower(trim((string) $s));
                if ($s !== '') {
                    $slugs[] = $s;
                }
            }
        }
        if ($company?->slug) {
            $slugs[] = strtolower(trim((string) $company->slug));
        }
        foreach (array_unique($hosts) as $host) {
            foreach ($slugs as $slug) {
                Cache::forget('public-branding:'.$host.':'.$slug);
            }
        }
    }

    private function brandingPayload(company $company): array
    {
        $theme = $company->theme_json ?: [];
        return [
            'id' => $company->id,
            'name' => $company->name,
            'company_code' => $company->company_code,
            'location' => $company->location,
            'portal_active' => (bool) ($company->portal_active ?? false),
            'slug' => $company->slug,
            'frontend_host' => $company->frontend_host,
            'org_group' => $company->org_group,
            'logo_url' => $this->publicLogoUrl($company),
            'hr_url' => CompanyHostScope::normalizeHost((string) $company->frontend_host) !== ''
                ? 'https://'.CompanyHostScope::normalizeHost((string) $company->frontend_host)
                : null,
            'process_config' => $company->process_config,
            'theme' => [
                'primary' => $company->theme_primary ?: ($theme['primary'] ?? '#0B4F5C'),
                'secondary' => $company->theme_secondary ?: ($theme['secondary'] ?? '#0D9488'),
                'accent' => $company->theme_accent ?: ($theme['accent'] ?? '#FF6B4A'),
                'ink' => $theme['ink'] ?? '#062A32',
                'surface' => $theme['surface'] ?? '#F3FBF9',
            ],
        ];
    }

    private function onlyExistingColumns(array $payload): array
    {
        $kept = [];
        foreach ($payload as $key => $value) {
            if (Schema::hasColumn('companies', $key)) {
                $kept[$key] = $value;
            }
        }

        return $kept;
    }

    private function makePortalActive(company $company): void
    {
        if (!Schema::hasColumn('companies', 'portal_active')) {
            return;
        }

        company::query()->where('id', '!=', $company->id)->update(['portal_active' => false]);
        $company->forceFill(['portal_active' => true])->save();
    }

    private function publicLogoUrl(company $company): ?string
    {
        if (!$company->logo_url) {
            return null;
        }

        return $this->driveLogos->displayUrl($company->logo_url) ?: $company->logo_url;
    }
}
