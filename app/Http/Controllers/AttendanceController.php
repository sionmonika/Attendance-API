<?php

namespace App\Http\Controllers;

use App\Models\Attendance;
use App\Models\Employee;
use Illuminate\Http\Request;
use Carbon\Carbon;

class AttendanceController extends Controller
{
    public function checkIn(Request $request)
    {
        $validated = $request->validate([
            'employee_id' => 'required|exists:employees,id',
        ]);

        // Check if employee already checked in today
        $existingAttendance = Attendance::where('employee_id', $validated['employee_id'])
            ->whereDate('check_in', Carbon::today())
            ->first();

        if ($existingAttendance) {
            return response()->json([
                'message' => 'Employee already checked in today'
            ], 400);
        }

        $attendance = Attendance::create([
            'employee_id' => $validated['employee_id'],
            'check_in' => Carbon::now(),
        ]);

        return response()->json($attendance, 201);
    }

    public function checkOut(Request $request)
    {
        $validated = $request->validate([
            'employee_id' => 'required|exists:employees,id',
        ]);

        // Get today's check-in record
        $attendance = Attendance::where('employee_id', $validated['employee_id'])
            ->whereDate('check_in', Carbon::today())
            ->first();

        if (!$attendance) {
            return response()->json([
                'message' => 'Employee has not checked in today'
            ], 400);
        }

        if ($attendance->check_out) {
            return response()->json([
                'message' => 'Employee already checked out today'
            ], 400);
        }

        $attendance->update([
            'check_out' => Carbon::now()
        ]);

        return response()->json($attendance);
    }

    public function getAttendance($employeeId)
    {
        $employee = Employee::findOrFail($employeeId);

        $attendances = $employee->attendances()
            ->orderBy('check_in', 'desc')
            ->paginate(10);

        return response()->json($attendances);
    }

    // Bonus: Export to CSV
    public function exportAttendance($employeeId)
    {
        $employee = Employee::findOrFail($employeeId);
        $attendances = $employee->attendances()
            ->orderBy('check_in', 'desc')
            ->get();

        $fileName = "attendance_{$employee->name}_".now()->format('YmdHis').'.csv';
        $headers = [
            "Content-type"        => "text/csv",
            "Content-Disposition" => "attachment; filename=$fileName",
            "Pragma"              => "no-cache",
            "Cache-Control"       => "must-revalidate, post-check=0, pre-check=0",
            "Expires"             => "0"
        ];

        $columns = ['Date', 'Check In', 'Check Out', 'Working Hours'];

        $callback = function() use($attendances, $columns) {
            $file = fopen('php://output', 'w');
            fputcsv($file, $columns);

            foreach ($attendances as $attendance) {
                $workingHours = $attendance->check_out 
                    ? round((strtotime($attendance->check_out) - strtotime($attendance->check_in)) / 3600, 2)
                    : 'N/A';

                fputcsv($file, [
                    $attendance->date,
                    $attendance->check_in->format('Y-m-d H:i:s'),
                    $attendance->check_out?->format('Y-m-d H:i:s') ?? 'N/A',
                    $workingHours
                ]);
            }

            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }
}