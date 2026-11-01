<?php

namespace App\Services;

use App\Models\company;
use App\Models\employee;

class CompanyProcessSettings
{
    public const SPM_STANDARD = 'spm_standard';
    public const SHIFT_ROSTER = 'shift_roster';

    public static function catalog(): array
    {
        return [
            [
                'key' => self::SPM_STANDARD,
                'label' => 'SPM current process',
                'group' => 'Attendance & payroll',
                'summary' => 'One roster shift per day. Working hours are the full IN–OUT span. OT, late, NoPay and salary stay on the current SPM Tax rules.',
                'affects' => ['attendance', 'ot', 'late', 'nopay', 'working_hours', 'salary'],
                'available' => true,
            ],
            [
                'key' => self::SHIFT_ROSTER,
                'label' => 'Multiple roster (manual table)',
                'group' => 'Attendance & payroll',
                'summary' => 'One employee can work more than one roster on the same day (e.g. R1 06:00–13:00 and R3 15:00–20:00). Overnight rosters such as R4 18:00–02:00 next day are allowed. Early/late IN–OUT are recorded; OT or penalty is applied from company config.',
                'affects' => ['attendance', 'ot', 'late', 'nopay', 'working_hours', 'salary', 'roster_calendar'],
                'available' => true,
            ],
            [
                'key' => 'leave_workflow',
                'label' => 'Covering-person leave workflow',
                'group' => 'Leave',
                'summary' => 'Off = keep the current leave process. On = portal balance + apply, mandatory covering person, then supervisor, then HR. Notifications on approve/reject. Email is sent when configured; SMS only if a provider is set.',
                'affects' => ['leave', 'employee_portal', 'leave_calendar'],
                'available' => true,
            ],
            [
                'key' => 'weekly_off',
                'label' => 'Weekly off management',
                'group' => 'Leave',
                'summary' => 'Off = keep the current day-off field only. On = earned weekly offs (1.5/week permanent max 6, 1/week contract-intern-probation max 4), portal apply, carry-forward, and an HR weekly-off schedule employees can see after approve.',
                'affects' => ['weekly_off', 'employee_portal'],
                'available' => true,
            ],
            [
                'key' => 'medical_leave',
                'label' => 'Medical leave with evidence',
                'group' => 'Leave',
                'summary' => 'Off = hide medical leave on the employee portal. On = separate medical-leave request, optional report upload, HR cannot approve until evidence is attached, and days deduct Casual first then Annual.',
                'affects' => ['leave', 'employee_portal'],
                'available' => true,
            ],
            [
                'key' => 'medical_claims',
                'label' => 'Medical claims',
                'group' => 'Benefits',
                'summary' => 'Off = no medical-claim module. On = portal bill upload, quota vs approved+pending, HR approve/reject, and approved claims go to Pending Payments.',
                'affects' => ['medical_claims', 'employee_portal', 'pending_payments'],
                'available' => true,
            ],
            [
                'key' => 'salary_advance',
                'label' => 'Salary advance quota',
                'group' => 'Benefits',
                'summary' => 'Off = keep the current portal advance (no quota). On = show available amount, validate the request, portal notify on HR decision, and approved advances go to Pending Payments. HR create/approve can deduct from basic salary or monthly bonus (employee portal cannot choose).',
                'affects' => ['salary_advance', 'employee_portal', 'pending_payments'],
                'available' => true,
            ],
            [
                'key' => 'reland_excel_import',
                'label' => 'Reland fingerprint Excel import',
                'group' => 'Attendance',
                'summary' => 'Off = hide Reland import on Time Card. On = HR can upload Raw Clock-InOut Log.xls from Reland and write IN/OUT punches to time cards for this company.',
                'affects' => ['attendance', 'time_card'],
                'available' => true,
            ],
            [
                'key' => 'hr_company_create',
                'label' => 'HR can create companies',
                'group' => 'Organization',
                'summary' => 'Off = only Cybernetic Admin creates companies. On = HR users of this company with Department Master "add" permission can add companies (code, name, location, established, NoPay days) from Department Master. New companies join the same organization group; branding, portal URL and add-ons stay Cybernetic Admin only.',
                'affects' => ['department_master'],
                'available' => true,
            ],
            [
                'key' => 'contract_as_permanent',
                'label' => 'Contract employees follow the permanent process',
                'group' => 'Attendance & payroll',
                'summary' => 'Off = contract employees are kept out of the normal process and appear only in Contract Time Attendance. On = contract employees of this company appear everywhere permanent employees do: employee lists, time cards, attendance and absent reports, overtime, leave, dashboard counts and salary processing.',
                'affects' => ['employees', 'attendance', 'time_card', 'leave', 'overtime', 'salary'],
                'available' => true,
            ],
            [
                'key' => 'late_grace_nopay',
                'label' => 'Variable allowance attendance (late grace NoPay)',
                'group' => 'Attendance & payroll',
                'summary' => 'Off = keep the current late NoPay rules. On = each day, minutes late after start time + grace (e.g. 07:15 + 15 min) are added up for the month; every 30 minutes reached = 1 NoPay day, deducted from monthly bonus (variable allowance) or basic. Replaces the other late NoPay deductions in salary.',
                'affects' => ['late', 'nopay', 'salary'],
                'available' => true,
            ],
            [
                'key' => 'qualifications',
                'label' => 'Employee qualifications',
                'group' => 'Employee Master',
                'summary' => 'Off = no qualifications section. On = the Education step in Employee Master records any number of completed qualifications (type, course, institute, completion year).',
                'affects' => ['employee_master'],
                'available' => true,
            ],
            [
                'key' => 'following_qualifications',
                'label' => 'Following qualifications',
                'group' => 'Employee Master',
                'summary' => 'Off = no following section. On = the Education step records qualifications the employee is studying for: name, institute, starting and ending year/month, and Weekday or Weekend lectures.',
                'affects' => ['employee_master'],
                'available' => true,
            ],
            [
                'key' => 'previous_employment',
                'label' => 'Previous employment information',
                'group' => 'Employee Master',
                'summary' => 'Off = no previous employment step. On = Employee Master records past employers: organization name, last designation, join date, last date and comments (50 words max).',
                'affects' => ['employee_master'],
                'available' => true,
            ],
            [
                'key' => 'ol_results',
                'label' => 'O/L results',
                'group' => 'Employee Master',
                'summary' => 'Off = no O/L fields. On = the Education step records the O/L English grade, Mathematics grade and year sat.',
                'affects' => ['employee_master'],
                'available' => true,
            ],
            [
                'key' => 'al_results',
                'label' => 'A/L details',
                'group' => 'Employee Master',
                'summary' => 'Off = no A/L fields. On = the Education step records the A/L syllabus (National, Cambridge, AQA), subject stream and year sat.',
                'affects' => ['employee_master'],
                'available' => true,
            ],
      [
    'key' => 'fish_deduction',
    'label' => 'Fish deduction management',
    'group' => 'Deductions & benefits',
    'summary' => 'Off = hide Fish Deduction module. On = HR can record fish purchases/deductions for employees and deduct total FISH from monthly salary slips.',
    'affects' => ['deductions', 'salary'],
    'available' => true,
],
[
    'key' => 'stamp_duty_paye_holiday',
    'label' => 'Stamp Duty, PAYE, No-Pay and Off-Day + Holiday Processing',
    'group' => 'Payroll & statutory',
    'summary' => 'Off = hide PAYE, Holiday and Off-Day modules. On = enable employee settings for Stamp Duty, PAYE/APIT, Holiday Processing, separate PAYE Management, and Off-Day + Holiday Management pages.',
    'affects' => ['salary', 'deductions', 'paye', 'off_day_holiday', 'nopay'],
    'available' => true,
],
[
    'key' => 'jaysea_food_salary_process',
    'label' => 'Jaysea Food Salary Process',
    'group' => 'Payroll & statutory',
    'summary' => 'Off = standard salary calculation. On = Jaysea Food salary sequence: Gross = Basic + Holiday + Off-Day + Increment - No-Pay; EPF/ETF basis = Gross Salary (EPF 8%, Employer EPF 12%, ETF 3%); Net = Gross - EPF 8% - PAYE - Stamp Duty - other deductions + allowances.',
    'affects' => ['salary', 'epf', 'etf', 'payslip'],
    'available' => true,
],
[
    'key' => 'spm_reports',
    'label' => 'SPM Reports',
    'group' => 'Reports',
    'summary' => 'Off = default reports. On = enable updated SPM reports (Schedule 3: Salary Advance, Shift Hours, Daily OT, Monthly Hours) and SPM-specific formatting.',
    'affects' => ['reports', 'allowances_report', 'timecard_reports'],
    'available' => true,
],
            [
                'key' => 'holiday_calendar',
                'label' => 'Holiday calendar pack',
                'group' => 'Coming soon',
                'summary' => 'Per-company public holiday and OT holiday mapping.',
                'affects' => ['ot', 'nopay', 'salary'],
                'available' => false,
            ],
        ];
    }

