// Records EgHub's video tutorials (App\Support\Tutorials): each scenario drives a real browser on the demo restaurant,
// with an Arabic caption bar for every step and a ring around what is about to be tapped, then ffmpeg turns the
// recording into public/tutorials/<id>.mp4.
//
//   1. A local copy with demo data: `php artisan migrate --seed` (DemoSeeder: owner@demo.test / password), the
//      demo-cafe on the Enterprise plan, DemoContent::seed() for sample orders, `php artisan serve`, `npm run build`.
//   2. NODE_PATH=$(npm root -g) OUT=public/tutorials node docs/tutorials/record.cjs docs/tutorials/scenarios/*.cjs
//
// Needs Playwright with Chromium, ffmpeg, and the Cairo font installed for Arabic text. A scenario that fails
// leaves <id>-error.png next to the videos.
const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const BASE = process.env.BASE || 'http://localhost:8000';
const OUT = path.resolve(process.env.OUT || 'out');
const SIZE = { width: 1280, height: 720 };
const USERS = { owner: ['owner@demo.test', 'password'] };

const OVERLAY = () => {
    const css = document.createElement('style');
    css.textContent = `
        #tut-bar { position: fixed; inset-inline: 0; bottom: 0; z-index: 2147483647; padding: 14px 24px; background: rgba(10,37,64,.92);
            color: #fff; font: 700 24px/1.5 Cairo, sans-serif; text-align: center; direction: rtl; transition: opacity .25s; }
        #tut-bar:empty { opacity: 0; }
        #tut-ring { position: fixed; z-index: 2147483646; border: 4px solid #F26522; border-radius: 14px; pointer-events: none;
            box-shadow: 0 0 0 9999px rgba(10,37,64,.18); transition: all .35s ease; opacity: 0; }
        #tut-card { position: fixed; inset: 0; z-index: 2147483647; display: grid; place-items: center; background: #0A2540; color: #fff;
            font: 800 44px/1.5 Cairo, sans-serif; text-align: center; direction: rtl; padding: 40px; }
        #tut-card small { display: block; font-size: 24px; font-weight: 600; color: #F7B48C; }`;
    const add = () => {
        if (document.getElementById('tut-bar')) return;
        document.head.appendChild(css);
        for (const id of ['tut-bar', 'tut-ring']) {
            const el = document.createElement('div');
            el.id = id;
            document.body.appendChild(el);
        }
        const saved = sessionStorage.getItem('tut-caption');
        if (saved) document.getElementById('tut-bar').textContent = saved;
    };
    document.readyState === 'loading' ? document.addEventListener('DOMContentLoaded', add) : add();
};

