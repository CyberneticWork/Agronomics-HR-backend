<?php

namespace App\Services;

use App\Models\loans;
use App\Models\salary_process;
use Carbon\Carbon;

class ScheduleReportService
{
    public function __construct(private LoanReportService $loanReportService)
    {
    }

    public function build(string $month, string $year, ?int $companyId = null): array
    {
        $monthInt = max(1, (int)$month);
        $monthPadded = str_pad((string)$monthInt, 2, '0', STR_PAD_LEFT);
        $monthYear = sprintf('%04d-%02d', (int)$year, $monthInt);
        $monthLabel = Carbon::create((int)$year, $monthInt, 1)->format('M-y');

        $loanReport = $this->loanReportService->buildReport();
        $loans = $loanReport['loans'] ?? [];
        if ($companyId) {
            $comp = \App\Models\company::find($companyId);
            if ($comp) {
                $loans = collect($loans)->filter(function ($loan) use ($comp) {
                    return strcasecmp((string)($loan['company_name'] ?? ''), (string)$comp->name) === 0;
                })->values()->all();
            }
        }
        $loanSummary = $this->buildLoanSummary($loans, $monthYear, $monthLabel);
        $loanDetails = $this->buildLoanDetails($loans, $monthYear);
        $staffFund = $this->buildStaffFundReport($monthPadded, $monthInt, $year, $companyId);
        $staffFundDetail07a = $this->buildStaffFundDetail07a($monthInt, $year, $companyId);
        $company = $this->resolveCompanyInfo($monthPadded, $monthInt, $year, $companyId);

        return [
            'month' => $monthPadded,
            'year' => (int)$year,
            'month_label' => $monthLabel,
            'company' => $company,
            'loan_summary' => $loanSummary,
            'loan_details' => $loanDetails,
            'staff_fund' => $staffFund,
            'staff_fund_detail_07a' => $staffFundDetail07a,
        ];
    }

    private function buildLoanSummary(array $loans, string $monthYear, string $monthLabel): array
    {
        $rows = [];
        $totals = [
            'granted_amount' => 0.0,
            'loan_monthly' => 0.0,
            'loan_total' => 0.0,
            'loan_balance' => 0.0,
            'interest_monthly' => 0.0,
            'interest_total' => 0.0,
            'interest_balance' => 0.0,
        ];

        $grouped = collect($loans)->groupBy('employee_no');

        foreach ($grouped as $employeeNo => $employeeLoans) {
            $first = true;
            foreach ($employeeLoans as $index => $loan) {
                $monthly = $this->monthlyLoanFigures($loan, $monthYear);
                $loanLabel = 'Loan ' . str_pad((string)($index + 1), 2, '0', STR_PAD_LEFT);
                $loanDate = !empty($loan['loan_date']) && $loan['loan_date'] !== '-'
                    ? $loan['loan_date']
                    : (!empty($loan['request_date'])
                        ? Carbon::parse($loan['request_date'])->format('Y-m-d')
                        : (!empty($loan['created_at_date'])
                            ? Carbon::parse($loan['created_at_date'])->format('Y-m-d')
                            : (!empty($loan['start_from'])
                                ? Carbon::parse($loan['start_from'])->format('Y-m-d')
                                : '-')));

                $rows[] = [
                    'employee_no' => $loan['employee_no'] ?? '-',
                    'epf_no' => $loan['employee_epf'] ?? '-',
                    'employee_name' => $first ? ($loan['employee_name'] ?? '-') : '',
                    'loan_label' => $loanLabel,
                    'loan_id' => $loan['loan_id'] ?? '-',
                    'granted_date' => $loanDate,
                    'loan_date' => $loanDate,
                    'granted_amount' => (float)($loan['loan_amount'] ?? 0),
                    'loan_monthly_deduction' => $monthly['principal'],
                    'loan_total_deduction' => (float)($loan['total_principal_paid'] ?? 0),
                    'loan_balance_outstanding' => (float)($loan['total_principal_outstanding'] ?? 0),
                    'interest_monthly_deduction' => $monthly['interest'],
                    'interest_total_deduction' => (float)($loan['total_interest_paid'] ?? 0),
                    'interest_balance_outstanding' => (float)($loan['total_interest_outstanding'] ?? 0),
                ];

                $totals['granted_amount'] += (float)($loan['loan_amount'] ?? 0);
                $totals['loan_monthly'] += $monthly['principal'];
                $totals['loan_total'] += (float)($loan['total_principal_paid'] ?? 0);
                $totals['loan_balance'] += (float)($loan['total_principal_outstanding'] ?? 0);
                $totals['interest_monthly'] += $monthly['interest'];
                $totals['interest_total'] += (float)($loan['total_interest_paid'] ?? 0);
                $totals['interest_balance'] += (float)($loan['total_interest_outstanding'] ?? 0);

                $first = false;
            }
        }

        return [
            'headers' => [
                'Employee Name', 'Loan',
                'Loan Date', 'Loan Amount',
                'Loan Installment', 'Total Loan Deduction', 'Balance Loan Outstanding',
                'Interest Deduction', 'Total Interest Deduction', 'Balance Interest Outstanding',
            ],
            'rows' => $rows,
            'footer' => [
                'employee_name' => 'TOTAL',
                'loan_label' => '',
                'loan_id' => '',
                'granted_date' => '',
                'loan_date' => '',
                'granted_amount' => round($totals['granted_amount'], 2),
                'loan_monthly_deduction' => round($totals['loan_monthly'], 2),
                'loan_total_deduction' => round($totals['loan_total'], 2),
                'loan_balance_outstanding' => round($totals['loan_balance'], 2),
                'interest_monthly_deduction' => round($totals['interest_monthly'], 2),
                'interest_total_deduction' => round($totals['interest_total'], 2),
                'interest_balance_outstanding' => round($totals['interest_balance'], 2),
            ],
            'summary' => [
                'available_staff_fund' => round($staffFundAvailable = $this->estimateAvailableStaffFund(), 2),
                'balance_recoverable' => round($totals['loan_balance'] + $totals['interest_balance'], 2),
            ],
        ];
    }

