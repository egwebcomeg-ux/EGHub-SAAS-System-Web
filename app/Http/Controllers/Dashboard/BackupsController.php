<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\TenantBackup;
use App\Services\Audit;
use App\Services\Backups\TenantBackups;
use App\Services\Entitlements;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The restaurant's backups ("النسخ الاحتياطية"): the weekly zips and the ones the owner asks for, listed with a
 * download link that is signed and short-lived (the file itself sits on the private disk). Owner only
 * (`backups.manage`); the `backups` plan feature unlocks creating and downloading, the page itself explains.
 */
class BackupsController extends Controller
{
    /** How long a download link stays valid. */
    private const LINK_MINUTES = 30;

    public function __construct(private TenantBackups $backups) {}

    public function index(): View
    {
        $allowed = Entitlements::current()->allows('backups');
        $backups = TenantBackup::query()->with('creator:id,name')->latest('id')->limit(30)->get();

        return view('dashboard.backups', [
            'allowed' => $allowed,
            'backups' => $backups,
            'blocked' => $allowed ? $this->backups->manualBlockedReason() : null,
            'links' => $backups->filter->isReady()->mapWithKeys(fn (TenantBackup $b) => [$b->id => $this->link($b)])->all(),
            'keepScheduled' => (int) config('backup.tenant.keep_scheduled'),
            'keepManualDays' => (int) config('backup.tenant.keep_manual_days'),
        ]);
    }

    /** "Create a backup now": one per hour, queued (built in place when there is no queue). */
    public function store(Request $request): RedirectResponse
    {
        if ($reason = $this->backups->manualBlockedReason()) {
            return back()->withErrors(['backup' => $reason]);
        }
        $backup = $this->backups->startManual($request->user());
        Audit::record('backup.created', $backup->filename, ['kind' => $backup->kind]);

        return back()->with('ok', $backup->isReady()
            ? __('Your backup is ready to download.')
            : __('Your backup is being prepared. Refresh this page in a minute or two.'));
    }

    public function download(TenantBackup $backup): StreamedResponse
    {
        abort_unless($backup->isReady() && Storage::disk('local')->exists($backup->storagePath()), 404);
        Audit::record('backup.downloaded', $backup->filename, ['kind' => $backup->kind, 'size' => $backup->size]);

        return Storage::disk('local')->download($backup->storagePath(), 'egmenu-'.$backup->tenant_id.'-'.$backup->filename);
    }

    public function destroy(TenantBackup $backup): RedirectResponse
    {
        $this->backups->delete($backup);
        Audit::record('backup.deleted', $backup->filename, ['kind' => $backup->kind]);

        return back()->with('ok', __('Backup deleted.'));
    }

    private function link(TenantBackup $backup): string
    {
        return URL::temporarySignedRoute('dash.backups.download', now()->addMinutes(self::LINK_MINUTES), ['backup' => $backup->id]);
    }
}
