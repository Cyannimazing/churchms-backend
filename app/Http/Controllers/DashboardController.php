<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Models\ChurchTransaction;
use App\Models\Appointment;
use App\Models\ChurchMember;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    /**
     * Get dashboard analytics for church staff
     */
    public function getAnalytics(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->load(['profile.systemRole', 'church', 'churches']);
        
        // Determine church ID based on user role
        if ($user->profile->systemRole->role_name === 'ChurchStaff') {
            $churchId = $user->church->ChurchID;
        } elseif ($user->profile->systemRole->role_name === 'ChurchOwner') {
            // For church owners, get church_id from request or route
            $churchId = $request->input('church_id');
            if (!$churchId) {
                return response()->json(['error' => 'Church ID is required'], 400);
            }
        } else {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        // Get current month and year
        $currentMonth = Carbon::now()->month;
        $currentYear = Carbon::now()->year;

        // 1. Financial Summary
        $totalCollection = ChurchTransaction::where('church_id', $churchId)
            ->where('refund_status', 'none')
            ->sum('amount_paid');

        $totalRefunded = ChurchTransaction::where('church_id', $churchId)
            ->where('refund_status', 'refunded')
            ->sum('amount_paid');

        $driver = DB::getDriverName();
        $yearExprTxn = $driver === 'sqlite' ? "strftime('%Y', transaction_date)" : 'YEAR(transaction_date)';
        $monthExprTxn = $driver === 'sqlite' ? "strftime('%m', transaction_date)" : 'MONTH(transaction_date)';

        $currentMonthCollection = ChurchTransaction::where('church_id', $churchId)
            ->where('refund_status', 'none')
            ->whereRaw($yearExprTxn.' = ?', [strval($currentYear)])
            ->whereRaw($monthExprTxn.' = ?', [str_pad(strval($currentMonth), 2, '0', STR_PAD_LEFT)])
            ->sum('amount_paid');

        // 2. Appointment Statistics
        $totalAppointments = Appointment::where('ChurchID', $churchId)->count();
        
        $appointmentsByStatus = Appointment::where('ChurchID', $churchId)
            ->select('Status', DB::raw('count(*) as count'))
            ->groupBy('Status')
            ->get()
            ->mapWithKeys(fn($item) => [$item->Status => $item->count]);

        $cancelledAppointments = Appointment::where('ChurchID', $churchId)
            ->where('Status', 'Cancelled')
            ->count();

        // Appointments per month (last 6 months - based on created_at, not AppointmentDate)
        $yearExprCreated = $driver === 'sqlite' ? "strftime('%Y', created_at)" : 'YEAR(created_at)';
        $monthExprCreated = $driver === 'sqlite' ? "strftime('%m', created_at)" : 'MONTH(created_at)';
        $appointmentsPerMonth = Appointment::where('ChurchID', $churchId)
            ->where('created_at', '>=', Carbon::now()->subMonths(6))
            ->where('created_at', '<=', Carbon::now())
            ->selectRaw($yearExprCreated.' as year, '.$monthExprCreated.' as month, count(*) as count')
            ->groupBy('year', 'month')
            ->orderBy('year')
            ->orderBy('month')
            ->get()
            ->map(function($item) {
                return [
                    'month' => Carbon::create($item->year, $item->month)->format('M Y'),
                    'count' => $item->count
                ];
            });

        // 3. Member Applications
        $totalMembers = ChurchMember::where('church_id', $churchId)->count();
        
        $membersByStatus = ChurchMember::where('church_id', $churchId)
            ->select('status', DB::raw('count(*) as count'))
            ->groupBy('status')
            ->get()
            ->mapWithKeys(fn($item) => [$item->status => $item->count]);

        $pendingApplications = ChurchMember::where('church_id', $churchId)
            ->where('status', 'pending')
            ->count();

        $approvedApplications = ChurchMember::where('church_id', $churchId)
            ->where('status', 'approved')
            ->count();

        $rejectedApplications = ChurchMember::where('church_id', $churchId)
            ->where('status', 'rejected')
            ->count();

        // Member applications per month (last 6 months)
        $yearExprMember = $driver === 'sqlite' ? "strftime('%Y', created_at)" : 'YEAR(created_at)';
        $monthExprMember = $driver === 'sqlite' ? "strftime('%m', created_at)" : 'MONTH(created_at)';
        $membersPerMonth = ChurchMember::where('church_id', $churchId)
            ->where('created_at', '>=', Carbon::now()->subMonths(6))
            ->where('created_at', '<=', Carbon::now())
            ->selectRaw($yearExprMember.' as year, '.$monthExprMember.' as month, count(*) as count')
            ->groupBy('year', 'month')
            ->orderBy('year')
            ->orderBy('month')
            ->get()
            ->map(function($item) {
                return [
                    'month' => Carbon::create($item->year, $item->month)->format('M Y'),
                    'count' => $item->count
                ];
            });

        // 4. Revenue per month (last 6 months)
        $revenuePerMonth = ChurchTransaction::where('church_id', $churchId)
            ->where('refund_status', 'none')
            ->where('transaction_date', '>=', Carbon::now()->subMonths(6))
            ->where('transaction_date', '<=', Carbon::now())
            ->selectRaw($yearExprTxn.' as year, '.$monthExprTxn.' as month, SUM(amount_paid) as total')
            ->groupBy('year', 'month')
            ->orderBy('year')
            ->orderBy('month')
            ->get()
            ->map(function($item) {
                return [
                    'month' => Carbon::create($item->year, $item->month)->format('M Y'),
                    'revenue' => (float) $item->total
                ];
            });

        // 5. Recent Transactions
        $recentTransactions = ChurchTransaction::where('church_id', $churchId)
            ->with(['user.profile', 'appointment.service'])
            ->orderBy('transaction_date', 'desc')
            ->limit(5)
            ->get()
            ->map(function($transaction) {
                return [
                    'id' => $transaction->ChurchTransactionID,
                    'receipt_code' => $transaction->receipt_code,
                    'user_name' => $transaction->user->profile->first_name . ' ' . $transaction->user->profile->last_name,
                    'amount' => (float) $transaction->amount_paid,
                    'payment_method' => $transaction->payment_method,
                    'transaction_date' => $transaction->transaction_date,
                    'service_name' => $transaction->appointment?->service?->ServiceName ?? 'N/A',
                    'is_refunded' => $transaction->refund_status === 'refunded',
                ];
            });

        return response()->json([
            'financial' => [
                'total_collection' => (float) $totalCollection,
                'total_refunded' => (float) $totalRefunded,
                'current_month_collection' => (float) $currentMonthCollection,
                'revenue_per_month' => $revenuePerMonth,
            ],
            'appointments' => [
                'total' => $totalAppointments,
                'by_status' => $appointmentsByStatus,
                'cancelled' => $cancelledAppointments,
                'per_month' => $appointmentsPerMonth,
            ],
            'members' => [
                'total' => $totalMembers,
                'by_status' => $membersByStatus,
                'pending' => $pendingApplications,
                'approved' => $approvedApplications,
                'rejected' => $rejectedApplications,
                'per_month' => $membersPerMonth,
            ],
            'recent_transactions' => $recentTransactions,
        ]);
    }
}
