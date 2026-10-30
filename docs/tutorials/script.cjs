// The voice-over script of every tutorial, line by line in the order the video says them (title card first, the
// closing card last, a line said twice listed once), without a browser: each scenario runs against a stand-in that
// only notes the captions. Used to hand the script to whoever records the voice, and by voice-split.cjs to match the
// recording to the lines.
//
//   node script.cjs                 → JSON of every scenario, in run-all.sh's order
//   node script.cjs pos-sale        → that one only
const fs = require('fs');
const path = require('path');

const ORDER = fs.readFileSync(path.join(__dirname, 'run-all.sh'), 'utf8').match(/for id in ([\s\S]*?); do/)[1].replace(/\\\n/g, ' ').trim().split(/\s+/);

/** Anything the scenario asks of the page: callable, any property, never a promise (so `await` gets it back). */
const nothing = new Proxy(function () {}, {
    get: (target, key) => (key === 'then' ? undefined : key === Symbol.toPrimitive ? () => '' : nothing),
    apply: () => nothing,
});

function linesOf(id) {
    const scenario = require(path.join(__dirname, 'scenarios', `${id}.cjs`));
    const voice = scenario.voice || {};
    const said = [];
    const say = (text) => {
        if (!text) return;
        const line = Object.prototype.hasOwnProperty.call(voice, text) ? voice[text] : text;
        if (line && !said.includes(line)) said.push(line);
    };
    const t = {
        page: nothing,
        wait: async () => {},
        card: async (title) => say(title),
        say: async (text) => say(text),
        go: async (url, text) => say(text),
        click: async (target, text) => say(text),
        fill: async (target, value, text) => say(text),
        select: async (target, value, text) => say(text),
        point: async (target, text) => say(text),
        phone: async (url, text) => say(text),
        inPhone: () => nothing,
        scroll: async () => {},
        clear: async () => {},
    };

    return (async () => {
        say(scenario.title);
        await scenario.run(t);
        say('خلصنا ✅');

        return { id, title: scenario.title, lines: said };
    })();
}

(async () => {
    const ids = process.argv.length > 2 ? process.argv.slice(2) : ORDER;
    const out = [];
    for (const id of ids) out.push(await linesOf(id));
    process.stdout.write(JSON.stringify(out, null, 2) + '\n');
})();
