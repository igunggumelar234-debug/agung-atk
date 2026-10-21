<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class UserController extends Controller
{
    /**
     * Role yang boleh dibuat/dipilih Admin saat mengelola akun.
     * Admin dibuat melalui seeding awal / mekanisme administrasi terlindungi,
     * bukan melalui formulir publik.
     */
    private const MANAGEABLE_ROLES = ['anggota', 'kurir', 'pelanggan'];

    /**
     * Status kerja yang valid untuk data pegawai.
     */
    private const EMPLOYMENT_STATUSES = ['permanent', 'contract', 'probation'];

    /**
     * Daftar seluruh pengguna (filter role & pencarian).
     */
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('q', ''));
        $roleName = $request->query('role');
        $roleNames = Role::pluck('name')->all();

        $users = User::with('role')
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('employee_code', 'like', "%{$search}%");
                });
            })
            ->when(in_array($roleName, $roleNames, true), function ($query) use ($roleName) {
                $query->whereHas('role', function ($q) use ($roleName) {
                    $q->where('name', $roleName);
                });
            })
            ->orderBy('name')
            ->paginate(10)
            ->withQueryString();

        $roleCounts = User::join('roles', 'users.role_id', '=', 'roles.id')
            ->selectRaw('roles.name as role_name, COUNT(*) as total')
            ->groupBy('roles.name')
            ->pluck('total', 'role_name');

        return view('admin.users.index', [
            'users' => $users,
            'search' => $search,
            'roleName' => in_array($roleName, $roleNames, true) ? $roleName : null,
            'roleCounts' => $roleCounts,
        ]);
    }

    /**
     * Formulir pembuatan akun Anggota / Kurir / Pelanggan oleh Admin.
     * Role ditentukan di sini (backend), bukan oleh browser pengguna.
     */
    public function create(): View
    {
        return view('admin.users.create', [
            'roles' => Role::whereIn('name', self::MANAGEABLE_ROLES)->orderBy('name')->get(),
            'employmentStatuses' => self::EMPLOYMENT_STATUSES,
        ]);
    }

    /**
     * Simpan akun baru (transaksi DB + jejak audit).
     */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:20'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'role' => ['required', Rule::in(self::MANAGEABLE_ROLES)],
            'employee_code' => ['nullable', 'string', 'max:30', 'unique:users,employee_code'],
            'position' => ['nullable', 'string', 'max:100'],
            'employment_status' => ['nullable', Rule::in(self::EMPLOYMENT_STATUSES)],
            'hired_at' => ['nullable', 'date'],
        ], [
            'role.in' => 'Role harus salah satu dari: Anggota, Kurir, atau Pelanggan.',
            'employee_code.unique' => 'Nomor pegawai tersebut sudah digunakan.',
            'employment_status.in' => 'Status kerja tidak valid.',
        ]);

        $role = Role::where('name', $data['role'])->firstOrFail();

        DB::transaction(function () use (&$data, $role) {
            if (in_array($role->name, ['anggota', 'kurir'], true) && empty($data['employee_code'])) {
                $data['employee_code'] = $this->generateEmployeeCode($role->name);
            }

            $user = User::create([
                'role_id' => $role->id,
                'employee_code' => $data['employee_code'] ?? null,
                'position' => $data['position'] ?? null,
                'employment_status' => $data['employment_status'] ?? null,
                'hired_at' => $data['hired_at'] ?? null,
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                'password' => Hash::make($data['password']),
                'is_active' => true,
            ]);

            ActivityLog::record('user.created', $user, sprintf(
                'Membuat akun %s (%s) - nomor pegawai %s.',
                $role->label,
                $user->email,
                $user->employee_code ?? '-',
            ));
        });

        return redirect()
            ->route('admin.users.index')
            ->with('success', 'Akun "'.$data['name'].'" berhasil dibuat dengan role '.$role->label.'.');
    }

    /**
     * Formulir ubah data pengguna/pegawai (oleh Admin).
     */
    public function edit(User $user): View
    {
        return view('admin.users.edit', [
            'user' => $user,
            'roles' => Role::orderBy('name')->get(),
            'employmentStatuses' => self::EMPLOYMENT_STATUSES,
        ]);
    }

    /**
     * Perbarui data pengguna (transaksi DB + jejak audit).
     * Role hanya dapat diubah oleh Admin, tidak dari sisi browser pengguna.
     */
    public function update(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'phone' => ['nullable', 'string', 'max:20'],
            'role' => ['required', Rule::in(array_merge(self::MANAGEABLE_ROLES, ['admin']))],
            'employee_code' => ['nullable', 'string', 'max:30', Rule::unique('users', 'employee_code')->ignore($user->id)],
            'position' => ['nullable', 'string', 'max:100'],
            'employment_status' => ['nullable', Rule::in(self::EMPLOYMENT_STATUSES)],
            'hired_at' => ['nullable', 'date'],
        ], [
            'role.in' => 'Role tidak valid.',
            'employee_code.unique' => 'Nomor pegawai tersebut sudah digunakan.',
        ]);

        $requester = $request->user();

        if ($user->id === $requester->id && $data['role'] !== $user->role?->name) {
            return back()->withErrors([
                'role' => 'Anda tidak dapat mengubah role akun sendiri.',
            ]);
        }

        $role = Role::where('name', $data['role'])->firstOrFail();
        $oldRole = $user->role?->name;

        DB::transaction(function () use ($user, $data, $role) {
            $user->update([
                'role_id' => $role->id,
                'employee_code' => $data['employee_code'] ?? null,
                'position' => $data['position'] ?? null,
                'employment_status' => $data['employment_status'] ?? null,
                'hired_at' => $data['hired_at'] ?? null,
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
            ]);
        });

        $summary = 'Mengubah data akun '.$user->email.'.';
        if ($oldRole !== $role->name) {
            $summary .= ' Role: '.$oldRole.' -> '.$role->name.'.';
        }

        ActivityLog::record('user.updated', $user, $summary);

        return redirect()
            ->route('admin.users.index')
            ->with('success', 'Data "'.$user->name.'" berhasil diperbarui.');
    }

    /**
     * Aktif / nonaktifkan akun pengguna.
     *
     * Admin tidak dapat menonaktifkan akunnya sendiri.
     */
    public function toggleStatus(Request $request, User $user): RedirectResponse
    {
        if ($user->id === $request->user()->id) {
            return back()->with('error', 'Anda tidak dapat menonaktifkan akun sendiri.');
        }

        $user->update([
            'is_active' => ! $user->is_active,
        ]);

        ActivityLog::record('user.status_changed', $user, sprintf(
            '%sakun %s (%s).',
            $user->is_active ? 'Mengaktifkan kembali ' : 'Menonaktifkan ',
            $user->email,
            $user->role?->name ?? '-',
        ));

        return back()->with(
            'success',
            $user->is_active
                ? 'Akun '.$user->name.' berhasil diaktifkan.'
                : 'Akun '.$user->name.' berhasil dinonaktifkan.'
        );
    }

    /**
     * Reset kata sandi akun oleh Admin (prosedur aman):
     * nilai baru tervalidasi panjang minimum + konfirmasi, langsung di-hash,
     * tidak pernah dicatat ke log/audit, dan aktivitas tetap terlacak.
     */
    public function resetPassword(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate([
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ], [
            'password.min' => 'Kata sandi baru minimal 8 karakter.',
            'password.confirmed' => 'Konfirmasi kata sandi tidak cocok.',
        ]);

        $user->update([
            'password' => Hash::make($data['password']),
        ]);

        // Invalidasi sesi aktif agar kata sandi lama tidak berlaku lagi.
        DB::table('sessions')->where('user_id', $user->id)->delete();

        ActivityLog::record('user.password_reset', $user, 'Reset kata sandi oleh Administrator.');

        return back()->with('success', 'Kata sandi akun "'.$user->name.'" berhasil direset. Sesi lama telah diakhiri.');
    }

    /**
     * Riwayat aktivitas milik seorang pegawai/pengguna (audit per orang).
     */
    public function activities(Request $request, User $user): View
    {
        $activityType = $request->query('type');

        $logs = ActivityLog::with('user')
            ->where('user_id', $user->id)
            ->when($activityType !== null && $activityType !== '', function ($query) use ($activityType) {
                $query->where('activity_type', $activityType);
            })
            ->latest()
            ->paginate(15)
            ->withQueryString();

        $activityTypes = ActivityLog::where('user_id', $user->id)
            ->distinct()
            ->orderBy('activity_type')
            ->pluck('activity_type');

        return view('admin.users.activities', compact('user', 'logs', 'activityTypes'));
    }

    /**
     * Buat nomor pegawai unik: prefix per role + nomor urut 4 digit.
     *
     * @param  string  $roleName  'anggota' -> AGT-, 'kurir' -> KUR-
     */
    private function generateEmployeeCode(string $roleName): string
    {
        $prefix = $roleName === 'kurir' ? 'KUR' : 'AGT';

        $lastNumber = User::where('employee_code', 'like', $prefix.'-%')
            ->orderByDesc('employee_code')
            ->value('employee_code');

        $next = $lastNumber ? ((int) substr($lastNumber, strlen($prefix) + 1)) + 1 : 1;

        do {
            $code = sprintf('%s-%04d', $prefix, $next);
            $next++;
        } while (User::where('employee_code', $code)->exists());

        return $code;
    }
}
