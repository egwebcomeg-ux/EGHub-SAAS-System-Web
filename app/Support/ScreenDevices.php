<?php

namespace App\Support;

/**
 * The TVs and devices EgHub screens play on, and how to set each one up: the catalogue behind «نوع الشاشة» on the
 * pairing page, the screen's own help page in the dashboard and the public page /supported-screens.
 *
 * Each device has a recommended way to open the player (method) with its numbered steps, an optional alternative,
 * how to keep it on all day (awake), what happens after a power cut (power: yes / partial / no) and its limits.
 * Text is English here (translation keys, Arabic in lang/ar.json); a menu name goes between [[ ]] and shows as a
 * left-to-right label (format()). Checked against the makers' support pages in October 2026; brand names are plain
 * text, never logos.
 */
final class ScreenDevices
{
    /** The tile for an owner who does not know the type: identification tips, no steps. */
    public const UNKNOWN = 'unknown';

    /** Ways to open the player, best first. */
    public const METHODS = ['app', 'browser', 'signage', 'kiosk', 'stick'];

    /**
     * Every device, in the order the picker shows them.
     *
     * @return array<string, array{key: string, name: string, label: string, group: string, search: string, method: string, steps: list<string>, alt: ?string, alt_steps: list<string>, awake: list<string>, power: string, power_text: string, notes: list<string>, supported: bool}>
     */
    public static function all(): array
    {
        $devices = [
            // ---------- smart TVs ----------
            'samsung' => [
                'name' => 'Samsung', 'label' => __('Smart TV · Tizen'), 'group' => 'tv', 'search' => 'سامسونج samsung tizen',
                'method' => 'browser',
                'steps' => [
                    __('Press Home on the remote and open the [[Internet]] app (from [[Apps]] if it is not on the bar).'),
                    __('Type [[eghub.app/tv]] in the address bar and press Enter: a 6-digit code shows.'),
                    self::pairStep(),
                    __('Leave the browser on this page and do not open other apps on this TV.'),
                ],
                'alt' => 'stick', 'alt_steps' => self::stickSteps(),
                'awake' => [
                    __('2025 models and newer: [[Settings > All Settings > General & Privacy > Power and Energy Saving]], turn off [[Auto Power Saving]] and [[Auto Power Off]].'),
                    __('Older models: [[Settings > General > Eco Solution]], turn off [[Auto Power Off]] (2014 models call it [[No Signal Power Off]]).'),
                    __('Check that [[Sleep Timer]] and [[Off Timer]] are off.'),
                ],
                'power' => 'no',
                'power_text' => __('Not possible: after a power cut the TV comes back off, and the browser does not open by itself. Open [[Internet]] again; the screen remembers its pairing, so no new code is needed.'),
                'notes' => [
                    __('Samsung TVs do not install Android apps, so the EgHub app does not run on them: the browser is the way.'),
                    __('The browser may close itself when the TV memory fills, more often with long videos. If it happens, use pictures instead of long videos, or a TV box.'),
                    __('Models from before 2017 have an old browser. If the page does not play well, use a TV box.'),
                ],
            ],
            'samsung-signage' => [
                'name' => 'Samsung', 'label' => __('Smart Signage or Business TV'), 'group' => 'tv', 'search' => 'سامسونج شاشة عرض signage business tv magicinfo url launcher',
                'method' => 'signage',
                'steps' => [
                    __('Open the display menu: [[Menu > System > Play via]] and choose [[URL Launcher]].'),
                    __('From Home open [[URL Launcher Settings > Install Web App]] and type [[https://eghub.app/tv]] in small letters.'),
                    __('Restart the display: it opens the page by itself and shows a 6-digit code.'),
                    self::pairStep(),
                ],
                'alt' => 'stick', 'alt_steps' => self::stickSteps(),
                'awake' => [
                    __('[[System > Power Control]]: turn off [[Auto Power Off]] and [[Max. Power Saving]].'),
                    __('Use the display\'s on and off timer if you want it to sleep at night.'),
                ],
                'power' => 'yes',
                'power_text' => __('Possible: the display opens the URL Launcher page by itself when it starts. Turn on [[Auto Power On]] in [[System > Power Control]] so it turns on when the power returns.'),
                'notes' => [
                    __('Menu names change a little between models and firmware. If the display runs MagicINFO, switch it to URL Launcher first.'),
                ],
            ],
            'lg' => [
                'name' => 'LG', 'label' => __('Smart TV · webOS'), 'group' => 'tv', 'search' => 'ال جي lg webos magic',
                'method' => 'browser',
                'steps' => [
                    __('Press Home on the remote and open [[Web Browser]].'),
                    __('Type [[eghub.app/tv]] in the address bar and press Enter: a 6-digit code shows.'),
                    self::pairStep(),
                    __('Leave the browser on this page and do not open other apps on this TV.'),
                ],
                'alt' => 'stick', 'alt_steps' => self::stickSteps(),
                'awake' => [
                    __('[[Settings > All Settings > General > Energy Saving]]: set [[Auto Power Off]] and [[Energy Saving Step]] to Off, and do not use [[Screen Off]].'),
                    __('2021 and 2022 models: also [[Support > OLED Care > Device Self Care > Energy Saving Step]] to Off.'),
                    __('Check that the [[Sleep Timer]] and [[Timer Power Off]] are off.'),
                ],
                'power' => 'no',
                'power_text' => __('Not possible: after a power cut the TV comes back off, and the browser does not open by itself. Open [[Web Browser]] again; the screen remembers its pairing, so no new code is needed.'),
                'notes' => [
                    __('LG says its TV browser is limited and may restart itself when memory runs short.'),
                    __('LG TVs from before 2017 (webOS 3.5) no longer support current web standards: use a TV box with them.'),
                    __('LG TVs do not install Android apps, so the EgHub app does not run on them.'),
                ],
            ],
            'lg-signage' => [
                'name' => 'LG', 'label' => __('webOS Signage display'), 'group' => 'tv', 'search' => 'ال جي شاشة عرض lg signage webos',
                'method' => 'stick',
                'steps' => array_merge(self::stickSteps(), [
                    __('Hold [[Settings]] on the remote for 5 seconds to open the installation menu (password [[0000]]) and set [[Power On Status]] to [[LST]], so the display turns back on after a power cut.'),
                ]),
                'alt' => 'browser', 'alt_steps' => [
                    __('If the display has a [[Web Browser]] app, open it and type [[eghub.app/tv]]: a 6-digit code shows.'),
                    self::pairStep(),
                ],
                'awake' => [
                    __('In the installation menu turn off [[No Signal Power Off]], [[No IR Power Off]] and [[DPM]].'),
                ],
                'power' => 'yes',
                'power_text' => __('Possible: with [[Power On Status]] on [[LST]] the display turns on by itself, and a box with the EgHub app opens the player when it starts.'),
                'notes' => [
                    __('LG signage plays its own app packages from an SI server, not a plain link; a TV box on HDMI is the simplest way.'),
                ],
            ],
            'sony' => self::androidTv('Sony', __('BRAVIA · Google TV or Android TV'), 'سوني sony bravia', [
                __('[[Settings > System > Power and energy > Energy saver]]: set [[Turn off TV after]] to Never when it shows.'),
                __('Turn off [[Idle TV standby]], [[Idle TV power off]] and the [[Sleep timer]].'),
            ], [
                __('BRAVIA Professional displays (BZ) have Pro mode: set [[AC power on]] to Always on and [[Initial input source]] to the EgHub app. That is the best setup for a screen that runs all day.'),
            ]),
            'xiaomi-tv' => self::androidTv('Xiaomi', __('Xiaomi TV or Mi TV · Google TV'), 'شاومي شاومى xiaomi mi tv redmi', [
                __('[[Settings > System > Power & Energy > Energy saver]]: choose Never, or the longest time it offers.'),
            ]),
            'tcl' => self::androidTv('TCL', __('Google TV or Android TV'), 'تي سي ال tcl', [self::googleEnergySaver()], [
                __('TCL Roku TV models (sold mostly in the US) have no browser and no Google Play: use a TV box with them.'),
            ]),
            'hisense' => self::vidaa('Hisense', 'هايسنس hisense vidaa', __('Hisense TVs with Google TV (Google Play on the home screen) use the EgHub app: choose «Another TV with Google Play».')),
            'toshiba' => self::vidaa('Toshiba', 'توشيبا toshiba vidaa العربي', __('Toshiba TVs sold in Egypt mostly run VIDAA. If yours has Google Play on the home screen, choose «Another TV with Google Play»; a Toshiba Fire TV Edition follows the Fire TV steps.')),
            'philips' => self::androidTv('Philips', __('Android TV or Google TV'), 'فيليبس philips', [self::googleEnergySaver()], [
                __('Newer Philips TVs with Titan OS and older ones with Saphi do not install apps: use a TV box with them.'),
            ]),
            'sharp' => self::androidTv('Sharp', __('Android TV or Google TV'), 'شارب sharp aquos العربي', [self::googleEnergySaver()]),
            'haier' => self::androidTv('Haier', __('Android TV or Google TV'), 'هاير haier', [self::googleEnergySaver()]),
            'unionaire' => self::androidTv('Unionaire', __('Android TV or Google TV'), 'يونيون اير يونيونير unionaire', [self::googleEnergySaver()]),
            'tornado' => self::androidTv('Tornado', __('Android TV or Google TV'), 'تورنيدو تورنادو tornado', [self::googleEnergySaver()]),
            'local' => self::androidTv(__('Other local brands'), __('Jac, ATA, Fresh and others'), 'جاك فريش اتا وايت بوينت jac ata fresh white point local', [self::googleEnergySaver()], [
                __('No Google Play on the TV: on a computer download [[eghub.app/tv.apk]] to a USB flash drive, plug it into the TV and open it from the file manager to install.'),
                __('Some local TVs run a simple Linux system with no app store. If yours has no store and no browser, use a TV box.'),
            ]),
            'huawei' => [
                'name' => 'Huawei', 'label' => __('Vision · HarmonyOS'), 'group' => 'tv', 'search' => 'هواوي huawei vision harmonyos',
                'method' => 'app',
                'steps' => [
                    __('On a computer download [[eghub.app/tv.apk]] and copy it to a USB flash drive.'),
                    __('Plug the flash drive into the TV, open it from the file manager and choose Install (allow installing from this source when the TV asks).'),
                    __('Open «EgHub Screens»: a 6-digit code shows on the TV.'),
                    self::pairStep(),
                ],
                'alt' => 'stick', 'alt_steps' => self::stickSteps(),
                'awake' => [__('The EgHub app keeps the screen on while it plays. Turn off any sleep or energy saving timer in the TV settings.')],
                'power' => 'partial',
                'power_text' => self::appPowerText(),
                'notes' => [__('Huawei says Vision runs most Android apps, without promising every one.')],
            ],
            'android-tv' => self::androidTv(__('Another TV with Google Play'), __('Any Android TV or Google TV'), 'اندرويد جوجل android google tv play store اخرى', [self::googleEnergySaver()]),

            // ---------- boxes and sticks ----------
            'xiaomi-box' => self::androidBox('Xiaomi', __('TV Box S, Mi Box S or TV Stick 4K'), 'شاومي بوكس xiaomi mi box s tv box stick'),
            'chromecast' => self::androidBox('Google', __('Chromecast with Google TV or Google TV Streamer'), 'جوجل كروم كاست chromecast google tv streamer'),
            'fire-tv' => [
                'name' => 'Amazon', 'label' => __('Fire TV Stick or Fire TV Cube'), 'group' => 'box', 'search' => 'امازون فاير amazon fire tv stick cube firestick',
                'method' => 'app',
                'steps' => [
                    __('Open the [[Amazon Appstore]] (or search from Home), find [[Downloader]] by AFTVnews and install it.'),
                    __('Open [[Settings > My Fire TV > About]] and press OK 7 times on the first line to show [[Developer options]].'),
                    __('Open [[Settings > My Fire TV > Developer options > Install unknown apps]] and turn on [[Downloader]].'),
                    __('Open Downloader, type [[eghub.app/tv.apk]] and press Go, then choose Install.'),
                    __('Open «EgHub Screens»: a 6-digit code shows on the TV.'),
                    self::pairStep(),
                ],
                'alt' => 'browser', 'alt_steps' => [
                    __('Install [[Silk Browser]] from the Appstore, open it and type [[eghub.app/tv]]: a 6-digit code shows.'),
                    self::pairStep(),
                ],
                'awake' => [
                    __('[[Settings > Display & Sounds > Screensaver]]: set [[Start Time]] to Never.'),
                    __('Power the stick from its own adapter in the wall, not from the TV\'s USB port.'),
                ],
                'power' => 'partial',
                'power_text' => self::appPowerText(),
                'notes' => [
                    __('Fire TV Stick 4K Select, the new Fire TV Stick HD (2026) and Fire TV Stick 4K (3rd gen) run Vega OS, which does not install Android apps: the EgHub app does not run on them. Choose a Fire TV Stick 4K Max or a Google TV box instead.'),
                ],
            ],
            'android-box' => self::androidBox(__('Android TV box'), __('Any Android box on HDMI'), 'بوكس اندرويد ريسيفر android box tv box h96 x96 mxq'),

            // ---------- others ----------
            'pc' => [
                'name' => __('Computer'), 'label' => __('PC, mini PC or Raspberry Pi'), 'group' => 'other', 'search' => 'كمبيوتر لابتوب ميني راسبيري pc computer laptop windows raspberry pi chrome',
                'method' => 'kiosk',
                'steps' => [
                    __('Install Google Chrome and connect the computer to the TV with HDMI.'),
                    __('Windows: make a Chrome shortcut and add [[--kiosk https://eghub.app/tv]] at the end of its target, then put the shortcut in the Startup folder ([[Win+R > shell:startup]]).'),
                    __('Raspberry Pi: add [[chromium https://eghub.app/tv --kiosk --noerrdialogs --disable-infobars --no-first-run &]] to [[~/.config/labwc/autostart]].'),
                    __('Restart: the page opens full screen and shows a 6-digit code.'),
                    self::pairStep(),
                ],
                'alt' => null, 'alt_steps' => [],
                'awake' => [
                    __('Windows: [[Settings > System > Power]], set the screen and sleep to Never.'),
                    __('Turn off the screen saver, and on the TV turn off its own energy saving.'),
                ],
                'power' => 'yes',
                'power_text' => __('Possible: set [[Restore on AC Power Loss]] to Power On in the computer BIOS. Chrome then opens the screen by itself at sign-in (turn on automatic sign-in). A Raspberry Pi starts by itself when the power returns.'),
                'notes' => [__('Press Alt+F4 to leave the full screen.')],
            ],
            'tablet' => [
                'name' => __('Tablet or phone'), 'label' => __('Android, iPad or iPhone on the wall'), 'group' => 'other', 'search' => 'تابلت موبايل ايباد تليفون tablet phone ipad iphone android',
                'method' => 'browser',
                'steps' => [
                    __('Android: install «EgHub Screens» from [[eghub.app/tv.apk]], or open [[eghub.app/tv]] in Chrome.'),
                    __('iPad or iPhone: open [[eghub.app/tv]] in Safari.'),
                    __('A 6-digit code shows.'),
                    self::pairStep(),
                ],
                'alt' => null, 'alt_steps' => [],
                'awake' => [
                    __('Keep it on the charger.'),
                    __('Android: turn on [[Developer options > Stay awake]] and pin the app with [[Settings > Security > App pinning]].'),
                    __('iPad: [[Settings > Accessibility > Guided Access]] on, with [[Display Auto-Lock]] set to Never, then triple-click to start it.'),
                ],
                'power' => 'partial',
                'power_text' => __('On the charger the tablet keeps running through a short power cut. If its battery runs out, open the screen again.'),
                'notes' => [],
            ],
            'apple-tv' => self::unsupported('Apple TV', 'tvOS', 'ابل apple tv', __('Apple TV has no web browser and does not install our app. AirPlay from an iPad or a Mac works only while that device stays on and near the TV.')),
            'roku' => self::unsupported('Roku', 'Roku OS', 'روكو roku', __('Roku has no web browser and does not install our app.')),
            'not-smart' => [
                'name' => __('A normal TV'), 'label' => __('Not smart, or very old'), 'group' => 'other', 'search' => 'عادي مش سمارت قديم normal not smart old hdmi',
                'method' => 'stick',
                'steps' => self::stickSteps(),
                'alt' => 'kiosk', 'alt_steps' => [__('Or connect a small computer with HDMI and follow the computer steps.')],
                'awake' => [__('Turn off the TV\'s own sleep and energy saving timers.')],
                'power' => 'partial',
                'power_text' => __('The box starts by itself and opens the EgHub app when the power returns; most TVs come back in standby, and HDMI-CEC can wake them.'),
                'notes' => [
                    __('Any TV with an HDMI port works with a box or stick. In Egypt look for Xiaomi TV Box S (3rd gen), Xiaomi TV Stick 4K, Google TV Streamer or Chromecast with Google TV, or an Android TV box from a known shop.'),
                    __('Avoid Fire TV sticks named «4K Select», «HD (2026)» or «4K (3rd gen)»: they run Vega OS and do not install the EgHub app.'),
                ],
            ],
        ];

        // brands sold in Egypt whose smart models are mostly, not all, Android TV / Google TV: how to check, and what to
        // do with a "smart" model that is not Android
        foreach (['xiaomi-tv', 'tcl', 'sharp', 'haier', 'unionaire', 'tornado', 'local'] as $key) {
            $devices[$key]['notes'] = array_merge([
                __('To check: open [[Settings > About]] (or [[Settings > Device Preferences > About]]). If it says Android TV or Google TV, or Google Play is on the TV, it is Android and the steps above work.'),
                __('A «smart» model that is not Android: open [[eghub.app/tv]] in the TV browser, or plug a cheap Android TV box into HDMI.'),
            ], $devices[$key]['notes']);
        }

        $out = [];
        foreach ($devices as $key => $device) {
            $out[$key] = ['key' => $key, 'supported' => $device['supported'] ?? true] + $device;
        }

        return $out;
    }

