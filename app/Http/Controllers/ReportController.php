<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\salary_process;
use App\Services\ScheduleReportService;

class ReportController extends Controller
{
    public function getScheduleReportData(Request $request, ScheduleReportService $scheduleReports)
    {
        $month = $request->query('month');
        $year = $request->query('year');
        $companyId = $request->query('company_id');
        if (!$month || !$year) {
            return response()->json(['message' => 'Month and year are required'], 422);
        }

        return response()->json($scheduleReports->build((string)$month, (string)$year, $companyId ? (int)$companyId : null));
    }

    public function getMonthlyReportData(Request $request)
    {
        $month = $request->query('month');
        $year = $request->query('year');
        $companyId = $request->query('company_id');
        $months = [str_pad($month, 2, '0', STR_PAD_LEFT), (int)$month, (string)(int)$month];

        $salariesQuery = salary_process::with([
            'employee.compensation',
            'employee.organizationAssignment.company'
        ])
            ->whereIn('month', $months)
            ->where('year', $year)
            ->whereIn('status', ['pending', 'processed', 'issued']);

        if ($companyId) {
            $salariesQuery->whereHas('employee.organizationAssignment', function ($q) use ($companyId) {
                $q->where('company_id', $companyId);
            });
        }

        $salaries = $salariesQuery->get();

        $monthInt = max(1, (int)$month);
        $ytdStaffFundPaid = self::loadYtdStaffFundPaid($year, $monthInt, $companyId ? (int)$companyId : null);

        $data = $salaries->map(function ($s) use ($ytdStaffFundPaid, $monthInt) {
            $breakdown = is_string($s->salary_breakdown) ? json_decode($s->salary_breakdown, true) : ($s->salary_breakdown ?? []);
            $allowances = is_string($s->allowances) ? json_decode($s->allowances, true) : ($s->allowances ?? []);
            $deductions = is_string($s->deductions) ? json_decode($s->deductions, true) : ($s->deductions ?? []);
            $bonuses = is_string($s->bonuses) ? json_decode($s->bonuses, true) : ($s->bonuses ?? []);
            $coinage = is_string($s->coinage_breakdown) ? json_decode($s->coinage_breakdown, true) : ($s->coinage_breakdown ?? null);
            $otCoinage = is_string($s->ot_coinage_breakdown) ? json_decode($s->ot_coinage_breakdown, true) : ($s->ot_coinage_breakdown ?? null);

            $comp = $s->employee->compensation ?? null;
            $joined = $s->employee->organizationAssignment->date_of_joining ?? null;
            $dateJoined = $joined ? date('Y/m/d', strtotime($joined)) : '-';

            // --- Sch 01: raw basic + salary-side allowances + BR + increment (NOT monthly bonus) ---
            $baseBasic = (float)($comp->basic_salary ?? $s->basic_salary ?? 0);
            $br1 = $s->br1 ?? $comp->br1 ?? false;
            $br2 = $s->br2 ?? $comp->br2 ?? false;
            $budgetRelief = self::calcBudgetRelief($br1, $br2);

            $increment = 0.0;
            if (!empty($s->increment_active) && !empty($s->increment_value)) {
                $increment = (float)$s->increment_value;
            } elseif (!empty($comp?->increment_active) && !empty($comp->increment_value)) {
                $increment = (float)$comp->increment_value;
            }

            $adjustedBasic = (float)($breakdown['basic_salary'] ?? 0);
            if ($budgetRelief <= 0 && $adjustedBasic > $baseBasic) {
                $budgetRelief = max(0, round($adjustedBasic - $baseBasic - $increment, 2));
            }

            $totalAllowances = array_reduce($allowances, fn($c, $a) => $c + (float)($a['amount'] ?? 0), 0);
            $budgetaryAllowance = round($totalAllowances, 2);
            $totalSalarySch01 = round($baseBasic + $budgetaryAllowance + $budgetRelief + $increment, 2);

            $monthlyBonus = (float)($breakdown['monthly_bonus'] ?? $comp->monthly_bonus ?? 0);
            $otherBonuses = array_reduce($bonuses, function ($c, $b) {
                $cat = strtolower((string)($b['category'] ?? ''));
                if ($cat === 'monthly_bonus') {
                    return $c;
                }
                return $c + (float)($b['amount'] ?? 0);
            }, 0.0);
            $otTotal = (float)($breakdown['ot_morning_fees'] ?? 0)
                + (float)($breakdown['ot_night_fees'] ?? 0)
                + (float)($breakdown['holiday_ot_fees'] ?? 0);

            $salaryComponent = round($adjustedBasic + $totalAllowances, 2);
            $allowanceComponent = round($monthlyBonus + $otherBonuses + $otTotal, 2);

            // --- Deductions from breakdown (matches SalaryProcessController tracks) ---
            $epf8 = (float)($breakdown['epf_employee_deduction'] ?? 0);
            $epfEtfFixed = (float)($breakdown['epf_etf_fixed_deductions'] ?? 0);
            $probation = (float)($breakdown['probation_deduction'] ?? 0);
            $stampDuty = (float)($breakdown['stamp_duty'] ?? 0);

            $loanPrincipal = (float)($breakdown['loan_principal'] ?? $breakdown['loan_installment'] ?? 0);
            $loanInterest = (float)($breakdown['loan_interest'] ?? 0);
            $loanDeductFrom = strtolower((string)($breakdown['loan_deduct_from'] ?? 'bonus'));
            $loanOnBasic = (float)($breakdown['loan_basic_principal'] ?? ($loanDeductFrom === 'basic' ? $loanPrincipal : 0));
            $loanOnBonus = (float)($breakdown['loan_bonus_principal'] ?? ($loanDeductFrom === 'bonus' ? $loanPrincipal : 0));
            $loanInterestOnBasic = (float)($breakdown['loan_basic_interest'] ?? ($loanDeductFrom === 'basic' ? $loanInterest : 0));
            $loanInterestOnBonus = (float)($breakdown['loan_bonus_interest'] ?? ($loanDeductFrom === 'bonus' ? $loanInterest : 0));
            $loanOnBasic += $loanInterestOnBasic;
            $loanOnBonus += $loanInterestOnBonus;

            $fullDayNoPay = (float)($breakdown['full_day_nopay_deduction'] ?? 0);
            $halfDayNoPay = (float)($breakdown['half_day_deduction'] ?? 0);
            $saturdayNoPay = (float)($breakdown['saturday_nopay_deduction'] ?? 0);
            $earlyOutNoPay = (float)($breakdown['early_out_nopay_deduction'] ?? 0);
            $shortLeaveDed = (float)($breakdown['short_leave_deduction'] ?? 0);
            $majorLateDed = (float)($breakdown['major_late_deduction'] ?? 0);
            $personalOutNoPay = (float)($breakdown['personal_out_nopay_deduction'] ?? 0);
            $preHireBasicNoPay = (float)($breakdown['pre_hire_nopay_basic'] ?? 0);
            $preHireBonusNoPay = (float)($breakdown['pre_hire_nopay_bonus'] ?? 0);

            $basicNoPay = round($fullDayNoPay + $preHireBasicNoPay, 2);
            $bonusNoPay = round(
                $saturdayNoPay + $earlyOutNoPay + $shortLeaveDed + $halfDayNoPay + $majorLateDed
                + $personalOutNoPay + $preHireBonusNoPay,
                2
            );
            $totalNoPayForReport = round($basicNoPay + $bonusNoPay, 2);

            $perDaySalary = (float)($breakdown['per_day_salary'] ?? 0);
            $deriveDays = static function (float $amount) use ($perDaySalary): float {
                if ($amount <= 0) {
                    return 0.0;
                }
                if ($perDaySalary > 0) {
                    return round($amount / $perDaySalary, 2);
                }
                return 0.0;
            };
            $basicNoPayDays = $deriveDays($basicNoPay);
            $bonusNoPayDays = $deriveDays($bonusNoPay);
            if ($basicNoPayDays <= 0 && $basicNoPay > 0) {
                $basicNoPayDays = (float)($s->approved_no_pay_days ?? 0);
            }

            $staffFundMonthly = round((float)($comp->staff_fund_amount ?? 0), 2);
            $employeeId = (int)($s->employee_id ?? 0);
            $staffFundYtdPaid = round((float)($ytdStaffFundPaid[$employeeId] ?? $staffFund), 2);
            $staffFundYtdContribution = round($staffFundMonthly * $monthInt, 2);

            $sportsFund = (float)($breakdown['sports_fund_deduction'] ?? 0);
            $staffFund = (float)($breakdown['staff_fund_deduction'] ?? 0);
            $salaryAdvanceBasic = (float)($breakdown['salary_advance_basic'] ?? 0);
            $salaryAdvanceBonus = (float)($breakdown['salary_advance_bonus'] ?? 0);
            $salaryAdvanceFromDeductions = self::sumDeductionsByPattern($deductions, ['salary advance', 'salary_advance', 'advance']);
            $salaryAdvance = round($salaryAdvanceBasic + $salaryAdvanceBonus, 2);
            if ($salaryAdvance <= 0) {
                $salaryAdvance = $salaryAdvanceFromDeductions;
                $salaryAdvanceBonus = $salaryAdvanceFromDeductions;
            }
            $customBonusDeductions = self::sumCustomBonusDeductions($deductions);

            // Salary for EPF/ETF = (basic − basic NoPay)
            $salaryForEpf = round((float)($breakdown['epf_etf_base'] ?? $adjustedBasic), 2);
            if ($salaryForEpf <= 0 && $epf8 > 0) {
                $salaryForEpf = round($epf8 / 0.08, 2);
            }

            // Salary track (Sch 01–06): basic-side deductions
            $basicOtherDeductions = round($probation + $epfEtfFixed, 2);
            $epfScheduleDeductions = round($epf8 + $epfEtfFixed + $probation + $loanOnBasic + $salaryAdvanceBasic, 2);
            $epfScheduleNet = round($salaryComponent - $basicNoPay - $epfScheduleDeductions, 2);

            // Allowance / bonus track
            $allowanceOtherDeductions = round($customBonusDeductions + $stampDuty, 2);
            $allowanceGross = round($allowanceComponent, 2);
            $allowanceDeductions = round(
                $bonusNoPay + $salaryAdvanceBonus + $loanOnBonus + $loanInterest
                + $sportsFund + $staffFund + $allowanceOtherDeductions,
                2
            );
            $allowanceNet = round($allowanceGross - $allowanceDeductions, 2);

            // Totals — use stored payroll figures as source of truth
            $grossSalary = (float)($breakdown['gross_salary'] ?? 0);
            $netSalary = (float)($breakdown['net_salary'] ?? 0);
            $totalDeductions = (float)($breakdown['total_deductions'] ?? 0);
            $totalOtherDeduction = round($basicOtherDeductions + $allowanceOtherDeductions, 2);

            $epfEmployer = (float)($breakdown['epf_employer_contribution'] ?? round($salaryForEpf * 0.12, 2));
            $enableEpfEtf = (int)(
                !empty($s->enable_epf_etf)
                || !empty($comp?->enable_epf_etf)
                || $epf8 > 0
                || $epfEmployer > 0
            );

            // Bank transfer = salary track net (basic + allowances − basic-side deductions)
            $bankAmount = max(0, round($salaryComponent - $basicNoPay - $epfScheduleDeductions, 2));
            $cashAmount = max(0, round($netSalary - $bankAmount, 2));

            return [
                'process_id' => $s->id,
                'emp_no' => $s->employee_no ?? '-',
                'name' => $s->full_name ?? '-',
                'epf_member_no' => $s->employee->epf ?? '-',
                'epf_no' => $s->employee->epf ?? '-',
                'company_id' => $s->employee?->organizationAssignment?->company_id,
                'company_name' => $s->employee?->organizationAssignment?->company?->name ?? $s->company_name ?? '-',
                'company_code' => $s->employee?->organizationAssignment?->company?->company_code ?? '-',
                'date_joined' => $dateJoined,
                'bank' => $comp->bank_name ?? '-',
                'branch' => $comp->branch_name ?? '-',
                'account' => $comp->bank_account_no ?? '-',

                'base_basic_salary' => $baseBasic,
                'budgetary_allowance' => $budgetaryAllowance,
                'budget_relief_allowance' => $budgetRelief,
                'increment_amount' => $increment,
                'total_salary_sch01' => $totalSalarySch01,
                'monthly_bonus' => $monthlyBonus,
                'total_salary' => round(($adjustedBasic > 0 ? $adjustedBasic : $baseBasic) + $monthlyBonus, 2),
                'salary_component' => $salaryComponent,
                'allowance_component' => $allowanceComponent,

                'basic_salary' => $adjustedBasic > 0 ? $adjustedBasic : $baseBasic,
                'base_basic_salary' => $baseBasic > 0 ? $baseBasic : $adjustedBasic,
                'gross_salary' => $grossSalary,
                'net_salary' => $netSalary,
                'total_deductions' => $totalDeductions,
                'total_report_deductions' => $totalDeductions,
                'total_report_net' => $netSalary,

                'basic_no_pay' => $basicNoPay,
                'bonus_no_pay' => $bonusNoPay,
                'salary_for_epf' => $salaryForEpf,
                'epf_schedule_deductions' => $epfScheduleDeductions,
                'epf_schedule_net' => $epfScheduleNet,
                'basic_other_deductions' => $basicOtherDeductions,
                'loan_on_basic' => $loanOnBasic,
                'loan_on_bonus' => $loanOnBonus,

                'allowance_gross' => $allowanceGross,
                'allowance_deductions' => $allowanceDeductions,
                'allowance_other_deductions' => $allowanceOtherDeductions,
                'allowance_net' => $allowanceNet,

                'enable_epf_etf' => $enableEpfEtf,
                'epf_base' => $salaryForEpf,
                'epf_8' => $epf8,
                'epf_12' => $epfEmployer,
                'etf_3' => (float)($breakdown['etf_employer_contribution'] ?? round($salaryForEpf * 0.03, 2)),

                'no_pay_days' => round($basicNoPayDays + $bonusNoPayDays, 2),
                'no_pay_amount' => $totalNoPayForReport,
                'basic_nopay_days' => $basicNoPayDays,
                'basic_nopay_amount' => $basicNoPay,
                'salary_nopay_days' => $basicNoPayDays,
                'salary_nopay_amount' => $basicNoPay,
                'allowance_nopay_days' => $bonusNoPayDays,
                'allowance_nopay_amount' => $bonusNoPay,
                'per_day_salary' => $perDaySalary,

                'sports_fund' => $sportsFund,
                'staff_fund' => $staffFund,
                'staff_fund_monthly' => $staffFundMonthly,
                'staff_fund_ytd_contribution' => $staffFundYtdContribution,
                'staff_fund_ytd_paid' => $staffFundYtdPaid,
                'staff_fund_balance' => round($staffFundYtdContribution - $staffFundYtdPaid, 2),
                'salary_advance' => $salaryAdvance,
                'salary_advance_basic' => round($salaryAdvanceBasic, 2),
                'salary_advance_bonus' => round($salaryAdvanceBonus, 2),
                'other_deduction' => $totalOtherDeduction,
                'stamp_duty' => $stampDuty,
                'probation_deduction' => $probation,

                'loan_amount' => (float)($s->total_loan_amount ?? 0),
                'loan_installment' => $loanPrincipal,
                'loan_interest' => $loanInterest,

                'ot_morning_hours' => (float)($breakdown['ot_morning_hours'] ?? 0),
                'ot_morning_fees' => (float)($breakdown['ot_morning_fees'] ?? 0),
                'ot_night_hours' => (float)($breakdown['ot_night_hours'] ?? 0),
                'ot_night_fees' => (float)($breakdown['ot_night_fees'] ?? 0),
                'holiday_ot_hours' => (float)($breakdown['holiday_ot_hours'] ?? 0),
                'holiday_ot_fees' => (float)($breakdown['holiday_ot_fees'] ?? 0),
                'total_ot_amount' => round($otTotal, 2),
                'ot_amount' => round($otTotal, 2),

                'bank_amount' => $bankAmount,
                'cash_amount' => $cashAmount,

                'saved_coinage' => $coinage,
                'saved_ot_coinage' => $otCoinage,
                'raw_allowances' => $allowances,
                'raw_deductions' => $deductions,
                'raw_bonuses' => $bonuses,
            ];
        });

        return response()->json($data);
    }

