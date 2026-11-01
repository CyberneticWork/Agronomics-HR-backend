<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\time_card;
use App\Models\employee;
use App\Models\leaveCalendar;
use Illuminate\Support\Facades\DB;

class SingleEntryReportController extends Controller
{
    /**
     * GET /reports/time-cards/single-entry
     * Returns time_card rows where an employee has exactly one IN/Late Coming/OUT/Early OUT entry for a given date, month, or date range.
     */
    public function index(Request $request)
    {
        $reportType = $request->input('report_type');
        $month = $request->input('month');
        $fromDate = $request->input('from_date');
        $toDate = $request->input('to_date');
        $date = $request->input('date');

        // Determine mode if not explicitly passed
        if (!$reportType) {
            if ($month) {
                $reportType = 'month';
            } elseif ($fromDate && $toDate) {
                $reportType = 'range';
            } else {
                $reportType = 'date';
            }
        }

        if ($reportType === 'month') {
            if (!$month) {
                return response()->json(['message' => 'Month is required'], 400);
            }
            if (str_contains((string) $month, '-')) {
                [$year, $monthNumber] = array_pad(explode('-', (string) $month, 2), 2, null);
            } else {
                $year = $request->input('year', date('Y'));
                $monthNumber = str_pad((string) $month, 2, '0', STR_PAD_LEFT);
            }
            if (!$year || !$monthNumber) {
                return response()->json(['message' => 'Month must be in Y-m format'], 422);
            }
            $monthNumber = str_pad((string) $monthNumber, 2, '0', STR_PAD_LEFT);
            $startDate = "{$year}-{$monthNumber}-01";
            $endDate = date('Y-m-t', strtotime($startDate));
        } elseif ($reportType === 'range') {
            if (!$fromDate || !$toDate) {
                return response()->json(['message' => 'from_date and to_date are required'], 400);
            }
            if (strtotime($fromDate) > strtotime($toDate)) {
                return response()->json(['message' => 'from_date must be before or equal to to_date'], 400);
            }
            $startDate = $fromDate;
            $endDate = $toDate;
        } else {
            if (!$date) {
                return response()->json(['message' => 'Date is required'], 400);
            }
            $startDate = $date;
            $endDate = $date;
        }

        $perPage = (int) ($request->input('per_page', 15));
        $search = $request->input('search');
        $company_id = $request->input('company_id');
        $department_id = $request->input('department_id');
        $employee_category = $request->input('employee_category');
        $holiday_worked = $request->boolean('holiday_worked');

        // 1. Filter employees matching company, department, category, and search
        $employeeQuery = employee::query()->excludeContract();

        if ($company_id || $department_id) {
            $employeeQuery->whereHas('organizationAssignment', function ($q) use ($company_id, $department_id) {
                if ($company_id) {
                    $q->where('company_id', $company_id);
                }
                if ($department_id) {
                    $q->where('department_id', $department_id);
                }
            });
        }

        if ($employee_category) {
            $employeeQuery->whereHas('compensation', function ($q) use ($employee_category) {
                $q->where('employee_category', $employee_category);
            });
        }

        if ($search) {
            $employeeQuery->where(function ($q) use ($search) {
                $q->where('full_name', 'like', "%{$search}%")
                  ->orWhere('nic', 'like', "%{$search}%")
                  ->orWhere('attendance_employee_no', 'like', "%{$search}%");
            });
        }

        $filteredEmployeeIds = $employeeQuery->pluck('id')->toArray();

        if (empty($filteredEmployeeIds)) {
            return response()->json([
                'data' => [],
                'current_page' => 1,
                'last_page' => 1,
                'per_page' => $perPage,
                'total' => 0,
            ]);
        }

        $statuses = ['IN', 'Late Coming', 'OUT', 'Early OUT'];

        // Subquery for (employee_id, date) pairs with count(*) = 1
        $singleEntriesSub = time_card::select('employee_id', 'date')
            ->whereBetween('date', [$startDate, $endDate])
            ->whereIn('status', $statuses)
            ->whereNull('deleted_at')
            ->whereIn('employee_id', $filteredEmployeeIds)
            ->groupBy('employee_id', 'date')
            ->havingRaw('COUNT(*) = 1');

        $query = time_card::joinSub($singleEntriesSub, 'single_entries', function ($join) {
                $join->on('time_cards.employee_id', '=', 'single_entries.employee_id')
                     ->on('time_cards.date', '=', 'single_entries.date');
            })
            ->with([
                'employee.organizationAssignment.company',
                'employee.organizationAssignment.department',
                'employee.organizationAssignment.subDepartment',
            ])
            ->whereBetween('time_cards.date', [$startDate, $endDate])
            ->whereIn('time_cards.status', $statuses)
            ->whereNull('time_cards.deleted_at')
            ->whereIn('time_cards.employee_id', $filteredEmployeeIds)
            ->select('time_cards.*')
            ->orderBy('time_cards.date', 'desc')
            ->orderBy('time_cards.time', 'asc');

        // Holiday mapping
        $leaveEntries = leaveCalendar::whereDate('start_date', '<=', $endDate)
            ->where(function ($q) use ($startDate) {
                $q->whereNull('end_date')
                  ->orWhereDate('end_date', '>=', $startDate);
            })
            ->get();

        $leaveMap = [];
        foreach ($leaveEntries as $leave) {
            $rangeStart = max($startDate, $leave->start_date);
            $rangeEnd = $leave->end_date ? min($endDate, $leave->end_date) : $leave->start_date;

            $current = strtotime($rangeStart);
            $last = strtotime($rangeEnd);
            while ($current <= $last) {
                $curDate = date('Y-m-d', $current);
                $key = ($leave->company_id ?? 'null') . '-' . ($leave->department_id ?? 'null');
                $leaveMap[$key][] = $curDate;
                $current = strtotime('+1 day', $current);
            }
        }

        $transformCard = function ($card) use ($leaveMap) {
            $employee = $card->employee;
            $org = $employee?->organizationAssignment;
            $companyId = $org->company_id ?? 'null';
            $departmentId = $org->department_id ?? 'null';

            $companyKey = $companyId . '-null';
            $departmentKey = $companyId . '-' . $departmentId;
            $globalKey = 'null-null';

            $holidayDates = array_merge(
                $leaveMap[$globalKey] ?? [],
                $leaveMap[$companyKey] ?? [],
                $leaveMap[$departmentKey] ?? []
            );
            $isLeaveDate = in_array($card->date, $holidayDates);

            return [
                'id' => $card->id,
                'employee_id' => $card->employee_id,
                'empNo' => $employee->attendance_employee_no ?? null,
                'name' => $employee->full_name ?? null,
                'date' => $card->date,
                'time' => $card->time,
                'entry' => $card->entry,
                'status' => $card->status,
                'inOut' => $card->entry == 1 ? 'IN' : ($card->entry == 2 ? 'OUT' : ($card->entry == 0 ? 'Early OUT' : null)),
                'company' => $org->company->name ?? null,
                'department' => $org->department->name ?? null,
                'sub_department' => $org->subDepartment->name ?? null,
                'is_leave_date' => $isLeaveDate,
                'date_label' => $isLeaveDate ? 'Holiday Worked' : null,
            ];
        };

        if ($holiday_worked) {
            $allCards = $query->get()->map($transformCard);
            $filteredCollection = $allCards->filter(function ($item) {
                return $item['is_leave_date'] === true;
            })->values();

            $page = max((int) $request->input('page', 1), 1);
            $total = $filteredCollection->count();
            $items = $filteredCollection->slice(($page - 1) * $perPage, $perPage)->values();

            return response()->json([
                'data' => $items,
                'current_page' => $page,
                'last_page' => $perPage > 0 ? (int) ceil($total / $perPage) : 1,
                'per_page' => $perPage,
                'total' => $total,
            ]);
        }

        $results = $query->paginate($perPage);
        $results->getCollection()->transform($transformCard);

        return response()->json($results);
    }
}