    public static function normalize(?string $mode): string
    {
        $mode = strtolower(trim((string) $mode));
        if ($mode === self::SHIFT_ROSTER) {
            return self::SHIFT_ROSTER;
        }

        return self::SPM_STANDARD;
    }

    public static function modeOf($source): string
    {
        if ($source instanceof company) {
            return self::normalize($source->attendance_process ?? null);
        }
        if ($source instanceof employee) {
            $company = $source->organizationAssignment?->company;
            if ($company instanceof company) {
                return self::modeOf($company);
            }
            $id = $source->organizationAssignment?->company_id;
            if ($id) {
                $company = company::find($id);
                return self::modeOf($company);
            }
            return self::SPM_STANDARD;
        }
        if (is_numeric($source)) {
            return self::modeOf(company::find((int) $source));
        }

        return self::SPM_STANDARD;
    }

    public static function usesShiftRoster($source): bool
    {
        return self::modeOf($source) === self::SHIFT_ROSTER;
    }

    public const OT_CURRENT = 'current';
    public const OT_MINUTE_BAND = 'minute_band';
    public const OT_SHIFT_END_BAND = 'shift_end_band';

    public static function otHourMode($source): string
    {
        $company = null;
        if ($source instanceof company) {
            $company = $source;
        } elseif ($source instanceof employee) {
            $source->loadMissing('organizationAssignment.company');
            $company = $source->organizationAssignment?->company;
        } elseif (is_numeric($source)) {
            $company = company::find((int) $source);
        }

        $cfg = is_array($company?->process_config) ? $company->process_config : [];
        $mode = strtolower(trim((string) ($cfg['ot_hour_calculation'] ?? self::OT_CURRENT)));
        $allowed = [self::OT_CURRENT, self::OT_MINUTE_BAND, self::OT_SHIFT_END_BAND];

        return in_array($mode, $allowed, true) ? $mode : self::OT_CURRENT;
    }