    private static function calcBudgetRelief($br1, $br2): float
    {
        $br1 = (int)(bool)$br1;
        $br2 = (int)(bool)$br2;
        if ($br1 && $br2) {
            return 3500.0;
        }
        if ($br1) {
            return 1000.0;
        }
        if ($br2) {
            return 2500.0;
        }
        return 0.0;
    }

    private static function sumDeductionsByPattern(array $deductions, array $patterns): float
    {
        $total = 0.0;
        foreach ($deductions as $deduction) {
            if (!empty($deduction['is_advance']) && in_array('advance', $patterns, true)) {
                $total += (float)($deduction['amount'] ?? 0);
                continue;
            }
            $name = strtolower((string)($deduction['name'] ?? ''));
            $code = strtolower((string)($deduction['code'] ?? ''));
            foreach ($patterns as $pattern) {
                if (str_contains($name, $pattern) || str_contains($code, $pattern)) {
                    $total += (float)($deduction['amount'] ?? 0);
                    break;
                }
            }
        }
        return round($total, 2);
    }

    /** Custom deductions on the bonus/allowance track (excludes advance, sports, staff, EPF/ETF). */
    private static function sumCustomBonusDeductions(array $deductions): float
    {
        $skipPatterns = ['salary advance', 'salary_advance', 'advance', 'sports fund', 'staff fund', 'epf', 'etf'];
        $total = 0.0;

        foreach ($deductions as $deduction) {
            $name = strtolower((string)($deduction['name'] ?? ''));
            $code = strtolower((string)($deduction['code'] ?? ''));
            $cat = strtoupper((string)($deduction['category'] ?? ''));
            if (in_array($cat, ['EPF', 'ETF'], true) || !empty($deduction['is_advance'])) {
                continue;
            }
            $skip = false;
            foreach ($skipPatterns as $pattern) {
                if (str_contains($name, $pattern) || str_contains($code, $pattern)) {
                    $skip = true;
                    break;
                }
            }
            if (!$skip) {
                $total += (float)($deduction['amount'] ?? 0);
            }
        }

        return round($total, 2);
    }