    private function buildLoanDetails(array $loans, string $monthYear): array
    {
        $sections = [];

        foreach ($loans as $loan) {
            $scheduleRows = [];
            $runningBalance = (float)($loan['loan_amount'] ?? 0);
            $loanDate = !empty($loan['loan_date']) && $loan['loan_date'] !== '-'
                ? $loan['loan_date']
                : (!empty($loan['request_date'])
                    ? Carbon::parse($loan['request_date'])->format('Y-m-d')
                    : (!empty($loan['created_at_date'])
                        ? Carbon::parse($loan['created_at_date'])->format('Y-m-d')
                        : (!empty($loan['start_from'])
                            ? Carbon::parse($loan['start_from'])->format('Y-m-d')
                            : '-')));

            foreach ($loan['schedule'] ?? [] as $row) {
                $dueMonth = $row['due_date'] ? substr((string)$row['due_date'], 0, 7) : null;
                if ($dueMonth && $dueMonth > $monthYear) {
                    continue;
                }

                $principal = (float)($row['principal_deduction'] ?? 0);
                $interest = (float)($row['interest_deduction'] ?? 0);
                $total = (float)($row['installment_amount'] ?? ($principal + $interest));
                $balance = (float)($row['balance_after'] ?? max(0, $runningBalance - $principal));
                $runningBalance = $balance;

                $scheduleRows[] = [
                    'month' => $dueMonth ? Carbon::parse($row['due_date'])->format('M-Y') : '-',
                    'loan_date' => $loanDate,
                    'loan_amount' => (float)($loan['loan_amount'] ?? 0),
                    'monthly_deduction' => round($principal, 2),
                    'balance_outstanding' => round($balance, 2),
                    'interest' => round($interest, 2),
                    'total_deduction' => round($total, 2),
                ];
            }

            if ($scheduleRows === [] && strtolower((string)($loan['status'] ?? '')) === 'active') {
                $monthly = $this->monthlyLoanFigures($loan, $monthYear);
                if ($monthly['principal'] > 0 || $monthly['interest'] > 0) {
                    $scheduleRows[] = [
                        'month' => Carbon::parse($monthYear . '-01')->format('M-Y'),
                        'loan_date' => $loanDate,
                        'loan_amount' => (float)($loan['loan_amount'] ?? 0),
                        'monthly_deduction' => $monthly['principal'],
                        'balance_outstanding' => (float)($loan['total_principal_outstanding'] ?? 0),
                        'interest' => $monthly['interest'],
                        'total_deduction' => round($monthly['principal'] + $monthly['interest'], 2),
                    ];
                }
            }

            if ($scheduleRows === []) {
                continue;
            }

            $sections[] = [
                'employee_no' => $loan['employee_no'] ?? '-',
                'epf_no' => $loan['employee_epf'] ?? '-',
                'employee_name' => $loan['employee_name'] ?? '-',
                'loan_id' => $loan['loan_id'] ?? '-',
                'loan_label' => $loan['loan_id'] ?? 'LOAN 01',
                'loan_date' => $loanDate,
                'headers' => ['Month', 'Loan Date', 'Loan Amount', 'Monthly Deduction', 'Balance Outstanding', 'Interest', 'Total Deduction'],
                'spm_headers' => ['Month', 'Loan Amount', 'Loan Installment', 'Balance Loan Outstanding', 'Interest', 'Total Deduction'],
                'rows' => $scheduleRows,
            ];
        }

        return $sections;
    }

