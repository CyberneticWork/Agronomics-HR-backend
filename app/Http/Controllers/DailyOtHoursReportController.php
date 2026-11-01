<?php

namespace App\Http\Controllers;

use App\Models\over_time;
use App\Models\time_card;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class DailyOtHoursReportController extends Controller
{
    public function index(Request $request)
    {
        $validated = $request->validate([
            'from_date' => 'required|date',
            'to_date' => 'required|date|after_or_equal:from_date',
            'company_id' => 'nullable|exists:companies,id',
            'department_id' => 'nullable|exists:departments,id',
            'search' => 'nullable|string|max:100',
        ]);

        $from = $validated['from_date'];
        $to = $validated['to_date'];
        $hasOtDate = Schema::hasColumn('over_times', 'date');
        $hasActualDate = Schema::hasColumn('time_cards', 'actual_date');

        $query = over_time::with([
            'employee.organizationAssignment.company',
            'employee.organizationAssignment.department',
            'shift',
            'timeCard',
        ])->whereNull('deleted_at');

        $query->where(function ($outer) use ($from, $to, $hasOtDate, $hasActualDate) {
            $outer->whereHas('timeCard', function ($q) use ($from, $to, $hasActualDate) {
                $q->where(function ($inner) use ($from, $to, $hasActualDate) {
                    $inner->whereBetween('date', [$from, $to]);
                    if ($hasActualDate) {
                        $inner->orWhereBetween('actual_date', [$from, $to]);
                    }
                });
            });
            if ($hasOtDate) {
                $outer->orWhereBetween('date', [$from, $to]);
            }
        });

        if (!empty($validated['company_id']) || !empty($validated['department_id']) || !empty($validated['search'])) {
            $query->whereHas('employee', function ($emp) use ($validated) {
                if (!empty($validated['search'])) {
                    $s = $validated['search'];
                    $emp->where(function ($q) use ($s) {
                        $q->where('full_name', 'like', "%{$s}%")
                            ->orWhere('attendance_employee_no', 'like', "%{$s}%")
                            ->orWhere('nic', 'like', "%{$s}%");
                    });
                }
                if (!empty($validated['company_id']) || !empty($validated['department_id'])) {
                    $emp->whereHas('organizationAssignment', function ($org) use ($validated) {
                        if (!empty($validated['company_id'])) {
                            $org->where('company_id', $validated['company_id']);
                        }
                        if (!empty($validated['department_id'])) {
                            $org->where('department_id', $validated['department_id']);
                        }
                    });
                }
            });
        }

        $ots = $query->get();
        $empIds = $ots->pluck('employee_id')->unique()->filter()->values()->all();
        $inByEmpDate = $this->inPunchesByEmployeeDate($empIds, $from, $to);

        $rows = [];
        $totals = [
            'morning_ot' => 0.0,
            'evening_ot' => 0.0,
            'holiday_ot_hours' => 0.0,
            'ot_hours' => 0.0,
            'records' => 0,
        ];

        foreach ($ots as $ot) {
            $card = $ot->timeCard;
            $date = null;
            if ($card) {
                $date = $card->actual_date ?: $card->date;
            } elseif ($hasOtDate && !empty($ot->date)) {
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

            $morning = (float) ($ot->morning_ot ?? 0);
            $evening = (float) ($ot->afternoon_ot ?? 0);
            $holiday = (float) ($ot->holiday_ot_hours ?? 0);
            $total = (float) ($ot->ot_hours ?? 0);
            if ($total <= 0) {
                $total = $holiday;
            }
            if ($total <= 0) {
                $total = $morning + $evening
                    + (float) ($ot->morning_ot_special ?? 0)
                    + (float) ($ot->evening_ot_special ?? 0);
            }
            if ($total <= 0) {
                continue;
            }

            $outTime = $card?->time;
            $inTime = $this->matchInTime($inByEmpDate, (int) $ot->employee_id, $date, $outTime);
            $org = $ot->employee?->organizationAssignment;
            $shift = $ot->shift;

            $rows[] = [
                'id' => $ot->id,
                'employee_id' => $ot->employee_id,
                'emp_no' => $ot->employee?->attendance_employee_no,
                'employee_name' => $ot->employee?->full_name,
                'company' => $org?->company?->name,
                'department' => $org?->department?->name,
                'date' => $date,
                'in_time' => $inTime,
                'out_time' => $outTime,
                'shift' => $shift
                    ? (substr((string) $shift->start_time, 0, 5).' - '.substr((string) $shift->end_time, 0, 5))
                    : null,
                'morning_ot' => $this->formatHoursToHhMm($morning),
                'evening_ot' => $this->formatHoursToHhMm($evening),
                'holiday_ot_hours' => $this->formatHoursToHhMm($holiday),
                'ot_hours' => $this->formatHoursToHhMm($total),
                'morning_ot_decimal' => round($morning, 2),
                'evening_ot_decimal' => round($evening, 2),
                'holiday_ot_hours_decimal' => round($holiday, 2),
                'ot_hours_decimal' => round($total, 2),
                'status' => $ot->status,
            ];

            $totals['morning_ot'] += $morning;
            $totals['evening_ot'] += $evening;
            $totals['holiday_ot_hours'] += $holiday;
            $totals['ot_hours'] += $total;
            $totals['records']++;
        }

        usort($rows, function ($a, $b) {
            $d = strcmp((string) $a['date'], (string) $b['date']);
            if ($d !== 0) {
                return $d;
            }

            return strnatcasecmp((string) $a['emp_no'], (string) $b['emp_no']);
        });

        $totalsFormatted = [
            'morning_ot' => $this->formatHoursToHhMm($totals['morning_ot']),
            'evening_ot' => $this->formatHoursToHhMm($totals['evening_ot']),
            'holiday_ot_hours' => $this->formatHoursToHhMm($totals['holiday_ot_hours']),
            'ot_hours' => $this->formatHoursToHhMm($totals['ot_hours']),
            'records' => $totals['records'],
        ];

        foreach (['morning_ot', 'evening_ot', 'holiday_ot_hours', 'ot_hours'] as $key) {
            $totals[$key] = round($totals[$key], 2);
        }

        return response()->json([
            'from' => $from,
            'to' => $to,
            'hour_format' => 'HH:MM',
            'data' => $rows,
            'totals' => $totalsFormatted,
            'totals_decimal' => $totals,
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

    private function inPunchesByEmployeeDate(array $employeeIds, string $from, string $to): array
    {
        if (empty($employeeIds)) {
            return [];
        }

        $cards = time_card::query()
            ->whereIn('employee_id', $employeeIds)
            ->whereNull('deleted_at')
            ->whereIn('status', ['IN', 'Late Coming'])
            ->whereBetween('date', [$from, $to])
            ->orderBy('time')
            ->get(['employee_id', 'date', 'time']);

        $map = [];
        foreach ($cards as $card) {
            $date = $card->date instanceof \DateTimeInterface
                ? $card->date->format('Y-m-d')
                : substr((string) $card->date, 0, 10);
            $map[$card->employee_id][$date][] = $card->time;
        }

        return $map;
    }

    private function matchInTime(array $inByEmpDate, int $employeeId, string $date, ?string $outTime): ?string
    {
        $times = $inByEmpDate[$employeeId][$date] ?? [];
        if (empty($times)) {
            return null;
        }
        if (!$outTime) {
            return $times[0];
        }
        $picked = $times[0];
        foreach ($times as $time) {
            if (strcmp((string) $time, (string) $outTime) <= 0) {
                $picked = $time;
            }
        }

        return $picked;
    }
}
