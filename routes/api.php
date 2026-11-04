<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\LoanController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\NopayController;
use App\Http\Controllers\ShiftController;
use App\Http\Controllers\RosterController;
use App\Http\Controllers\EnrollmentController;
use App\Http\Controllers\SalaryController;
use App\Http\Controllers\ApiDataController;
use App\Http\Controllers\CompanyController;
use App\Http\Controllers\CompanyLocationController;
use App\Http\Controllers\MediaUploadController;
use App\Http\Controllers\CyberneticAdminController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\OvertimeController;
use App\Http\Controllers\TimeCardController;
use App\Http\Controllers\TimeCardApprovalController;
use App\Http\Controllers\HikvisionController;
use App\Http\Controllers\AttendanceExceptionController;
use App\Http\Controllers\ShiftHoursReportController;
use App\Http\Controllers\MonthlyHoursReportController;
use App\Http\Controllers\ContractAttendanceReportController;
use App\Http\Controllers\DailyOtHoursReportController;
use App\Http\Controllers\DeductionController;
use App\Http\Controllers\AllowancesController;
use App\Http\Controllers\DepartmentsController;
use App\Http\Controllers\LeaveMasterController;
use App\Http\Controllers\EmployeeLeaveBalanceController;
use App\Http\Controllers\ResignationController;
use App\Http\Controllers\LeaveCalenderController;
use App\Http\Controllers\SalaryProcessController;
use App\Http\Controllers\SubDepartmentsController;
use App\Http\Controllers\LMSController;
use App\Http\Controllers\LMSAdmincontroller;
use App\Http\Controllers\ExamController;
use App\Http\Controllers\PmsController;
use App\Http\Controllers\AccountController;
use App\Http\Controllers\AccountGroupController;
use App\Http\Controllers\SupplierController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\CustomerCategoryController;
use App\Http\Controllers\CustomerTypeController;
use App\Http\Controllers\PerformanceEvaluationController;
use App\Http\Controllers\PerformanceAppraisalController;
use App\Http\Controllers\JournalEntryController;
use App\Http\Controllers\DiscountLevelController;
use App\Http\Controllers\ProductTypeController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\CentersController;
use App\Http\Controllers\ShiftOvertimeRateController;

use App\Http\Controllers\BonusesController;
use App\Http\Controllers\EmployeeBonusController;
use App\Http\Controllers\LeaveSettingController;

use App\Http\Controllers\AttendanceReportController;
use App\Http\Controllers\AbsentReportController;

use App\Http\Controllers\SingleEntryReportController;

use App\Http\Controllers\DinnerAllowanceController;

use App\Http\Controllers\ReportController;
use App\Http\Controllers\EmergencyContactRelationshipTypesController;
use App\Http\Controllers\EmployeeWiseAllowanceController;
use App\Http\Controllers\EmployeeWiseDeductionController;
use App\Http\Controllers\EmployeeWiseBonusController;
use App\Http\Controllers\AssignSalaryComponentController;
use App\Http\Controllers\MonthlyLateDeductionController;
use App\Http\Controllers\ExcessLateController;
use App\Http\Controllers\PersonalOutController;
use App\Http\Controllers\FishDeductionController;
use App\Http\Controllers\PayeManagementController;
use App\Http\Controllers\OffDayHolidayManagementController;
use App\Http\Controllers\ZktecoController;
use App\Http\Controllers\ZktecoAdmsController;
use App\Models\EmployeeWiseAllowance;

Route::middleware('auth.token')->get('/user', [\App\Http\Controllers\AclController::class, 'me']);

Route::post('/cybernetic-admin/login', [CyberneticAdminController::class, 'login'])
    ->middleware('throttle:8,1');
Route::get('/cybernetic-admin/me', [CyberneticAdminController::class, 'me'])
    ->middleware('cybernetic');
Route::get('/cybernetic-admin/process-catalog', [CyberneticAdminController::class, 'processCatalog'])
    ->middleware('cybernetic');
Route::post('/cybernetic-admin/logout', [CyberneticAdminController::class, 'logout'])
    ->middleware('cybernetic');
Route::middleware('auth.token')->match(['get', 'post'], '/logout', function (Request $request) {
    try {
        app(\App\Services\JwtTokenService::class)->revokeBearer($request->bearerToken());
    } catch (\Throwable $e) {
        \Illuminate\Support\Facades\Log::warning('JWT revoke failed on logout.');
    }
    try {
        $request->user()?->currentAccessToken()?->delete();
    } catch (\Throwable $e) {
        // JWT sessions have no Sanctum token to delete
    }

    return response()->noContent();
});

Route::post('/login', [AuthController::class, 'login']);
Route::post('/send-otp', [AuthController::class, 'sendOtp']);
Route::post('/login/otp', [AuthController::class, 'loginWithOtp']);
Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:3,1');

// Protected routes
Route::middleware('auth.token')->group(function () {
    Route::post('/hr/companies', [\App\Http\Controllers\HrCompanyController::class, 'store']);
    Route::post('/change-password', [AuthController::class, 'changePassword']);
    Route::get('/acl/roles', [\App\Http\Controllers\AclController::class, 'roles']);
    Route::post('/acl/roles', [\App\Http\Controllers\AclController::class, 'storeRole']);
    Route::delete('/acl/roles/{id}', [\App\Http\Controllers\AclController::class, 'destroyRole']);
    Route::post('/acl/assign-role', [\App\Http\Controllers\AclController::class, 'assignRole']);
    Route::get('/acl/catalog', [\App\Http\Controllers\AclController::class, 'catalog']);
    Route::get('/acl/users/{id}', [\App\Http\Controllers\AclController::class, 'show']);
    Route::match(['put', 'post', 'patch'], '/acl/users/{id}', [\App\Http\Controllers\AclController::class, 'update']);
});
Route::put('/leave-masters/{id}/status', [LeaveMasterController::class, 'updateStatus']);
Route::get('/leave-types/{employeeId}', [LeaveMasterController::class, 'getLeaveTypes']);
// Route::get('/test', [AuthController::class, 'test']);
Route::get('/dashboard/stats/today', [TimeCardController::class, 'getTodayStats']);
Route::get('/dashboard/stats/weekly', [TimeCardController::class, 'getWeeklyAttendanceStats']);
Route::apiResource('users', UserController::class);
Route::apiResource('shifts', ShiftController::class);
Route::get('/employees/template', [EmployeeController::class, 'downloadTemplate']);
Route::post('/employees/import-excel', [EmployeeController::class, 'importExcel']);
Route::apiResource('employees', EmployeeController::class);
Route::post('/employees/change-password', [EmployeeController::class, 'changePassword'])->middleware('auth.token');
Route::post('employes/post/update', [EmployeeController::class, 'update']);
Route::get('/emp/table', [EmployeeController::class, 'getEmployeesForTable']);
Route::get('/employees/export/data', [EmployeeController::class, 'export']);
Route::get('/employees/report/export', [EmployeeController::class, 'report']);
Route::get('/emp/search', [EmployeeController::class, 'search']);
Route::get('/emp/search/empno', [EmployeeController::class, 'searchByAttendanceNo']);
Route::get('/employees/by-employment-type/{typeName}', [EmployeeController::class, 'getByEmploymentType']);
// ✅ Loan special routes FIRST, then resource
Route::get('/loans/employee-by-number/{number}', [LoanController::class, 'getEmployeeByNumber']);
Route::get('/loans/by-employee/{employeeNo}', [LoanController::class, 'getByEmployeeNo']);
Route::get('/loans/report/export', [LoanController::class, 'report']);
Route::post('/loans/{id}/skip-request', [LoanController::class, 'requestSkip']);
Route::post('/loans/{id}/skip-decide', [LoanController::class, 'decideSkip']);
Route::apiResource('loans', LoanController::class);
// ✅ Special route FIRST
Route::get('/allowances/by-company-or-department', [AllowancesController::class, 'getAllowancesByCompanyOrDepartment']);