    public static function usesMinuteBandOt($source): bool
    {
        return self::otHourMode($source) === self::OT_MINUTE_BAND;
    }

    public static function usesShiftEndBandOt($source): bool
    {
        return self::otHourMode($source) === self::OT_SHIFT_END_BAND;
    }

    public static function otMinimumHours($source): float
    {
        $mode = self::otHourMode($source);
        if (in_array($mode, [self::OT_MINUTE_BAND, self::OT_SHIFT_END_BAND], true)) {
            return 0.0;
        }

        return 0.5;
    }

    public static function otHoursFromMinutes(int $minutes, $source): float
    {
        $minutes = max(0, $minutes);
        $mode = self::otHourMode($source);
        if ($mode === self::OT_SHIFT_END_BAND) {
            return self::roundShiftEndBand($minutes);
        }
        if ($mode === self::OT_MINUTE_BAND) {
            return self::roundMinuteBand($minutes);
        }

        return round(((int) floor($minutes / 30) * 30) / 60, 2);
    }

    /**
     * After shift end: 0–30m = 0. From 31m = 0.30. From 45m = 0.45.
     * From 60m = 1.00. After the first full hour, every 15 minutes adds 0.15.
     */
    public static function roundShiftEndBand(int $minutes): float
    {
        $minutes = max(0, $minutes);
        $hours = intdiv($minutes, 60);
        $mins = $minutes % 60;

        if ($hours === 0) {
            if ($mins <= 30) {
                return 0.0;
            }
            if ($mins >= 45) {
                return 0.45;
            }

            return 0.30;
        }

        if ($mins <= 14) {
            return (float) $hours;
        }
        if ($mins <= 29) {
            return $hours + 0.15;
        }
        if ($mins <= 44) {
            return $hours + 0.30;
        }

        return $hours + 0.45;
    }

    public static function roundMinuteBand(int $minutes): float
    {
        $minutes = max(0, $minutes);
        $hours = intdiv($minutes, 60);
        $mins = $minutes % 60;

        if ($hours === 0) {
            if ($mins >= 45) {
                return 0.45;
            }
            if ($mins >= 30) {
                return 0.30;
            }

            return 0.0;
        }

        if ($mins <= 14) {
            return (float) $hours;
        }
        if ($mins <= 29) {
            return $hours + 0.15;
        }
        if ($mins <= 44) {
            return $hours + 0.30;
        }

        return $hours + 0.45;
    }

