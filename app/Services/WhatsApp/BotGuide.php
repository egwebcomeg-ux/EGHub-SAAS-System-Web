<?php

namespace App\Services\WhatsApp;

use App\Models\Plan;
use App\Services\TenantProvisioner;
use App\Support\SiteModules;

/**
 * The EgHub number's guide: a menu of topics the person taps through (the live plans, every module of the website
 * by group, how to start), answered from the same facts as the website without asking the model, so it answers at
 * once and never invents. Every answer ends with the next choices, the main menu and «أكلم حد من الفريق», so the
 * conversation goes on until the person asks for the team. A tap reaches us as the chosen title: titles are unique.
 */
final class BotGuide
{
    public const MENU = 'القائمة الرئيسية';

    public const TEAM = 'أكلم حد من الفريق';

    public const PRICES = 'الأسعار والباقات';

    public const START = 'أبدأ إزاي؟';

    /** The welcome's «المميزات» button and words a person types to see the menu again. */
    private const MENU_WORDS = ['المميزات', 'القائمة', 'قائمة', 'القايمة', 'المنيو الرئيسي', 'مساعدة', 'menu', 'help', '0'];

    /** Messenger and Instagram quick replies keep 20 characters of a title: titles are compared on that much. */
    private const MATCH_LENGTH = 20;

    /** WhatsApp list rows: 10 at most, a title of 24 characters, a description of 72. */
    public const MAX_ROWS = 10;

    /**
     * What to send for $asked, null when it is not one of the guide's choices.
     *
     * @return array{text: string, rows: list<array{title: string, description: string}>}|null
     */
    public function answerFor(string $asked): ?array
    {
        $asked = self::key($asked);
        if ($asked === '') {
            return null;
        }

        return $this->inArabic(function () use ($asked) {
            if ($asked === self::key(self::MENU) || in_array($asked, array_map(self::key(...), self::MENU_WORDS), true)) {
                return $this->menu();
            }
            if ($asked === self::key(self::PRICES)) {
                return $this->prices();
            }
            if ($asked === self::key(self::START)) {
                return $this->start();
            }
            foreach (SiteModules::groups() as $group => [$label]) {
                if ($asked === self::key($label)) {
                    return $this->group($group);
                }
            }
            foreach (SiteModules::all() as $module) {
                if ($asked === self::key($module['name'])) {
                    return $this->module($module);
                }
            }

            return null;
        });
    }

    public static function isTeam(string $asked): bool
    {
        return self::key($asked) === self::key(self::TEAM);
    }

    /**
     * The main menu, with $intro instead of the usual question (an answer the model could not give, say).
     *
     * @return array{text: string, rows: list<array{title: string, description: string}>}
     */
    public function menu(string $intro = ''): array
    {
        return $this->inArabic(function () use ($intro) {
            $rows = [['title' => self::PRICES, 'description' => 'الباقات وأسعارها والإضافات']];
            foreach (SiteModules::grouped() as $group => $modules) {
                $rows[] = [
                    'title' => SiteModules::groups()[$group][0],
                    'description' => self::cut(implode('، ', array_column($modules, 'name')), 72),
                ];
            }
            $rows[] = ['title' => self::START, 'description' => 'التسجيل والتجربة المجانية'];
            $rows[] = ['title' => self::TEAM, 'description' => 'حد من الفريق هيرد عليك هنا'];

            return ['text' => trim(($intro !== '' ? $intro."\n\n" : '').'اختار الموضوع اللي عايز تعرف عنه من القايمة، أو اكتب سؤالك.'), 'rows' => $rows];
        });
    }

    /** @return array{text: string, rows: list<array{title: string, description: string}>} */
    private function prices(): array
    {
        $lines = ['الباقات (بالجنيه في الشهر، والدفع لـ 6 أو 12 شهر عليه خصم):'];
        foreach (Plan::query()->offered()->where('is_public', true)->orderBy('sort_order')->get() as $plan) {
            $price = match (true) {
                $plan->isContactSales() => 'السعر بيتحدد مع فريق المبيعات حسب الفروع والشاشات',
                $plan->monthly_price <= 0 => 'مجانية على طول',
                default => money($plan->monthly_price).' جنيه في الشهر',
            };
            $lines[] = '• '.$plan->displayName().': '.$price;
        }
        $lines[] = '';
        $lines[] = 'مفيش عمولة على الطلبات في أي باقة. كل باقة وفيها إيه بالظبط، والإضافات وأسعارها: '.self::site('/pricing');

        return ['text' => implode("\n", $lines), 'rows' => $this->next([self::START])];
    }