// ✅ (optional) remove this duplicate unless you really need it
// Route::get('/allowance/by-company-or-department', [AllowancesController::class, 'getAllowancesByCompanyOrDepartment']);

Route::apiResource('allowances', AllowancesController::class);


Route::get('/allowance/by-company-or-department', [AllowancesController::class, 'getAllowancesByCompanyOrDepartment']);
Route::get('/deduction/by-company-or-department', [DeductionController::class, 'getDeductionsByCompanyOrDepartment']);

Route::get('/deductions', [DeductionController::class, 'index']);
Route::get('/deductions/{id}', [DeductionController::class, 'show']);

Route::get('/leave-masters/{employeeId}/counts', [LeaveMasterController::class, 'getLeaveRecordCountsByEmployee']);
Route::apiResource('deductions', DeductionController::class);
Route::get('/fish-deductions/summary', [FishDeductionController::class, 'monthlySummary']);
Route::apiResource('fish-deductions', FishDeductionController::class);

Route::get('/paye-management', [PayeManagementController::class, 'index']);
Route::get('/paye-management/tax-tables', [PayeManagementController::class, 'taxTables']);
Route::post('/paye-management/tax-tables', [PayeManagementController::class, 'saveTaxTable']);

Route::get('/off-day-holiday-management', [OffDayHolidayManagementController::class, 'index']);
Route::apiResource('leave-calendars', LeaveCalenderController::class);
Route::get('/public/branding', [CompanyController::class, 'publicBranding']);
Route::get('/public/company-logo/{id}', [CompanyController::class, 'publicLogo']);
Route::get('/public/firebase-status', [MediaUploadController::class, 'status']);
Route::post('/media/firebase', [MediaUploadController::class, 'store']);
Route::post('/companies/{id}/activate-portal', [CompanyController::class, 'activatePortal'])->middleware('cybernetic');
Route::post('/companies/{id}/logo', [CompanyController::class, 'uploadLogo'])->middleware('cybernetic');
Route::post('/companies', [CompanyController::class, 'store'])->middleware('cybernetic');
Route::apiResource('companies', CompanyController::class)->except(['store']);
Route::apiResource('departments', DepartmentsController::class)->only(['store', 'update', 'destroy']);
Route::apiResource('company-locations', CompanyLocationController::class)->only(['index', 'store', 'update', 'destroy']);
Route::apiResource('subdepartments', SubDepartmentsController::class);


Route::get('/rosters/calendar', [RosterController::class, 'calendar']);
Route::post('/rosters/bulk', [RosterController::class, 'storeBulk']);
Route::post('/rosters/bulk-cancel', [RosterController::class, 'bulkCancel']);
Route::delete('/rosters/bulk-delete', [RosterController::class, 'bulkDestroy']);
Route::post('/rosters/{id}/cancel', [RosterController::class, 'cancel']);
Route::apiResource('rosters', RosterController::class);
Route::apiResource('overtime', OvertimeController::class);
Route::post('/overtime/approve/{id}', [OvertimeController::class, 'approve']);
Route::put('/overtime/{id}', [OvertimeController::class, 'update']);
//Route::apiResource('leave-masters', LeaveMasterController::class);



Route::post('/salary-process/store', [SalaryProcessController::class, 'storeSalaryData']);
Route::post('/salary-process/unlock', [SalaryProcessController::class, 'unlockForRevision']);
Route::post('/salary/process/reprocess', [SalaryProcessController::class, 'storeSalaryData']);
Route::post('/salary-process/reprocess', [SalaryProcessController::class, 'storeSalaryData']);


//new
Route::get('/leave-eligibility', [LeaveMasterController::class, 'getLeaveEligibility']);

// new leaves
//Route::get('/leave-master/supervisor-pending', [LeaveMasterController::class, 'getSupervisorPendingLeaves']);
Route::get('/leave-master/supervisor-leaves', [LeaveMasterController::class, 'getSupervisorLeaves']);
// new masterleave
Route::get('/leave-master/pending', [LeaveMasterController::class, 'getPendingLeaveRecords']);

Route::put('/leave-masters/{id}/status', [LeaveMasterController::class, 'updateStatus']);
Route::get('/leave-masters/eligibility', [LeaveMasterController::class, 'getLeaveEligibility']);
Route::get('/leave-masters/{employeeId}/counts', [LeaveMasterController::class, 'getLeaveRecordCountsByEmployee']);

Route::apiResource('leave-masters', LeaveMasterController::class);
//absent
Route::get('/reports/time-cards/absent', [AbsentReportController::class, 'index']);

// Single Entry Report Route
Route::get('/reports/time-cards/single-entry', [SingleEntryReportController::class, 'index']);