    public static function rosterPolicy($source): array
    {
        $company = null;
        if ($source instanceof company) {
            $company = $source;
        } elseif ($source instanceof employee) {
            $source->loadMissing('organizationAssignment.company');
            $company = $source->organizationAssignment?->company;
        } elseif (is_numeric($source)) {
            $company = company::find((int) $source);
        }

        $cfg = is_array($company?->process_config) ? $company->process_config : [];
        $multi = is_array($cfg['multi_roster'] ?? null) ? $cfg['multi_roster'] : [];

        $allowed = ['ot', 'penalty', 'record_only'];
        $pick = function ($key, $default) use ($multi, $allowed) {
            $value = strtolower(trim((string) ($multi[$key] ?? $default)));
            return in_array($value, $allowed, true) ? $value : $default;
        };

        return [
            'early_in' => $pick('early_in', 'ot'),
            'late_in' => $pick('late_in', 'penalty'),
            'early_out' => $pick('early_out', 'penalty'),
            'late_out' => $pick('late_out', 'ot'),
        ];
    }

    public static function earlyInIsOt($source): bool
    {
        return self::rosterPolicy($source)['early_in'] === 'ot';
    }

    public static function lateOutIsOt($source): bool
    {
        return self::rosterPolicy($source)['late_out'] === 'ot';
    }

    public static function usesLeaveWorkflow($source): bool
    {
        return self::packEnabled($source, 'leave_workflow');
    }

    public static function usesWeeklyOff($source): bool
    {
        return self::packEnabled($source, 'weekly_off');
    }

    public static function usesMedicalClaims($source): bool
    {
        return self::packEnabled($source, 'medical_claims');
    }

    public static function usesMedicalLeave($source): bool
    {
        return self::packEnabled($source, 'medical_leave');
    }

    public static function isMedicalLeaveType(?string $type): bool
    {
        $normalized = strtolower(trim((string) $type));

        return $normalized !== '' && str_contains($normalized, 'medical') && !str_contains($normalized, 'claim');
    }

    public static function usesSalaryAdvancePack($source): bool
    {
        return self::packEnabled($source, 'salary_advance');
    }

    /** HR create/approve only: deduct salary advance from basic or monthly bonus. */
    public static function salaryAdvanceHrDeductFrom($source): string
    {
        $cfg = self::packConfig($source, 'salary_advance');
        $v = strtolower(trim((string) ($cfg['hr_deduct_from'] ?? 'bonus')));

        return $v === 'basic' ? 'basic' : 'bonus';
    }

    public static function usesRelandExcelImport($source): bool
    {
        return self::packEnabled($source, 'reland_excel_import');
    }

    public static function usesQualifications($source): bool
    {
        return self::packEnabled($source, 'qualifications');
    }

    public static function usesFollowingQualifications($source): bool
    {
        return self::packEnabled($source, 'following_qualifications');
    }

    public static function usesPreviousEmployment($source): bool
    {
        return self::packEnabled($source, 'previous_employment');
    }

    public static function usesOlResults($source): bool
    {
        return self::packEnabled($source, 'ol_results');
    }

    public static function usesAlResults($source): bool
    {
        return self::packEnabled($source, 'al_results');
    }

    public static function usesHrCompanyCreate($source): bool
    {
        return self::packEnabled($source, 'hr_company_create');
    }

    public static function usesLateGraceNoPay($source): bool
    {
        return self::packEnabled($source, 'late_grace_nopay');
    }

    public static function usesContractAsPermanent($source): bool
    {
        return self::packEnabled($source, 'contract_as_permanent');
    }
public static function usesFishDeduction($source): bool
{
    return self::packEnabled($source, 'fish_deduction');
}

public static function usesStampDutyPayeHoliday($source): bool
{
    return self::packEnabled($source, 'stamp_duty_paye_holiday');
}

public static function stampDutyAmount($source): float
{
    $company = self::companyOf($source);
    $cfg = is_array($company?->process_config) ? $company->process_config : [];

    // 1. Pack-specific config
    if (isset($cfg['stamp_duty_paye_holiday']['stamp_duty_amount']) && is_numeric($cfg['stamp_duty_paye_holiday']['stamp_duty_amount'])) {
        return (float) $cfg['stamp_duty_paye_holiday']['stamp_duty_amount'];
    }

    if (isset($cfg['stamp_duty_paye_holiday']['amount']) && is_numeric($cfg['stamp_duty_paye_holiday']['amount'])) {
        return (float) $cfg['stamp_duty_paye_holiday']['amount'];
    }

    // 2. Company process_config
    if (isset($cfg['stamp_duty_amount']) && is_numeric($cfg['stamp_duty_amount'])) {
        return (float) $cfg['stamp_duty_amount'];
    }

    // 3. Default stamp duty
    return 25.0;
}

public static function usesJayseaFoodSalaryProcess($source): bool
{
    return self::packEnabled($source, 'jaysea_food_salary_process');
}

public static function usesSpmReports($source): bool
{
    return self::packEnabled($source, 'spm_reports');
}
    public static function packConfig($source, string $key): array
    {
        $cfg = is_array(self::companyOf($source)?->process_config)
            ? self::companyOf($source)->process_config
            : [];

        return is_array($cfg[$key] ?? null) ? $cfg[$key] : [];
    }

