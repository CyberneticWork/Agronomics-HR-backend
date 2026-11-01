<?php

namespace App\Http\Controllers;

use App\Models\employee;
use App\Models\over_time;
use App\Models\time_card;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class MonthlyHoursReportController extends Controller
{
    /**
     * Month grid: employees as rows, each calendar day as a column.
     * type = working | ot
     */
    public function index(Request $request)
    {
        $validated = $request->validate([
            'month' => 'required|date_format:Y-m',
            'type' => 'required|in:working,ot',
            'company_id' => 'nullable|exists:companies,id',
            'department_id' => 'nullable|exists:departments,id',
            'search' => 'nullable|string|max:100',
        ]);

        $start = Carbon::createFromFormat('Y-m', $validated['month'])->startOfMonth();
        $end = $start->copy()->endOfMonth();
        $from = $start->toDateString();
        $to = $end->toDateString();
        $daysInMonth = $start->daysInMonth;

        $dates = [];
        for ($d = 1; $d <= $daysInMonth; $d++) {
            $dates[] = $start->copy()->day($d)->toDateString();
        }

        $employeesQuery = employee::with(['organizationAssignment.company', 'organizationAssignment.department'])
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
                    ->orWhere('attendance_employee_no', 'like', "%{$s}%")
                    ->orWhere('nic', 'like', "%{$s}%");
            });
        }

        $employees = $employeesQuery
            ->orderBy('attendance_employee_no')
            ->get();

        $employeeIds = $employees->pluck('id')->all();
        $hoursByEmployeeDate = [];

        if (!empty($employeeIds)) {
            if ($validated['type'] === 'ot') {
                $hoursByEmployeeDate = $this->otHoursByEmployeeDate($employeeIds, $from, $to);
            } else {
                $hoursByEmployeeDate = $this->workingHoursByEmployeeDate($employeeIds, $from, $to);
            }
        }

        $dayTotals = array_fill_keys($dates, 0.0);
        $rows = [];

        foreach ($employees as $employee) {
            $org = $employee->organizationAssignment;
            $dayMap = [];
            $total = 0.0;

            foreach ($dates as $date) {
                $value = (float) ($hoursByEmployeeDate[$employee->id][$date] ?? 0);
                $dayMap[$date] = $value > 0 ? $this->formatHoursToHhMm($value) : null;
                if ($value > 0) {
                    $total += $value;
                    $dayTotals[$date] += $value;
                }
            }

            $rows[] = [
                'employee_id' => $employee->id,
                'emp_no' => $employee->attendance_employee_no,
                'employee_name' => $employee->full_name,
                'company' => $org?->company?->name,
                'company_code' => $org?->company?->company_code,
                'department' => $org?->department?->name,
                'days' => $dayMap,
                'total' => $this->formatHoursToHhMm($total),
                'total_decimal' => round($total, 2),
            ];
        }

        $formattedDayTotals = [];
        foreach ($dayTotals as $date => $sum) {
            $formattedDayTotals[$date] = $sum > 0 ? $this->formatHoursToHhMm($sum) : '00:00';
            $dayTotals[$date] = round($sum, 2);
        }

        $grandTotal = array_sum($dayTotals);

        return response()->json([
            'month' => $validated['month'],
            'type' => $validated['type'],
            'from' => $from,
            'to' => $to,
            'dates' => $dates,
            'hour_format' => 'HH:MM',
            'data' => $rows,
            'day_totals' => $formattedDayTotals,
            'day_totals_decimal' => $dayTotals,
            'grand_total' => $this->formatHoursToHhMm($grandTotal),
            'grand_total_decimal' => round($grandTotal, 2),
        ]);
    }

    private function formatHoursToHhMm(float $num): string
    {
        if ($num <= 0) {
            return '00:00';
        }
        $hours = (int) floor($num);
        $frac = (int) round(($num - $hours) * 100);

        if ($frac === 15) {
            $mins = 15;
        } elseif ($frac === 30) {
            $mins = 30;
        } elseif ($frac === 45) {
            $mins = 45;
        } else {
            $mins = (int) round(($num - $hours) * 60);
        }

        if ($mins >= 60) {
            $hours += intdiv($mins, 60);
            $mins = $mins % 60;
        }

        return sprintf('%02d:%02d', $hours, $mins);
    }

    private function workingHoursByEmployeeDate(array $employeeIds, string $from, string $to): array
    {
        $cards = time_card::whereIn('employee_id', $employeeIds)
            ->whereNull('deleted_at')
            ->whereIn('status', ['OUT', 'Early OUT'])
            ->where(function ($q) use ($from, $to) {
                $q->whereBetween('date', [$from, $to]);
                if (Schema::hasColumn('time_cards', 'actual_date')) {
                    $q->orWhereBetween('actual_date', [$from, $to]);
                }
            })
            ->get(['employee_id', 'date', 'actual_date', 'working_hours']);

        $map = [];
        foreach ($cards as $card) {
            $date = $card->actual_date ?: $card->date;
            if ($date instanceof \DateTimeInterface) {
                $date = $date->format('Y-m-d');
            } else {
                $date = substr((string) $date, 0, 10);
            }
            if ($date < $from || $date > $to) {
                continue;
            }
            $hours = (float) $card->working_hours;
            if ($hours <= 0) {
                continue;
            }
            $empId = $card->employee_id;
            $map[$empId][$date] = round(($map[$empId][$date] ?? 0) + $hours, 2);
        }

        return $map;
    }

    private function otHoursByEmployeeDate(array $employeeIds, string $from, string $to): array
    {
        $query = over_time::with('timeCard')
            ->whereNull('deleted_at')
            ->whereIn('employee_id', $employeeIds);

        $ots = $query->get();
        $hasDateColumn = Schema::hasColumn('over_times', 'date');
        $map = [];

        foreach ($ots as $ot) {
            $card = $ot->timeCard;
            $date = null;
            if ($card) {
                $date = $card->actual_date ?: $card->date;
            } elseif ($hasDateColumn && !empty($ot->date)) {
                $date = $ot->date;
            }

            if ($date instanceof \DateTimeInterface) {
                $date = $date->format('Y-m-d');
            } elseif ($date) {
                $date = substr((string) $date, 0, 10);
            }

            if (!$date || $date < $from || $date > $to) {
                continue;
            }

            $hours = (float) ($ot->ot_hours ?? 0);
            if ($hours <= 0) {
                $hours = (float) ($ot->holiday_ot_hours ?? 0);
            }
            if ($hours <= 0) {
                $hours = (float) ($ot->morning_ot ?? 0) + (float) ($ot->afternoon_ot ?? 0)
                    + (float) ($ot->morning_ot_special ?? 0) + (float) ($ot->evening_ot_special ?? 0);
            }
            if ($hours <= 0) {
                continue;
            }

            $empId = $ot->employee_id;
            $map[$empId][$date] = round(($map[$empId][$date] ?? 0) + $hours, 2);
        }

        return $map;
    }
}