Route::apiResource('salary-process', SalaryProcessController::class);
Route::get('salary/processed', [SalaryProcessController::class, 'getProcessedSalaries']);
Route::post('/salary/process/mark-issued', [SalaryProcessController::class, 'markAsIssued']);
Route::post('/salary/process/fetchExcelData', [SalaryProcessController::class, 'fetchExcelData']);
Route::post('/salary/process/importExcelData', [SalaryProcessController::class, 'importExcelData']);
Route::get('/salary/update/status', [SalaryProcessController::class, 'updateSlaryStatus']);
// Route::apiResource('salary', SalaryController::class);

// Payroll Reports Download Routes
Route::get('/reports/bank-transfer', [ReportController::class, 'downloadBankTransfer']);
Route::get('/reports/epf-etf', [ReportController::class, 'downloadEpfEtf']);


Route::get('/reports/monthly-data', [ReportController::class, 'getMonthlyReportData']);
Route::get('/reports/schedule-data', [ReportController::class, 'getScheduleReportData']);
Route::get('/reports/leave-summary', [ReportController::class, 'leaveSummary']);

// ✅ Special route FIRST (Bonuses)
Route::get('/bonuses/by-company-or-department', [BonusesController::class, 'getBonusesByCompanyOrDepartment']);

Route::apiResource('bonuses', BonusesController::class);

// Bonuses import/export routes
Route::get('/bonuses/template/download', [BonusesController::class, 'downloadTemplate']);
Route::post('/bonuses/import', [BonusesController::class, 'import']);
Route::get('/employee-bonuses', [EmployeeBonusController::class, 'index']);
Route::post('/employee-bonuses', [EmployeeBonusController::class, 'store']);
Route::delete('/employee-bonuses/{id}', [EmployeeBonusController::class, 'destroy']);


// Route::get('salary/{id}/audit', [SalaryController::class, 'getAuditLogs']);
Route::apiResource('customers', CustomerController::class);

// Discount levels (index/show are public; create/update/delete require auth)
Route::get('/discount-levels', [DiscountLevelController::class, 'index']);
Route::get('/discount-levels/{id}', [DiscountLevelController::class, 'show']);
Route::middleware('auth.token')->group(function () {
    Route::post('/discount-levels', [DiscountLevelController::class, 'store']);
    Route::put('/discount-levels/{id}', [DiscountLevelController::class, 'update']);
    Route::delete('/discount-levels/{id}', [DiscountLevelController::class, 'destroy']);
});

Route::get('customer-categories', [CustomerCategoryController::class, 'index']);
Route::post('customer-categories', [CustomerCategoryController::class, 'store']);
Route::get('customer-types', [CustomerTypeController::class, 'index']);
Route::post('customer-types', [CustomerTypeController::class, 'store']);


Route::get('/Leave-Master/{employeeId}/counts', [LeaveMasterController::class, 'getLeaveRecordCountsByEmployee']);

Route::get('/Leave-Master/status/pending', [LeaveMasterController::class, 'getPendingLeaveRecords']);
Route::get('/Leave-Master/status/approved', [LeaveMasterController::class, 'getApprovedLeaveRecords']);
Route::get('/Leave-Master/status/hr-approved', [LeaveMasterController::class, 'getHRApprovedLeaveRecords']);


Route::get('/time-cards', [TimeCardController::class, 'index']);
Route::get('/reports/time-cards/attendance', [TimeCardController::class, 'getAttendanceReport']);


Route::prefix('apiData')->group(function () {
    Route::get('/companies', [ApiDataController::class, 'companies']);
    Route::get('/departments', [ApiDataController::class, 'departments']);
    Route::get('/subDepartments', [ApiDataController::class, 'subDepartments']);
    Route::get('/designations', [ApiDataController::class, 'designations']);
    Route::post('/addNewDesignation', [ApiDataController::class, 'addNewDesignation']);
    Route::get('/companies/{id}/employees', [ApiDataController::class, 'employeesByCompany']);
    Route::get('/companies/{id}', [ApiDataController::class, 'companiesById']);
    Route::get('/departments/{id}', [ApiDataController::class, 'departmentsById']);
    Route::get('/subDepartments/{id}', [ApiDataController::class, 'subDepartmentsById']);
    Route::get('/subDepartments/{id}/employees', [ApiDataController::class, 'employeesBySubDepartment']);
});

// Resignation routes
Route::get('/resignations', [ResignationController::class, 'index']);
Route::post('/resignations', [ResignationController::class, 'store']);
Route::get('/resignations/{id}', [ResignationController::class, 'show']);
Route::put('/resignations/{id}/status', [ResignationController::class, 'updateStatus']);

// Document routes
Route::post('/resignations/{id}/documents', [ResignationController::class, 'uploadDocuments']);
Route::delete('/resignations/{resignationId}/documents/{documentId}', [ResignationController::class, 'destroyDocument']);

//time card
Route::put('/time-cards/{id}', [TimeCardController::class, 'update']);
Route::delete('/time-cards/{id}', [TimeCardController::class, 'destroy']);
Route::get('/employees/by-nic/{nic}', [EmployeeController::class, 'getByNic']);
Route::post('/time-cards', [TimeCardController::class, 'store']);
Route::post('/attendance', [TimeCardController::class, 'attendance']);
// Route::post('/attendance/mark-absentees', [TimeCardController::class, 'markAbsentees']);
Route::get('/time-cards/search-employee', [TimeCardController::class, 'searchByEmployee']);
Route::post('/attendance/import-excel', [TimeCardController::class, 'importExcel']);
Route::post('/attendance/import-reland-excel', [\App\Http\Controllers\RelandAttendanceController::class, 'importExcel']);
Route::get('/companies', [CompanyController::class, 'index']);
Route::get('/attendance/absentees', [TimeCardController::class, 'fetchAbsentees']);
Route::get('/attendance-template', [TimeCardController::class, 'downloadTemplate']);