    /** @return array<string, mixed>|null */
    public static function find(?string $key): ?array
    {
        return $key === null ? null : (self::all()[$key] ?? null);
    }

    /** @return list<string> every device key, for validation */
    public static function keys(): array
    {
        return array_keys(self::all());
    }

    /** @return array<string, string> group => heading, in picker order */
    public static function groups(): array
    {
        return ['tv' => __('Smart TVs'), 'box' => __('TV boxes and sticks'), 'other' => __('Computers, tablets and others')];
    }

    public static function methodLabel(string $method): string
    {
        return [
            'app' => __('The EgHub Screens app'),
            'browser' => __('The browser on the screen'),
            'signage' => __('The display\'s URL Launcher'),
            'kiosk' => __('Chrome in full screen'),
            'stick' => __('A TV box or stick on HDMI'),
        ][$method] ?? $method;
    }

    public static function powerLabel(string $power): string
    {
        return ['yes' => __('Turns on by itself'), 'partial' => __('Partly'), 'no' => __('Needs someone to open it')][$power] ?? $power;
    }

    /** Short name for the screen card: «Samsung · Smart TV · Tizen». */
    public static function title(?string $key): ?string
    {
        $device = self::find($key);

        return $device ? $device['name'].' · '.$device['label'] : null;
    }