    private function buildStaffFundReport(string $monthPadded, int $monthInt, string $year, ?int $companyId = null): array
    {
        $months = [$monthPadded, $monthInt, (string)$monthInt];
        $salariesQuery = salary_process::with(['employee.compensation', 'employee.organizationAssignment'])
            ->whereIn('month', $months)
            ->where('year', $year)
            ->whereIn('status', ['pending', 'processed', 'issued']);

        if ($companyId) {
            $salariesQuery->whereHas('employee.organizationAssignment', fn($q) => $q->where('company_id', $companyId));
        }

        $salaries = $salariesQuery->get();

        $ytdPaid = $this->loadYtdStaffFundPaid($year, $monthInt, $companyId);

        $detailRows = [];
        $detailTotals = [
            'basic' => 0.0, 'allowance' => 0.0, 'total_salary' => 0.0,
            'employee_contribution' => 0.0, 'employer_contribution' => 0.0, 'total_contribution' => 0.0,
        ];

        foreach ($salaries as $s) {
            $comp = $s->employee->compensation ?? null;
            $org = $s->employee->organizationAssignment ?? null;
            $breakdown = is_string($s->salary_breakdown) ? json_decode($s->salary_breakdown, true) : ($s->salary_breakdown ?? []);

            $joined = $org->date_of_joining ?? null;
            $resigned = $org->date_of_resigning ?? null;
            $joinedDisplay = $joined ? date('Y/m/d', strtotime($joined)) : '-';
            $resignedDisplay = $resigned ? date('Y/m/d', strtotime($resigned)) : '-';
            $asOfDate = Carbon::create((int)$year, $monthInt, 1)->endOfMonth();
            $period = $this->formatPeriodOfService($joined, $resigned, $asOfDate);

            $basic = (float)($comp->basic_salary ?? $s->basic_salary ?? 0);
            $allowance = (float)($breakdown['monthly_bonus'] ?? $comp->monthly_bonus ?? 0);
            $totalSalary = round($basic + $allowance, 2);
            $employeeContrib = (float)($breakdown['staff_fund_deduction'] ?? 0);
            $employerContrib = round((float)($comp->staff_fund_amount ?? 0), 2);
            $totalContrib = round($employeeContrib + $employerContrib, 2);

            $detailRows[] = [
                'employee_no' => $s->employee_no ?? $s->employee?->attendance_employee_no ?? '-',
                'epf_no' => $s->employee?->epf ?? '-',
                'date_joined' => $joinedDisplay,
                'date_resigned' => $resignedDisplay,
                'period_of_service' => $period,
                'employee_name' => $s->full_name ?? '-',
                'basic_salary' => $basic,
                'allowance' => $allowance,
                'total_salary' => $totalSalary,
                'employee_contribution' => $employeeContrib,
                'employer_contribution' => $employerContrib,
                'total_contribution' => $totalContrib,
            ];

            $detailTotals['basic'] += $basic;
            $detailTotals['allowance'] += $allowance;
            $detailTotals['total_salary'] += $totalSalary;
            $detailTotals['employee_contribution'] += $employeeContrib;
            $detailTotals['employer_contribution'] += $employerContrib;
            $detailTotals['total_contribution'] += $totalContrib;
        }

        $ytdEmployee = 0.0;
        $ytdEmployer = 0.0;
        $ytdPayments = round(array_sum($ytdPaid), 2);

        $monthValues = [];
        for ($m = 1; $m <= $monthInt; $m++) {
            $monthValues[] = $m;
            $monthValues[] = str_pad((string)$m, 2, '0', STR_PAD_LEFT);
            $monthValues[] = (string)$m;
        }
        $ytdSalariesQuery = salary_process::with(['employee.compensation'])
            ->where('year', $year)
            ->whereIn('month', array_unique($monthValues))
            ->whereIn('status', ['pending', 'processed', 'issued']);

        if ($companyId) {
            $ytdSalariesQuery->whereHas('employee.organizationAssignment', fn($q) => $q->where('company_id', $companyId));
        }

        $ytdSalaries = $ytdSalariesQuery->get();

        foreach ($ytdSalaries as $ys) {
            $yb = is_string($ys->salary_breakdown) ? json_decode($ys->salary_breakdown, true) : ($ys->salary_breakdown ?? []);
            $yc = $ys->employee->compensation ?? null;
            $ytdEmployee += (float)($yb['staff_fund_deduction'] ?? 0);
            $ytdEmployer += (float)($yc->staff_fund_amount ?? 0);
        }

        $ytdEmployee = round($ytdEmployee, 2);
        $ytdEmployer = round($ytdEmployer, 2);
        $ytdTotalContrib = round($ytdEmployee + $ytdEmployer, 2);

        return [
            'detail' => [
                'headers' => [
                    'Date Joined', 'Date Resigned', 'Period of Service', 'Employee Name',
                    'Basic Salary', 'Allowance', 'Total Salary',
                    'Employee Contribution', 'Employer Contribution', 'Total Contribution',
                ],
                'spm_headers' => [
                    'Date Joined', 'Period of Service', 'Employee Name',
                    'Basic Salary', 'Monthly Bonus', 'Total Salary',
                    'Employee Contribution', 'Employer Contribution', 'Total Contribution',
                ],
                'rows' => $detailRows,
                'footer' => [
                    'date_joined' => '', 'date_resigned' => '', 'period_of_service' => 'TOTAL', 'employee_name' => '',
                    'basic_salary' => round($detailTotals['basic'], 2),
                    'allowance' => round($detailTotals['allowance'], 2),
                    'total_salary' => round($detailTotals['total_salary'], 2),
                    'employee_contribution' => round($detailTotals['employee_contribution'], 2),
                    'employer_contribution' => round($detailTotals['employer_contribution'], 2),
                    'total_contribution' => round($detailTotals['total_contribution'], 2),
                ],
            ],
            'cumulative' => [
                'headers' => [
                    'Employee Contribution', 'Employer Contribution', 'Total Contribution',
                    'Payments Made', 'Balance Payable',
                ],
                'rows' => [[
                    'employee_contribution' => $ytdEmployee,
                    'employer_contribution' => $ytdEmployer,
                    'total_contribution' => $ytdTotalContrib,
                    'payments_made' => $ytdPayments,
                    'balance_payable' => round($ytdTotalContrib - $ytdPayments, 2),
                ]],
            ],
        ];
    }

