{{-- Star rating card for guests. Happy guests (4-5) get the restaurant's Google review link afterwards. --}}
<section class="card mt-6 text-center" data-rate>
    <h2 class="section-title">{{ __('How was your experience?') }}</h2>
    <div class="my-3 flex justify-center gap-1" dir="ltr" data-stars>
        @for ($i = 1; $i <= 5; $i++)
            <button type="button" class="text-4xl text-navy-200 transition" data-star="{{ $i }}" aria-label="{{ $i }}">★</button>
        @endfor
    </div>
    <div class="hidden space-y-3" data-rate-more>
        <textarea class="field min-h-20 py-2" maxlength="1000" data-comment placeholder="{{ __('Tell us more (optional)') }}"></textarea>
        <button type="button" class="btn-primary w-full" data-send-rate>{{ __('Send') }}</button>
    </div>
    <div class="hidden space-y-3" data-rate-done>
        <p class="font-semibold text-emerald-700">{{ __('Thank you for your feedback!') }}</p>
        <a href="#" target="_blank" rel="noopener" class="btn-ghost hidden w-full" data-google>⭐ {{ __('Rate us on Google too') }}</a>
    </div>
</section>
<script>
    (() => {
        const box = document.currentScript.previousElementSibling;
        let rating = 0;
        box.querySelectorAll('[data-star]').forEach((b) => b.addEventListener('click', () => {
            rating = Number(b.dataset.star);
            box.querySelectorAll('[data-star]').forEach((s) => s.style.color = Number(s.dataset.star) <= rating ? '#f59e0b' : '');
            box.querySelector('[data-rate-more]').classList.remove('hidden');
        }));
        box.querySelector('[data-send-rate]').addEventListener('click', async (e) => {
            e.currentTarget.disabled = true;
            const res = await fetch(@js(route('menu.feedback')), {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
                body: JSON.stringify({ rating, comment: box.querySelector('[data-comment]').value, order: @js($orderToken ?? null) }),
            });
            const data = await res.json().catch(() => ({}));
            box.querySelector('[data-stars]').classList.add('hidden');
            box.querySelector('[data-rate-more]').classList.add('hidden');
            box.querySelector('[data-rate-done]').classList.remove('hidden');
            if (data.google) {
                const a = box.querySelector('[data-google]');
                a.href = data.google;
                a.classList.remove('hidden');
            }
        });
    })();
</script>
