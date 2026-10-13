<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Feedback;
use Illuminate\Http\Request;
use Illuminate\View\View;

class FeedbackController extends Controller
{
    public function index(Request $request): View
    {
        $rating = (int) $request->query('rating');
        $base = Feedback::query()->when($request->user()->branch_id, fn ($q, $b) => $q->where('branch_id', $b));
        return view('dashboard.feedback', [
            'feedback' => (clone $base)->with(['branch', 'order'])->when($rating, fn ($q) => $q->where('rating', $rating))
                ->latest('id')->paginate(30)->withQueryString(),
            'average' => round((float) (clone $base)->avg('rating'), 1),
            'count' => (clone $base)->count(),
            'spread' => (clone $base)->selectRaw('rating, count(*) as n')->groupBy('rating')->pluck('n', 'rating')->all(),
            'rating' => $rating,
        ]);
    }
}