    /**
     * For «مش عارف»: how to tell what the screen is from what it shows.
     *
     * @return list<string>
     */
    public static function identifyTips(): array
    {
        return [
            __('Press Home on the remote. If Google Play or «Google TV» shows, it is Android TV or Google TV: choose its brand, or «Another TV with Google Play».'),
            __('An app called [[Internet]] and a bar of apps at the bottom: it is a Samsung (Tizen).'),
            __('A remote with a pointer like a mouse and an app called [[Web Browser]]: it is an LG (webOS).'),
            __('The word VIDAA on the home screen: it is a Hisense or Toshiba with VIDAA.'),
            __('No app store at all, only inputs like HDMI 1 and HDMI 2: choose «A normal TV» and use a TV box.'),
            __('The brand is written on the front of the TV, and the label on the back gives the model.'),
        ];
    }

    /**
     * The device type a pairing device most likely is, from its user agent (the EgHub app adds "EgHubScreens").
     * Null when nothing matches: the owner picks.
     */
    public static function suggest(?string $userAgent): ?string
    {
        $ua = (string) $userAgent;
        if ($ua === '') {
            return null;
        }
        $rules = [
            'samsung' => '/Tizen/i',
            'lg' => '/Web0S|webOS|NetCast/i',
            'fire-tv' => '/\bAFT[A-Z0-9]+|Fire ?TV|Silk\//i',
            'chromecast' => '/Chromecast|Google TV Streamer|\bsabrina\b|\bboreal\b/i',
            'xiaomi-box' => '/MIBOX|Mi ?Box|TV Box S|MiTV-AFKR|Xiaomi TV Stick|MiTV-MSSP/i',
            'xiaomi-tv' => '/MiTV|Xiaomi|Redmi TV/i',
            'sony' => '/BRAVIA|Sony/i',
            'philips' => '/Philips|TPVision/i',
            'tcl' => '/\bTCL\b|SmartTV Pro/i',
            'hisense' => '/VIDAA|Hisense/i',
            'huawei' => '/HarmonyOS|HUAWEI|HONOR ?Vision/i',
            'haier' => '/Haier/i',
            'unionaire' => '/Unionaire/i',
            'tornado' => '/Tornado/i',
            'roku' => '/Roku/i',
            'apple-tv' => '/AppleTV|tvOS/i',
            'tablet' => '/iPad|iPhone/i',
            'android-box' => '/EgHubScreens|Android TV|\bTV\b|BOX|\bATV\b/i',
        ];
        $android = (bool) preg_match('/Android/i', $ua);
        foreach ($rules as $key => $pattern) {
            if (! preg_match($pattern, $ua)) {
                continue;
            }
            if ($key === 'android-box' && ! $android && ! str_contains($ua, 'EgHubScreens')) {
                continue;
            }

            return $key;
        }
        if ($android) {
            return 'tablet';
        }
        if (preg_match('/Windows NT|Macintosh|X11|CrOS|Linux (x86_64|aarch64|armv7l)/i', $ua)) {
            return 'pc';
        }

        return null;
    }

