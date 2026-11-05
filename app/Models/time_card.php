<?php

namespace App\Models;

use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Support\Facades\DB;

class time_card extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'employee_id',
        'time',
        'date',
        'working_hours',
        'entry',
        'status',
        'approval_status',
        'fingerprint_clock',
        'actual_date',
        'entry_source',
        'created_by',
        'shift_id',
        // --- අලුතින් එකතු කළ කොටස ---
        'reason', 
        'remarks',
        'break_status'
        // ------------------------
    ];

    public function employee()
    {
        return $this->belongsTo(employee::class, 'employee_id');
    }

    public function importExcel(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls',
        ]);

        $path = $request->file('file')->getRealPath();
        $rows = Excel::toArray([], $path)[0];

        $results = [
            'imported' => 0,
            'absent' => 0,
            'errors' => [],
        ];

        DB::beginTransaction();

        try {

            foreach ($rows as $index => $row) {

                if ($index === 0) continue;

                $nic = trim($row[0]);
                $name = trim($row[1]);
                $date = trim($row[2]);
                $time = trim($row[3]);
                $entry = trim($row[4]);
                $status = trim($row[5]);

                $identifier = trim($nic);

                $employee = employee::where(function ($q) use ($identifier) {
                    $q->whereRaw('LOWER(nic) = ?', [strtolower($identifier)])
                      ->orWhere('attendance_employee_no', $identifier);
                })->first();

                if (!$employee) {
                    $results['errors'][] = "Row $index: Employee not found for NIC $nic";
                    continue;
                }

                if (in_array($status, ['IN', 'OUT', 'Leave'])) {

                    $requestData = [
                        'employee_id' => $employee->id,
                        'time' => $time,
                        'date' => $date,
                        'entry' => $entry,
                        'status' => $status,
                        'approval_status' => 'Pending'
                    ];

                    $storeRequest = new Request($requestData);

                    // Note: Ensure that the 'store' method is accessible here or change the logic accordingly.
                    // Usually, controller methods shouldn't be called from models.
                    $response = app(\App\Http\Controllers\TimeCardController::class)->store($storeRequest); 

                    if ($response->getStatusCode() === 201) {
                        $results['imported']++;
                    } else {
                        $results['errors'][] = "Row $index: " . $response->getData()->message;
                    }

                } elseif ($status === 'Absent') {

                    // Ensure the absence model exists and is imported if you use it
                    \App\Models\absence::create([
                        'employee_id' => $employee->id,
                        'date' => $date,
                        'reason' => 'Imported from Excel',
                    ]);

                    $results['absent']++;
                }
            }

            DB::commit();

        } catch (\Exception $e) {

            DB::rollBack();

            return response()->json([
                'message' => 'Import failed',
                'error' => $e->getMessage()
            ], 500);
        }

        return response()->json($results);
    }
}





/*
namespace App\Models;

use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Support\Facades\DB;

class time_card extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'employee_id',
        'time',
        'date',
        'working_hours',
        'entry',
        'status',
        'approval_status',
        'fingerprint_clock',
        'actual_date'
    ];

    public function employee()
    {
        return $this->belongsTo(employee::class, 'employee_id');
    }

    public function importExcel(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls',
        ]);

        $path = $request->file('file')->getRealPath();
        $rows = Excel::toArray([], $path)[0];

        $results = [
            'imported' => 0,
            'absent' => 0,
            'errors' => [],
        ];

        DB::beginTransaction();

        try {

            foreach ($rows as $index => $row) {

                if ($index === 0) continue;

                $nic = trim($row[0]);
                $name = trim($row[1]);
                $date = trim($row[2]);
                $time = trim($row[3]);
                $entry = trim($row[4]);
                $status = trim($row[5]);

                $identifier = trim($nic);

                $employee = employee::where(function ($q) use ($identifier) {
                    $q->whereRaw('LOWER(nic) = ?', [strtolower($identifier)])
                      ->orWhere('attendance_employee_no', $identifier);
                })->first();

                if (!$employee) {
                    $results['errors'][] = "Row $index: Employee not found for NIC $nic";
                    continue;
                }

                if (in_array($status, ['IN', 'OUT', 'Leave'])) {

                    $requestData = [
                        'employee_id' => $employee->id,
                        'time' => $time,
                        'date' => $date,
                        'entry' => $entry,
                        'status' => $status,
                        'approval_status' => 'Pending'
                    ];

                    $storeRequest = new Request($requestData);

                    $response = $this->store($storeRequest);

                    if ($response->getStatusCode() === 201) {
                        $results['imported']++;
                    } else {
                        $results['errors'][] = "Row $index: " . $response->getData()->message;
                    }

                } elseif ($status === 'Absent') {

                    absence::create([
                        'employee_id' => $employee->id,
                        'date' => $date,
                        'reason' => 'Imported from Excel',
                    ]);

                    $results['absent']++;
                }
            }

            DB::commit();

        } catch (\Exception $e) {

            DB::rollBack();

            return response()->json([
                'message' => 'Import failed',
                'error' => $e->getMessage()
            ], 500);
        }

        return response()->json($results);
    }
}

*/