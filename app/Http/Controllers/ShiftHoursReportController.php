<?php

namespace App\Http\Controllers;

use App\Models\employee;
use App\Models\Roster;
use App\Models\shifts;
use App\Models\time_card;
use App\Services\ContractEmployeeScope;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class ShiftHoursReportController extends Controller
{
    /**
     * Working hours within shift window AND extra hours after shift end.
     * report_type: within | extra | both
     * Hours are returned in HH:MM format (e.g. 08:30).
     */
    public function index(Request $request)
    {
        $validated = $request->validate([
            'from_date' => 'required|date',
            'to_date' => 'required|date|after_or_equal:from_date',
            'company_id' => 'nullable|exists:companies,id',
            'department_id' => 'nullable|exists:departments,id',
            'report_type' => 'nullable|in:within,extra,both',
            'search' => 'nullable|string|max:100',
        ]);

        $from = $validated['from_date'];
        $to = $validated['to_date'];
        $reportType = $validated['report_type'] ?? 'both';

        $employeesQuery = employee::with(['organizationAssignment.company', 'organizationAssignment.department', 'compensation'])
            ->where('is_active', 1)
            ->excludeContract();

        if (!empty($validated['company_id']) || !empty($validated['department_id'])) {
            $employeesQuery->whereHas('organizationAssignment', function ($q) use ($validated) {
                if (!empty($validated['company_id'])) {
                    $q->where('company_id', $validated['company_id']);
                }
                if (!empty($validated['department_id'])) {
                    $q->where('department_id', $validated['department_id']);
                }
            });
        }

        if (!empty($validated['search'])) {
            $s = $validated['search'];
            $employeesQuery->where(function ($q) use ($s) {
                $q->where('full_name', 'like', "%{$s}%")
                    ->orWhere('attendance_employee_no', 'like', "%{$s}%");
            });
        }

        $employees = $employeesQuery->orderBy('full_name')->get();
        $rows = [];
        $totalWithinMinutes = 0;
        $totalExtraMinutes = 0;
        $totalWorkedMinutes = 0;

        foreach ($employees as $employee) {
            $cards = time_card::where('employee_id', $employee->id)
                ->whereNull('deleted_at')
                ->whereBetween('date', [$from, $to])
                ->orderBy('date')
                ->orderBy('time')
                ->get();

            $byDate = $cards->groupBy('date');

            foreach ($byDate as $date => $dayCards) {
                $inCard = $dayCards->first(fn ($c) => in_array($c->status, ['IN', 'Late Coming'], true));
                $outCard = $dayCards->last(fn ($c) => in_array($c->status, ['OUT', 'Early OUT'], true));

                if (!$inCard || !$outCard) {
                    continue;
                }

                [$roster, $shift] = $this->resolveRosterAndShift($employee, $date);
                if (!$shift) {
                    continue;
                }

                $shiftStart = Carbon::parse($date . ' ' . $shift->start_time);
                $shiftEnd = Carbon::parse($date . ' ' . $shift->end_time);
                if ($shiftEnd->lte($shiftStart)) {
                    $shiftEnd->addDay();
                }

                $inAt = Carbon::parse($inCard->date . ' ' . $inCard->time);
                $outAt = Carbon::parse($outCard->date . ' ' . $outCard->time);
                if ($outAt->lt($inAt)) {
                    $outAt->addDay();
                }

                $withinStart = $inAt->greaterThan($shiftStart) ? $inAt->copy() : $shiftStart->copy();
                $withinEnd = $outAt->lessThan($shiftEnd) ? $outAt->copy() : $shiftEnd->copy();

                $withinMinutes = $withinEnd->greaterThan($withinStart)
                    ? $withinStart->diffInMinutes($withinEnd)
                    : 0;
                $extraMinutes = $outAt->greaterThan($shiftEnd)
                    ? $shiftEnd->diffInMinutes($outAt)
                    : 0;
                $totalMinutes = $inAt->diffInMinutes($outAt);

                if ($reportType === 'within' && $withinMinutes <= 0) {
                    continue;
                }
                if ($reportType === 'extra' && $extraMinutes <= 0) {
                    continue;
                }

                $org = $employee->organizationAssignment;

                $totalWithinMinutes += $withinMinutes;
                $totalExtraMinutes += $extraMinutes;
                $totalWorkedMinutes += $totalMinutes;

                $rows[] = [
                    'employee_id' => $employee->id,
                    'emp_no' => $employee->attendance_employee_no,
                    'employee_name' => $employee->full_name,
                    'company' => $org?->company?->name,
                    'department' => $org?->department?->name,
                    'date' => $date,
                    'shift_code' => $shift->shift_code ?? $shift->id,
                    'shift_start' => substr((string) $shift->start_time, 0, 5),
                    'shift_end' => substr((string) $shift->end_time, 0, 5),
                    'in_time' => substr((string) $inCard->time, 0, 8),
                    'out_time' => substr((string) $outCard->time, 0, 8),
                    'in_status' => $inCard->status,
                    'out_status' => $outCard->status,
                    'within_shift_hours' => $this->formatMinutesAsHm($withinMinutes),
                    'extra_hours' => $this->formatMinutesAsHm($extraMinutes),
                    'total_worked_hours' => $this->formatMinutesAsHm($totalMinutes),
                    'within_shift_minutes' => $withinMinutes,
                    'extra_minutes' => $extraMinutes,
                    'total_worked_minutes' => $totalMinutes,
                    'within_shift_hours_decimal' => round($withinMinutes / 60, 2),
                    'extra_hours_decimal' => round($extraMinutes / 60, 2),
                    'total_worked_hours_decimal' => round($totalMinutes / 60, 2),
                ];
            }
        }

        $totals = [
            'within_shift_hours' => $this->formatMinutesAsHm($totalWithinMinutes),
            'extra_hours' => $this->formatMinutesAsHm($totalExtraMinutes),
            'total_worked_hours' => $this->formatMinutesAsHm($totalWorkedMinutes),
            'within_shift_hours_decimal' => round($totalWithinMinutes / 60, 2),
            'extra_hours_decimal' => round($totalExtraMinutes / 60, 2),
            'total_worked_hours_decimal' => round($totalWorkedMinutes / 60, 2),
            'records' => count($rows),
        ];

        return response()->json([
            'report_type' => $reportType,
            'from_date' => $from,
            'to_date' => $to,
            'hour_format' => 'HH:MM',
            'totals' => $totals,
            'data' => $rows,
        ]);
    }

    /**
     * Convert minutes to HH:MM (e.g. 510 -> "08:30").
     */
    private function formatMinutesAsHm(int $minutes): string
    {
        $minutes = max(0, (int) $minutes);
        $hours = intdiv($minutes, 60);
        $mins = $minutes % 60;

        return sprintf('%02d:%02d', $hours, $mins);
    }

    private function resolveRosterAndShift(employee $employee, string $date): array
    {
        $org = $employee->organizationAssignment;
        if (!$org) {
            return [null, null];
        }

        $applyActiveStatus = function ($query) {
            if (Schema::hasColumn('rosters', 'status')) {
                $query->where(function ($q) {
                    $q->whereNull('status')->orWhere('status', 'Active');
                });
            }
            return $query;
        };

        $rosterQuery = Roster::where('employee_id', $employee->id)->whereNull('deleted_at');
        $applyActiveStatus($rosterQuery);
        $roster = $rosterQuery
            ->where(function ($q) use ($date) {
                $q->whereNull('date_from')->orWhere('date_from', '<=', $date);
            })
            ->where(function ($q) use ($date) {
                $q->whereNull('date_to')->orWhere('date_to', '>=', $date);
            })
            ->orderByDesc('id')
            ->first();

        if (!$roster && $org->sub_department_id) {
            $rosterQuery = Roster::where('sub_department_id', $org->sub_department_id)
                ->whereNull('employee_id')
                ->whereNull('deleted_at');
            $applyActiveStatus($rosterQuery);
            $roster = $rosterQuery
                ->where(function ($q) use ($date) {
                    $q->whereNull('date_from')->orWhere('date_from', '<=', $date);
                })
                ->where(function ($q) use ($date) {
                    $q->whereNull('date_to')->orWhere('date_to', '>=', $date);
                })
                ->orderByDesc('id')
                ->first();
        }

        if (!$roster && $org->department_id) {
            $rosterQuery = Roster::where('department_id', $org->department_id)
                ->whereNull('employee_id')
                ->whereNull('sub_department_id')
                ->whereNull('deleted_at');
            $applyActiveStatus($rosterQuery);
            $roster = $rosterQuery
                ->where(function ($q) use ($date) {
                    $q->whereNull('date_from')->orWhere('date_from', '<=', $date);
                })
                ->where(function ($q) use ($date) {
                    $q->whereNull('date_to')->orWhere('date_to', '>=', $date);
                })
                ->orderByDesc('id')
                ->first();
        }

        if (!$roster && $org->company_id) {
            $rosterQuery = Roster::where('company_id', $org->company_id)
                ->whereNull('employee_id')
                ->whereNull('sub_department_id')
                ->whereNull('department_id')
                ->whereNull('deleted_at');
            $applyActiveStatus($rosterQuery);
            $roster = $rosterQuery
                ->where(function ($q) use ($date) {
                    $q->whereNull('date_from')->orWhere('date_from', '<=', $date);
                })
                ->where(function ($q) use ($date) {
                    $q->whereNull('date_to')->orWhere('date_to', '>=', $date);
                })
                ->orderByDesc('id')
                ->first();
        }

        $shift = $roster ? shifts::find($roster->shift_code) : null;

        return [$roster, $shift];
    }
}
