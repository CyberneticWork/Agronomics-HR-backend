<?php

namespace App\Http\Controllers;

use App\Exports\AttendanceTemplateExport;
use App\Models\absence;
use App\Models\employee;
use App\Models\leave_master;
use App\Models\over_time;
use App\Models\Roster;
use App\Models\shifts;
use App\Models\time_card;
use App\Services\CompanyProcessSettings;
use App\Services\ContractEmployeeScope;
use App\Services\Overtime\OvertimeCalculator;
use App\Services\RosterShiftResolver;
use App\Services\TimeCardAuditService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Maatwebsite\Excel\Facades\Excel;

class TimeCardController extends Controller
{
    protected OvertimeCalculator $overtimeCalculator;

    public function __construct(
        OvertimeCalculator $overtimeCalculator,
        private RosterShiftResolver $rosterResolver,
    ) {
        $this->overtimeCalculator = $overtimeCalculator;
    }

    public function index(Request $request)
    {
        $request->validate([
            'date' => 'nullable|date',
            'from_date' => 'nullable|date',
            'to_date' => 'nullable|date|after_or_equal:from_date',
        ]);

        $query = time_card::with(['employee.organizationAssignment.department'])
            ->whereNull('deleted_at');
        ContractEmployeeScope::excludeRelated($query);

        if ($request->filled('date')) {
            $query->whereDate('date', $request->date);
        } else {
            if ($request->filled('from_date')) {
                $query->whereDate('date', '>=', $request->from_date);
            }
            if ($request->filled('to_date')) {
                $query->whereDate('date', '<=', $request->to_date);
            }
        }

        $cards = $query
            ->orderBy('date', 'desc')
            ->orderBy('time', 'asc')
            ->get()
            ->map(function ($card) {
                return [
                    'id' => $card->id,
                    'empNo' => $card->employee->attendance_employee_no ?? null,
                    'name' => $card->employee->full_name ?? null,
                    'fingerprintClock' => $card->fingerprint_clock ?? null,
                    'time' => $card->time,
                    'date' => $card->date,
                    'entry' => $card->entry,
                    'inOut' => $card->entry == 1
                        ? 'IN'
                        : ($card->entry == 2 ? 'OUT' : ($card->entry == 0 ? 'Early OUT' : null)),
                    'department' => $card->employee->organizationAssignment->department->name ?? null,
                    'status' => $card->status,
                    'remarks' => $card->remarks,
                    'nic' => $card->employee->nic ?? null,
                ];
            });

        return response()->json($cards);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'employee_id' => 'required|exists:employees,id',
            'time' => 'required',
            'date' => 'required|date',
            'entry' => 'required',
            'status' => 'required',
            'remarks' => 'nullable|string|max:255',
        ]);

        $employee = employee::find($validated['employee_id']);
        if (!$employee) {
            return response()->json(['message' => 'Employee not found'], 404);
        }

        $org = $employee->organizationAssignment;
        if (!$org) {
            return response()->json(['message' => 'Organization assignment not found'], 404);
        }

        [$roster, $shift] = $this->resolveRosterAndShift($employee, $validated['date']);

        if (!$roster) {
            return response()->json(['message' => 'No shift/roster assigned for this employee on this date'], 422);
        }

        if (!$shift) {
            return response()->json(['message' => 'Shift not found'], 404);
        }

        try {
            $inputTime = Carbon::createFromFormat('H:i:s', $validated['time']);
        } catch (\Exception $e) {
            try {
                $inputTime = Carbon::createFromFormat('H:i', $validated['time']);
            } catch (\Exception $ex) {
                return response()->json(['message' => 'Invalid time format. Please use HH:mm or HH:mm:ss'], 422);
            }
        }

        $storeTime = $inputTime->format('H:i:s');

        $kind = (strtoupper((string)$validated['status']) === 'OUT' || strtoupper((string)$validated['status']) === 'EARLY OUT') ? 'out' : 'in';
        [$matchedRoster, $matchedShift] = $this->rosterResolver->match($employee, $validated['date'], $storeTime, $kind);
        if ($matchedShift) { $roster = $matchedRoster; $shift = $matchedShift; }

        $entryType = (int) $validated['entry'];
        $status = strtoupper($validated['status']);
        $working_hours = null;
        $actual_date = null;
        $pairedInCard = null;

        if ($status === 'OUT' || $status === 'EARLY OUT') {
            $lastInCard = $this->findPairedInCard($employee, $validated['date'], $storeTime, $shift);

            if ($lastInCard) {
                $pairedInCard = $lastInCard;

                $inDate = $lastInCard->date;
                $outDate = $validated['date'];

                $inDateTime = Carbon::parse($inDate . ' ' . $lastInCard->time);
                $outDateTime = Carbon::parse($outDate . ' ' . $storeTime);

                $working_hours = $this->computeWorkingHours($employee, $inDateTime, $outDateTime, $shift);

                $shiftStartDT = Carbon::parse($inDate . ' ' . $shift->start_time);
                $shiftEndDT = Carbon::parse($inDate . ' ' . $shift->end_time);
                if ($shiftEndDT->lte($shiftStartDT)) {
                    $shiftEndDT->addDay();
                }

                $actual_date = ($inDate !== $outDate) ? $inDate : null;

                if ($outDateTime->lt($shiftEndDT)) {
                    $entryType = 0;
                    $status = 'Early OUT';
                } else {
                    $entryType = 2;
                    $status = 'OUT';
                }
            }
        } else {
            $entryType = 1;
            $working_hours = null;
            $status = $this->resolveInStatus($validated['date'], $storeTime, $shift);
        }

        $fingerprintClock = now();

        $payload = [
            'employee_id' => $employee->id,
            'time' => $storeTime,
            'date' => $validated['date'],
            'working_hours' => $working_hours,
            'entry' => $entryType,
            'status' => $status,
            'approval_status' => 'Pending',
            'fingerprint_clock' => $fingerprintClock,
            'actual_date' => $actual_date,
            'remarks' => isset($validated['remarks']) && trim($validated['remarks']) !== '' ? trim($validated['remarks']) : null,
        ];
        $source = $request->input('entry_source', 'manual');
        if (!in_array($source, ['manual', 'import'], true)) {
            $source = 'manual';
        }
        if (Schema::hasColumn('time_cards', 'entry_source')) {
            $payload['entry_source'] = $source;
        }
        if (Schema::hasColumn('time_cards', 'created_by')) {
            $payload['created_by'] = Auth::id();
        }
        if (Schema::hasColumn('time_cards', 'shift_id') && $shift) {
            $payload['shift_id'] = $shift->id;
        }

        $timeCard = time_card::create($payload);
        TimeCardAuditService::log($timeCard, 'created', $request->input('reason'), null, TimeCardAuditService::snapshot($timeCard), $source);

        if (in_array($status, ['OUT', 'Early OUT']) && $pairedInCard) {
            $referenceDate = $actual_date ?? $pairedInCard->date;
            $this->processOvertimeForOutPunch($employee, $timeCard, $pairedInCard, $shift, $referenceDate);
        }

        return response()->json($timeCard, 201);
    }

    public function attendance(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'empno' => ['required', 'regex:/^[A-Za-z0-9_\-]+$/'],
            'date' => 'required|date',
            'time' => 'required|date_format:H:i:s',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $rawEmpNo = trim($request->empno);

        $employee = employee::where('attendance_employee_no', $rawEmpNo)->first();

        // Devices pad IDs ("0006") while HR may store "6" (or the other way round).
        if (!$employee && ctype_digit($rawEmpNo)) {
            $unpadded = ltrim($rawEmpNo, '0');
            if ($unpadded !== '') {
                $employee = employee::whereRaw("TRIM(LEADING '0' FROM TRIM(attendance_employee_no)) = ?", [$unpadded])->first();
            }
        }

        if (!$employee && ctype_digit($rawEmpNo)) {
            $employee = employee::find((int) $rawEmpNo);
        }

        if (!$employee) {
            return response()->json(['message' => 'Employee not found'], 404);
        }

        $org = $employee->organizationAssignment;
        if (!$org) {
            return response()->json(['message' => 'Organization assignment not found'], 404);
        }

        [$roster, $shift] = $this->resolveRosterAndShift($employee, $request->date);

        if (!$roster) {
            return response()->json(['message' => 'No roster assigned for this employee on this date'], 422);
        }

        if (!$shift) {
            return response()->json(['message' => 'Shift not found'], 404);
        }

        $inputTime = Carbon::createFromFormat('H:i:s', $request->time);
        $storeTime = $inputTime->format('H:i:s');
        [$matchedRoster, $matchedShift] = $this->rosterResolver->match($employee, $request->date, $storeTime, 'in');
        if ($matchedShift) {
            $roster = $matchedRoster;
            $shift = $matchedShift;
        }

        $recentCard = time_card::where('employee_id', $employee->id)
            ->where('date', $request->date)
            ->where(function ($q) use ($inputTime) {
                $q->whereBetween('time', [
                    $inputTime->copy()->subMinutes(5)->format('H:i:s'),
                    $inputTime->copy()->addMinutes(5)->format('H:i:s')
                ]);
            })
            ->orderBy('time', 'desc')
            ->first();

        if ($recentCard) {
            return response()->json([
                'message' => 'You cannot mark attendance within 5 minutes of your previous record.'
            ], 409);
        }

        $lastCard = time_card::where('employee_id', $employee->id)
            ->whereNull('deleted_at')
            ->orderBy('date', 'desc')
            ->orderBy('time', 'desc')
            ->first();

        $entryType = 1;
        $status = 'IN';
        $working_hours = null;
        $actual_date = null;
        $pairedInCard = null;

        $openIn = $this->findPairedInCard($employee, $request->date, $storeTime, $shift);
        $treatAsOut = $openIn && (
            CompanyProcessSettings::usesShiftRoster($employee)
            || ($lastCard && in_array($lastCard->status, ['IN', 'Late Coming'], true))
        );

        if ($treatAsOut) {
            $pairedInCard = $openIn;
            $lastInDate = Carbon::parse($openIn->date);
            $currentDate = Carbon::parse($request->date);

            if ($lastInDate->eq($currentDate)) {
                $inTime = Carbon::parse($openIn->date . ' ' . $openIn->time);
                $outTime = Carbon::parse($request->date . ' ' . $storeTime);
                $working_hours = $this->computeWorkingHours($employee, $inTime, $outTime, $shift);

                $shiftStartDT = Carbon::parse($openIn->date . ' ' . $shift->start_time);
                $shiftEndDT = Carbon::parse($openIn->date . ' ' . $shift->end_time);
                if ($shiftEndDT->lte($shiftStartDT)) {
                    $shiftEndDT->addDay();
                }

                $currentPunchDT = Carbon::parse($request->date . ' ' . $storeTime);

                if ($currentPunchDT->lt($shiftEndDT)) {
                    $entryType = 0;
                    $status = 'Early OUT';
                } else {
                    $entryType = 2;
                    $status = 'OUT';
                }
            } else {
                $inDateTime = Carbon::parse($openIn->date . ' ' . $openIn->time);
                $outDateTime = Carbon::parse($request->date . ' ' . $request->time);
                $working_hours = $this->computeWorkingHours($employee, $inDateTime, $outDateTime, $shift);

                $entryType = 2;
                $status = 'OUT';
                $actual_date = $openIn->date;
            }
        } else {
            $entryType = 1;
            $working_hours = null;
            $status = $this->resolveInStatus($request->date, $storeTime, $shift);
        }

        $fingerprintClock = now();

        $needsApproval = in_array($status, ['Late Coming', 'Early OUT'], true);

        $payload = [
            'employee_id' => $employee->id,
            'time' => $storeTime,
            'date' => $request->date,
            'working_hours' => $working_hours,
            'entry' => $entryType,
            'status' => $status,
            'approval_status' => $needsApproval ? 'Pending' : 'Active',
            'fingerprint_clock' => $fingerprintClock,
            'actual_date' => $actual_date,
        ];
        if (Schema::hasColumn('time_cards', 'entry_source')) {
            $payload['entry_source'] = 'device';
        }
        if (Schema::hasColumn('time_cards', 'shift_id') && $shift) {
            $payload['shift_id'] = $shift->id;
        }

        $timeCard = time_card::create($payload);
        TimeCardAuditService::log($timeCard, 'created', null, null, TimeCardAuditService::snapshot($timeCard), 'device');

        if (in_array($status, ['OUT', 'Early OUT']) && $pairedInCard) {
            $referenceDate = $actual_date ?? $pairedInCard->date;
            $this->processOvertimeForOutPunch($employee, $timeCard, $pairedInCard, $shift, $referenceDate);
        }

        return response()->json([
            'message' => 'Attendance marked as ' . $status,
            'data' => $timeCard,
            'employee' => [
                'id' => $employee->id,
                'attendance_employee_no' => $employee->attendance_employee_no,
                'full_name' => $employee->full_name,
                'department' => $org->department_id,
                'sub_department' => $org->sub_department_id,
                'company' => $org->company_id,
            ],
            'shift' => [
                'id' => $shift->id,
                'shift_code' => $shift->shift_code,
                'shift_description' => $shift->shift_description,
                'start_time' => $shift->start_time,
                'end_time' => $shift->end_time,
            ],
            'roster' => $roster,
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $validated = $request->validate([
            'date' => 'required|date',
            'time' => 'required',
            'entry' => 'required|in:0,1,2',
            'status' => 'required|in:IN,Late Coming,OUT,Absent,Early OUT',
            'reason' => 'nullable|string|max:500',
            'remarks' => 'nullable|string|max:255',
        ]);

        $timeCard = time_card::findOrFail($id);
        $oldSnapshot = TimeCardAuditService::snapshot($timeCard);
        $employee = $timeCard->employee;

        if (!$employee) {
            return response()->json(['message' => 'Employee not found'], 404);
        }

        $org = $employee->organizationAssignment;
        if (!$org) {
            return response()->json(['message' => 'Organization assignment not found'], 404);
        }

        [$roster, $shift] = $this->resolveRosterAndShift($employee, $validated['date']);

        if (!$roster) {
            return response()->json(['message' => 'No shift/roster assigned for this employee on this date'], 422);
        }

        if (!$shift) {
            return response()->json(['message' => 'Shift not found'], 404);
        }

        try {
            $inputTime = Carbon::createFromFormat('H:i:s', $validated['time']);
        } catch (\Exception $e) {
            try {
                $inputTime = Carbon::createFromFormat('H:i', $validated['time']);
            } catch (\Exception $ex) {
                return response()->json(['message' => 'Invalid time format. Please use HH:mm or HH:mm:ss'], 422);
            }
        }

        $storeTime = $inputTime->format('H:i:s');

        $entryType = (int) $validated['entry'];
        $requestedStatus = $validated['status'];
        $finalStatus = strtoupper($requestedStatus);
        $working_hours = null;
        $actual_date = null;
        $pairedInCard = null;

        if (in_array($finalStatus, ['OUT', 'EARLY OUT'])) {
            $lastInCard = null;
            $morningOutRecord = Carbon::parse($storeTime)->hour < 12;

            if ($morningOutRecord) {
                $previousDayIN = time_card::where('employee_id', $employee->id)
                    ->whereIn('status', ['IN', 'Late Coming'])
                    ->where('date', '<', $validated['date'])
                    ->where('id', '!=', $timeCard->id)
                    ->whereNotExists(function ($query) {
                        $query->select(DB::raw(1))
                            ->from('time_cards as tc')
                            ->whereRaw('tc.actual_date = time_cards.date')
                            ->whereIn('tc.status', ['OUT', 'Early OUT']);
                    })
                    ->orderBy('date', 'desc')
                    ->orderBy('time', 'desc')
                    ->first();

                if ($previousDayIN) {
                    $lastInCard = $previousDayIN;
                } else {
                    $lastInCard = time_card::where('employee_id', $employee->id)
                        ->where('date', $validated['date'])
                        ->whereIn('status', ['IN', 'Late Coming'])
                        ->where('time', '<', $storeTime)
                        ->where('id', '!=', $timeCard->id)
                        ->orderBy('time', 'desc')
                        ->first();
                }
            } else {
                $lastInCard = time_card::where('employee_id', $employee->id)
                    ->where('date', $validated['date'])
                    ->whereIn('status', ['IN', 'Late Coming'])
                    ->where('id', '!=', $timeCard->id)
                    ->orderBy('time', 'desc')
                    ->first();
            }

            if ($lastInCard) {
                $pairedInCard = $lastInCard;
                $inDate = $lastInCard->date;
                $outDate = $validated['date'];

                $inDateTime = Carbon::parse($inDate . ' ' . $lastInCard->time);
                $outDateTime = Carbon::parse($outDate . ' ' . $storeTime);

                $working_hours = $this->computeWorkingHours($employee, $inDateTime, $outDateTime, $shift);
                $actual_date = ($inDate !== $outDate) ? $inDate : null;
            }
        } elseif (in_array($finalStatus, ['IN', 'LATE COMING'])) {
            $working_hours = null;
            $actual_date = null;
            $finalStatus = $this->resolveInStatus($validated['date'], $storeTime, $shift);
        } else {
            $finalStatus = 'ABSENT';
        }

        $timeCard->update([
            'time' => $storeTime,
            'date' => $validated['date'],
            'working_hours' => $working_hours,
            'entry' => $entryType,
            'status' => $finalStatus,
            'actual_date' => $actual_date,
            'approval_status' => 'Pending',
            'remarks' => isset($validated['remarks']) && trim($validated['remarks']) !== '' ? trim($validated['remarks']) : null,
        ]);
        TimeCardAuditService::log(
            $timeCard,
            'adjusted',
            $validated['reason'] ?? 'Time card adjusted from Time Card screen',
            $oldSnapshot,
            TimeCardAuditService::snapshot($timeCard->fresh()),
            $timeCard->entry_source ?? 'manual'
        );

        if (in_array($finalStatus, ['OUT', 'EARLY OUT'])) {
            if ($pairedInCard) {
                $referenceDate = $actual_date ?? $pairedInCard->date;
                $this->processOvertimeForOutPunch($employee, $timeCard, $pairedInCard, $shift, $referenceDate);
            }
        } elseif (in_array($finalStatus, ['IN', 'LATE COMING'])) {
            $pairedOutCard = time_card::where('employee_id', $employee->id)
                ->whereIn('status', ['OUT', 'Early OUT'])
                ->where(function ($q) use ($timeCard) {
                    $q->where('actual_date', $timeCard->date)
                        ->orWhere(function ($sq) use ($timeCard) {
                            $sq->whereNull('actual_date')->where('date', $timeCard->date);
                        });
                })
                ->where('time', '>', $timeCard->time)
                ->orderBy('time', 'asc')
                ->first();

            if ($pairedOutCard) {
                $referenceDate = $timeCard->date;
                $this->processOvertimeForOutPunch($employee, $pairedOutCard, $timeCard, $shift, $referenceDate);
            }
        }

        return response()->json([
            'message' => 'Time card updated successfully',
            'data' => $timeCard
        ]);
    }

    public function destroy(Request $request, $id)
    {
        $reason = trim((string) ($request->input('reason') ?: $request->query('reason')));
        if (strlen($reason) < 3) {
            return response()->json([
                'message' => 'A delete reason is required (at least 3 characters).',
            ], 422);
        }

        $timeCard = time_card::findOrFail($id);
        TimeCardAuditService::log(
            $timeCard,
            'deleted',
            $reason,
            TimeCardAuditService::snapshot($timeCard),
            null,
            $timeCard->entry_source ?? 'manual'
        );

        over_time::where('time_cards_id', $timeCard->id)
            ->whereNull('deleted_at')
            ->update(['deleted_at' => now()]);

        if (is_null($timeCard->deleted_at)) {
            $timeCard->deleted_at = now();
            $timeCard->save();
        }

        return response()->json(['message' => 'Time card soft-deleted successfully']);
    }

    private function resolveRosterAndShift(employee $employee, string $date, ?string $time = null, string $kind = 'in'): array
    {
        if ($time) {
            return $this->rosterResolver->match($employee, $date, $time, $kind);
        }

        return $this->rosterResolver->primary($employee, $date);
    }

    private function findPairedInCard(employee $employee, string $date, string $storeTime, ?shifts $shift): ?time_card
    {
        if ($shift && CompanyProcessSettings::usesShiftRoster($employee)) {
            $punch = Carbon::parse($date . ' ' . $storeTime);
            [$start, $end] = $this->rosterResolver->window($date, $shift);
            if ($punch->lt($start->copy()->subHours(2))) {
                $prev = Carbon::parse($date)->subDay()->toDateString();
                [$start, $end] = $this->rosterResolver->window($prev, $shift);
            }

            $query = time_card::where('employee_id', $employee->id)
                ->whereIn('status', ['IN', 'Late Coming'])
                ->whereNull('deleted_at');

            if (Schema::hasColumn('time_cards', 'shift_id')) {
                $query->where(function ($q) use ($shift) {
                    $q->where('shift_id', $shift->id)->orWhereNull('shift_id');
                });
            }

            $best = null;
            foreach ($query->orderBy('date')->orderBy('time')->get() as $card) {
                $at = Carbon::parse($card->date . ' ' . $card->time);
                if (!$at->between($start->copy()->subHours(4), $end->copy()->addHour())) {
                    continue;
                }
                $alreadyOut = time_card::where('employee_id', $employee->id)
                    ->whereIn('status', ['OUT', 'Early OUT'])
                ->whereNull('deleted_at')
                    ->where(function ($q) use ($card, $shift) {
                        if (Schema::hasColumn('time_cards', 'shift_id') && $shift) {
                            $q->where('shift_id', $shift->id);
                        }
                        $q->where(function ($inner) use ($card) {
                            $inner->where('actual_date', $card->date)
                                ->orWhere(function ($d) use ($card) {
                                    $d->whereNull('actual_date')->where('date', $card->date);
                                });
                    });
                })
                    ->exists();
                if (!$alreadyOut) {
                    $best = $card;
                }
            }
            if ($best) {
                return $best;
            }
        }

        $sameDay = time_card::where('employee_id', $employee->id)
            ->where('date', $date)
            ->whereIn('status', ['IN', 'Late Coming'])
            ->where('time', '<', $storeTime)
            ->orderBy('time', 'desc')
                ->first();
        if ($sameDay) {
            return $sameDay;
        }

        return time_card::where('employee_id', $employee->id)
            ->whereIn('status', ['IN', 'Late Coming'])
            ->where('date', '<', $date)
            ->orderBy('date', 'desc')
            ->orderBy('time', 'desc')
                ->first();
        }

    private function computeWorkingHours(employee $employee, Carbon $inDateTime, Carbon $outDateTime, ?shifts $shift): float
    {
        $raw = round($inDateTime->floatDiffInHours($outDateTime), 2);
        if (!$shift || !CompanyProcessSettings::usesShiftRoster($employee)) {
            return $raw;
        }

        return $this->rosterResolver->clippedHours($inDateTime, $outDateTime, $shift);
    }

    private function resolveInStatus(string $date, string $time, ?shifts $shift, int $graceMinutes = 0): string
    {
        if (!$shift) {
            return 'IN';
        }

        $shiftStartDateTime = Carbon::parse($date . ' ' . $shift->start_time);
        $punchDateTime = Carbon::parse($date . ' ' . $time);

        return $punchDateTime->gt($shiftStartDateTime->copy()->addMinutes($graceMinutes))
            ? 'Late Coming'
            : 'IN';
    }

    /**
     * ðŸ”¥ à¶´à¶±à·Šà¶ à·Š à¶‘à¶š à·€à¶¯à·’à¶± à·€à·™à¶½à·à·€à·™à¶¸ Holiday OT (Shift vs Outside) à·€à·™à¶±à·Š à¶šà¶»à¶± à¶šà·œà¶§à·ƒ
     */
    // private function processOvertimeForOutPunch(
    //     employee $employee,
    //     time_card $outCard,
    //     time_card $inCard,
    //     ?shifts $fallbackShift = null,
    //     ?string $referenceDate = null
    // ): void {
    //     if (!$inCard) return;

    //     $targetDate = $referenceDate ?? $inCard->date;
    //     [, $resolvedShift] = $this->resolveRosterAndShift($employee, $targetDate);
    //     if (!$resolvedShift) $resolvedShift = $fallbackShift;
    //     if (!$resolvedShift) return;

    //     $comp = $employee->compensation;
    //     if (!$comp || !$comp->ot_active) return;

    //     $clockIn = Carbon::parse($inCard->date . ' ' . $inCard->time);
    //     $clockOut = Carbon::parse($outCard->date . ' ' . $outCard->time);
    //     if ($clockOut->lte($clockIn)) return;

    //     $isHoliday = $this->isHoliday($employee, $targetDate);

    //     // 1. à¶…à¶´à·’ à·„à¶¯à¶´à·” Calculator à¶‘à¶šà¶§ à¶¯à¶­à·Šà¶­ à¶ºà·€à¶¸à·”
    //     $breakdown = $this->overtimeCalculator->calculate(
    //         $employee,
    //         $resolvedShift,
    //         $clockIn,
    //         $clockOut,
    //         $isHoliday
    //     );

    //     if ($isHoliday) {
    //         // ðŸ”¥ à¶…à¶½à·”à¶­à·Š à¶¯à¶­à·Šà¶­ (Shift à¶´à·à¶º à·ƒà·„ Outside à¶´à·à¶º) Calculator à¶‘à¶šà·™à¶±à·Šà¶¸ à¶œà¶¸à·”
    //         $hShiftHours = (float)($breakdown['hours']['holiday_shift_hours'] ?? 0);
    //         $hOutsideHours = (float)($breakdown['hours']['holiday_outside_hours'] ?? 0);
    //         $hShiftAmount = (float)($breakdown['amounts']['holiday_shift_amount'] ?? 0);
    //         $hOutsideAmount = (float)($breakdown['amounts']['holiday_outside_amount'] ?? 0);

    //         $totalHolidayAmount = $hShiftAmount + $hOutsideAmount;
    //         $totalHolidayHours = $hShiftHours + $hOutsideHours;

    //         if ($totalHolidayHours <= 0) return;

    //         $this->saveOrUpdateOtRecord($outCard->id, [
    //             'employee_id' => $employee->id,
    //             'date' => $targetDate,
    //             'shift_code' => $resolvedShift->id,
    //             'ot_hours' => $totalHolidayHours,
    //             'morning_ot' => 0.0,
    //             'afternoon_ot' => 0.0,
    //             'morning_ot_amount' => 0.0,
    //             'evening_ot_amount' => 0.0,
    //             'holiday_shift_hours' => $hShiftHours,
    //             'holiday_outside_hours' => $hOutsideHours,
    //             'holiday_shift_amount' => $hShiftAmount,
    //             'holiday_outside_amount' => $hOutsideAmount,
    //             'holiday_ot_hours' => $totalHolidayHours,
    //             'holiday_ot_amount' => $totalHolidayAmount,
    //             'total_ot_amount' => $totalHolidayAmount,
    //             'status' => 'pending',
    //         ]);
    //     } else {
    //         // à·ƒà·à¶¸à·à¶±à·Šâ€à¶º à¶¯à·’à¶± à·ƒà¶³à·„à· (Normal Days)
    //         $hours = array_merge([
    //             'morning_regular' => 0.0,
    //             'evening_regular' => 0.0,
    //             'total' => 0.0,
    //         ], $breakdown['hours'] ?? []);

    //         $amounts = array_merge([
    //             'morning_regular' => 0.0,
    //             'evening_regular' => 0.0,
    //             'total' => 0.0,
    //         ], $breakdown['amounts'] ?? []);

    //         $this->saveOrUpdateOtRecord($outCard->id, [
    //             'employee_id' => $employee->id,
    //             'date' => $targetDate,
    //             'shift_code' => $resolvedShift->id,
    //             'ot_hours' => $hours['total'],
    //             'morning_ot' => $hours['morning_regular'],
    //             'afternoon_ot' => $hours['evening_regular'],
    //             'morning_ot_amount' => $amounts['morning_regular'],
    //             'evening_ot_amount' => $amounts['evening_regular'],
    //             'holiday_ot_hours' => 0.0,
    //             'holiday_ot_amount' => 0.0,
    //             'total_ot_amount' => $amounts['total'],
    //             'status' => 'pending',
    //         ]);
    //     }
    // }

    /**
     * Process and record overtime data upon an OUT/Early OUT punch.
     * * ðŸ”¥ à¶´à¶±à·Šà¶ à·Š à¶‘à¶š à·€à¶¯à·’à¶± à·€à·™à¶½à·à·€à·™à¶¸ Holiday OT (Shift vs Outside) à·€à·™à¶±à·Š à¶šà¶»à¶± à¶šà·œà¶§à·ƒ
     * Note: Core calculation logic and array structures are strictly preserved as per client requirements.
     * Client Rule: OT is only credited if total OT exceeds 30 minutes (0.5 hours).
     * If it exceeds 30 minutes, the entire duration is awarded.
     */
    private function processOvertimeForOutPunch(
        employee $employee,
        time_card $outCard,
        time_card $inCard,
        ?shifts $fallbackShift = null,
        ?string $referenceDate = null
    ): void {
        // 1. Guard clauses for basic prerequisites
        if (!$inCard) {
            return;
        }

        $targetDate = $referenceDate ?? $inCard->date;

        $resolvedShift = $fallbackShift;
        if (!$resolvedShift || !CompanyProcessSettings::usesShiftRoster($employee)) {
            [, $resolved] = $this->resolveRosterAndShift($employee, $targetDate, $inCard->time ?? null, 'in');
            $resolvedShift = $resolved ?? $fallbackShift;
        }

        if (!$resolvedShift) {
            return;
        }

        // 3. Verify if overtime tracking is active for the employee
        $comp = $employee->compensation;
        if (!$comp || !$comp->ot_active) {
            return;
        }

        // 4. Calculate timing differences and ensure valid chronological sequence
        $clockIn = Carbon::parse($inCard->date . ' ' . $inCard->time);
        $clockOut = Carbon::parse($outCard->date . ' ' . $outCard->time);

        if ($clockOut->lte($clockIn)) {
            return;
        }

        // 5. Execute external calculator breakdown
        $isHoliday = $this->isHoliday($employee, $targetDate);

        // à¶…à¶´à·’ à·„à¶¯à¶´à·” Calculator à¶‘à¶šà¶§ à¶¯à¶­à·Šà¶­ à¶ºà·€à¶¸à·”
        $breakdown = $this->overtimeCalculator->calculate(
            $employee,
            $resolvedShift,
            $clockIn,
            $clockOut,
            $isHoliday
        );

        // 6. Map data and persist records based on day classification
        if ($isHoliday) {
            // ðŸ”¥ à¶…à¶½à·”à¶­à·Š à¶¯à¶­à·Šà¶­ (Shift à¶´à·à¶º à·ƒà·„ Outside à¶´à·à¶º) Calculator à¶‘à¶šà·™à¶±à·Šà¶¸ à¶œà¶¸à·”
            $hShiftHours = (float)($breakdown['hours']['holiday_shift_hours'] ?? 0);
            $hOutsideHours = (float)($breakdown['hours']['holiday_outside_hours'] ?? 0);
            $hShiftAmount = (float)($breakdown['amounts']['holiday_shift_amount'] ?? 0);
            $hOutsideAmount = (float)($breakdown['amounts']['holiday_outside_amount'] ?? 0);

            $totalHolidayHours = $hShiftHours + $hOutsideHours;
            $totalHolidayAmount = $hShiftAmount + $hOutsideAmount;

            // Client threshold: current OT keeps 30-minute (0.5h) minimum. Minute-band OT stores 0.30 / 0.45.
            $minHours = CompanyProcessSettings::otMinimumHours($employee);
            if ($totalHolidayHours <= $minHours) {
                return;
            }

            $this->saveOrUpdateOtRecord($outCard->id, [
                'employee_id'           => $employee->id,
                'date'                  => $targetDate,
                'shift_code'            => $resolvedShift->id,
                'ot_hours'              => $totalHolidayHours,
                'morning_ot'            => 0.0,
                'afternoon_ot'          => 0.0,
                'morning_ot_amount'     => 0.0,
                'evening_ot_amount'     => 0.0,
                'holiday_shift_hours'   => $hShiftHours,
                'holiday_outside_hours' => $hOutsideHours,
                'holiday_shift_amount'  => $hShiftAmount,
                'holiday_outside_amount' => $hOutsideAmount,
                'holiday_ot_hours'      => $totalHolidayHours,
                'holiday_ot_amount'     => $totalHolidayAmount,
                'total_ot_amount'       => $totalHolidayAmount,
                'status'                => 'pending',
            ]);
        } else {
            // à·ƒà·à¶¸à·à¶±à·Šâ€à¶º à¶¯à·’à¶± à·ƒà¶³à·„à· (Normal Days)
            $hours = array_merge([
                'morning_regular' => 0.0,
                'evening_regular' => 0.0,
                'total'           => 0.0,
            ], $breakdown['hours'] ?? []);

            $amounts = array_merge([
                'morning_regular' => 0.0,
                'evening_regular' => 0.0,
                'total'           => 0.0,
            ], $breakdown['amounts'] ?? []);

            $minHours = CompanyProcessSettings::otMinimumHours($employee);
            if ($hours['total'] <= $minHours) {
                return;
            }

            $this->saveOrUpdateOtRecord($outCard->id, [
                'employee_id'           => $employee->id,
                'date'                  => $targetDate,
                'shift_code'            => $resolvedShift->id,
                'ot_hours'              => $hours['total'],
                'morning_ot'            => $hours['morning_regular'],
                'afternoon_ot'          => $hours['evening_regular'],
                'morning_ot_amount'     => $amounts['morning_regular'],
                'evening_ot_amount'     => $amounts['evening_regular'],
                'holiday_ot_hours'      => 0.0,
                'holiday_ot_amount'     => 0.0,
                'total_ot_amount'       => $amounts['total'],
                'status'                => 'pending',
            ]);
        }
    }

    public function getAllIntermediateMovements(Request $request)
    {
        $date = $request->query('date', date('Y-m-d'));
        $company_id = $request->query('company_id');
        $search = $request->query('search');

        $query = \App\Models\time_card::with(['employee.organizationAssignment.company'])
            ->where('date', $date)
            ->whereNull('deleted_at')
            ->orderBy('time', 'asc');

        if ($search) {
            $query->whereHas('employee', function ($q) use ($search) {
                $q->where('full_name', 'like', "%{$search}%")
                    ->orWhere('attendance_employee_no', 'like', "%{$search}%");
            });
        }
        if ($company_id) {
            $query->whereHas('employee.organizationAssignment', function ($q) use ($company_id) {
                $q->where('company_id', $company_id);
            });
        }

        $allPunches = $query->get()->groupBy('employee_id');
        $movements = [];

        foreach ($allPunches as $empId => $punches) {
            if ($punches->count() <= 2) continue;

            $middlePunches = $punches->slice(1, $punches->count() - 2)->values();
            $emp = $punches->first()->employee;

            for ($i = 0; $i < $middlePunches->count(); $i++) {
                $current = $middlePunches[$i];
                $next = $middlePunches[$i + 1] ?? null;

                $outTime = '-';
                $inTime = '-';
                $duration = 0;

                if (
                    in_array(strtoupper($current->status), ['OUT', 'EARLY OUT']) &&
                    $next && in_array(strtoupper($next->status), ['IN', 'LATE COMING'])
                ) {

                    $outTime = $current->time;
                    $inTime = $next->time;
                    $duration = round((strtotime($inTime) - strtotime($outTime)) / 60);
                    $i++;
                } else {
                    if (in_array(strtoupper($current->status), ['IN', 'LATE COMING'])) {
                        $inTime = $current->time;
                    } else {
                        $outTime = $current->time;
                    }
                }

                $movements[] = [
                    'employee_id' => $empId,
                    'emp_no' => $emp->attendance_employee_no ?? '-',
                    'emp_name' => $emp->full_name ?? '-',
                    'company' => $emp->organizationAssignment->company->name ?? '-',
                    'out_id' => $current->id,
                    'in_id' => $next ? $next->id : null,
                    'out_time' => $outTime,
                    'in_time' => $inTime,
                    'duration_mins' => $duration > 0 ? $duration : '-',
                    'reason' => $current->reason,
                    'status' => $current->break_status ?? 'Pending',
                ];
            }
        }

        return response()->json(['data' => $movements]);
    }

    public function updateMovementStatus(Request $request)
    {
        $outId = $request->out_id;
        \App\Models\time_card::where('id', $outId)->update([
            'break_status' => $request->status,
            'reason' => $request->reason
        ]);
        return response()->json(['message' => 'Status updated successfully!']);
    }

    private function saveOrUpdateOtRecord($timeCardId, $data)
    {
        $ot = over_time::withTrashed()->where('time_cards_id', $timeCardId)->first();
        if ($ot) {
            $ot->restore();
            $ot->update($data);
        } else {
            $data['time_cards_id'] = $timeCardId;
            over_time::create($data);
        }
    }

    private function isHoliday(employee $employee, string $date): bool
    {
        $org = $employee->organizationAssignment;
        if (!$org) return false;

        return \App\Models\leaveCalendar::where('company_id', $org->company_id)
            ->where(function ($query) use ($org) {
                $query->whereNull('department_id');
                if (!empty($org->department_id)) {
                    $query->orWhere('department_id', $org->department_id);
                }
            })
            ->where(function ($query) use ($date) {
                $query->where(function ($q) use ($date) {
                    $q->whereNull('end_date')->whereDate('start_date', $date);
                })->orWhere(function ($q) use ($date) {
                    $q->whereNotNull('end_date')->whereDate('start_date', '<=', $date)->whereDate('end_date', '>=', $date);
                });
            })
            ->exists();
    }

    public function importExcel(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls',
            'company_id' => 'required|exists:companies,id',
            'from_date' => 'required|date',
            'to_date' => 'nullable|date|after_or_equal:from_date',
        ]);

        $companyId = $request->input('company_id');
        $fromDate = $request->input('from_date');
        $toDate = $request->input('to_date');

        $uploaded = $request->file('file');
        $rows = Excel::toArray(new class implements \Maatwebsite\Excel\Concerns\ToArray {
            public function array(array $array) {}
        }, $uploaded)[0];

        $header = array_map(fn ($v) => strtolower(trim((string) $v)), $rows[0] ?? []);
        $joined = implode(' ', $header);
        if (str_contains($joined, 'user id') || str_contains($joined, 'enroll id') || str_contains($joined, 'att type')) {
            try {
                $result = app(\App\Services\RelandExcelImportService::class)->import(
                    $uploaded->getRealPath(),
                    (int) $companyId,
                    $fromDate,
                    $toDate
                );
            } catch (\Throwable $e) {
                return response()->json(['message' => $e->getMessage(), 'imported' => 0, 'skipped' => 0, 'errors' => [$e->getMessage()]], 422);
            }

            return response()->json($result);
        }

        $results = ['imported' => 0, 'absent' => 0, 'errors' => []];

        DB::beginTransaction();
        try {
            foreach ($rows as $index => $row) {
                if ($index === 0) continue;

                $nic = trim($row[0] ?? '');
                $excelDate = trim($row[1] ?? '');
                $rawTime = trim($row[2] ?? '');
                $entry = trim($row[3] ?? '');
                $status = trim($row[4] ?? '');

                try {
                    $date = is_numeric($excelDate) ? gmdate('Y-m-d', ($excelDate - 25569) * 86400) : date('Y-m-d', strtotime($excelDate));
                } catch (\Exception $ex) {
                    continue;
                }

                if ($date < $fromDate || ($toDate && $date > $toDate)) continue;

                $employee = employee::where('nic', $nic)->orWhere('attendance_employee_no', $nic)->first();
                if (!$employee) continue;

                [$roster, $shift] = $this->resolveRosterAndShift($employee, $date);
                if (!$roster || !$shift) continue;

                $statusUpper = strtoupper($status);
                if (in_array($statusUpper, ['IN', 'OUT', 'EARLY OUT', 'LATE COMING'])) {
                    $payload = [
                        'employee_id' => $employee->id,
                        'time' => $rawTime,
                        'date' => $date,
                        'entry' => (int)$entry,
                        'status' => $statusUpper,
                        'actual_date' => $date,
                        'approval_status' => 'Pending',
                    ];
                    if (Schema::hasColumn('time_cards', 'entry_source')) {
                        $payload['entry_source'] = 'import';
                    }
                    $card = time_card::create($payload);
                    TimeCardAuditService::log($card, 'created', 'Imported from Excel', null, TimeCardAuditService::snapshot($card), 'import');
                    $results['imported']++;
                }
            }
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => $e->getMessage()], 500);
        }

        return response()->json($results);
    }

    public function downloadTemplate()
    {
        return Excel::download(new AttendanceTemplateExport, 'attendance_template.xlsx');
    }

    public function getTodayStats()
    {
        $today = now()->format('Y-m-d');
        $onLeaveCount = leave_master::where('status', 'Approved')
            ->where(function ($query) use ($today) {
                $query->where('leave_date', $today)->orWhere(function ($q) use ($today) {
                    $q->where('leave_from', '<=', $today)->where('leave_to', '>=', $today);
                });
            })->distinct('employee_id')->count('employee_id');

        $presentCount = time_card::where('date', $today)->whereIn('status', ['IN', 'Late Coming'])->distinct('employee_id')->count('employee_id');

        return response()->json(['on_leave' => $onLeaveCount, 'present' => $presentCount]);
    }

    public function searchByEmployee(Request $request)
    {
        $search = $request->query('q');
        if (!$search) {
            return response()->json(['message' => 'Search query is required'], 422);
        }

        $employee = employee::where('nic', $search)
            ->orWhere('attendance_employee_no', $search)
            ->first();

        if (!$employee) {
            return response()->json(['message' => 'Employee not found'], 404);
        }

        $cards = time_card::where('employee_id', $employee->id)
            ->whereNull('deleted_at')
            ->orderBy('date', 'desc')
            ->orderBy('time', 'desc')
            ->get()
            ->map(function ($card) {
                return [
                    'id'            => $card->id,
                    'empNo'         => $card->employee->attendance_employee_no ?? null,
                    'name'          => $card->employee->full_name ?? null,
                    'time'          => $card->time,
                    'date'          => $card->date,
                    'actual_date'   => $card->actual_date,
                    'entry'         => $card->entry,
                    'inOut'         => $card->entry == 1 ? 'IN' : ($card->entry == 2 ? 'OUT' : ($card->entry == 0 ? 'Early OUT' : null)),
                    'working_hours' => $card->working_hours,
                    'status'        => $card->status,
                ];
            })->toArray();

        $absentCards = \App\Models\absence::where('employee_id', $employee->id)
            ->get()
            ->map(function ($abs) use ($employee) {
                return [
                    'id'            => 'abs_' . $abs->id,
                    'empNo'         => $employee->attendance_employee_no,
                    'name'          => $employee->full_name,
                    'time'          => null,
                    'date'          => $abs->date,
                    'actual_date'   => null,
                    'entry'         => null,
                    'inOut'         => null,
                    'working_hours' => null,
                    'status'        => 'Absent',
                ];
            })->toArray();

        return response()->json(array_merge($cards, $absentCards));
    }
    public function getWeeklyAttendanceStats()
    {
        $days = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
        $present = [];
        $absent = [];

        $monday = Carbon::now()->startOfWeek(Carbon::MONDAY);

        for ($i = 0; $i < 7; $i++) {
            $date = $monday->copy()->addDays($i)->format('Y-m-d');
            $present[] = time_card::where('date', $date)
                ->whereIn('status', ['IN', 'Late Coming'])
                ->distinct('employee_id')
                ->count('employee_id');
            $absent[] = time_card::where('date', $date)
                ->where('status', 'Absent')
                ->distinct('employee_id')
                ->count('employee_id');
        }

        return response()->json(['days' => $days, 'present' => $present, 'absent' => $absent]);
    }

    /**
     * Recalculate IN/Late Coming and OUT/Early OUT using the current active roster shift.
     * Use after roster cancel/replace so old punch statuses match the new shift times.
     */
    public function recalculateAttendance(Request $request)
    {
        $validated = $request->validate([
            'from_date' => 'required|date',
            'to_date' => 'required|date|after_or_equal:from_date',
            'employee_id' => 'nullable|exists:employees,id',
            'company_id' => 'nullable|exists:companies,id',
            'department_id' => 'nullable|exists:departments,id',
        ]);

        $from = $validated['from_date'];
        $to = $validated['to_date'];

        $cardsQuery = time_card::with('employee.organizationAssignment')
            ->whereNull('deleted_at')
            ->whereBetween('date', [$from, $to])
            ->whereIn('status', ['IN', 'Late Coming', 'OUT', 'Early OUT']);

        if (!empty($validated['employee_id'])) {
            $cardsQuery->where('employee_id', $validated['employee_id']);
        }

        if (!empty($validated['company_id']) || !empty($validated['department_id'])) {
            $cardsQuery->whereHas('employee.organizationAssignment', function ($q) use ($validated) {
                if (!empty($validated['company_id'])) {
                    $q->where('company_id', $validated['company_id']);
                }
                if (!empty($validated['department_id'])) {
                    $q->where('department_id', $validated['department_id']);
                }
            });
        }

        $cards = $cardsQuery->orderBy('employee_id')->orderBy('date')->orderBy('time')->get();

        $updated = 0;
        $skipped = 0;
        $errors = [];
        $byStatus = [
            'IN' => 0,
            'Late Coming' => 0,
            'OUT' => 0,
            'Early OUT' => 0,
        ];

        DB::beginTransaction();
        try {
            foreach ($cards as $card) {
                $employee = $card->employee;
                if (!$employee) {
                    $skipped++;
                    continue;
                }

                [$roster, $shift] = $this->resolveRosterAndShift($employee, $card->date);
                if (!$shift) {
                    $skipped++;
                    $errors[] = "No active roster for employee {$employee->id} on {$card->date}";
                    continue;
                }

                $oldStatus = $card->status;
                $oldEntry = $card->entry;
                $newStatus = $oldStatus;
                $newEntry = $oldEntry;
                $storeTime = is_string($card->time) ? $card->time : Carbon::parse($card->time)->format('H:i:s');

                if (in_array($oldStatus, ['IN', 'Late Coming'], true)) {
                    $newStatus = $this->resolveInStatus($card->date, $storeTime, $shift);
                    $newEntry = 1;
                } elseif (in_array($oldStatus, ['OUT', 'Early OUT'], true)) {
                    $pairedIn = time_card::where('employee_id', $card->employee_id)
                        ->whereNull('deleted_at')
                        ->whereIn('status', ['IN', 'Late Coming'])
                        ->where(function ($q) use ($card) {
                            $q->where('date', '<', $card->date)
                                ->orWhere(function ($q2) use ($card) {
                                    $q2->where('date', $card->date)->where('time', '<=', $card->time);
                                });
                        })
                        ->orderBy('date', 'desc')
                        ->orderBy('time', 'desc')
                        ->first();

                    $inDate = $pairedIn ? $pairedIn->date : $card->date;
                    $shiftStartDT = Carbon::parse($inDate . ' ' . $shift->start_time);
                    $shiftEndDT = Carbon::parse($inDate . ' ' . $shift->end_time);
                    if ($shiftEndDT->lte($shiftStartDT)) {
                        $shiftEndDT->addDay();
                    }

                    $outDateTime = Carbon::parse($card->date . ' ' . $storeTime);
                    if ($outDateTime->lt($shiftEndDT)) {
                        $newStatus = 'Early OUT';
                        $newEntry = 0;
                    } else {
                        $newStatus = 'OUT';
                        $newEntry = 2;
                    }
                }

                if ($newStatus !== $oldStatus || (string) $newEntry !== (string) $oldEntry) {
                    $needsApproval = in_array($newStatus, ['Late Coming', 'Early OUT'], true);
                    $card->status = $newStatus;
                    $card->entry = $newEntry;
                    if (\Illuminate\Support\Facades\Schema::hasColumn('time_cards', 'approval_status')) {
                        $card->approval_status = $needsApproval ? 'Pending' : 'Active';
                    }
                    $card->save();
                    $updated++;
                } else {
                    $skipped++;
                }

                if (isset($byStatus[$newStatus])) {
                    $byStatus[$newStatus]++;
                }
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Recalculation failed',
                'error' => $e->getMessage(),
            ], 500);
        }

        return response()->json([
            'message' => 'Attendance recalculated from current active rosters',
            'from_date' => $from,
            'to_date' => $to,
            'processed' => $cards->count(),
            'updated' => $updated,
            'unchanged_or_skipped' => $skipped,
            'status_counts' => $byStatus,
            'sample_errors' => array_slice(array_unique($errors), 0, 10),
        ]);
    }
}