    /** A step's text as HTML: escaped, with each [[menu name]] as a left-to-right label. */
    public static function format(string $text): string
    {
        return preg_replace_callback('/\[\[(.+?)\]\]/u', fn (array $m) => '<b dir="ltr" class="whitespace-normal font-bold text-navy-900">'.str_replace('&gt;', '›', $m[1]).'</b>', e($text));
    }

    /** The text without [[ ]] marks, for meta descriptions and structured data. */
    public static function plain(string $text): string
    {
        return preg_replace('/\[\[(.+?)\]\]/u', '$1', $text);
    }

    /** The Google Play listing of the EgHub Screens app, once it is published (null until then: the APK steps show). */
    public static function playUrl(): ?string
    {
        return config('services.eghub_screens.play_url') ?: null;
    }

    private static function pairStep(): string
    {
        return __('Type that code in the dashboard under «Pair a TV», or scan the QR on the TV with your phone.');
    }

    /** @return list<string> installing the EgHub app on an Android TV / Google TV device with Google Play */
    private static function appSteps(): array
    {
        $open = __('Open «EgHub Screens»: a 6-digit code shows on the TV.');
        if (self::playUrl()) {
            return [__('Open Google Play on the TV, search for «EgHub Screens» and install it.'), $open, self::pairStep()];
        }

        return [
            __('Open Google Play on the TV, search for [[Downloader]] by AFTVnews and install it.'),
            __('Allow it to install apps: [[Settings > Apps > Security & restrictions > Unknown sources]] and turn on Downloader. On Google TV first press [[Settings > System > About > Android TV OS build]] 7 times.'),
            __('Open Downloader, type [[eghub.app/tv.apk]] and press Go, then choose Install.'),
            $open,
            self::pairStep(),
        ];
    }