// Hikvision fingerprint terminal integration (DS-K1T320 / Solar-parity sync + bridge)
Route::post('/hikvision/webhook/{token}', [HikvisionController::class, 'webhook']);
Route::post('/hikvision/punches/{token}', [HikvisionController::class, 'punches']);
Route::get('/hikvision/punches/{token}', [HikvisionController::class, 'punchesPing']);
Route::get('/hikvision/punches/{token}/sync-requests', [HikvisionController::class, 'agentSyncRequests']);
Route::post('/hikvision/punches/{token}/sync-requests/{requestId}', [HikvisionController::class, 'agentSyncRequestUpdate'])->whereNumber('requestId');
Route::get('/hikvision/cloud-base', [HikvisionController::class, 'cloudBase']);
Route::get('/hikvision/setup-guide', [HikvisionController::class, 'setupGuide']);
Route::get('/hikvision/devices', [HikvisionController::class, 'index']);
Route::post('/hikvision/devices', [HikvisionController::class, 'store']);
Route::put('/hikvision/devices/{id}', [HikvisionController::class, 'update']);
Route::delete('/hikvision/devices/{id}', [HikvisionController::class, 'destroy']);
Route::post('/hikvision/devices/{id}/test', [HikvisionController::class, 'testConnection']);
Route::post('/hikvision/devices/{id}/sync', [HikvisionController::class, 'syncNow']);
Route::get('/hikvision/devices/{id}/sync-requests', [HikvisionController::class, 'syncRequests']);
Route::post('/hikvision/devices/{id}/configure-webhook', [HikvisionController::class, 'configureWebhook']);
Route::get('/hikvision/devices/{id}/agent-config', [HikvisionController::class, 'agentConfig']);
Route::get('/hikvision/devices/{id}/logs', [HikvisionController::class, 'eventLogs']);
Route::post('/attendance/import-hikvision-excel', [HikvisionController::class, 'importExcel']);

// ZKTeco K40 Pro (ADMS push → same Time Card writer as Hikvision)
Route::post('/zkteco/punches/{token}', [ZktecoController::class, 'punches']);
Route::get('/zkteco/punches/{token}', [ZktecoController::class, 'punchesPing']);
Route::get('/zkteco/setup-guide', [ZktecoController::class, 'setupGuide']);
Route::get('/zkteco/devices', [ZktecoController::class, 'index']);
Route::post('/zkteco/devices', [ZktecoController::class, 'store']);
Route::put('/zkteco/devices/{id}', [ZktecoController::class, 'update']);
Route::delete('/zkteco/devices/{id}', [ZktecoController::class, 'destroy']);
Route::get('/zkteco/devices/{id}/logs', [ZktecoController::class, 'eventLogs']);
// Mirror ADMS under /api for reverse proxies that only forward /api
Route::match(['GET', 'POST'], '/zkteco/iclock/cdata', [ZktecoAdmsController::class, 'cdata']);
Route::match(['GET', 'POST'], '/zkteco/iclock/getrequest', [ZktecoAdmsController::class, 'getRequest']);



//  Mid-Shift Breaks Routes ---
// =====================================================================
Route::get('/attendance/all-movements', [TimeCardController::class, 'getAllIntermediateMovements']);
Route::post('/attendance/movements/status', [TimeCardController::class, 'updateMovementStatus']);
// =====================================================================



// Dinner Allowance Routes
Route::get('/dinner-allowance/daily', [DinnerAllowanceController::class, 'getEligibleEmployees']);
Route::post('/dinner-allowance/process', [DinnerAllowanceController::class, 'processAllowance']);
Route::get('/dinner-allowance/monthly', [DinnerAllowanceController::class, 'getMonthlyReport']);


//get employees by month and company
Route::get('/salaryCal/employees', [SalaryProcessController::class, 'getEmployeesByMonthAndCompany']);
Route::post('/salary/process/allowances', [SalaryProcessController::class, 'updateEmployeesAllowances']);
Route::post('/salary/process/save', [SalaryProcessController::class, 'storeSalaryData']);

// Assign predefined allowances/deductions (all employees or one employee)
Route::get('/assign/allowances', [AssignSalaryComponentController::class, 'listAllowances']);
Route::post('/assign/allowances', [AssignSalaryComponentController::class, 'assignAllowance']);
Route::delete('/assign/allowances/{id}', [AssignSalaryComponentController::class, 'destroyAllowance']);
Route::put('/assign/allowances/{id}', [AssignSalaryComponentController::class, 'updateAllowanceAmount']);
Route::get('/assign/deductions', [AssignSalaryComponentController::class, 'listDeductions']);
Route::post('/assign/deductions', [AssignSalaryComponentController::class, 'assignDeduction']);
Route::delete('/assign/deductions/{id}', [AssignSalaryComponentController::class, 'destroyDeduction']);
Route::put('/assign/deductions/{id}', [AssignSalaryComponentController::class, 'updateDeductionAmount']);

Route::post('/attendance/mark-absentees', [TimeCardController::class, 'markAbsentees']);
Route::post('/attendance/recalculate', [TimeCardController::class, 'recalculateAttendance']);
Route::get('/absentees', [ApiDataController::class, 'Absentees']);
// No Pay routes
/*
Route::post('/no-pay-records/generate-monthly', [NoPayRecordController::class, 'generateMonthly']);
Route::get('no-pay-records', [NopayController::class, 'index']);
Route::post('no-pay-records', [NopayController::class, 'store']);
Route::put('no-pay-records/{id}', [NopayController::class, 'update']);
Route::post('no-pay-records/bulk-update', [NopayController::class, 'bulkUpdateStatus']);
Route::delete('no-pay-records/{id}', [NopayController::class, 'destroy']);
Route::delete('no-pay-records/bulk-delete', [NopayController::class, 'bulkDestroy']);
Route::post('no-pay-records/generate', [NopayController::class, 'generateDailyNoPayRecords']);
Route::get('no-pay-records/stats', [NopayController::class, 'getNoPayStats']);
*/

// No Pay routes
Route::post('no-pay-records/generate-monthly', [NopayController::class, 'generateMonthlyNoPayRecords']); // නිවැරදි කළ පේළිය
Route::post('no-pay-records/generate', [NopayController::class, 'generateDailyNoPayRecords']);

Route::get('no-pay-records', [NopayController::class, 'index']);
Route::post('no-pay-records', [NopayController::class, 'store']);
Route::put('no-pay-records/{id}', [NopayController::class, 'update']);
Route::post('no-pay-records/bulk-update', [NopayController::class, 'bulkUpdateStatus']);
Route::delete('no-pay-records/{id}', [NopayController::class, 'destroy']);
Route::delete('no-pay-records/bulk-delete', [NopayController::class, 'bulkDestroy']);
Route::get('no-pay-records/stats', [NopayController::class, 'getNoPayStats']);