function tutor(page) {
    const wait = (ms) => page.waitForTimeout(ms);
    const caption = async (text) => {
        await page.evaluate((t) => {
            sessionStorage.setItem('tut-caption', t);
            const bar = document.getElementById('tut-bar');
            if (bar) bar.textContent = t;
        }, text);
    };
    const ring = async (locator) => {
        const box = await locator.boundingBox();
        if (!box) return;
        await page.evaluate((b) => {
            const r = document.getElementById('tut-ring');
            if (!r) return;
            Object.assign(r.style, { left: `${b.x - 6}px`, top: `${b.y - 6}px`, width: `${b.width + 12}px`, height: `${b.height + 12}px`, opacity: 1 });
            // the caption leaves the bottom when what it points at is down there
            const bar = document.getElementById('tut-bar');
            if (bar) Object.assign(bar.style, b.y + b.height > innerHeight - 110 ? { top: '0', bottom: 'auto' } : { top: 'auto', bottom: '0' });
        }, box);
    };
    const unring = () => page.evaluate(() => { const r = document.getElementById('tut-ring'); if (r) r.style.opacity = 0; });
    const find = (target) => (typeof target === 'string' ? page.locator(target).first() : target(page));

    return {
        page,
        wait,
        /** A full-screen title card. */
        async card(title, subtitle = '') {
            await page.evaluate(([t, s]) => {
                const c = document.createElement('div');
                c.id = 'tut-card';
                c.innerHTML = `<div>${t}${s ? `<small>${s}</small>` : ''}</div>`;
                document.body.appendChild(c);
            }, [title, subtitle]);
            await wait(2800);
            await page.evaluate(() => document.getElementById('tut-card')?.remove());
        },
        async say(text, ms = 2600) {
            await caption(text);
            await wait(ms);
        },
        async go(url, text) {
            if (text) await page.evaluate((t) => sessionStorage.setItem('tut-caption', t), text).catch(() => {});
            await page.goto(url.startsWith('http') ? url : BASE.replace('://', `://${url.startsWith('app:') ? 'app.' : ''}`) + url.replace(/^app:/, ''));
            await wait(text ? 2400 : 900);
        },
        /** Shows the caption, rings the element, then taps it. */
        async click(target, text, after = 1400) {
            const el = find(target);
            await el.scrollIntoViewIfNeeded();
            if (text) await caption(text);
            await ring(el);
            await wait(text ? 1700 : 700);
            await el.click();
            await unring();
            await wait(after);
        },
        async fill(target, value, text, after = 900) {
            const el = find(target);
            await el.scrollIntoViewIfNeeded();
            if (text) await caption(text);
            await ring(el);
            await wait(text ? 1200 : 500);
            // date and time boxes are typed per segment in the page's locale: set them whole
            if (['date', 'time', 'datetime-local'].includes(await el.getAttribute('type'))) {
                await el.fill(String(value));
            } else {
                await el.fill('');
                await el.pressSequentially(String(value), { delay: 70 });
            }
            await unring();
            await wait(after);
        },
        async select(target, value, text, after = 900) {
            const el = find(target);
            if (text) await caption(text);
            await ring(el);
            await wait(1200);
            await el.selectOption(value);
            await unring();
            await wait(after);
        },
        /** Rings something without tapping it. */
        async point(target, text, ms = 2600) {
            const el = find(target);
            await el.scrollIntoViewIfNeeded();
            await caption(text);
            await ring(el);
            await wait(ms);
            await unring();
        },
        clear: () => caption(''),
    };
}

async function login(browser, who) {
    const page = await browser.newPage({ viewport: SIZE });
    await page.goto(BASE.replace('://', '://app.') + '/login');
    await page.fill('input[name=login]', USERS[who][0]);
    await page.fill('input[name=password]', USERS[who][1]);
    await page.press('input[name=password]', 'Enter');
    await page.waitForURL((u) => !u.pathname.startsWith('/login'));
    const state = await page.context().storageState();
    await page.close();
    return state;
}

(async () => {
    fs.mkdirSync(OUT, { recursive: true });
    const browser = await chromium.launch({ args: ['--no-proxy-server'] });
    for (const file of process.argv.slice(2)) {
        const scenario = require(path.resolve(file));
        const state = scenario.as ? await login(browser, scenario.as) : undefined;
        if (scenario.prepare) await scenario.prepare();
        const context = await browser.newContext({ viewport: SIZE, locale: 'ar-EG', storageState: state, recordVideo: { dir: path.join(OUT, 'raw'), size: SIZE } });
        await context.addInitScript(OVERLAY);
        const page = await context.newPage();
        page.on('dialog', (d) => setTimeout(() => d.accept(scenario.promptAnswer || undefined).catch(() => {}), 1200));
        const t = tutor(page);
        try {
            await page.goto(BASE.replace('://', `://${scenario.start?.startsWith('app:') ? 'app.' : ''}`) + (scenario.start || 'app:/').replace(/^app:/, ''));
            await t.card(scenario.title, scenario.subtitle || 'شرح EgHub');
            await scenario.run(t);
            await t.clear();
            // a modal <dialog> sits above everything: close it so the closing card shows
            await page.evaluate(() => document.querySelectorAll('dialog[open]').forEach((d) => d.close()));
            await t.card('خلصنا ✅', scenario.title);
        } catch (e) {
            await page.screenshot({ path: path.join(OUT, `${scenario.id}-error.png`) });
            console.error(`✗ ${scenario.id}:`, e.message.split('\n')[0]);
        }
        const video = page.video();
        await context.close();
        const raw = await video.path();
        const mp4 = path.join(OUT, `${scenario.id}.mp4`);
        execFileSync('ffmpeg', ['-y', '-loglevel', 'error', '-i', raw, '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '28', '-pix_fmt', 'yuv420p', '-movflags', '+faststart', mp4]);
        fs.rmSync(raw);
        console.log(`✓ ${scenario.id} → ${mp4} (${(fs.statSync(mp4).size / 1e6).toFixed(1)} MB)`);
    }
    await browser.close();
})();