    /** @return list<string> */
    private static function stickSteps(): array
    {
        return [
            __('Get a Google TV box or stick (for example Xiaomi TV Box S or Google TV Streamer) and plug it into an HDMI port on the TV.'),
            __('Power it from its own adapter in the wall, not from the TV\'s USB port, so it stays on.'),
            __('Choose the box in «TV boxes and sticks» to see how to install the EgHub app on it.'),
            __('On the TV turn on HDMI-CEC ([[Anynet+]] on Samsung, [[SIMPLINK]] on LG) so the box can wake the TV.'),
        ];
    }

    private static function googleEnergySaver(): string
    {
        return __('[[Settings > System > Energy saver]] (or [[Power & Energy > Energy saver]]): set [[Turn off display]] to Never. Google says apps cannot stop the energy saver, so this setting matters.');
    }

    private static function appPowerText(): string
    {
        return __('Partly: the EgHub app opens by itself when the device starts, once you allow it. Hold OK on the remote for 2 seconds to open the app settings and allow [[Display over other apps]]. Most TVs come back in standby after a power cut and need the remote, unless HDMI-CEC wakes them.');
    }

    /**
     * @param  list<string>  $awake
     * @param  list<string>  $notes
     * @return array<string, mixed>
     */
    private static function androidTv(string $name, string $label, string $search, array $awake, array $notes = []): array
    {
        return [
            'name' => $name, 'label' => $label, 'group' => 'tv', 'search' => $search,
            'method' => 'app', 'steps' => self::appSteps(),
            'alt' => 'browser', 'alt_steps' => [
                __('If the TV has a browser, open it and type [[eghub.app/tv]]. The app is steadier: it keeps the screen on and opens by itself.'),
                self::pairStep(),
            ],
            'awake' => array_merge([__('The EgHub app keeps the screen on while it plays.')], $awake),
            'power' => 'partial', 'power_text' => self::appPowerText(),
            'notes' => $notes,
        ];
    }