// Monthly late deduction (total late → short leave → annual/casual → nopay)
Route::get('monthly-late-deductions/rules', [MonthlyLateDeductionController::class, 'rules']);
Route::get('monthly-late-deductions/preview', [MonthlyLateDeductionController::class, 'preview']);
Route::post('monthly-late-deductions/apply', [MonthlyLateDeductionController::class, 'apply']);
Route::get('monthly-late-deductions/employees/{employeeId}', [MonthlyLateDeductionController::class, 'employeeDetail']);
Route::get('excess-late-reviews/preview', [ExcessLateController::class, 'preview']);
Route::post('excess-late-reviews/decide', [ExcessLateController::class, 'decide']);

// Personal out during work: ≤2h short leave, >2h half day, no balance → NoPay from monthly bonus
Route::get('personal-out', [PersonalOutController::class, 'index']);
Route::get('personal-out/employees', [PersonalOutController::class, 'employees']);
Route::get('personal-out/preview', [PersonalOutController::class, 'preview']);
Route::post('personal-out', [PersonalOutController::class, 'store']);
Route::delete('personal-out/{id}', [PersonalOutController::class, 'destroy']);

// Allowances import/export routes
Route::get('/allowances/template/download', [AllowancesController::class, 'downloadTemplate']);
Route::post('/allowances/import', [AllowancesController::class, 'import']);
Route::get('/roster/search', [RosterController::class, 'search']);

// Deductions import/export routes
Route::get('/deductions/template/download', [DeductionController::class, 'downloadTemplate']);
Route::post('/deductions/import', [DeductionController::class, 'import']);

//leave settings
// Leave Settings Routes
Route::get('/leave-settings', [LeaveSettingController::class, 'index']);
Route::post('/leave-settings', [LeaveSettingController::class, 'store']);
Route::get('/leave-settings/active-summary', [LeaveSettingController::class, 'getActiveSummary']);
Route::get('/leave-settings/type/{type}', [LeaveSettingController::class, 'getByType']);
Route::get('/leave-settings/{leaveSetting}', [LeaveSettingController::class, 'show']);
Route::put('/leave-settings/{leaveSetting}', [LeaveSettingController::class, 'update']);
Route::delete('/leave-settings/{leaveSetting}', [LeaveSettingController::class, 'destroy']);


// Attendance Report Routes
Route::get('/reports/time-cards/attendance', [AttendanceReportController::class, 'index']);

Route::get('/reports/time-cards/attendance/monthly', [AttendanceReportController::class, 'monthly']);
Route::get('/reports/time-cards/attendance/range', [AttendanceReportController::class, 'dateRange']);

Route::put('/reports/time-cards/attendance/{employeeId}/{date}/approval-status', [AttendanceReportController::class, 'updateApprovalStatus']);

// Late Coming / Early OUT approval
Route::get('/attendance/exceptions', [AttendanceExceptionController::class, 'index']);
Route::put('/attendance/exceptions/{id}', [AttendanceExceptionController::class, 'updateStatus']);
Route::post('/attendance/exceptions/bulk-status', [AttendanceExceptionController::class, 'bulkUpdateStatus']);

Route::get('/time-cards/pending-approvals', [TimeCardApprovalController::class, 'pending']);
Route::put('/time-cards/{id}/approval', [TimeCardApprovalController::class, 'updateStatus']);
Route::post('/time-cards/bulk-approval', [TimeCardApprovalController::class, 'bulkUpdateStatus']);
Route::get('/reports/time-cards/audit', [TimeCardApprovalController::class, 'audit']);
Route::get('/reports/time-cards/deleted', [TimeCardApprovalController::class, 'deleted']);

// Within-shift hours & Extra hours reports
Route::get('/reports/shift-hours', [ShiftHoursReportController::class, 'index']);
Route::get('/reports/monthly-hours', [MonthlyHoursReportController::class, 'index']);
Route::get('/reports/contract-attendance', [ContractAttendanceReportController::class, 'index']);
Route::get('/reports/daily-ot-hours', [DailyOtHoursReportController::class, 'index']);
// ✅ special route FIRST
//Route::get('/loans/employee-by-number/{number}', [LoanController::class, 'getEmployeeByNumber']);

// (loan routes already defined above)

Route::post('/test', [ResignationController::class, 'testFunction']);

Route::apiResource('salary', SalaryController::class);
Route::get('salary/{id}/audit', [SalaryController::class, 'getAuditLogs']);
Route::get('/salary/process/csv', [SalaryController::class, 'salaryCSV']);

Route::get('/salary/process/csv', [SalaryProcessController::class, 'downloadSalaryCSV']);

// LMS Routes

Route::apiResource('courses', LMSController::class);
Route::apiResource('accounts', AccountController::class);
Route::apiResource('journal-entries', JournalEntryController::class);
Route::get('journal-entries-next-number', [JournalEntryController::class, 'getNextEntryNumber']);
//Route::apiResource('account-groups', AccountGroupController::class);

Route::delete('/attachments/{id}', [LMSController::class, 'removeAttachment']);
Route::middleware('auth.token')->group(function () {
    // Exam routes
    Route::apiResource('exams', ExamController::class);
    Route::post('exams/{id}/submit', [ExamController::class, 'submitExam']);
    Route::get('exam-results', [ExamController::class, 'getResults']);

    // Enrollment routes
    Route::get('enrollments', [EnrollmentController::class, 'index']);
    Route::post('courses/{courseId}/enroll', [EnrollmentController::class, 'enroll']);
    Route::delete('courses/{courseId}/enroll', [EnrollmentController::class, 'unenroll']);
    Route::get('courses/{courseId}/enrollment', [EnrollmentController::class, 'checkEnrollment']);
    Route::get('courses/{courseId}/progress', [EnrollmentController::class, 'getProgress']);
    Route::post('courses/{courseId}/modules/{moduleId}/progress', [EnrollmentController::class, 'updateModuleProgress']);
    Route::get('user/progress', [EnrollmentController::class, 'getUserProgress']);
});

// LMS Admin Dashboard Routes
Route::middleware('auth.token')->prefix('admin/lms')->group(function () {
    Route::get('users/course-progress', [LMSAdmincontroller::class, 'listAllUsersCourseProgress']);
    Route::get('users/exam-progress', [LMSAdmincontroller::class, 'listAllUsersExamProgress']);
    Route::get('users/{userId}/course-progress', [LMSAdmincontroller::class, 'getUserCourseProgress']);
    Route::get('users/{userId}/exam-progress', [LMSAdmincontroller::class, 'getUserExamProgress']);
    Route::get('stats', [LMSAdmincontroller::class, 'getLmsStats']);
});

