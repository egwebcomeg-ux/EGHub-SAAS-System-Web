{{--
    A checklist, from `[[checklist title]] … [[/checklist]]` (App\Support\PostBlocks). Native checkboxes, so it works
    without JavaScript; landing.js saves the ticks per article on this device (localStorage), shows the progress and
    prints this list alone. $id · $title · $items: inline Markdown lines
--}}
@use('App\Support\PostBlocks')
<section class="lp-block" data-bchecklist="{{ $id }}" aria-labelledby="{{ $id }}-t">
    <div class="lp-block-head">
        <span class="lp-block-ic" aria-hidden="true"><x-landing.icon name="check" class="size-5" /></span>
        <div class="min-w-0 flex-1">
            <h3 id="{{ $id }}-t" class="lp-block-title">{{ $title !== '' ? $title : 'قائمة مراجعة' }}</h3>
            <p class="lp-block-intro" data-bchecklist-progress aria-live="polite">{{ count($items) }} بند</p>
        </div>
    </div>
    <div class="lp-checklist-bar" data-bchecklist-bar hidden><span></span></div>
    <ul class="lp-checklist">
        @foreach ($items as $item)
            <li>
                <label for="{{ $id }}-{{ $loop->index }}">
                    <input id="{{ $id }}-{{ $loop->index }}" type="checkbox" data-index="{{ $loop->index }}">
                    <span>{!! PostBlocks::inline($item) !!}</span>
                </label>
            </li>
        @endforeach
    </ul>
    <div class="lp-block-actions" data-bchecklist-actions hidden>
        <button type="button" class="btn-ghost min-h-10 px-4" data-bchecklist-print><x-landing.icon name="printer" class="size-4" /> اطبع القائمة</button>
        <button type="button" class="btn-ghost min-h-10 px-4" data-bchecklist-reset>امسح العلامات</button>
        <span class="lp-block-note">العلامات بتتحفظ على الجهاز ده بس.</span>
    </div>
</section>
