<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ServiceRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Requests for EgWeb's one-time services (eghub.app/services): follow each one from new to done. */
class ServicesController extends Controller
{
    public function index(Request $request): View
    {
        $status = (string) $request->query('status', 'open');
        $requests = ServiceRequest::query()
            ->when($status === 'open', fn ($q) => $q->whereIn('status', ['new', 'contacted']))
            ->when(array_key_exists($status, ServiceRequest::STATUSES), fn ($q) => $q->where('status', $status))
            ->latest('id')->paginate(30)->withQueryString();

        return view('admin.services', [
            'requests' => $requests, 'status' => $status,
            'counts' => ServiceRequest::query()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status')->all(),
        ]);
    }

    public function update(Request $request, ServiceRequest $serviceRequest): RedirectResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in(array_keys(ServiceRequest::STATUSES))]]);
        $serviceRequest->update(['status' => $data['status']]);

        return back()->with('ok', __('Request updated.'));
    }
}