// PMS Related Data Endpoints
Route::middleware(['auth.token'])->group(function () {
    // PMS Related Data Endpoints - MOVED INSIDE AUTH
    Route::get('/kpi-tasks', [PmsController::class, 'getKpiTasks']);
    Route::get('/creator-roles', [PmsController::class, 'getCreatorRoles']);
    Route::get('/pms/companies', [PmsController::class, 'getCompanies']);
    Route::get('/pms/departments/{companyId}', [PmsController::class, 'getDepartmentsByCompany']);
    Route::get('/pms/employees-by-company', [PmsController::class, 'getEmployeesByCompany']);
    Route::get('/pms/search-employees', [PmsController::class, 'searchEmployeesByAttendanceNo']);
    Route::get('/pms/kpi-task-assignments', [PmsController::class, 'getKpiTaskAssignments']);
    Route::post('/pms/kpi-task-assignments', [PmsController::class, 'storeKpiTaskAssignment']);
    Route::put('/pms/kpi-task-assignments/{id}', [PmsController::class, 'updateKpiTaskAssignment']);
    Route::delete('/pms/kpi-task-assignments/{id}', [PmsController::class, 'destroy']);

    // PMS Performance Reviews
    Route::get('/pms/performance-reviews', [PmsController::class, 'getPerformanceReviews']);
    Route::get('/pms/performance-reviews/{assignmentId}/details', [PmsController::class, 'getPerformanceReviewDetails']);
    Route::get('/pms/performance-reviews/{assignmentId}/documents', [PmsController::class, 'getAssignmentDocuments']);
    Route::put('/pms/performance-reviews/{assignmentId}', [PmsController::class, 'updatePerformanceReview']);


    // Other PMS routes that require authentication
    Route::get('/pms/kpi-task-assignments/employee/{employeeId}', [PmsController::class, 'getEmployeeKpiTaskAssignments']);
    Route::post('/pms/task-progress-submissions', [PmsController::class, 'storeTaskProgressSubmission']);
    Route::get('/pms/task-progress-submissions/assignment/{assignmentId}', [PmsController::class, 'getTaskProgressSubmissions']);
    Route::get('/pms/task-progress-submissions/employee/{employeeId}', [PmsController::class, 'getEmployeeTaskProgressSubmissions']);

    // PMS Dashboard endpoints
    Route::get('/pms/dashboard/stats', [PmsController::class, 'getDashboardStats']);
    Route::get('/pms/dashboard/upcoming-deadlines', [PmsController::class, 'getUpcomingDeadlines']);
    Route::get('/pms/dashboard/KPIs', [PmsController::class, 'getKpiPerformance']);

    // KPI Tasks CRUD routes
    Route::post('/kpi-tasks', [PmsController::class, 'storeKpiTask']);
    Route::put('/kpi-tasks/{id}', [PmsController::class, 'updateKpiTask']);
    Route::delete('/kpi-tasks/{id}', [PmsController::class, 'destroyKpiTask']);

    // Creator Roles CRUD
    Route::post('/creator-roles', [PmsController::class, 'storeCreatorRole']);
    Route::put('/creator-roles/{id}', [PmsController::class, 'updateCreatorRole']);
    Route::delete('/creator-roles/{id}', [PmsController::class, 'destroyCreatorRole']);

    // Approval list (used by frontend TaskApproval)
    Route::get('/pms/kpi-task-assignments-for-approval', [PmsController::class, 'getKpiTaskAssignmentsForApproval']);

    // Employee-specific assignments (used by employee view)
    Route::get('/pms/employee-kpi-task-assignments/{employeeId}', [PmsController::class, 'getEmployeeKpiTaskAssignments']);

    // Approve / reject endpoints (POST)
    Route::post('/pms/kpi-tasks/{id}/approve', [PmsController::class, 'approveKpiTask']);
    Route::post('/pms/kpi-tasks/{id}/reject', [PmsController::class, 'rejectKpiTask']);

    // Practical Feedback routes
    Route::post('/pms/performance-reviews/{assignmentId}/practical-feedback', [PmsController::class, 'submitPracticalFeedback']);
    Route::get('/pms/practical-feedback/history', [PmsController::class, 'getPracticalFeedbackHistory']);
    Route::get('/pms/practical-feedback/stats', [PmsController::class, 'getFeedbackStats']);

    // Notification routes
    Route::get('/notifications', [PmsController::class, 'getUserNotifications']);
    Route::post('/notifications/{notificationId}/read', [PmsController::class, 'markNotificationRead']);
    Route::post('/notifications/mark-all-read', [PmsController::class, 'markAllNotificationsRead']);
    Route::get('/notifications/unread-count', [PmsController::class, 'getUnreadCount']);
    Route::get('/pms/kpi-weights', [PmsController::class, 'getKpiWeights']);
    Route::post('/pms/kpi-weights', [PmsController::class, 'createKpiWeight']);
    Route::put('/pms/kpi-weights/{id}', [PmsController::class, 'updateKpiWeight']);
    Route::delete('/pms/kpi-weights/{id}', [PmsController::class, 'deleteKpiWeight']);
});

// Supplier routes
Route::apiResource('suppliers', SupplierController::class);

//record
Route::post('/reports/save-coinage', [App\Http\Controllers\ReportController::class, 'saveCoinageData']);
Route::post('/reports/save-ot-coinage', [App\Http\Controllers\ReportController::class, 'saveOtCoinageData']);

// Employee Performance Evaluation endpoints (these can remain public if needed)
Route::post('/pms/employee-performance/calculate', [PmsController::class, 'calculateEmployeePerformance']);
Route::post('/pms/employee-performance/save', [PmsController::class, 'saveEmployeePerformance']);
Route::get('/pms/employee-performance', [PmsController::class, 'getEmployeePerformanceEvaluations']);

// Add these routes in the authenticated section

Route::middleware('auth.token')->group(function () {
    // ... existing routes ...

    // Performance Appraisal routes
    Route::post('/pms/performance-appraisal/calculate', [PmsController::class, 'calculatePerformanceAppraisal']);
    Route::post('/pms/performance-appraisal/save', [PmsController::class, 'savePerformanceAppraisal']);
    Route::get('/pms/performance-appraisals', [PmsController::class, 'getPerformanceAppraisals']);
});