    /** YTD staff fund payments per employee (Schedule 07 cumulative). */
    private static function loadYtdStaffFundPaid(string $year, int $throughMonth, ?int $companyId = null): array
    {
        $monthValues = [];
        for ($m = 1; $m <= $throughMonth; $m++) {
            $monthValues[] = $m;
            $monthValues[] = str_pad((string)$m, 2, '0', STR_PAD_LEFT);
            $monthValues[] = (string)$m;
        }

        $query = salary_process::where('year', $year)
            ->whereIn('month', array_unique($monthValues))
            ->whereIn('status', ['pending', 'processed', 'issued']);

        if ($companyId) {
            $query->whereHas('employee.organizationAssignment', fn($q) => $q->where('company_id', $companyId));
        }

        $records = $query->get(['employee_id', 'salary_breakdown']);

        $totals = [];
        foreach ($records as $record) {
            $breakdown = is_string($record->salary_breakdown)
                ? json_decode($record->salary_breakdown, true)
                : ($record->salary_breakdown ?? []);
            $empId = (int)$record->employee_id;
            $paid = (float)($breakdown['staff_fund_deduction'] ?? 0);
            $totals[$empId] = round(($totals[$empId] ?? 0) + $paid, 2);
        }

        return $totals;
    }

    public function saveCoinageData(Request $request)
    {
        $request->validate(['coinage_data' => 'required|array']);
        try {
            $isOt = (bool)($request->input('is_ot', false) || $request->input('type') === 'ot');
            $field = $isOt ? 'ot_coinage_breakdown' : 'coinage_breakdown';

            foreach ($request->coinage_data as $data) {
                if (isset($data['process_id'])) {
                    salary_process::where('id', $data['process_id'])->update([
                        $field => json_encode($data['notes'])
                    ]);
                }
            }
            return response()->json(['message' => ($isOt ? 'OT coinage' : 'Coinage') . ' data saved successfully']);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Failed to save: ' . $e->getMessage()], 500);
        }
    }

