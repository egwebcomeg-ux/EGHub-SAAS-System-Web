<?php

namespace App\Services\Orders;

use App\Models\Branch;
use App\Models\PosTerminal;
use App\Models\User;
use App\Services\Audit;
use App\Services\Settings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * POS terminals (tills) of a branch. The owner / manager names them; a device claims one and keeps the token it got
 * (localStorage), sending it with the terminal id on every POS request (X-POS-Terminal / X-POS-Terminal-Token, or
 * terminal_id / terminal_token fields). A terminal of another branch, a disabled one or a wrong token is ignored.
 */
final class Terminals
{
    /** Default hours a device may keep selling offline before it must sync (setting pos_offline_hours). */
    public const OFFLINE_HOURS = 12;

    public static function offlineHours(): int
    {
        $hours = (int) Settings::get('pos_offline_hours', (string) self::OFFLINE_HOURS);

        return max(1, min(72, $hours ?: self::OFFLINE_HOURS));
    }

    public function create(Branch $branch, string $name, ?string $code = null): PosTerminal
    {
        $code = $code !== null && trim($code) !== '' ? self::normalizeCode($code) : $this->nextCode();
        if (! preg_match('/^[A-Z][A-Z0-9]{0,5}$/', $code)) {
            throw new OrderException(__('The code is one letter, then up to 5 letters or digits (e.g. K1).'));
        }
        if (PosTerminal::query()->where('code', $code)->exists()) {
            throw new OrderException(__('Another terminal already has the code :code.', ['code' => $code]));
        }
        $terminal = PosTerminal::query()->create(['branch_id' => $branch->id, 'name' => mb_substr(trim($name), 0, 60), 'code' => $code]);
        Audit::record('pos.terminal_created', $terminal->label());

        return $terminal;
    }

    /**
     * This device takes the terminal: a new token is made (the previous device, if any, is signed off). Taking a
     * terminal another device holds needs $takeover, and is refused while that terminal has an open shift of someone
     * else (unless the user manages terminals).
     *
     * @return array{terminal:PosTerminal, token:string}
     */
    public function claim(PosTerminal $terminal, User $by, string $deviceLabel = '', bool $takeover = false): array
    {
        if (! $terminal->is_active) {
            throw new OrderException(__('This terminal is switched off.'));
        }

        return DB::transaction(function () use ($terminal, $by, $deviceLabel, $takeover) {
            $locked = PosTerminal::query()->lockForUpdate()->findOrFail($terminal->id);
            if ($locked->isClaimed() && ! $takeover) {
                throw new OrderException(__('Another device uses :name. Take it over?', ['name' => $locked->name]));
            }
            $open = $locked->openShift();
            if ($locked->isClaimed() && $open && $open->user_id !== $by->id && ! $by->can('branches.manage')) {
                throw new OrderException(__(':name has an open shift of :user: close it first, or ask a manager.', ['name' => $locked->name, 'user' => $open->user?->name]));
            }
            $token = random_token(24);
            $locked->update([
                'device_token' => hash('sha256', $token), 'device_label' => mb_substr($deviceLabel, 0, 120) ?: null,
                'claimed_at' => now(), 'claimed_by' => $by->id, 'last_seen_at' => now(),
            ]);
            Audit::record('pos.terminal_claimed', $locked->label(), ['takeover' => $takeover]);

            return ['terminal' => $locked, 'token' => $token];
        });
    }

    /** The manager frees the terminal: its device must claim it again. */
    public function release(PosTerminal $terminal): void
    {
        $terminal->update(['device_token' => null, 'device_label' => null, 'claimed_at' => null, 'claimed_by' => null]);
        Audit::record('pos.terminal_released', $terminal->label());
    }

    /** The terminal this request's device holds, in $branch (null when none, or the token is not its own). */
    public static function fromRequest(Request $request, ?Branch $branch = null): ?PosTerminal
    {
        $id = (int) ($request->header('X-POS-Terminal') ?: $request->input('terminal_id'));
        $token = (string) ($request->header('X-POS-Terminal-Token') ?: $request->input('terminal_token'));
        if ($id <= 0 || $token === '') {
            return null;
        }
        $terminal = PosTerminal::query()->where('is_active', true)->find($id);
        if (! $terminal || ! $terminal->holds($token) || ($branch && $terminal->branch_id !== $branch->id)) {
            return null;
        }
        if (! $terminal->last_seen_at || $terminal->last_seen_at->lt(now()->subMinute())) {
            $terminal->forceFill(['last_seen_at' => now()])->saveQuietly();
        }

        return $terminal;
    }

    public static function normalizeCode(string $code): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code) ?? '');
    }

    /** K1, K2 … the first one not taken in this restaurant. */
    private function nextCode(): string
    {
        $taken = PosTerminal::query()->pluck('code')->all();
        for ($n = 1; ; $n++) {
            if (! in_array('K'.$n, $taken, true)) {
                return 'K'.$n;
            }
        }
    }
}