// Performance Evaluation routes - using the new controller
Route::middleware('auth.token')->group(function () {
    // CRUD operations
    Route::get('/performance-evaluations', [App\Http\Controllers\PerformanceEvaluationController::class, 'index']);
    Route::post('/performance-evaluations', [App\Http\Controllers\PerformanceEvaluationController::class, 'store']);
    Route::post('/performance-evaluations/bulk', [App\Http\Controllers\PerformanceEvaluationController::class, 'storeBulk']); // Add this line
    Route::get('/performance-evaluations/{id}', [App\Http\Controllers\PerformanceEvaluationController::class, 'show']);
    Route::put('/performance-evaluations/{id}', [App\Http\Controllers\PerformanceEvaluationController::class, 'update']);
    Route::delete('/performance-evaluations/{id}', [App\Http\Controllers\PerformanceEvaluationController::class, 'destroy']);

    // Additional functionality
    Route::get('/performance-evaluations/employee/{employeeId}', [App\Http\Controllers\PerformanceEvaluationController::class, 'getByEmployee']);

    // Stats, trash and restore/force-delete endpoints
    Route::get('/performance-evaluations/stats/overview', [App\Http\Controllers\PerformanceEvaluationController::class, 'getStats']);
    Route::get('/performance-evaluations/trashed/list', [App\Http\Controllers\PerformanceEvaluationController::class, 'getTrashed']);
    Route::post('/performance-evaluations/{id}/restore', [App\Http\Controllers\PerformanceEvaluationController::class, 'restore']);
    Route::delete('/performance-evaluations/{id}/force', [App\Http\Controllers\PerformanceEvaluationController::class, 'forceDestroy']);
});



Route::apiResource('shift-overtime-rates', ShiftOvertimeRateController::class);

// Dropdown route
Route::get(
    'shift-overtime-rates/shifts/dropdown',
    [ShiftOvertimeRateController::class, 'getShifts']
);

// By shift
Route::get(
    'shift-overtime-rates/by-shift/{shiftId}',
    [ShiftOvertimeRateController::class, 'getByShiftId']
);

// Calculate
Route::post(
    'shift-overtime-rates/{shiftId}/calculate',
    [ShiftOvertimeRateController::class, 'calculateRates']
);





// Performance Appraisal routes - using the new controller
Route::middleware('auth.token')->group(function () {
    // CRUD operations
    Route::get('/performance-appraisals', [App\Http\Controllers\PerformanceAppraisalController::class, 'index']);
    Route::post('/performance-appraisals', [App\Http\Controllers\PerformanceAppraisalController::class, 'store']);
    Route::post('/performance-appraisals/bulk', [App\Http\Controllers\PerformanceAppraisalController::class, 'storeBulk']);
    Route::get('/performance-appraisals/{id}', [App\Http\Controllers\PerformanceAppraisalController::class, 'show']);
    Route::put('/performance-appraisals/{id}', [App\Http\Controllers\PerformanceAppraisalController::class, 'update']);
    Route::delete('/performance-appraisals/{id}', [App\Http\Controllers\PerformanceAppraisalController::class, 'destroy']);

    // Additional functionality
    Route::get('/performance-appraisals/employee/{employeeId}', [App\Http\Controllers\PerformanceAppraisalController::class, 'getByEmployee']);
    Route::get('/performance-appraisals/trashed/list', [App\Http\Controllers\PerformanceAppraisalController::class, 'getTrashed']);
    Route::post('/performance-appraisals/{id}/restore', [App\Http\Controllers\PerformanceAppraisalController::class, 'restore']);
    Route::delete('/performance-appraisals/{id}/force', [App\Http\Controllers\PerformanceAppraisalController::class, 'forceDestroy']);
    Route::get('/performance-appraisals/stats/overview', [App\Http\Controllers\PerformanceAppraisalController::class, 'getStats']);

    //Center routes
    Route::apiResource('centers', CentersController::class);
    Route::apiResource('products', ProductController::class);
});

//  Customer routes
Route::get('/customer/email/{email}', [CustomerController::class, 'getByEmail']);
Route::get('/customer/type/{typeId}', [CustomerController::class, 'getByType']);
Route::get('/customer/name/{name}', [CustomerController::class, 'getByName']);

// Product Type routes
Route::apiResource('product-types', ProductTypeController::class);
Route::post('product-types', [ProductTypeController::class, 'store']);
Route::post('product-types/{id}/restore', [ProductTypeController::class, 'restore']);
Route::post('product-types/{id}/status', [ProductTypeController::class, 'setStatus']);
Route::get('product-types/trashed/list', [ProductTypeController::class, 'getTrashed']);
Route::get('product-types/stats/overview', [ProductTypeController::class, 'getStats']);
Route::delete('product-types/{id}/force', [ProductTypeController::class, 'forceDestroy']);

// Product routes
Route::middleware('auth.token')->group(function () {
    // Add this new route
    Route::post('/pms/kpi-task-assignments/check-weights', [PmsController::class, 'checkAssigneeWeights']);
});

Route::get('employee-wise-allowance', [EmployeeWiseAllowanceController::class, 'index']);
Route::get('employee-wise-allowance/{id}', [EmployeeWiseAllowanceController::class, 'getOneById']);
Route::post('employee-wise-allowance', [EmployeeWiseAllowanceController::class, 'store']);
Route::put('employee-wise-allowance/{id}', [EmployeeWiseAllowanceController::class, 'update']);
Route::delete('employee-wise-allowance/{id}', [EmployeeWiseAllowanceController::class, 'destroy']);

Route::get('employee-wise-deduction', [EmployeeWiseDeductionController::class, 'index']);
Route::get('employee-wise-deduction/{id}', [EmployeeWiseDeductionController::class, 'getOneById']);
Route::post('employee-wise-deduction', [EmployeeWiseDeductionController::class, 'store']);
Route::put('employee-wise-deduction/{id}', [EmployeeWiseDeductionController::class, 'update']);
Route::delete('employee-wise-deduction/{id}', [EmployeeWiseDeductionController::class, 'destroy']);

Route::get('employee-wise-bonus', [EmployeeWiseBonusController::class, 'index']);
Route::get('employee-wise-bonus/{id}', [EmployeeWiseBonusController::class, 'getOneById']);
Route::post('employee-wise-bonus', [EmployeeWiseBonusController::class, 'store']);
Route::put('employee-wise-bonus/{id}', [EmployeeWiseBonusController::class, 'update']);
Route::delete('employee-wise-bonus/{id}', [EmployeeWiseBonusController::class, 'destroy']);

