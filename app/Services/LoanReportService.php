<?php

namespace App\Services;

use App\Models\loans;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class LoanReportService
{
    public function buildReport(?string $employeeNo = null, ?string $loanId = null): array
    {
        $query = loans::with([
            'employee:id,full_name,attendance_employee_no,nic,epf,organization_assignment_id',
            'employee.organizationAssignment:id,department_id,company_id',
            'employee.organizationAssignment.department:id,name',
            'employee.organizationAssignment.company:id,name',
        ])->orderBy('created_at', 'desc');

        if ($employeeNo) {
            $query->whereHas('employee', fn ($q) => $q->where('attendance_employee_no', $employeeNo));
        }

        if ($loanId) {
            $query->where('loan_id', $loanId);
        }

        $loans = $query->get();
        $today = Carbon::today();

        $loanReports = $loans->map(fn ($loan) => $this->formatLoan($loan, $today));

        $installmentLines = $loanReports->flatMap(function ($loan) {
            return collect($loan['schedule'])->map(function ($row) use ($loan) {
                return array_merge($row, [
                    'loan_id' => $loan['loan_id'],
                    'employee_no' => $loan['employee_no'],
                    'employee_name' => $loan['employee_name'],
                ]);
            });
        })->values();

        return [
            'generated_at' => now()->toIso8601String(),
            'report_date' => $today->toDateString(),
            'filter' => [
                'employee_no' => $employeeNo,
                'loan_id' => $loanId,
                'scope' => $loanId ? 'loan' : ($employeeNo ? 'employee' : 'all'),
            ],
            'summary' => [
                'total_loans' => $loanReports->count(),
                'active_loans' => $loanReports->where('status', 'active')->count(),
                'completed_loans' => $loanReports->where('status', 'completed')->count(),
                'total_disbursed' => round($loanReports->sum('loan_amount'), 2),
                'total_outstanding_balance' => round($loanReports->sum('outstanding_balance'), 2),
                'total_repaid' => round($loanReports->sum('total_repaid'), 2),
                'total_interest_payable' => round($loanReports->sum('total_interest_payable'), 2),
                'total_interest_paid' => round($loanReports->sum('total_interest_paid'), 2),
                'total_principal_payable' => round($loanReports->sum('total_principal_payable'), 2),
                'total_principal_paid' => round($loanReports->sum('total_principal_paid'), 2),
                'installments_paid' => (int) $loanReports->sum('installments_paid'),
                'installments_pending' => (int) $loanReports->sum('installments_pending'),
                'installments_overdue' => (int) $loanReports->sum('installments_overdue'),
            ],
            'loans' => $loanReports->values()->all(),
            'installment_lines' => $installmentLines->all(),
        ];
    }

    private function formatLoan(loans $loan, Carbon $today): array
    {
        $schedule = $this->normalizeSchedule($loan);
        $totalInstallments = count($schedule) ?: max(1, (int) $loan->installment_count);
        $remaining = strtolower((string) $loan->status) === 'completed'
            ? 0
            : max(0, (int) $loan->installment_count);
        $paidCount = max(0, $totalInstallments - $remaining);

        $monthlyInterest = 0.0;
        if ($loan->with_interest && $loan->interest_rate_per_annum > 0) {
            $monthlyInterest = round(($loan->loan_amount * ($loan->interest_rate_per_annum / 100)) / 12, 2);
        }

        $scheduleRows = [];
        $totalInterest = 0.0;
        $totalPrincipal = 0.0;
        $totalRepaid = 0.0;
        $totalInterestPaid = 0.0;
        $totalPrincipalPaid = 0.0;
        $pendingCount = 0;
        $overdueCount = 0;
        $nextDueDate = null;

        foreach ($schedule as $index => $row) {
            $installmentNo = (int) ($row['no'] ?? $row['installment_no'] ?? ($index + 1));
            $installment = (float) ($row['installment_amount'] ?? $row['installmentAmount'] ?? $loan->installment_amount);
            $interest = (float) ($row['interest_payment'] ?? $row['interestPayment'] ?? 0);
            $principal = (float) ($row['capital_repayment'] ?? $row['capitalRepayment'] ?? ($installment - $interest));

            if ($interest <= 0 && $loan->with_interest && $monthlyInterest > 0) {
                $interest = min($monthlyInterest, $installment);
                $principal = max(0, $installment - $interest);
            }

            $dueDateRaw = $row['due_date'] ?? $row['dueDate'] ?? null;
            $dueDate = $this->parseDueDate($dueDateRaw, $loan->start_from, $installmentNo);
            $balance = (float) ($row['due_balance'] ?? $row['dueBalance'] ?? $row['capital_outstanding'] ?? $row['capitalOutstanding'] ?? 0);

            $isPaid = $index < $paidCount || strtolower((string) $loan->status) === 'completed';
            $status = 'pending';
            if ($isPaid) {
                $status = 'paid';
            } elseif ($dueDate && $dueDate->lt($today)) {
                $status = 'overdue';
                $overdueCount++;
            } else {
                $pendingCount++;
                if (!$nextDueDate && $dueDate) {
                    $nextDueDate = $dueDate->toDateString();
                }
            }

            if ($isPaid) {
                $totalRepaid += $installment;
                $totalInterestPaid += $interest;
                $totalPrincipalPaid += $principal;
            }

            $totalInterest += $interest;
            $totalPrincipal += $principal;

            $scheduleRows[] = [
                'installment_no' => $installmentNo,
                'due_date' => $dueDate?->toDateString(),
                'due_date_display' => $dueDate?->format('d M Y'),
                'installment_amount' => round($installment, 2),
                'principal_deduction' => round($principal, 2),
                'interest_deduction' => round($interest, 2),
                'balance_after' => round($balance, 2),
                'status' => $status,
            ];
        }

        if ($scheduleRows === [] && $totalInstallments > 0) {
            $scheduleRows = $this->generateFlatSchedule($loan, $totalInstallments, $monthlyInterest, $paidCount, $today);
            foreach ($scheduleRows as $row) {
                $totalInterest += $row['interest_deduction'];
                $totalPrincipal += $row['principal_deduction'];
                if ($row['status'] === 'paid') {
                    $totalRepaid += $row['installment_amount'];
                    $totalInterestPaid += $row['interest_deduction'];
                    $totalPrincipalPaid += $row['principal_deduction'];
                } elseif ($row['status'] === 'overdue') {
                    $overdueCount++;
                } elseif ($row['status'] === 'pending') {
                    $pendingCount++;
                    $nextDueDate = $nextDueDate ?? $row['due_date'];
                }
            }
        }

        $outstanding = round(max(0, ($loan->loan_amount + $totalInterest) - $totalRepaid), 2);
        if ($outstanding === 0.0 && strtolower((string) $loan->status) !== 'completed' && $remaining > 0) {
            $outstanding = round($remaining * (float) $loan->installment_amount, 2);
        }

        $employee = $loan->employee;
        $org = $employee?->organizationAssignment;

        return [
            'id' => $loan->id,
            'loan_id' => $loan->loan_id,
            'employee_no' => $employee?->attendance_employee_no,
            'employee_name' => $employee?->full_name,
            'employee_nic' => $employee?->nic,
            'employee_epf' => $employee?->epf,
            'company_name' => $org?->company?->name,
            'department_name' => $org?->department?->name,
            'loan_amount' => round((float) $loan->loan_amount, 2),
            'interest_rate_per_annum' => round((float) $loan->interest_rate_per_annum, 2),
            'with_interest' => (bool) $loan->with_interest,
            'installment_amount' => round((float) $loan->installment_amount, 2),
            'installment_count_total' => $totalInstallments,
            'installments_remaining' => $remaining,
            'installments_paid' => $paidCount,
            'installments_pending' => $pendingCount,
            'installments_overdue' => $overdueCount,
            'monthly_interest_deduction' => $monthlyInterest,
            'deduct_from' => $loan->deduct_from,
            'installment_deduct_from' => $loan->installment_deduct_from,
            'interest_deduct_from' => $loan->interest_deduct_from,
            'deduct_from_label' => $this->deductFromLabel($loan),
            'deduct_basic_amount' => $loan->deduct_basic_amount,
            'deduct_bonus_amount' => $loan->deduct_bonus_amount,
            'created_at_date' => $loan->created_at?->format('Y-m-d'),
            'loan_date' => $loan->request_date?->format('Y-m-d')
                ?: ($loan->created_at?->format('Y-m-d') ?: ($loan->start_from?->format('Y-m-d') ?: '-')),
            'start_from' => $loan->start_from?->format('Y-m-d'),
            'start_from_display' => $loan->start_from?->format('M Y'),
            'request_date' => $loan->request_date?->format('Y-m-d'),
            'request_date_display' => $loan->request_date?->format('d M Y'),
            'status' => $loan->status,
            'next_due_date' => $nextDueDate,
            'outstanding_balance' => $outstanding,
            'total_repaid' => round($totalRepaid, 2),
            'total_interest_payable' => round($totalInterest, 2),
            'total_interest_paid' => round($totalInterestPaid, 2),
            'total_interest_outstanding' => round(max(0, $totalInterest - $totalInterestPaid), 2),
            'total_principal_payable' => round($totalPrincipal, 2),
            'total_principal_paid' => round($totalPrincipalPaid, 2),
            'total_principal_outstanding' => round(max(0, $totalPrincipal - $totalPrincipalPaid), 2),
            'schedule' => $scheduleRows,
        ];
    }

    private function normalizeSchedule(loans $loan): array
    {
        $schedule = $loan->schedule;
        if (is_string($schedule)) {
            $schedule = json_decode($schedule, true);
        }

        return is_array($schedule) ? $schedule : [];
    }

    private function parseDueDate(mixed $dueDateRaw, mixed $startFrom, int $installmentNo): ?Carbon
    {
        if ($dueDateRaw) {
            try {
                return Carbon::parse($dueDateRaw);
            } catch (\Throwable) {
                // fall through
            }
        }

        if ($startFrom) {
            return Carbon::parse($startFrom)->addMonths($installmentNo);
        }

        return null;
    }

    private function generateFlatSchedule(loans $loan, int $totalInstallments, float $monthlyInterest, int $paidCount, Carbon $today): array
    {
        $rows = [];
        $principalRemaining = (float) $loan->loan_amount;
        $principalPerInst = $totalInstallments > 0 ? $loan->loan_amount / $totalInstallments : 0;

        for ($i = 1; $i <= $totalInstallments; $i++) {
            $installment = (float) $loan->installment_amount;
            $interest = $loan->with_interest ? min($monthlyInterest, $installment) : 0;
            $principal = max(0, $installment - $interest);
            $principalRemaining = max(0, $principalRemaining - $principal);
            $dueDate = $this->parseDueDate(null, $loan->start_from, $i);

            $isPaid = ($i - 1) < $paidCount;
            $status = 'pending';
            if ($isPaid) {
                $status = 'paid';
            } elseif ($dueDate && $dueDate->lt($today)) {
                $status = 'overdue';
            }

            $rows[] = [
                'installment_no' => $i,
                'due_date' => $dueDate?->toDateString(),
                'due_date_display' => $dueDate?->format('d M Y'),
                'installment_amount' => round($installment, 2),
                'principal_deduction' => round($principal, 2),
                'interest_deduction' => round($interest, 2),
                'balance_after' => round($principalRemaining, 2),
                'status' => $status,
            ];
        }

        return $rows;
    }

    private function deductFromLabel(loans $loan): string
    {
        $pay = function (?string $from, ?string $fallback): string {
            $v = strtolower(trim((string) ($from ?: $fallback ?: 'bonus')));
            return $v === 'basic' ? 'Basic' : 'Monthly Bonus';
        };
        $legacy = $loan->deduct_from;
        $inst = $pay($loan->installment_deduct_from, $legacy === 'basic' ? 'basic' : 'bonus');
        $interest = $pay($loan->interest_deduct_from, $loan->installment_deduct_from ?: ($legacy === 'basic' ? 'basic' : 'bonus'));
        if ($inst === $interest) {
            return "Installment and interest from {$inst}";
        }
        return "Installment from {$inst}, interest from {$interest}";
    }
}
