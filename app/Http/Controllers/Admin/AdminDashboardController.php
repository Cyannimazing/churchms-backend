<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Models\SubscriptionTransaction;
use App\Models\Church;
use App\Models\ChurchSubscription;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class AdminDashboardController extends Controller
{
    /**
     * Get admin dashboard analytics
     */
    public function getAnalytics(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->load(['profile.systemRole']);
        
        // Only allow admin users
        if ($user->profile->systemRole->role_name !== 'Admin') {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        // Get current date info
        $today = Carbon::today();
        $currentMonth = Carbon::now()->month;
        $currentYear = Carbon::now()->year;

        // Database driver detection for date functions
        $driver = DB::getDriverName();
        $yearExpr = $driver === 'sqlite' ? "strftime('%Y', TransactionDate)" : 'YEAR(TransactionDate)';
        $monthExpr = $driver === 'sqlite' ? "strftime('%m', TransactionDate)" : 'MONTH(TransactionDate)';
        $dayExpr = $driver === 'sqlite' ? "strftime('%d', TransactionDate)" : 'DAY(TransactionDate)';

        // 1. SUBSCRIPTION EARNINGS SUMMARY
        $totalEarnings = SubscriptionTransaction::sum('AmountPaid');

        $dailyEarnings = SubscriptionTransaction::whereDate('TransactionDate', $today)
            ->sum('AmountPaid');

        $monthlyEarnings = SubscriptionTransaction::whereRaw($yearExpr.' = ?', [strval($currentYear)])
            ->whereRaw($monthExpr.' = ?', [str_pad(strval($currentMonth), 2, '0', STR_PAD_LEFT)])
            ->sum('AmountPaid');

        $yearlyEarnings = SubscriptionTransaction::whereRaw($yearExpr.' = ?', [strval($currentYear)])
            ->sum('AmountPaid');

        // 2. EARNINGS TREND (Last 12 months)
        $earningsPerMonth = SubscriptionTransaction::where('TransactionDate', '>=', Carbon::now()->subMonths(12))
            ->selectRaw($yearExpr.' as year, '.$monthExpr.' as month, SUM(AmountPaid) as total, COUNT(*) as count')
            ->groupBy('year', 'month')
            ->orderBy('year')
            ->orderBy('month')
            ->get()
            ->map(function($item) {
                return [
                    'month' => Carbon::create($item->year, $item->month)->format('M Y'),
                    'earnings' => (float) $item->total,
                    'transactions' => $item->count
                ];
            });

        // 3. DAILY EARNINGS (Last 30 days)
        $earningsPerDay = SubscriptionTransaction::where('TransactionDate', '>=', Carbon::now()->subDays(30))
            ->selectRaw($yearExpr.' as year, '.$monthExpr.' as month, '.$dayExpr.' as day, SUM(AmountPaid) as total')
            ->groupBy('year', 'month', 'day')
            ->orderBy('year')
            ->orderBy('month')
            ->orderBy('day')
            ->get()
            ->map(function($item) {
                return [
                    'date' => Carbon::create($item->year, $item->month, $item->day)->format('M d'),
                    'earnings' => (float) $item->total
                ];
            });

        // 4. CHURCH APPLICATIONS STATISTICS
        $totalChurches = Church::count();
        $pendingApplications = Church::where('ChurchStatus', 'Pending')->count();
        $approvedChurches = Church::where('ChurchStatus', 'Active')->count();
        $rejectedApplications = Church::where('ChurchStatus', 'Rejected')->count();
        $publishedChurches = Church::where('IsPublic', true)->count();

        $churchesByStatus = Church::select('ChurchStatus as status', DB::raw('count(*) as count'))
            ->groupBy('ChurchStatus')
            ->get()
            ->mapWithKeys(fn($item) => [$item->status => $item->count]);

        // 5. CHURCH APPLICATIONS TREND (Last 12 months)
        $yearExprChurch = $driver === 'sqlite' ? "strftime('%Y', created_at)" : 'YEAR(created_at)';
        $monthExprChurch = $driver === 'sqlite' ? "strftime('%m', created_at)" : 'MONTH(created_at)';
        
        $churchApplicationsPerMonth = Church::where('created_at', '>=', Carbon::now()->subMonths(12))
            ->selectRaw($yearExprChurch.' as year, '.$monthExprChurch.' as month, COUNT(*) as count')
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

        // 6. SUBSCRIPTION PLANS DISTRIBUTION
        $subscriptionsByPlan = ChurchSubscription::with('plan')
            ->where('Status', 'Active')
            ->get()
            ->groupBy('PlanID')
            ->map(function($group) {
                return [
                    'plan_name' => $group->first()->plan->PlanName ?? 'Unknown',
                    'count' => $group->count(),
                    'total_revenue' => $group->sum(function($sub) {
                        return $sub->plan->Price ?? 0;
                    })
                ];
            })
            ->values();

        // 7. RECENT TRANSACTIONS
        $recentTransactions = SubscriptionTransaction::with(['user.churches', 'newPlan', 'paymentSession'])
            ->orderBy('TransactionDate', 'desc')
            ->limit(10)
            ->get()
            ->map(function($transaction) {
                $status = $transaction->paymentSession->status ?? null;
                return [
                    'id' => $transaction->SubTransactionID,
                    'reference_number' => $transaction->receipt_code ?? ($transaction->paymongo_session_id ?? 'N/A'),
                    'church_name' => $transaction->user?->churches?->first()?->ChurchName ?? 'N/A',
                    'plan_name' => $transaction->newPlan->PlanName ?? 'N/A',
                    'amount' => (float) $transaction->AmountPaid,
                    'payment_method' => $transaction->PaymentMethod ?? 'N/A',
                    'payment_status' => $status ?? 'unknown',
                    'transaction_date' => optional($transaction->TransactionDate)->toDateTimeString(),
                ];
            });

        // 8. RECENT CHURCH APPLICATIONS
        $recentApplications = Church::with(['owner.profile'])
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get()
            ->map(function($church) {
                return [
                    'id' => $church->ChurchID,
                    'church_name' => $church->ChurchName,
                    'owner_name' => $church->owner ? 
                        ($church->owner->profile->first_name . ' ' . $church->owner->profile->last_name) : 'N/A',
                    'status' => $church->ChurchStatus,
                    'is_published' => $church->IsPublic,
                    'created_at' => $church->created_at->toDateTimeString(),
                ];
            });

        // 9. SUBSCRIPTION STATUS BREAKDOWN
        $subscriptionsByStatus = ChurchSubscription::select('Status as status', DB::raw('count(*) as count'))
            ->groupBy('Status')
            ->get()
            ->mapWithKeys(fn($item) => [$item->status => $item->count]);

        // 10. PAYMENT METHOD BREAKDOWN
        $paymentMethodBreakdown = SubscriptionTransaction::select('PaymentMethod', DB::raw('count(*) as count'), DB::raw('SUM(AmountPaid) as total'))
            ->groupBy('PaymentMethod')
            ->get()
            ->map(function($item) {
                return [
                    'method' => $item->PaymentMethod ?? 'Unknown',
                    'count' => $item->count,
                    'total' => (float) $item->total
                ];
            });

        // 11. ACTIVE SUBSCRIPTIONS
        $activeSubscriptions = ChurchSubscription::where('Status', 'Active')->count();
        $expiredSubscriptions = ChurchSubscription::where('Status', 'Expired')->count();
        $pendingSubscriptions = ChurchSubscription::where('Status', 'Pending')->count();

        return response()->json([
            'earnings' => [
                'total' => (float) $totalEarnings,
                'daily' => (float) $dailyEarnings,
                'monthly' => (float) $monthlyEarnings,
                'yearly' => (float) $yearlyEarnings,
                'per_month' => $earningsPerMonth,
                'per_day' => $earningsPerDay,
            ],
            'churches' => [
                'total' => $totalChurches,
                'pending' => $pendingApplications,
                'approved' => $approvedChurches,
                'rejected' => $rejectedApplications,
                'published' => $publishedChurches,
                'by_status' => $churchesByStatus,
                'per_month' => $churchApplicationsPerMonth,
            ],
            'subscriptions' => [
                'active' => $activeSubscriptions,
                'expired' => $expiredSubscriptions,
                'pending' => $pendingSubscriptions,
                'by_status' => $subscriptionsByStatus,
                'by_plan' => $subscriptionsByPlan,
            ],
            'payment_methods' => $paymentMethodBreakdown,
            'recent_transactions' => $recentTransactions,
            'recent_applications' => $recentApplications,
        ]);
    }
}
