{{--
    The "which box?" picker, from a `[[box-picker]]` line (App\Support\PostBlocks). Every answer comes from
    App\Support\PackagingPicker and travels as data (the distinct answers, and a food|temp|texture → answer map);
    landing.js only looks it up. Without JavaScript the table of the usual answer for each food is open.
--}}
@use('App\Support\PackagingPicker')
@php
    $answers = [];
    $map = [];
    foreach (PackagingPicker::matrix() as $key => $answer) {
        $index = array_search($answer, $answers, true);
        if ($index === false) {
            $answers[] = $answer;
            $index = count($answers) - 1;
        }
        $map[$key] = $index;
    }
    $typical = PackagingPicker::typical();
    $firstFood = array_key_first(PackagingPicker::foods());
    $first = PackagingPicker::recommend($firstFood, ...$typical[$firstFood]);
    $selected = ['food' => $firstFood, 'temp' => $typical[$firstFood][0], 'texture' => $typical[$firstFood][1]];
@endphp
<section class="lp-block" data-bpicker data-answers="{{ json_encode($answers, JSON_UNESCAPED_UNICODE) }}" data-map="{{ json_encode($map) }}" data-typical="{{ json_encode($typical) }}" aria-labelledby="{{ $id }}-t">
    <div class="lp-block-head">
        <span class="lp-block-ic" aria-hidden="true"><x-landing.icon name="box" class="size-5" /></span>
        <div>
            <h3 id="{{ $id }}-t" class="lp-block-title">أنهي علبة تنفع الصنف ده؟</h3>
            <p class="lp-block-intro">اختار نوع الأكل وهيوصل إزاي، وخد نوع العلبة والسبب.</p>
        </div>
    </div>

    <div class="lp-picker-form" data-bpicker-form hidden>
        @foreach (['food' => ['نوع الأكل', PackagingPicker::foods()], 'temp' => ['هيوصل', PackagingPicker::temperatures()], 'texture' => ['قوامه', PackagingPicker::textures()]] as $field => [$label, $options])
            <div>
                <label for="{{ $id }}-{{ $field }}" class="lp-block-label">{{ $label }}</label>
                <select id="{{ $id }}-{{ $field }}" name="{{ $field }}" class="lp-select">
                    @foreach ($options as $value => $text)
                        <option value="{{ $value }}" @selected($selected[$field] === $value)>{{ $text }}</option>
                    @endforeach
                </select>
            </div>
        @endforeach
    </div>

    <div class="lp-picker-result" data-bpicker-result aria-live="polite" hidden>
        <p class="lp-picker-box" data-field="box">{{ $first['box'] }}</p>
        <p><strong>ليه:</strong> <span data-field="reason">{{ $first['reason'] }}</span></p>
        <p><strong>نصيحة:</strong> <span data-field="tip">{{ $first['tip'] }}</span></p>
    </div>

    <details class="lp-picker-table" data-bpicker-table open>
        <summary>الاختيار المعتاد لكل نوع أكل</summary>
        <div class="overflow-x-auto">
            <table>
                <thead><tr><th scope="col">الأكل</th><th scope="col">العلبة</th><th scope="col">ليه</th></tr></thead>
                <tbody>
                    @foreach (PackagingPicker::foods() as $food => $name)
                        @php $answer = PackagingPicker::recommend($food, ...$typical[$food]); @endphp
                        <tr><th scope="row">{{ $name }}</th><td>{{ $answer['box'] }}</td><td>{{ $answer['reason'] }}</td></tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </details>
    <p class="lp-block-note">الفوم مش في الاختيارات عن قصد: بيتكسر، بيعرق، وشكله بيقول للعميل إن المحل بيوفّر في الحتة الغلط.</p>
</section>