Route::get('employee-leave-balances', [EmployeeLeaveBalanceController::class, 'index']);
Route::get('employee-leave-balances/leave-types', [EmployeeLeaveBalanceController::class, 'leaveTypes']);
Route::get('employee-leave-balances/employees', [EmployeeLeaveBalanceController::class, 'employees']);
Route::post('employee-leave-balances', [EmployeeLeaveBalanceController::class, 'store']);
Route::put('employee-leave-balances/{id}', [EmployeeLeaveBalanceController::class, 'update']);
Route::delete('employee-leave-balances/{id}', [EmployeeLeaveBalanceController::class, 'destroy']);

// emergency contact relationship types
Route::get('emergency-contact-relationship-types', [EmergencyContactRelationshipTypesController::class, 'index']);
Route::post('emergency-contact-relationship-types', [EmergencyContactRelationshipTypesController::class, 'store']);
Route::get('emergency-contact-relationship-types/{id}', [EmergencyContactRelationshipTypesController::class, 'getOneById']);
Route::put('emergency-contact-relationship-types/{id}', [EmergencyContactRelationshipTypesController::class, 'update']);
Route::delete('emergency-contact-relationship-types/{id}', [EmergencyContactRelationshipTypesController::class, 'destroy']);

// Employee self-service portal (Solar-style)
Route::middleware('auth.token')->prefix('me')->group(function () {
    Route::get('/portal', [App\Http\Controllers\EmployeePortalController::class, 'home']);
    Route::get('/attendance', [App\Http\Controllers\EmployeePortalController::class, 'attendance']);
    Route::get('/overtime', [App\Http\Controllers\EmployeePortalController::class, 'overtime']);
    Route::get('/nopay', [App\Http\Controllers\EmployeePortalController::class, 'nopay']);
    Route::get('/salary', [App\Http\Controllers\EmployeePortalController::class, 'salary']);
    Route::get('/leaves', [App\Http\Controllers\EmployeePortalController::class, 'leaves']);
    Route::post('/leaves', [App\Http\Controllers\EmployeePortalController::class, 'storeLeave']);
    Route::post('/leaves/{id}/evidence', [App\Http\Controllers\EmployeePortalController::class, 'attachLeaveEvidence']);
    Route::get('/covering-colleagues', [App\Http\Controllers\EmployeePortalController::class, 'coveringColleagues']);
    Route::get('/covering-leaves', [App\Http\Controllers\EmployeePortalController::class, 'coveringLeaves']);
    Route::put('/covering-leaves/{id}', [App\Http\Controllers\EmployeePortalController::class, 'respondCovering']);
    Route::get('/advances', [App\Http\Controllers\EmployeePortalController::class, 'myAdvances']);
    Route::post('/advances', [App\Http\Controllers\EmployeePortalController::class, 'storeAdvance']);
    Route::get('/weekly-offs', [App\Http\Controllers\WeeklyOffController::class, 'portalIndex']);
    Route::post('/weekly-offs', [App\Http\Controllers\WeeklyOffController::class, 'portalStore']);
    Route::get('/medical-claims', [App\Http\Controllers\MedicalClaimController::class, 'portalIndex']);
    Route::post('/medical-claims', [App\Http\Controllers\MedicalClaimController::class, 'portalStore']);
    Route::post('/change-password', [App\Http\Controllers\EmployeePortalController::class, 'changePassword']);
    Route::get('/punch', [App\Http\Controllers\EmployeePortalController::class, 'punchStatus']);
    Route::post('/punch', [App\Http\Controllers\EmployeePortalController::class, 'punch']);
    Route::get('/notices', [App\Http\Controllers\HrNoticeController::class, 'portalIndex']);
    Route::post('/push-token', [App\Http\Controllers\HrNoticeController::class, 'savePushToken']);
    Route::get('/resignations', [ResignationController::class, 'portalIndex']);
    Route::post('/resignations', [ResignationController::class, 'portalStore']);
    Route::get('/loans', [LoanController::class, 'portalIndex']);
    Route::post('/loans', [LoanController::class, 'portalStore']);
});

Route::middleware('auth.token')->group(function () {
    Route::get('/hr/advance-requests', [App\Http\Controllers\EmployeePortalController::class, 'listAdvances']);
    Route::post('/hr/advance-requests', [App\Http\Controllers\EmployeePortalController::class, 'hrStoreAdvance']);
    Route::post('/hr/advance-requests/{id}/review', [App\Http\Controllers\EmployeePortalController::class, 'reviewAdvance']);
    Route::post('/hr/advance-requests/{id}/deduct-from', [App\Http\Controllers\EmployeePortalController::class, 'updateAdvanceDeductFrom']);
    Route::get('/hr/weekly-offs', [App\Http\Controllers\WeeklyOffController::class, 'hrIndex']);
    Route::post('/hr/weekly-offs', [App\Http\Controllers\WeeklyOffController::class, 'hrStore']);
    Route::post('/hr/weekly-offs/{id}/review', [App\Http\Controllers\WeeklyOffController::class, 'hrReview']);
    Route::get('/hr/medical-claims', [App\Http\Controllers\MedicalClaimController::class, 'hrIndex']);
    Route::post('/hr/medical-claims/{id}/review', [App\Http\Controllers\MedicalClaimController::class, 'hrReview']);
    Route::get('/hr/medical-claims/{id}/bill', [App\Http\Controllers\MedicalClaimController::class, 'billUrl']);
    Route::post('/hr/medical-quotas', [App\Http\Controllers\MedicalClaimController::class, 'upsertQuota']);
    Route::get('/hr/notices', [App\Http\Controllers\HrNoticeController::class, 'hrIndex']);
    Route::post('/hr/notices', [App\Http\Controllers\HrNoticeController::class, 'store']);
    Route::delete('/hr/notices/{id}', [App\Http\Controllers\HrNoticeController::class, 'destroy']);
    Route::get('/hr/loans', [LoanController::class, 'hrIndex']);
    Route::post('/hr/loans/{id}/review', [LoanController::class, 'review']);
    Route::get('/hr/pending-payments', [App\Http\Controllers\PendingPaymentController::class, 'index']);
    Route::post('/hr/pending-payments/{id}/paid', [App\Http\Controllers\PendingPaymentController::class, 'markPaid']);
});
