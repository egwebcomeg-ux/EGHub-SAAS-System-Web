<?php

namespace App\Http\Controllers\Menu;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Customer;
use App\Services\Loyalty\LoyaltyService;
use App\Services\Qr;
use App\Services\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The guest's digital loyalty card: egmenu.app/{tenant}/card/{token}. Joining only needs a mobile number; the
 * card shows stamps and rewards but can never add stamps itself (staff or a counted order do that).
 * The browser remembers the card per restaurant so the menu can link to "My card".
 */
class LoyaltyCardController extends Controller
{
    public function join(Request $request, LoyaltyService $loyalty): View|RedirectResponse
    {
        $program = $loyalty->program() ?? abort(404);
        if ($token = $request->cookie($this->cookie())) {
            if (Customer::query()->where('card_token', $token)->exists()) {
                return redirect()->route('menu.card', $token);
            }
        }
        return view('menu.card-join', ['program' => $program, 'settings' => Settings::all(), 'milestones' => $loyalty->milestones($program)]);
    }

    public function store(Request $request, LoyaltyService $loyalty): RedirectResponse
    {
        abort_unless($loyalty->program(), 404);
        $data = $request->validate(['phone' => 'required|string|max:20', 'name' => 'nullable|string|max:100', 'consent' => 'nullable|boolean']);
        $phone = normalize_phone($data['phone']);
        if (!$phone) {
            return back()->withInput()->withErrors(['phone' => __('Enter a valid Egyptian mobile number.')]);
        }
        $card = $loyalty->enroll($phone, $data['name'] ?? '');
        if ($request->boolean('consent')) {
            $card->customer->update(['marketing_opt_in' => true]);
        }
        return redirect()->route('menu.card', $card->customer->card_token)
            ->withCookie(cookie()->forever($this->cookie(), $card->customer->card_token));
    }

    public function show(string $token, LoyaltyService $loyalty): View
    {
        $customer = Customer::query()->where('card_token', $token)->firstOrFail();
        $program = $loyalty->program() ?? abort(404);
        $card = $loyalty->card($program, $customer)->load(['availableRewards', 'transactions' => fn ($q) => $q->whereIn('type', ['stamp', 'points', 'redeem'])->limit(15)]);

        return view('menu.card', [
            'customer' => $customer, 'program' => $program, 'card' => $card, 'settings' => Settings::all(),
            'milestones' => $loyalty->milestones($program),
            'qr' => Qr::svg(route('menu.card', $token), 220, '#111111'),
            'branches' => Branch::query()->where('is_active', true)->orderBy('sort_order')->get(),
        ]);
    }

    private function cookie(): string
    {
        return 'card_' . tenant()->getTenantKey();
    }
}
