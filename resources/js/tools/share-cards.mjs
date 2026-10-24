// Screenshots the share cards for `php artisan egmenu:share-images` (App\Console\Commands\ShareImagesCommand).
// One headless Chrome over the DevTools protocol, no npm packages (Node 22+ has WebSocket built in).
// Usage: node share-cards.mjs <chrome> <jobs.json>   jobs.json: {"width":1200,"height":630,"profile":"dir","jobs":[{"html":"file:///…","png":"…"}]}
import { spawn } from 'node:child_process';
import { readFileSync, writeFileSync } from 'node:fs';

const [chrome, jobsFile] = process.argv.slice(2);
const { width, height, profile, jobs } = JSON.parse(readFileSync(jobsFile, 'utf8'));
const port = 9300 + Math.floor(Math.random() * 500);
const browser = spawn(chrome, ['--headless=new', '--disable-gpu', '--hide-scrollbars', '--no-first-run', '--no-default-browser-check',
    '--disable-extensions', '--disable-component-update', '--disable-background-networking', '--disable-sync',
    `--remote-debugging-port=${port}`, `--user-data-dir=${profile}`, 'about:blank'], { stdio: 'ignore' });
const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

let wsUrl;
for (let i = 0; i < 100 && !wsUrl; i++) {
    await sleep(200);
    try {
        const targets = await (await fetch(`http://127.0.0.1:${port}/json/list`)).json();
        wsUrl = targets.find((target) => target.type === 'page')?.webSocketDebuggerUrl;
    } catch {}
}
if (!wsUrl) {
    console.error('Chrome did not start');
    browser.kill();
    process.exit(1);
}

const ws = new WebSocket(wsUrl);
await new Promise((resolve) => ws.addEventListener('open', resolve));
let id = 0;
const pending = new Map();
const waiters = [];
ws.addEventListener('message', (event) => {
    const message = JSON.parse(event.data);
    if (message.id && pending.has(message.id)) {
        pending.get(message.id)(message);
        pending.delete(message.id);
    }
    if (message.method) {
        waiters.filter((waiter) => waiter.method === message.method).forEach((waiter) => waiter.resolve(message));
    }
});
const send = (method, params = {}) => new Promise((resolve) => {
    const n = ++id;
    pending.set(n, resolve);
    ws.send(JSON.stringify({ id: n, method, params }));
});
const once = (method) => new Promise((resolve) => waiters.push({ method, resolve }));
const evaluate = async (expression) => (await send('Runtime.evaluate', { expression, returnByValue: true })).result?.result?.value;

await send('Page.enable');
await send('Emulation.setDeviceMetricsOverride', { width, height, deviceScaleFactor: 1, mobile: false });

let failed = 0;
for (const job of jobs) {
    const loaded = once('Page.loadEventFired');
    await send('Page.navigate', { url: job.html });
    await Promise.race([loaded, sleep(20000)]);
    // the card sets data-ready once Cairo is loaded and the text is fitted
    let ready = false;
    for (let i = 0; i < 100 && !ready; i++) {
        ready = (await evaluate('document.body && document.body.dataset.ready === "1"')) === true;
        if (!ready) {
            await sleep(100);
        }
    }
    const shot = await send('Page.captureScreenshot', { format: 'png', clip: { x: 0, y: 0, width, height, scale: 1 } });
    if (!ready || !shot.result?.data) {
        failed++;
        console.error(`not ready: ${job.png}`);
        continue;
    }
    writeFileSync(job.png, Buffer.from(shot.result.data, 'base64'));
}

ws.close();
browser.kill();
process.exit(failed === 0 ? 0 : 1);