    public function saveOtCoinageData(Request $request)
    {
        $request->merge(['is_ot' => true]);
        return $this->saveCoinageData($request);
    }

    public function leaveSummary(Request $request)
    {
        $year = (int)($request->input('year', date('Y')));
        $month = $request->filled('month') ? (int)$request->input('month') : null;
        $companyId = $request->input('company_id');

        $empQuery = \App\Models\employee::with([
            'organizationAssignment.company',
            'compensation',
        ])->where('is_active', 1);

        if ($companyId) {
            $empQuery->whereHas('organizationAssignment', function ($q) use ($companyId) {
                $q->where('company_id', $companyId);
            });
        }

        $employees = $empQuery->orderBy('attendance_employee_no')->get();
        $calculator = app(\App\Services\ShopAndOfficeLeaveCalculator::class);

        $data = $employees->map(function ($emp) use ($year, $month, $calculator) {
            $manual = \App\Models\EmployeeLeaveBalance::where('employee_id', $emp->id)
                ->where('year', $year)
                ->where('status', 'active')
                ->whereNull('deleted_at')
                ->sum('entitled_days');

            if ($manual > 0) {
                $entitled = (float)$manual;
            } else {
                $joined = $emp->organizationAssignment?->date_of_joining;
                if ($joined) {
                    $targetDate = $month ? \Carbon\Carbon::create($year, $month)->endOfMonth() : \Carbon\Carbon::create($year, 12, 31);
                    $law = $calculator->calculate(\Carbon\Carbon::parse($joined), $targetDate);
                    $entitled = (float)($law['casual_days'] ?? 0) + (float)($law['annual_days'] ?? 0);
                } else {
                    $entitled = 0.0;
                }
            }

            $leavesQuery = \App\Models\leave_master::where('employee_id', $emp->id)
                ->whereIn('status', ['Approved', 'HR_Approved'])
                ->whereNull('deleted_at')
                ->where(function ($q) use ($year, $month) {
                    $q->whereYear('leave_date', $year)->orWhereYear('leave_from', $year);
                });

            if ($month) {
                $leavesQuery->where(function ($q) use ($month) {
                    $q->whereMonth('leave_date', '<=', $month)->orWhereMonth('leave_from', '<=', $month);
                });
            }

            $leaves = $leavesQuery->get();
            $obtained = 0.0;
            foreach ($leaves as $l) {
                if ($l->leave_balance_days !== null) {
                    $obtained += (float)$l->leave_balance_days;
                } elseif ($l->is_short_leave) {
                    $obtained += 0.25;
                } elseif ($l->is_half_day) {
                    $obtained += 0.5;
                } else {
                    $obtained += (float)($l->leave_duration ?? 1);
                }
            }

            $balance = max(0.0, round($entitled - $obtained, 2));
            $fullName = $emp->full_name ?: ($emp->name_with_initials ?: ($emp->display_name ?: '-'));

            return [
                'employee_id' => $emp->id,
                'emp_no' => $emp->attendance_employee_no ?? '-',
                'epf_no' => $emp->epf ?? $emp->epf_number ?? $emp->epf_member_no ?? '-',
                'full_name' => $fullName,
                'employee_full_name' => $fullName,
                'name' => $fullName,
                'employee_name' => $fullName,
                'leave_entitlement' => round($entitled, 2),
                'leave_obtained' => round($obtained, 2),
                'leave_balance' => round($balance, 2),
            ];
        });

        return response()->json($data);
    }
}