    /** @return array{text: string, rows: list<array{title: string, description: string}>} */
    private function start(): array
    {
        $text = implode("\n", [
            'تسجّل في دقيقتين من هنا: '.self::site('/register'),
            '• الاسم والإيميل وكلمة سر ورقم موبايلك، ونوع محلك (كافيه، مطعم، الاتنين، أو محل).',
            '• كل مميزات الباقة الكاملة مجانًا '.TenantProvisioner::TRIAL_DAYS.' يوم، من غير كارت.',
            '• بعد التجربة المحل بيرجع للباقة المجانية لو ما اشتركتش، والمنيو بيفضل شغال.',
            '• ممكن تبعتلنا صورة أو PDF للمنيو هنا والفريق يساعدك تدخّله.',
        ]);

        return ['text' => $text, 'rows' => $this->next([self::PRICES])];
    }

    /** @return array{text: string, rows: list<array{title: string, description: string}>} */
    private function group(string $group): array
    {
        $modules = SiteModules::grouped()[$group] ?? [];
        $lines = [SiteModules::groups()[$group][0].':'];
        foreach ($modules as $module) {
            $lines[] = '• '.$module['name'].': '.$module['summary'];
        }
        $lines[] = '';
        $lines[] = 'اختار أي واحدة عشان تعرف تفاصيلها.';
        $rows = array_map(fn (array $m) => ['title' => $m['name'], 'description' => self::cut($m['tagline'], 72)], $modules);

        return ['text' => implode("\n", $lines), 'rows' => [...array_slice($rows, 0, self::MAX_ROWS - 2), ...$this->next([])]];
    }

    /**
     * @param  array<string, mixed>  $module  one of SiteModules::all()
     * @return array{text: string, rows: list<array{title: string, description: string}>}
     */
    private function module(array $module): array
    {
        $lines = [$module['name'].': '.$module['tagline'].'.', ''];
        foreach (array_slice($module['benefits'], 0, 4) as [, $title, $body]) {
            $lines[] = '• '.$title.': '.$body;
        }
        $where = SiteModules::where($module, SiteModules::pricing());
        $lines[] = '';
        if ($where !== '') {
            $lines[] = 'متاحة: '.$where;
        }
        $lines[] = 'التفاصيل: '.self::site('/features/'.$module['slug']);
        $related = array_values(array_filter(array_map(fn (string $slug) => SiteModules::find($slug)['name'] ?? null, $module['related'] ?? [])));

        return ['text' => implode("\n", $lines), 'rows' => $this->next(array_slice($related, 0, 3), [self::PRICES])];
    }

    /**
     * $first, then the prices or what else is given, the main menu and the team.
     *
     * @param  list<string>  $first
     * @param  list<string>  $also
     * @return list<array{title: string, description: string}>
     */
    private function next(array $first, array $also = []): array
    {
        $titles = array_values(array_unique([...$first, ...$also, self::MENU, self::TEAM]));

        return array_map(fn (string $title) => ['title' => $title, 'description' => ''], $titles);
    }

    /** The answer is in Arabic whatever language the queue worker runs in. */
    private function inArabic(\Closure $build): mixed
    {
        $locale = app()->getLocale();
        app()->setLocale('ar');
        try {
            return $build();
        } finally {
            app()->setLocale($locale);
        }
    }

    private static function key(string $text): string
    {
        return mb_strtolower(mb_substr(trim(preg_replace('/\s+/u', ' ', $text)), 0, self::MATCH_LENGTH));
    }

    private static function cut(string $text, int $length): string
    {
        return mb_strlen($text) > $length ? rtrim(mb_substr($text, 0, $length - 1)).'…' : $text;
    }

    private static function site(string $path): string
    {
        return rtrim(platform_url(null, '/'), '/').$path;
    }
}