/*
namespace App\Http\Controllers;

use App\Exports\AttendanceTemplateExport;
use App\Models\absence;
use App\Models\employee;
use App\Models\leave_master;
use App\Models\over_time;
use App\Models\Roster;
use App\Models\shifts;
use App\Models\time_card;
use App\Services\Overtime\OvertimeCalculator;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Maatwebsite\Excel\Facades\Excel;

class TimeCardController extends Controller
{
    protected OvertimeCalculator $overtimeCalculator;

    public function __construct(OvertimeCalculator $overtimeCalculator)
    {
        $this->overtimeCalculator = $overtimeCalculator;
    }

    public function index(Request $request)
    {
        $request->validate([
            'date' => 'nullable|date',
            'from_date' => 'nullable|date',
            'to_date' => 'nullable|date|after_or_equal:from_date',
        ]);

        $query = time_card::with(['employee.organizationAssignment.department'])
            ->whereNull('deleted_at');

        if ($request->filled('date')) {
            $query->whereDate('date', $request->date);
        } else {
            if ($request->filled('from_date')) {
                $query->whereDate('date', '>=', $request->from_date);
            }
            if ($request->filled('to_date')) {
                $query->whereDate('date', '<=', $request->to_date);
            }
        }

        $cards = $query
            ->orderBy('date', 'desc')
            ->orderBy('time', 'asc')
            ->get()
            ->map(function ($card) {
                return [
                    'id' => $card->id,
                    'empNo' => $card->employee->attendance_employee_no ?? null,
                    'name' => $card->employee->full_name ?? null,
                    'fingerprintClock' => $card->fingerprint_clock ?? null,
                    'time' => $card->time,
                    'date' => $card->date,
                    'entry' => $card->entry,
                    'inOut' => $card->entry == 1
                        ? 'IN'
                        : ($card->entry == 2 ? 'OUT' : ($card->entry == 0 ? 'Early OUT' : null)),
                    'department' => $card->employee->organizationAssignment->department->name ?? null,
                    'status' => $card->status,
                    'nic' => $card->employee->nic ?? null,
                ];
            });

        return response()->json($cards);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'employee_id' => 'required|exists:employees,id',
            'time' => 'required',
            'date' => 'required|date',
            'entry' => 'required',
            'status' => 'required',
        ]);

        $employee = employee::find($validated['employee_id']);
        if (!$employee) {
            return response()->json(['message' => 'Employee not found'], 404);
        }

        $org = $employee->organizationAssignment;
        if (!$org) {
            return response()->json(['message' => 'Organization assignment not found'], 404);
        }

        [$roster, $shift] = $this->resolveRosterAndShift($employee, $validated['date']);

        if (!$roster) {
            return response()->json(['message' => 'No shift/roster assigned for this employee on this date'], 422);
        }

        if (!$shift) {
            return response()->json(['message' => 'Shift not found'], 404);
        }

        try {
            $inputTime = Carbon::createFromFormat('H:i:s', $validated['time']);
        } catch (\Exception $e) {
            try {
                $inputTime = Carbon::createFromFormat('H:i', $validated['time']);
            } catch (\Exception $ex) {
                return response()->json(['message' => 'Invalid time format. Please use HH:mm or HH:mm:ss'], 422);
            }
        }

        $storeTime = $inputTime->format('H:i:s');

        $entryType = (int) $validated['entry'];
        $status = strtoupper($validated['status']);
        $working_hours = null;
        $actual_date = null;
        $pairedInCard = null;

        if ($status === 'OUT' || $status === 'EARLY OUT') {
            $lastInCard = null;
            $morningOutRecord = Carbon::parse($storeTime)->hour < 12;

            if ($morningOutRecord) {
                $previousDayIN = time_card::where('employee_id', $employee->id)
                    ->whereIn('status', ['IN', 'Late Coming'])
                    ->where('date', '<', $validated['date'])
                    ->whereNotExists(function ($q) {
                        $q->select(DB::raw(1))
                            ->from('time_cards as tc')
                            ->whereRaw('tc.employee_id = time_cards.employee_id')
                            ->whereIn('tc.status', ['OUT', 'Early OUT'])
                            ->where(function ($x) {
                                $x->whereRaw('tc.actual_date = time_cards.date')
                                    ->orWhere(function ($y) {
                                        $y->whereNull('tc.actual_date')
                                            ->whereRaw('tc.date = time_cards.date');
                                    });
                            });
                    })
                    ->orderBy('date', 'desc')
                    ->orderBy('time', 'desc')
                    ->first();

                if ($previousDayIN) {
                    $lastInCard = $previousDayIN;
                } else {
                    $lastInCard = time_card::where('employee_id', $employee->id)
                        ->where('date', $validated['date'])
                        ->whereIn('status', ['IN', 'Late Coming'])
                        ->where('time', '<', $storeTime)
                        ->orderBy('time', 'desc')
                        ->first();

                    if (!$lastInCard) {
                        $lastInCard = time_card::where('employee_id', $employee->id)
                            ->whereIn('status', ['IN', 'Late Coming'])
                            ->where('date', '<', $validated['date'])
                            ->orderBy('date', 'desc')
                            ->orderBy('time', 'desc')
                            ->first();
                    }
                }
            } else {
                $lastInCard = time_card::where('employee_id', $employee->id)
                    ->where('date', $validated['date'])
                    ->whereIn('status', ['IN', 'Late Coming'])
                    ->orderBy('time', 'desc')
                    ->first();

                if (!$lastInCard) {
                    $lastInCard = time_card::where('employee_id', $employee->id)
                        ->whereIn('status', ['IN', 'Late Coming'])
                        ->where('date', '<', $validated['date'])
                        ->orderBy('date', 'desc')
                        ->orderBy('time', 'desc')
                        ->first();
                }
            }

            if ($lastInCard) {
                $pairedInCard = $lastInCard;

                $inDate = $lastInCard->date;
                $outDate = $validated['date'];

                $inDateTime = Carbon::parse($inDate . ' ' . $lastInCard->time);
                $outDateTime = Carbon::parse($outDate . ' ' . $storeTime);

                $working_hours = $this->computeWorkingHours($employee, $inDateTime, $outDateTime, $shift);

                $shiftStartDT = Carbon::parse($inDate . ' ' . $shift->start_time);
                $shiftEndDT = Carbon::parse($inDate . ' ' . $shift->end_time);
                if ($shiftEndDT->lte($shiftStartDT)) {
                    $shiftEndDT->addDay();
                }

                $actual_date = ($inDate !== $outDate) ? $inDate : null;

                if ($outDateTime->lt($shiftEndDT)) {
                    $entryType = 0;
                    $status = 'Early OUT';
                } else {
                    $entryType = 2;
                    $status = 'OUT';
                }
            }
        } else {
            $entryType = 1;
            $working_hours = null;
            $status = $this->resolveInStatus($validated['date'], $storeTime, $shift);
        }

        $fingerprintClock = now();

        $timeCard = time_card::create([
            'employee_id' => $employee->id,
            'time' => $storeTime,
            'date' => $validated['date'],
            'working_hours' => $working_hours,
            'entry' => $entryType,
            'status' => $status,
            'fingerprint_clock' => $fingerprintClock,
            'actual_date' => $actual_date,
        ]);

        if (in_array($status, ['OUT', 'Early OUT']) && $pairedInCard) {
            $referenceDate = $actual_date ?? $pairedInCard->date;
            $this->processOvertimeForOutPunch($employee, $timeCard, $pairedInCard, $shift, $referenceDate);
        }

        return response()->json($timeCard, 201);
    }

    public function attendance(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'empno' => ['required', 'regex:/^[A-Za-z0-9_\-]+$/'],
            'date' => 'required|date',
            'time' => 'required|date_format:H:i:s',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $rawEmpNo = trim($request->empno);

        $employee = employee::where('attendance_employee_no', $rawEmpNo)->first();

        if (!$employee && ctype_digit($rawEmpNo)) {
            $employee = employee::find((int) $rawEmpNo);
        }

        if (!$employee) {
            return response()->json(['message' => 'Employee not found'], 404);
        }

        $org = $employee->organizationAssignment;
        if (!$org) {
            return response()->json(['message' => 'Organization assignment not found'], 404);
        }

        [$roster, $shift] = $this->resolveRosterAndShift($employee, $request->date);

        if (!$roster) {
            return response()->json(['message' => 'No roster assigned for this employee on this date'], 422);
        }

        if (!$shift) {
            return response()->json(['message' => 'Shift not found'], 404);
        }

        $inputTime = Carbon::createFromFormat('H:i:s', $request->time);
        $storeTime = $inputTime->format('H:i:s');
        [$matchedRoster, $matchedShift] = $this->rosterResolver->match($employee, $request->date, $storeTime, 'in');
        if ($matchedShift) {
            $roster = $matchedRoster;
            $shift = $matchedShift;
        }

        $recentCard = time_card::where('employee_id', $employee->id)
            ->where('date', $request->date)
            ->where(function ($q) use ($inputTime) {
                $q->whereBetween('time', [
                    $inputTime->copy()->subMinutes(5)->format('H:i:s'),
                    $inputTime->copy()->addMinutes(5)->format('H:i:s')
                ]);
            })
            ->orderBy('time', 'desc')
            ->first();

        if ($recentCard) {
            return response()->json([
                'message' => 'You cannot mark attendance within 5 minutes of your previous record.'
            ], 409);
        }

        $lastCard = time_card::where('employee_id', $employee->id)
            ->whereNull('deleted_at')
            ->orderBy('date', 'desc')
            ->orderBy('time', 'desc')
            ->first();

        $entryType = 1;
        $status = 'IN';
        $working_hours = null;
        $actual_date = null;
        $pairedInCard = null;

        if ($lastCard && in_array($lastCard->status, ['IN', 'Late Coming'])) {
            $pairedInCard = $lastCard;
            $lastInDate = Carbon::parse($lastCard->date);
            $currentDate = Carbon::parse($request->date);

            if ($lastInDate->eq($currentDate)) {
                $inTime = Carbon::parse($lastCard->time);
                $outTime = $inputTime;
                $working_hours = $this->computeWorkingHours($employee, $inTime, $outTime, $shift);

                $shiftStartDT = Carbon::parse($request->date . ' ' . $shift->start_time);
                $shiftEndDT = Carbon::parse($request->date . ' ' . $shift->end_time);
                if ($shiftEndDT->lte($shiftStartDT)) {
                    $shiftEndDT->addDay();
                }

                $currentPunchDT = Carbon::parse($request->date . ' ' . $storeTime);

                if ($currentPunchDT->lt($shiftEndDT)) {
                    $entryType = 0;
                    $status = 'Early OUT';
                } else {
                    $entryType = 2;
                    $status = 'OUT';
                }
            } else {
                $inDateTime = Carbon::parse($lastCard->date . ' ' . $lastCard->time);
                $outDateTime = Carbon::parse($request->date . ' ' . $request->time);
                $working_hours = $this->computeWorkingHours($employee, $inDateTime, $outDateTime, $shift);

                $entryType = 2;
                $status = 'OUT';
                $actual_date = $lastCard->date;
            }
        } else {
            $entryType = 1;
            $working_hours = null;
            $status = $this->resolveInStatus($request->date, $storeTime, $shift);
        }

        $fingerprintClock = now();

        $timeCard = time_card::create([
            'employee_id' => $employee->id,
            'time' => $storeTime,
            'date' => $request->date,
            'working_hours' => $working_hours,
            'entry' => $entryType,
            'status' => $status,
            'fingerprint_clock' => $fingerprintClock,
            'actual_date' => $actual_date,
        ]);

        if (in_array($status, ['OUT', 'Early OUT']) && $pairedInCard) {
            $referenceDate = $actual_date ?? $pairedInCard->date;
            $this->processOvertimeForOutPunch($employee, $timeCard, $pairedInCard, $shift, $referenceDate);
        }

        return response()->json([
            'message' => 'Attendance marked as ' . $status,
            'data' => $timeCard,
            'employee' => [
                'id' => $employee->id,
                'attendance_employee_no' => $employee->attendance_employee_no,
                'full_name' => $employee->full_name,
                'department' => $org->department_id,
                'sub_department' => $org->sub_department_id,
                'company' => $org->company_id,
            ],
            'shift' => [
                'id' => $shift->id,
                'shift_code' => $shift->shift_code,
                'shift_description' => $shift->shift_description,
                'start_time' => $shift->start_time,
                'end_time' => $shift->end_time,
            ],
            'roster' => $roster,
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $validated = $request->validate([
            'date' => 'required|date',
            'time' => 'required',
            'entry' => 'required|in:0,1,2',
            'status' => 'required|in:IN,Late Coming,OUT,Absent,Early OUT',
        ]);

        $timeCard = time_card::findOrFail($id);
        $employee = $timeCard->employee;

        if (!$employee) {
            return response()->json(['message' => 'Employee not found'], 404);
        }

        $org = $employee->organizationAssignment;
        if (!$org) {
            return response()->json(['message' => 'Organization assignment not found'], 404);
        }

        [$roster, $shift] = $this->resolveRosterAndShift($employee, $validated['date']);

        if (!$roster) {
            return response()->json(['message' => 'No shift/roster assigned for this employee on this date'], 422);
        }

        if (!$shift) {
            return response()->json(['message' => 'Shift not found'], 404);
        }

        try {
            $inputTime = Carbon::createFromFormat('H:i:s', $validated['time']);
        } catch (\Exception $e) {
            try {
                $inputTime = Carbon::createFromFormat('H:i', $validated['time']);
            } catch (\Exception $ex) {
                return response()->json(['message' => 'Invalid time format. Please use HH:mm or HH:mm:ss'], 422);
            }
        }

        $storeTime = $inputTime->format('H:i:s');

        $entryType = (int) $validated['entry'];
        $requestedStatus = $validated['status'];
        $finalStatus = strtoupper($requestedStatus);
        $working_hours = null;
        $actual_date = null;
        $pairedInCard = null;

        if (in_array($finalStatus, ['OUT', 'EARLY OUT'])) {
            $lastInCard = null;
            $morningOutRecord = Carbon::parse($storeTime)->hour < 12;

            if ($morningOutRecord) {
                $previousDayIN = time_card::where('employee_id', $employee->id)
                    ->whereIn('status', ['IN', 'Late Coming'])
                    ->where('date', '<', $validated['date'])
                    ->where('id', '!=', $timeCard->id)
                    ->whereNotExists(function ($query) {
                        $query->select(DB::raw(1))
                            ->from('time_cards as tc')
                            ->whereRaw('tc.actual_date = time_cards.date')
                            ->whereIn('tc.status', ['OUT', 'Early OUT']);
                    })
                    ->orderBy('date', 'desc')
                    ->orderBy('time', 'desc')
                    ->first();

                if ($previousDayIN) {
                    $lastInCard = $previousDayIN;
                } else {
                    $lastInCard = time_card::where('employee_id', $employee->id)
                        ->where('date', $validated['date'])
                        ->whereIn('status', ['IN', 'Late Coming'])
                        ->where('time', '<', $storeTime)
                        ->where('id', '!=', $timeCard->id)
                        ->orderBy('time', 'desc')
                        ->first();
                }
            } else {
                $lastInCard = time_card::where('employee_id', $employee->id)
                    ->where('date', $validated['date'])
                    ->whereIn('status', ['IN', 'Late Coming'])
                    ->where('id', '!=', $timeCard->id)
                    ->orderBy('time', 'desc')
                    ->first();
            }

            if ($lastInCard) {
                $pairedInCard = $lastInCard;
                $inDate = $lastInCard->date;
                $outDate = $validated['date'];

                $inDateTime = Carbon::parse($inDate . ' ' . $lastInCard->time);
                $outDateTime = Carbon::parse($outDate . ' ' . $storeTime);

                $working_hours = $this->computeWorkingHours($employee, $inDateTime, $outDateTime, $shift);
                $actual_date = ($inDate !== $outDate) ? $inDate : null;
            }
        } elseif (in_array($finalStatus, ['IN', 'LATE COMING'])) {
            $working_hours = null;
            $actual_date = null;
            $finalStatus = $this->resolveInStatus($validated['date'], $storeTime, $shift);
        } else {
            $finalStatus = 'ABSENT';
        }

        $timeCard->update([
            'time' => $storeTime,
            'date' => $validated['date'],
            'working_hours' => $working_hours,
            'entry' => $entryType,
            'status' => $finalStatus,
            'actual_date' => $actual_date,
        ]);

        if (in_array($finalStatus, ['OUT', 'EARLY OUT'])) {
            if ($pairedInCard) {
                $referenceDate = $actual_date ?? $pairedInCard->date;
                $this->processOvertimeForOutPunch($employee, $timeCard, $pairedInCard, $shift, $referenceDate);
            }
        } elseif (in_array($finalStatus, ['IN', 'LATE COMING'])) {
            $pairedOutCard = time_card::where('employee_id', $employee->id)
                ->whereIn('status', ['OUT', 'Early OUT'])
                ->where(function ($q) use ($timeCard) {
                    $q->where('actual_date', $timeCard->date)
                      ->orWhere(function ($sq) use ($timeCard) {
                          $sq->whereNull('actual_date')->where('date', $timeCard->date);
                      });
                })
                ->where('time', '>', $timeCard->time)
                ->orderBy('time', 'asc')
                ->first();

            if ($pairedOutCard) {
                $referenceDate = $timeCard->date;
                $this->processOvertimeForOutPunch($employee, $pairedOutCard, $timeCard, $shift, $referenceDate);
            }
        }

        return response()->json([
            'message' => 'Time card updated successfully',
            'data' => $timeCard
        ]);
    }

    public function destroy($id)
    {
        $timeCard = time_card::findOrFail($id);

        over_time::where('time_cards_id', $timeCard->id)
            ->whereNull('deleted_at')
            ->update(['deleted_at' => now()]);

        if (is_null($timeCard->deleted_at)) {
            $timeCard->deleted_at = now();
            $timeCard->save();
        }

        return response()->json(['message' => 'Time card soft-deleted successfully']);
    }

    private function resolveRosterAndShift(employee $employee, string $date): array
    {
        $org = $employee->organizationAssignment;
        if (!$org) {
            return [null, null];
        }

        $roster = roster::where('employee_id', $employee->id)
            ->whereNull('deleted_at')
            ->where(function ($q) use ($date) {
                $q->whereNull('date_from')->orWhere('date_from', '<=', $date);
            })
            ->where(function ($q) use ($date) {
                $q->whereNull('date_to')->orWhere('date_to', '>=', $date);
            })
            ->first();

        if (!$roster && $org->sub_department_id) {
            $roster = roster::where('sub_department_id', $org->sub_department_id)
                ->whereNull('employee_id')
                ->whereNull('deleted_at')
                ->where(function ($q) use ($date) {
                    $q->where(function ($nested) use ($date) {
                        $nested->whereNull('date_from')->orWhere('date_from', '<=', $date);
                    });
                    $q->where(function ($nested) use ($date) {
                        $nested->whereNull('date_to')->orWhere('date_to', '>=', $date);
                    });
                })
                ->first();
        }

        if (!$roster && $org->department_id) {
            $roster = roster::where('department_id', $org->department_id)
                ->whereNull('employee_id')
                ->whereNull('sub_department_id')
                ->whereNull('deleted_at')
                ->where(function ($q) use ($date) {
                    $q->where(function ($nested) use ($date) {
                        $nested->whereNull('date_from')->orWhere('date_from', '<=', $date);
                    });
                    $q->where(function ($nested) use ($date) {
                        $nested->whereNull('date_to')->orWhere('date_to', '>=', $date);
                    });
                })
                ->first();
        }

        if (!$roster && $org->company_id) {
            $roster = roster::where('company_id', $org->company_id)
                ->whereNull('employee_id')
                ->whereNull('sub_department_id')
                ->whereNull('department_id')
                ->whereNull('deleted_at')
                ->where(function ($q) use ($date) {
                    $q->where(function ($nested) use ($date) {
                        $nested->whereNull('date_from')->orWhere('date_from', '<=', $date);
                    });
                    $q->where(function ($nested) use ($date) {
                        $nested->whereNull('date_to')->orWhere('date_to', '>=', $date);
                    });
                })
                ->first();
        }

        $shift = $roster ? shifts::find($roster->shift_code) : null;

        return [$roster, $shift];
    }

    private function resolveInStatus(string $date, string $time, ?shifts $shift, int $graceMinutes = 0): string
    {
        if (!$shift) {
            return 'IN';
        }

        $shiftStartDateTime = Carbon::parse($date . ' ' . $shift->start_time);
        $punchDateTime = Carbon::parse($date . ' ' . $time);

        return $punchDateTime->gt($shiftStartDateTime->copy()->addMinutes($graceMinutes))
            ? 'Late Coming'
            : 'IN';
    }


    private function processOvertimeForOutPunch(
        employee $employee,
        time_card $outCard,
        time_card $inCard,
        ?shifts $fallbackShift = null,
        ?string $referenceDate = null
    ): void {
        if (!$inCard) {
            return;
        }

        $targetDate = $referenceDate ?? $inCard->date;

        [, $resolvedShift] = $this->resolveRosterAndShift($employee, $targetDate);
        if (!$resolvedShift && $fallbackShift) {
            $resolvedShift = $fallbackShift;
        }

        if (!$resolvedShift) {
            return;
        }

        $comp = $employee->compensation;
        if (!$comp || !$comp->ot_active) {
            return;
        }

        $clockIn = Carbon::parse($inCard->date . ' ' . $inCard->time);
        $clockOut = Carbon::parse($outCard->date . ' ' . $outCard->time);

        if ($clockOut->lte($clockIn)) {
            return;
        }

        $isHoliday = $this->isHoliday($employee, $targetDate);

        $breakdown = $this->overtimeCalculator->calculate(
            $employee,
            $resolvedShift,
            $clockIn,
            $clockOut,
            $isHoliday
        );

        $morningRate = (float) ($comp->ot_morning_rate ?? 0);
        $nightRate = (float) ($comp->ot_night_rate ?? 0);
        $morningSpecialRate = (float) ($comp->ot_morning_rate_special ?? $morningRate);
        $nightSpecialRate = (float) ($comp->ot_night_rate_special ?? $nightRate);

        // ============================================================
        // ðŸ”¥ HOLIDAY OT FIX: à¶…à¶½à·”à¶­à·Š à¶šà·Šâ€à¶»à¶¸à¶º (Basic/240)
        // ============================================================
        if ($isHoliday && isset($breakdown['amounts']['holiday'])) {
            $hHours = (float) ($breakdown['hours']['holiday'] ?? 0);
            $hAmount = (float) ($breakdown['amounts']['holiday'] ?? 0);

            if ($hHours <= 0) return;

            $this->saveOrUpdateOtRecord($outCard->id, [
                'employee_id' => $employee->id,
                'date' => $targetDate,
                'shift_code' => $resolvedShift->id,
                'ot_hours' => $hHours,
                'morning_ot' => 0.0,
                'afternoon_ot' => 0.0,
                'morning_ot_special' => 0.0,
                'evening_ot_special' => 0.0,
                'morning_ot_amount' => 0.0,
                'morning_ot_special_amount' => 0.0,
                'evening_ot_amount' => 0.0,
                'evening_ot_special_amount' => 0.0,
                'holiday_shift_hours' => (float)($breakdown['hours']['holiday_shift_hours'] ?? 0),
                'holiday_outside_hours' => (float)($breakdown['hours']['holiday_outside_hours'] ?? 0),
                'holiday_shift_amount' => (float)($breakdown['amounts']['holiday_shift_amount'] ?? 0),
                'holiday_outside_amount' => (float)($breakdown['amounts']['holiday_outside_amount'] ?? 0),
                'holiday_ot_hours' => $hHours,
                'holiday_ot_amount' => $hAmount,
                'total_ot_amount' => $hAmount,
                'status' => 'pending',
            ]);

            return;
        }

        // ============================================================
        // NORMAL DAY LOGIC (Fixed Rates)
        // ============================================================
        $hours = array_merge([
            'morning_regular' => 0.0,
            'morning_special' => 0.0,
            'evening_regular' => 0.0,
            'evening_special' => 0.0,
            'total' => 0.0,
        ], $breakdown['hours'] ?? []);

        $allowMorning = (bool) ($comp->ot_morning ?? false);
        $allowEvening = (bool) ($comp->ot_evening ?? false);
        $allowMorningSpecial = (bool) ($comp->ot_morning_special ?? false);
        $allowEveningSpecial = (bool) ($comp->ot_evening_special ?? false);

        if (!$allowMorning) $hours['morning_regular'] = 0.0;
        if (!$allowMorningSpecial) $hours['morning_special'] = 0.0;
        if (!$allowEvening) $hours['evening_regular'] = 0.0;
        if (!$allowEveningSpecial) $hours['evening_special'] = 0.0;

        $hours['total'] = round(
            $hours['morning_regular'] + $hours['morning_special'] + $hours['evening_regular'] + $hours['evening_special'], 2
        );

        if ($hours['total'] <= 0) {
            over_time::where('time_cards_id', $outCard->id)->delete();
            return;
        }

        $amounts = [
            'morning_regular' => round($hours['morning_regular'] * $morningRate, 2),
            'morning_special' => round($hours['morning_special'] * $morningSpecialRate, 2),
            'evening_regular' => round($hours['evening_regular'] * $nightRate, 2),
            'evening_special' => round($hours['evening_special'] * $nightSpecialRate, 2),
        ];

        $amounts['total'] = round(array_sum($amounts), 2);

        $this->saveOrUpdateOtRecord($outCard->id, [
            'employee_id' => $employee->id,
            'date' => $targetDate,
            'shift_code' => $resolvedShift->id,
            'ot_hours' => $hours['total'],
            'morning_ot' => $hours['morning_regular'],
            'afternoon_ot' => $hours['evening_regular'],
            'morning_ot_special' => $hours['morning_special'],
            'evening_ot_special' => $hours['evening_special'],
            'morning_ot_amount' => $amounts['morning_regular'],
            'morning_ot_special_amount' => $amounts['morning_special'],
            'evening_ot_amount' => $amounts['evening_regular'],
            'evening_ot_special_amount' => $amounts['evening_special'],
            'holiday_ot_hours' => 0.0,
            'holiday_ot_amount' => 0.0,
            'total_ot_amount' => $amounts['total'],
            'status' => 'pending',
        ]);
    }

    public function getAllIntermediateMovements(Request $request)
    {
        $date = $request->query('date', date('Y-m-d'));
        $company_id = $request->query('company_id');
        $search = $request->query('search');

        $query = \App\Models\time_card::with(['employee.organizationAssignment.company'])
            ->where('date', $date)
            ->whereNull('deleted_at')
            ->orderBy('time', 'asc');

        if ($search) {
            $query->whereHas('employee', function($q) use ($search) {
                $q->where('full_name', 'like', "%{$search}%")
                  ->orWhere('attendance_employee_no', 'like', "%{$search}%");
            });
        }
        if ($company_id) {
            $query->whereHas('employee.organizationAssignment', function($q) use ($company_id) {
                $q->where('company_id', $company_id);
            });
        }

        $allPunches = $query->get()->groupBy('employee_id');
        $movements = [];

        foreach ($allPunches as $empId => $punches) {
            if ($punches->count() <= 2) continue;

            $middlePunches = $punches->slice(1, $punches->count() - 2)->values();
            $emp = $punches->first()->employee;

            for ($i = 0; $i < $middlePunches->count(); $i++) {
                $current = $middlePunches[$i];
                $next = $middlePunches[$i + 1] ?? null;

                $outTime = '-';
                $inTime = '-';
                $duration = 0;

                if (in_array(strtoupper($current->status), ['OUT', 'EARLY OUT']) &&
                    $next && in_array(strtoupper($next->status), ['IN', 'LATE COMING'])) {

                    $outTime = $current->time;
                    $inTime = $next->time;
                    $duration = round((strtotime($inTime) - strtotime($outTime)) / 60);
                    $i++;

                } else {
                    if (in_array(strtoupper($current->status), ['IN', 'LATE COMING'])) {
                        $inTime = $current->time;
                    } else {
                        $outTime = $current->time;
                    }
                }

                $movements[] = [
                    'employee_id' => $empId,
                    'emp_no' => $emp->attendance_employee_no ?? '-',
                    'emp_name' => $emp->full_name ?? '-',
                    'company' => $emp->organizationAssignment->company->name ?? '-',
                    'out_id' => $current->id,
                    'in_id' => $next ? $next->id : null,
                    'out_time' => $outTime,
                    'in_time' => $inTime,
                    'duration_mins' => $duration > 0 ? $duration : '-',
                    'reason' => $current->reason,
                    'status' => $current->break_status ?? 'Pending',
                ];
            }
        }

        return response()->json(['data' => $movements]);
    }

    public function updateMovementStatus(Request $request)
    {
        $outId = $request->out_id;
        \App\Models\time_card::where('id', $outId)->update([
            'break_status' => $request->status,
            'reason' => $request->reason
        ]);
        return response()->json(['message' => 'Status updated successfully!']);
    }

    private function saveOrUpdateOtRecord($timeCardId, $data) {
        $ot = over_time::withTrashed()->where('time_cards_id', $timeCardId)->first();
        if ($ot) {
            $ot->restore();
            $ot->update($data);
        } else {
            $data['time_cards_id'] = $timeCardId;
            over_time::create($data);
        }
    }

    private function isHoliday(employee $employee, string $date): bool
    {
        $org = $employee->organizationAssignment;
        if (!$org) {
            return false;
        }

        return \App\Models\leaveCalendar::where('company_id', $org->company_id)
            ->where(function ($query) use ($org) {
                $query->where(function ($q) {
                    $q->whereNull('department_id');
                });

                if (!empty($org->department_id)) {
                    $query->orWhere(function ($q) use ($org) {
                        $q->where('department_id', $org->department_id);
                    });
                }
            })
            ->where(function ($query) use ($date) {
                $query->where(function ($q) use ($date) {
                    $q->whereNull('end_date')
                      ->whereDate('start_date', $date);
                })->orWhere(function ($q) use ($date) {
                    $q->whereNotNull('end_date')
                      ->whereDate('start_date', '<=', $date)
                      ->whereDate('end_date', '>=', $date);
                });
            })
            ->exists();
    }

    public function importExcel(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls',
            'company_id' => 'required|exists:companies,id',
            'from_date' => 'required|date',
            'to_date' => 'nullable|date|after_or_equal:from_date',
        ]);

        $companyId = $request->input('company_id');
        $fromDate = $request->input('from_date');
        $toDate = $request->input('to_date');

        $uploaded = $request->file('file');
        $rows = Excel::toArray(new class implements \Maatwebsite\Excel\Concerns\ToArray {
            public function array(array $array)
            {
            }
        }, $uploaded)[0];

        $results = [
            'imported' => 0,
            'absent' => 0,
            'errors' => [],
        ];

        DB::beginTransaction();
        try {
            foreach ($rows as $index => $row) {
                if ($index === 0) {
                    continue;
                }

                $nic = trim($row[0] ?? '');
                $excelDate = trim($row[1] ?? '');
                $rawTime = trim($row[2] ?? '');
                $entry = trim($row[3] ?? '');
                $status = trim($row[4] ?? '');
                $reason = isset($row[5]) ? trim($row[5]) : null;

                try {
                    if (is_numeric($excelDate)) {
                        $unixDate = ($excelDate - 25569) * 86400;
                        $date = gmdate('Y-m-d', $unixDate);
                    } else {
                        $date = date('Y-m-d', strtotime($excelDate));
                    }
                } catch (\Exception $ex) {
                    $results['errors'][] = "Row $index: Invalid date format";
                    continue;
                }

                if ($date < $fromDate) {
                    continue;
                }
                if ($toDate && $date > $toDate) {
                    continue;
                }

                try {
                    if (is_numeric($rawTime)) {
                        $seconds = round($rawTime * 24 * 60 * 60);
                        $hours = floor($seconds / 3600);
                        $minutes = floor(($seconds % 3600) / 60);
                        $seconds = $seconds % 60;
                        $time = sprintf('%02d:%02d:%02d', $hours, $minutes, $seconds);
                    } else {
                        $carbonTime = Carbon::parse($rawTime);
                        $time = $carbonTime->format('H:i:s');
                    }
                } catch (\Exception $ex) {
                    $results['errors'][] = "Row $index: Invalid time format";
                    continue;
                }

                $identifier = trim($nic);
                $employeeQuery = employee::where(function ($q) use ($identifier) {
                    $q->whereRaw('LOWER(nic) = ?', [strtolower($identifier)])
                        ->orWhere('attendance_employee_no', $identifier);
                });

                if (!empty($companyId)) {
                    $employeeQuery->whereHas('organizationAssignment', function ($q) use ($companyId) {
                        $q->where('company_id', $companyId);
                    });
                }

                $employee = $employeeQuery->first();

                if (!$employee) {
                    $results['errors'][] = "Row $index: Employee not found for NIC/Attendance number in selected company";
                    continue;
                }

                [$roster, $shift] = $this->resolveRosterAndShift($employee, $date);

                if (!$roster) {
                    $results['errors'][] = "Row $index: No shift/roster assigned for this employee on $date";
                    continue;
                }

                if (!$shift) {
                    $results['errors'][] = "Row $index: Shift not found for roster";
                    continue;
                }

                $statusUpper = strtoupper($status);

                if (in_array($statusUpper, ['IN', 'OUT', 'EARLY OUT', 'LATE COMING'])) {
                    $entryType = (int) $entry;
                    $working_hours = null;
                    $actual_date = null;
                    $lastInCard = null;
                    $finalStatus = $statusUpper;

                    if (in_array($statusUpper, ['OUT', 'EARLY OUT'])) {
                        $morningOutRecord = Carbon::parse($time)->hour < 12;

                        if ($morningOutRecord) {
                            $previousDayIN = time_card::where('employee_id', $employee->id)
                                ->whereIn('status', ['IN', 'Late Coming'])
                                ->where('date', '<', $date)
                                ->whereNotExists(function ($query) {
                                    $query->select(DB::raw(1))
                                        ->from('time_cards as tc')
                                        ->whereRaw('tc.actual_date = time_cards.date')
                                        ->whereIn('tc.status', ['OUT', 'Early OUT']);
                                })
                                ->orderBy('date', 'desc')
                                ->orderBy('time', 'desc')
                                ->first();

                            if ($previousDayIN) {
                                $lastInCard = $previousDayIN;
                            } else {
                                $lastInCard = time_card::where('employee_id', $employee->id)
                                    ->where('date', $date)
                                    ->whereIn('status', ['IN', 'Late Coming'])
                                    ->where('time', '<', $time)
                                    ->orderBy('time', 'desc')
                                    ->first();

                                if (!$lastInCard) {
                                    $lastInCard = time_card::where('employee_id', $employee->id)
                                        ->whereIn('status', ['IN', 'Late Coming'])
                                        ->where('date', '<', $date)
                                        ->orderBy('date', 'desc')
                                        ->orderBy('time', 'desc')
                                        ->first();
                                }
                            }
                        } else {
                            $lastInCard = time_card::where('employee_id', $employee->id)
                                ->where('date', $date)
                                ->whereIn('status', ['IN', 'Late Coming'])
                                ->orderBy('time', 'desc')
                                ->first();

                            if (!$lastInCard) {
                                $lastInCard = time_card::where('employee_id', $employee->id)
                                    ->whereIn('status', ['IN', 'Late Coming'])
                                    ->where('date', '<', $date)
                                    ->orderBy('date', 'desc')
                                    ->orderBy('time', 'desc')
                                    ->first();
                            }
                        }

                        if ($lastInCard) {
                            $inDate = $lastInCard->date;
                            $outDate = $date;

                            $inDateTime = Carbon::parse($inDate . ' ' . $lastInCard->time);
                            $outDateTime = Carbon::parse($outDate . ' ' . $time);
                            $working_hours = $this->computeWorkingHours($employee, $inDateTime, $outDateTime, $shift);

                            $shiftStartDT = Carbon::parse($inDate . ' ' . $shift->start_time);
                            $shiftEndDT = Carbon::parse($inDate . ' ' . $shift->end_time);
                            if ($shiftEndDT->lte($shiftStartDT)) {
                                $shiftEndDT->addDay();
                            }

                            $actual_date = ($inDate !== $outDate) ? $inDate : null;

                            if ($outDateTime->lt($shiftEndDT)) {
                                $entryType = 0;
                                $finalStatus = 'Early OUT';
                            } else {
                                $entryType = 2;
                                $finalStatus = 'OUT';
                            }
                        }
                    } else {
                        $entryType = 1;
                        $working_hours = null;
                        $finalStatus = $this->resolveInStatus($date, $time, $shift);
                    }

                    $exists = time_card::where('employee_id', $employee->id)
                        ->where('date', $date)
                        ->where('time', $time)
                        ->where('entry', $entryType)
                        ->where('status', $finalStatus)
                        ->whereNull('deleted_at')
                        ->exists();

                    if (!$exists) {
                        $timeCard = time_card::create([
                            'employee_id' => $employee->id,
                            'time' => $time,
                            'date' => $date,
                            'working_hours' => $working_hours,
                            'entry' => $entryType,
                            'status' => $finalStatus,
                            'actual_date' => $actual_date,
                        ]);

                        $results['imported']++;

                        if (in_array($finalStatus, ['OUT', 'Early OUT']) && $lastInCard) {
                            $referenceDate = $actual_date ?? $lastInCard->date;
                            $this->processOvertimeForOutPunch($employee, $timeCard, $lastInCard, $shift, $referenceDate);
                        }
                    }
                } elseif ($statusUpper === 'ABSENT') {
                    $exists = absence::where('employee_id', $employee->id)
                        ->where('date', $date)
                        ->exists();

                    if (!$exists) {
                        absence::create([
                            'employee_id' => $employee->id,
                            'date' => $date,
                            'reason' => $reason ?: 'not mentioned',
                        ]);
                        $results['absent']++;
                    }
                } else {
                    $results['errors'][] = "Row $index: Unknown status";
                }
            }

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Import error',
                'errors' => array_unique($results['errors']),
                'exception' => $e->getMessage()
            ], 500);
        }

        $results['errors'] = array_unique($results['errors']);

        return response()->json($results);
    }

    public function searchByEmployee(Request $request)
    {
        $search = $request->query('q');
        if (!$search) {
            return response()->json(['message' => 'Search query is required'], 422);
        }

        $employee = employee::where('nic', $search)
            ->orWhere('attendance_employee_no', $search)
            ->first();

        if (!$employee) {
            return response()->json(['message' => 'Employee not found'], 404);
        }

        $cards = time_card::with(['employee.organizationAssignment.department'])
            ->where('employee_id', $employee->id)
            ->whereNull('deleted_at')
            ->orderBy('date', 'desc')
            ->orderBy('time', 'desc')
            ->get()
            ->map(function ($card) {
                return [
                    'id' => $card->id,
                    'empNo' => $card->employee->attendance_employee_no ?? null,
                    'name' => $card->employee->full_name ?? null,
                    'fingerprintClock' => null,
                    'time' => $card->time,
                    'date' => $card->date,
                    'entry' => $card->entry,
                    'inOut' => $card->entry == 1
                        ? 'IN'
                        : ($card->entry == 2 ? 'OUT' : ($card->entry == 0 ? 'Early OUT' : null)),
                    'department' => $card->employee->organizationAssignment->department->name ?? null,
                    'status' => $card->status,
                ];
            })->toArray();

        // Absent records absences table ekenda include karanawa
        $absentCards = absence::where('employee_id', $employee->id)
            ->get()
            ->map(function ($abs) use ($employee) {
                return [
                    'id' => 'abs_' . $abs->id,
                    'empNo' => $employee->attendance_employee_no,
                    'name' => $employee->full_name,
                    'fingerprintClock' => null,
                    'time' => null,
                    'date' => $abs->date,
                    'entry' => null,
                    'inOut' => null,
                    'department' => null,
                    'status' => 'Absent',
                ];
            })->toArray();

        $allRecords = array_merge($cards, $absentCards);

        return response()->json($allRecords);
    }

    public function fetchAbsentees(Request $request)
    {
        $date = $request->query('date');
        $search = $request->query('search', '');

        $query = absence::with(['employee'])
            ->when($date, function ($q) use ($date) {
                $q->where('date', $date);
            })
            ->when($search, function ($q) use ($search) {
                $q->whereHas('employee', function ($q2) use ($search) {
                    $q2->where('nic', 'like', "%$search%")
                        ->orWhere('attendance_employee_no', 'like', "%$search%");
                });
            });

        $absentees = $query->get()->map(function ($abs) {
            return [
                'id' => $abs->id,
                'employee_name' => $abs->employee->full_name ?? null,
                'date' => $abs->date,
                'reason' => $abs->reason,
            ];
        });

        return response()->json($absentees);
    }

    public function downloadTemplate()
    {
        return Excel::download(new AttendanceTemplateExport, 'attendance_template.xlsx');
    }

    public function getTodayStats()
    {
        $today = now()->format('Y-m-d');

        $onLeaveCount = leave_master::where('status', 'Approved')
            ->where(function ($query) use ($today) {
                $query->where('leave_date', $today)
                    ->orWhere(function ($q) use ($today) {
                        $q->where('leave_from', '<=', $today)
                            ->where('leave_to', '>=', $today);
                    });
            })
            ->distinct('employee_id')
            ->count('employee_id');

        $presentCount = time_card::where('date', $today)
            ->whereIn('status', ['IN', 'Late Coming'])
            ->distinct('employee_id')
            ->count('employee_id');

        return response()->json([
            'on_leave' => $onLeaveCount,
            'present' => $presentCount
        ]);
    }
}
*/