    private function monthlyLoanFigures(array $loan, string $monthYear): array
    {
        foreach ($loan['schedule'] ?? [] as $row) {
            $dueMonth = $row['due_date'] ? substr((string)$row['due_date'], 0, 7) : null;
            if ($dueMonth === $monthYear) {
                return [
                    'principal' => round((float)($row['principal_deduction'] ?? 0), 2),
                    'interest' => round((float)($row['interest_deduction'] ?? 0), 2),
                ];
            }
        }

        if (strtolower((string)($loan['status'] ?? '')) === 'active') {
            $startMonth = !empty($loan['start_from']) ? substr((string)$loan['start_from'], 0, 7) : null;
            if ($startMonth && $startMonth <= $monthYear) {
                $installment = (float)($loan['installment_amount'] ?? 0);
                $interest = (float)($loan['monthly_interest_deduction'] ?? 0);
                return [
                    'principal' => round(max(0, $installment - $interest), 2),
                    'interest' => round($interest, 2),
                ];
            }
        }

        return ['principal' => 0.0, 'interest' => 0.0];
    }

    private function formatPeriodOfService(?string $joined, ?string $resigned, ?Carbon $asOf = null): string
    {
        if (!$joined) {
            return '-';
        }
        try {
            $start = Carbon::parse($joined)->startOfDay();
            $end = $resigned ? Carbon::parse($resigned)->endOfDay() : ($asOf ? $asOf->copy()->endOfMonth()->endOfDay() : Carbon::today()->endOfDay());
            if ($start->gt($end)) {
                return '0Y 0M';
            }
            $diff = $start->diff($end);
            return "{$diff->y}Y {$diff->m}M";
        } catch (\Throwable) {
            return '-';
        }
    }

