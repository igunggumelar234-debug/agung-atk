<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Audit log aktivitas seluruh pengguna — hanya dapat diakses Admin.
 */
class ActivityLogController extends Controller
{
    /**
     * Daftar audit aktivitas dengan filter pengguna, jenis, & tanggal.
     */
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('q', ''));
        $activityType = trim((string) $request->query('type', ''));
        $dateFrom = $request->query('from');
        $dateTo = $request->query('to');

        $logs = ActivityLog::with(['user', 'subject'])
            ->when($search !== '', function ($query) use ($search) {
                $query->whereHas('user', function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            })
            ->when($activityType !== '', function ($query) use ($activityType) {
                $query->where('activity_type', $activityType);
            })
            ->when($dateFrom, function ($query) use ($dateFrom) {
                // Rentang eksplisit (bukan fungsi DATE() pada kolom) agar
                // index created_at tetap terpakai; batas akhir hari memakai
                // 23:59:59 agar cakupannya identik dengan whereDate lama.
                $query->where('created_at', '>=', substr((string) $dateFrom, 0, 10));
            })
            ->when($dateTo, function ($query) use ($dateTo) {
                $query->where('created_at', '<=', substr((string) $dateTo, 0, 10).' 23:59:59');
            })
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return view('admin.activity-logs.index', [
            'logs' => $logs,
            'search' => $search,
            'activityType' => $activityType,
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'activityTypes' => ActivityLog::distinct()->orderBy('activity_type')->pluck('activity_type'),
            'userOptions' => User::orderBy('name')->get(['id', 'name', 'email']),
        ]);
    }
}
