<?php

namespace App\Http\Controllers;

use App\Models\EmployeeLeaveBalance;
use App\Models\employee;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmployeeLeaveBalanceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = EmployeeLeaveBalance::with(['employee:id,full_name,attendance_employee_no,name_with_initials'])
            ->whereNull('deleted_at')
            ->orderByDesc('year')
            ->orderBy('leave_type');

        if ($request->filled('employee_id')) {
            $query->where('employee_id', $request->employee_id);
        }
        if ($request->filled('year')) {
            $query->where('year', $request->year);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('search')) {
            $s = $request->search;
            $query->whereHas('employee', function ($q) use ($s) {
                $q->where('full_name', 'like', "%{$s}%")
                    ->orWhere('attendance_employee_no', 'like', "%{$s}%");
            });
        }

        $rows = $query->get()->map(function (EmployeeLeaveBalance $row) {
            return $this->transform($row);
        });

        return response()->json($rows);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'employee_id' => 'required|exists:employees,id',
            'year' => 'required|integer|min:2000|max:2100',
            'leave_type' => 'required|string|max:100',
            'entitled_days' => 'required|numeric|min:0|max:366',
            'notes' => 'nullable|string|max:255',
            'status' => 'nullable|in:active,inactive',
        ]);

        $validated['leave_type'] = trim($validated['leave_type']);
        $validated['status'] = $validated['status'] ?? 'active';

        try {
            $row = EmployeeLeaveBalance::withTrashed()
                ->where('employee_id', $validated['employee_id'])
                ->where('year', $validated['year'])
                ->where('leave_type', $validated['leave_type'])
                ->first();

            if ($row) {
                if ($row->trashed()) {
                    $row->restore();
                }
                $row->update($validated);
            } else {
                $row = EmployeeLeaveBalance::create($validated);
            }

            $row->load(['employee:id,full_name,attendance_employee_no,name_with_initials']);

            return response()->json([
                'message' => 'Leave balance saved successfully.',
                'data' => $this->transform($row),
            ], 201);
        } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
            return response()->json([
                'message' => "A leave balance for this employee already exists for {$validated['leave_type']} ({$validated['year']}).",
                'errors' => [
                    'leave_type' => ["A leave balance for this employee already exists for {$validated['leave_type']} ({$validated['year']})."],
                ],
            ], 422);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Failed to save leave balance: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $row = EmployeeLeaveBalance::findOrFail($id);

        $validated = $request->validate([
            'employee_id' => 'sometimes|exists:employees,id',
            'year' => 'sometimes|integer|min:2000|max:2100',
            'leave_type' => 'sometimes|string|max:100',
            'entitled_days' => 'sometimes|numeric|min:0|max:366',
            'notes' => 'nullable|string|max:255',
            'status' => 'sometimes|in:active,inactive',
        ]);

        if (isset($validated['leave_type'])) {
            $validated['leave_type'] = trim($validated['leave_type']);
        }

        $employeeId = $validated['employee_id'] ?? $row->employee_id;
        $year = $validated['year'] ?? $row->year;
        $leaveType = $validated['leave_type'] ?? $row->leave_type;

        $conflict = EmployeeLeaveBalance::withTrashed()
            ->where('id', '!=', $id)
            ->where('employee_id', $employeeId)
            ->where('year', $year)
            ->where('leave_type', $leaveType)
            ->first();

        if ($conflict) {
            if ($conflict->trashed()) {
                $conflict->forceDelete();
            } else {
                return response()->json([
                    'message' => "A leave balance for this employee already exists for {$leaveType} ({$year}).",
                    'errors' => [
                        'leave_type' => ["A leave balance for this employee already exists for {$leaveType} ({$year})."],
                    ],
                ], 422);
            }
        }

        try {
            $row->update($validated);
            $row->load(['employee:id,full_name,attendance_employee_no,name_with_initials']);

            return response()->json([
                'message' => 'Leave balance updated successfully.',
                'data' => $this->transform($row),
            ]);
        } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
            return response()->json([
                'message' => "A leave balance for this employee already exists for {$leaveType} ({$year}).",
                'errors' => [
                    'leave_type' => ["A leave balance for this employee already exists for {$leaveType} ({$year})."],
                ],
            ], 422);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Failed to update leave balance: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function destroy(int $id): JsonResponse
    {
        $row = EmployeeLeaveBalance::findOrFail($id);
        $row->delete();

        return response()->json(['message' => 'Leave balance deleted successfully.']);
    }

    public function leaveTypes(): JsonResponse
    {
        return response()->json([
            'Annual Leave',
            'Casual Leave',
            'Sick Leave',
            'Short Leave',
            'Maternity Leave',
            'Paternity Leave',
            'No Pay Leave',
            'Other',
        ]);
    }

    public function employees(Request $request): JsonResponse
    {
        $q = employee::query()
            ->select('id', 'full_name', 'attendance_employee_no', 'name_with_initials')
            ->where('is_active', 1)
            ->orderBy('full_name');

        if ($request->filled('search')) {
            $s = $request->search;
            $q->where(function ($query) use ($s) {
                $query->where('full_name', 'like', "%{$s}%")
                    ->orWhere('attendance_employee_no', 'like', "%{$s}%");
            });
        }

        return response()->json($q->limit(100)->get());
    }

    private function transform(EmployeeLeaveBalance $row): array
    {
        $emp = $row->employee;

        return [
            'id' => $row->id,
            'employee_id' => $row->employee_id,
            'employee_name' => $emp?->full_name ?? $emp?->name_with_initials,
            'emp_no' => $emp?->attendance_employee_no,
            'year' => $row->year,
            'leave_type' => $row->leave_type,
            'entitled_days' => (float) $row->entitled_days,
            'notes' => $row->notes,
            'status' => $row->status,
            'created_at' => $row->created_at,
            'updated_at' => $row->updated_at,
        ];
    }
}