    public static function packEnabled($source, string $key): bool
    {
        $cfg = is_array(self::companyOf($source)?->process_config)
            ? self::companyOf($source)->process_config
            : [];
        $flag = $cfg[$key] ?? false;
        if (is_array($flag)) {
            return !empty($flag['enabled']);
        }

        return filter_var($flag, FILTER_VALIDATE_BOOLEAN);
    }

    public static function companyOf($source): ?company
    {
        if ($source instanceof company) {
            return $source;
        }
        if ($source instanceof employee) {
            $source->loadMissing('organizationAssignment.company');
            return $source->organizationAssignment?->company;
        }
        if (is_numeric($source)) {
            return company::find((int) $source);
        }

        return null;
    }

    public static function mobilePunchForEmployee(employee $employee): array
    {
        $employee->loadMissing('organizationAssignment.company', 'organizationAssignment.department');
        $cfg = self::packConfig($employee, 'mobile_punch');
        $company = $employee->organizationAssignment?->company;
        $deptId = (int) ($employee->organizationAssignment?->department_id ?? 0);
        $enabledFlag = !empty($cfg['enabled']);
        $scope = strtolower(trim((string) ($cfg['scope'] ?? 'company')));
        if (!in_array($scope, ['company', 'department'], true)) {
            $scope = 'company';
        }
        $deptIds = array_values(array_unique(array_map('intval', $cfg['department_ids'] ?? [])));
        $inScope = false;
        if ($enabledFlag && $scope === 'department') {
            $inScope = $deptId > 0 && in_array($deptId, $deptIds, true);
        } elseif ($enabledFlag) {
            $inScope = true;
        }

        $lat = is_numeric($cfg['latitude'] ?? null) ? (float) $cfg['latitude'] : null;
        $lng = is_numeric($cfg['longitude'] ?? null) ? (float) $cfg['longitude'] : null;
        $deptPins = is_array($cfg['department_locations'] ?? null) ? $cfg['department_locations'] : [];
        $pin = $deptPins[(string) $deptId] ?? $deptPins[$deptId] ?? null;
        if (is_array($pin)) {
            if (is_numeric($pin['latitude'] ?? null)) {
                $lat = (float) $pin['latitude'];
            }
            if (is_numeric($pin['longitude'] ?? null)) {
                $lng = (float) $pin['longitude'];
            }
        }

        $gpsSet = $lat !== null && $lng !== null;
        $radius = (float) ($cfg['radiusMeters'] ?? $cfg['radius_meters'] ?? 400);
        $office = trim((string) ($cfg['officeName'] ?? $cfg['office_name'] ?? $company?->name ?? 'Office')) ?: 'Office';

        $disabledReason = '';
        if (!$enabledFlag) {
            $disabledReason = 'Cybernetic Admin has not enabled mobile fingerprint for this company.';
        } elseif (!$inScope) {
            $disabledReason = $scope === 'department'
                ? 'Mobile fingerprint is only allowed for selected departments.'
                : 'Mobile fingerprint is not available for your login.';
        } elseif (!$gpsSet) {
            $disabledReason = 'Office location is not set. Ask Cybernetic Admin to save the premises pin.';
        }

        return [
            'enabled' => $enabledFlag && $inScope && $gpsSet,
            'inScope' => $inScope,
            'scope' => $scope,
            'gpsRequired' => true,
            'latitude' => $lat,
            'longitude' => $lng,
            'radiusMeters' => $radius > 20 ? min($radius, 2000) : 400,
            'officeName' => $office,
            'requireBiometric' => array_key_exists('requireBiometric', $cfg)
                ? !empty($cfg['requireBiometric'])
                : !empty($cfg['require_biometric'] ?? true),
            'disabledReason' => $disabledReason,
            'departmentId' => $deptId ?: null,
            'departmentName' => $employee->organizationAssignment?->department?->name,
        ];
    }
}