    private function loadYtdStaffFundPaid(string $year, int $throughMonth, ?int $companyId = null): array
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

    /** Schedule 07(a): monthly staff/sports fund contributions per employee for fiscal year. */
    private function buildStaffFundDetail07a(int $selectedMonth, string $year, ?int $companyId = null): array
    {
        $yearInt = (int)$year;
        $fyStartYear = $selectedMonth >= 4 ? $yearInt : $yearInt - 1;
        $fyEndYear = $fyStartYear + 1;
        $fyLabel = "{$fyStartYear}/{$fyEndYear}";

        $periods = [];
        for ($i = 0; $i < 12; $i++) {
            $calendarMonth = 4 + $i;
            $actualMonth = $calendarMonth <= 12 ? $calendarMonth : $calendarMonth - 12;
            $actualYear = $calendarMonth <= 12 ? $fyStartYear : $fyEndYear;
            $periods[] = [
                'month' => $actualMonth,
                'year' => $actualYear,
                'label' => Carbon::create($actualYear, $actualMonth, 1)->format('F Y'),
            ];
        }

        $monthValues = [$selectedMonth, str_pad((string)$selectedMonth, 2, '0', STR_PAD_LEFT), (string)$selectedMonth];
        $currentQuery = salary_process::with(['employee.compensation', 'employee.organizationAssignment'])
            ->whereIn('month', $monthValues)
            ->where('year', $year)
            ->whereIn('status', ['pending', 'processed', 'issued']);

        if ($companyId) {
            $currentQuery->whereHas('employee.organizationAssignment', fn($q) => $q->where('company_id', $companyId));
        }

        $currentSalaries = $currentQuery->get();

        $employeeSections = [];

        foreach ($currentSalaries as $current) {
            $empId = (int)$current->employee_id;
            $comp = $current->employee->compensation ?? null;
            $org = $current->employee->organizationAssignment ?? null;
            $joined = $org->date_of_joining ?? null;
            $resigned = $org->date_of_resigning ?? null;

            $employeeRows = [];
            $empTotals = ['staff' => 0.0, 'sports' => 0.0, 'total' => 0.0];
            $employerTotals = ['staff' => 0.0, 'sports' => 0.0, 'total' => 0.0];

            foreach ($periods as $period) {
                $monthKeys = [$period['month'], str_pad((string)$period['month'], 2, '0', STR_PAD_LEFT), (string)$period['month']];
                $record = salary_process::where('employee_id', $empId)
                    ->where('year', (string)$period['year'])
                    ->whereIn('month', $monthKeys)
                    ->whereIn('status', ['pending', 'processed', 'issued'])
                    ->first();

                $breakdown = $record
                    ? (is_string($record->salary_breakdown) ? json_decode($record->salary_breakdown, true) : ($record->salary_breakdown ?? []))
                    : [];

                $staffEmp = (float)($breakdown['staff_fund_deduction'] ?? 0);
                $sportsEmp = (float)($breakdown['sports_fund_deduction'] ?? 0);
                $staffEr = $record ? round((float)($comp->staff_fund_amount ?? 0), 2) : 0.0;
                $sportsEr = 0.0;

                $employeeRows[] = [
                    'period' => $period['label'],
                    'employee_staff' => round($staffEmp, 2),
                    'employee_sports' => round($sportsEmp, 2),
                    'employee_total' => round($staffEmp + $sportsEmp, 2),
                    'employer_staff' => $staffEr,
                    'employer_sports' => $sportsEr,
                    'employer_total' => round($staffEr + $sportsEr, 2),
                ];

                $empTotals['staff'] += $staffEmp;
                $empTotals['sports'] += $sportsEmp;
                $empTotals['total'] += $staffEmp + $sportsEmp;
                $employerTotals['staff'] += $staffEr;
                $employerTotals['sports'] += $sportsEr;
                $employerTotals['total'] += $staffEr + $sportsEr;
            }

            $employeeSections[] = [
                'employee_no' => $current->employee_no ?? $current->employee?->attendance_employee_no ?? '-',
                'epf_no' => $current->employee?->epf ?? '-',
                'employee_name' => $current->full_name ?? '-',
                'date_joined' => $joined ? date('Y/m/d', strtotime($joined)) : '-',
                'date_resigned' => $resigned ? date('Y/m/d', strtotime($resigned)) : '-',
                'period_of_service' => $this->formatPeriodOfService($joined, $resigned),
                'fiscal_year' => $fyLabel,
                'employee_rows' => $employeeRows,
                'employee_footer' => [
                    'period' => 'Total Employee Contribution',
                    'employee_staff' => round($empTotals['staff'], 2),
                    'employee_sports' => round($empTotals['sports'], 2),
                    'employee_total' => round($empTotals['total'], 2),
                ],
                'employer_footer' => [
                    'period' => 'Total Employer Contribution',
                    'employer_staff' => round($employerTotals['staff'], 2),
                    'employer_sports' => round($employerTotals['sports'], 2),
                    'employer_total' => round($employerTotals['total'], 2),
                ],
                'grand_total' => [
                    'staff' => round($empTotals['staff'] + $employerTotals['staff'], 2),
                    'sports' => round($empTotals['sports'] + $employerTotals['sports'], 2),
                    'total' => round($empTotals['total'] + $employerTotals['total'], 2),
                ],
            ];
        }

        return [
            'fiscal_year' => $fyLabel,
            'fund_labels' => ['staff' => 'Staff Fund', 'sports' => 'Sports Fund'],
            'employees' => $employeeSections,
        ];
    }