    /** @return array<string, mixed> */
    private static function androidBox(string $name, string $label, string $search): array
    {
        return [
            'name' => $name, 'label' => $label, 'group' => 'box', 'search' => $search,
            'method' => 'app',
            'steps' => array_merge([__('Plug the box into the TV with HDMI and power it from its own adapter in the wall, not from the TV\'s USB port.')], self::appSteps()),
            'alt' => 'browser', 'alt_steps' => [
                __('If the box has Chrome or another browser, open [[eghub.app/tv]] in it.'),
                self::pairStep(),
            ],
            'awake' => [
                __('The EgHub app keeps the screen on while it plays.'),
                self::googleEnergySaver(),
                __('Older Android boxes: set the screen saver ([[Daydream]]) to Never.'),
                __('On the TV turn on HDMI-CEC ([[Anynet+]] on Samsung, [[SIMPLINK]] on LG) so the box can wake the TV.'),
            ],
            'power' => 'partial', 'power_text' => self::appPowerText(),
            'notes' => [],
        ];
    }

    /** @return array<string, mixed> a TV on VIDAA: no apps, a browser of its own we could not test on every model */
    private static function vidaa(string $name, string $search, string $note): array
    {
        return [
            'name' => $name, 'label' => __('VIDAA'), 'group' => 'tv', 'search' => $search,
            'method' => 'stick', 'steps' => self::stickSteps(),
            'alt' => 'browser', 'alt_steps' => [
                __('Open [[Browser]] from the home screen and type [[eghub.app/tv]]. VIDAA\'s browser differs from model to model; if the page does not play well, use a box.'),
                self::pairStep(),
            ],
            'awake' => [__('Turn off [[Auto Power Off]] and the [[Sleep Timer]] in the TV settings.')],
            'power' => 'partial',
            'power_text' => __('The box starts by itself and opens the EgHub app when the power returns; most TVs come back in standby, and HDMI-CEC can wake them.'),
            'notes' => [__('VIDAA TVs do not install Android apps.'), $note],
        ];
    }

    /** @return array<string, mixed> */
    private static function unsupported(string $name, string $label, string $search, string $why): array
    {
        return [
            'name' => $name, 'label' => $label, 'group' => 'other', 'search' => $search, 'supported' => false,
            'method' => 'stick', 'steps' => self::stickSteps(), 'alt' => null, 'alt_steps' => [],
            'awake' => [], 'power' => 'partial',
            'power_text' => __('The box starts by itself and opens the EgHub app when the power returns; most TVs come back in standby, and HDMI-CEC can wake them.'),
            'notes' => [$why],
        ];
    }
}