    private function resolveCompanyInfo(string $monthPadded, int $monthInt, string $year, ?int $companyId = null): array
    {
        if ($companyId) {
            $companyModel = \App\Models\company::find($companyId);
            if ($companyModel) {
                return [
                    'name' => $companyModel->name,
                    'registration_no' => $companyModel->company_code ?? '-',
                ];
            }
        }

        $months = [$monthPadded, $monthInt, (string)$monthInt];
        $record = salary_process::whereIn('month', $months)
            ->where('year', $year)
            ->whereIn('status', ['pending', 'processed', 'issued'])
            ->first();

        $companyName = $record->company_name ?? 'Company';
        $companyModel = \App\Models\company::where('name', $companyName)->first();

        return [
            'name' => $companyName,
            'registration_no' => $companyModel->company_code ?? '-',
        ];
    }

    private function estimateAvailableStaffFund(): float
    {
        $totalPaid = 0.0;
        salary_process::whereIn('status', ['pending', 'processed', 'issued'])
            ->get(['salary_breakdown'])
            ->each(function ($record) use (&$totalPaid) {
                $breakdown = is_string($record->salary_breakdown)
                    ? json_decode($record->salary_breakdown, true)
                    : ($record->salary_breakdown ?? []);
                $totalPaid += (float)($breakdown['staff_fund_deduction'] ?? 0);
            });

        return round($totalPaid, 2);
    }
}
