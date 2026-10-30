// A person's recording of a tutorial's script (script.cjs) as that tutorial's voice-over: the recording is cleaned
// (low hum cut, noise reduced, loudness evened), split at the pauses between lines, and each line saved where
// record.cjs looks for its voice, so recording the tutorial with the same VOICE uses these clips instead of a
// synthetic voice. Read the lines in order with a pause of about two seconds between them.
//
//   VOICE=owner node voice-split.cjs pos-sale ~/recordings/06-pos-sale.m4a
//   VOICE=owner OUT=... node record.cjs scenarios/pos-sale.cjs
//
// When the pauses found don't match the number of lines, nothing is saved and the parts found are listed with
// their times: re-record, or set SILENCE_DB / SILENCE_SECONDS (default -38 dB for 1.2 s).
const fs = require('fs');
const os = require('os');
const path = require('path');
const crypto = require('crypto');
const { execFileSync, spawnSync } = require('child_process');

const [id, recording] = process.argv.slice(2);
if (!id || !recording) {
    console.error('usage: VOICE=owner node voice-split.cjs <tutorial id> <recording>');
    process.exit(1);
}
const VOICE = process.env.VOICE || 'owner';
const RATE = process.env.VOICE_RATE || '+0%';
const CACHE = path.resolve(process.env.VOICE_CACHE || path.join(__dirname, '.voice'));
const SILENCE_DB = process.env.SILENCE_DB || '-38';
const SILENCE_SECONDS = process.env.SILENCE_SECONDS || '1.2';

const lines = JSON.parse(execFileSync('node', [path.join(__dirname, 'script.cjs'), id], { encoding: 'utf8' }))[0].lines;

const work = fs.mkdtempSync(path.join(os.tmpdir(), 'voice-'));
const clean = path.join(work, 'clean.wav');
execFileSync('ffmpeg', ['-y', '-loglevel', 'error', '-i', recording, '-ac', '1', '-ar', '44100',
    '-af', 'highpass=f=80,afftdn=nf=-25,loudnorm=I=-16:TP=-1.5:LRA=11', clean]);

const duration = parseFloat(execFileSync('ffprobe', ['-v', 'error', '-show_entries', 'format=duration', '-of', 'csv=p=0', clean], { encoding: 'utf8' }));
const { stderr } = spawnSync('ffmpeg', ['-hide_banner', '-i', clean, '-af', `silencedetect=noise=${SILENCE_DB}dB:d=${SILENCE_SECONDS}`, '-f', 'null', '-'], { encoding: 'utf8' });

// the speech is what lies between the silences; a blip shorter than a third of a second is a click, not a line
const starts = [...stderr.matchAll(/silence_start: ([\d.]+)/g)].map((m) => parseFloat(m[1]));
const ends = [...stderr.matchAll(/silence_end: ([\d.]+)/g)].map((m) => parseFloat(m[1]));
const parts = [];
let from = 0;
for (let i = 0; i < starts.length; i++) {
    if (starts[i] - from > 0.33) parts.push([from, starts[i]]);
    from = ends[i] ?? duration;
}
if (duration - from > 0.33) parts.push([from, duration]);

if (parts.length !== lines.length) {
    console.error(`✗ ${id}: ${lines.length} lines in the script, ${parts.length} parts in the recording:`);
    parts.forEach(([a, b], i) => console.error(`  ${String(i + 1).padStart(2)}. ${a.toFixed(1)}s – ${b.toFixed(1)}s`));
    process.exit(1);
}

fs.mkdirSync(CACHE, { recursive: true });
parts.forEach(([a, b], i) => {
    const file = path.join(CACHE, crypto.createHash('sha1').update(`${VOICE}|${RATE}|${lines[i]}`).digest('hex') + '.mp3');
    // a breath of room either side, faded so the cut doesn't click
    const start = Math.max(0, a - 0.08);
    const length = Math.min(duration, b + 0.12) - start;
    execFileSync('ffmpeg', ['-y', '-loglevel', 'error', '-ss', String(start), '-t', String(length), '-i', clean,
        '-af', `afade=t=in:d=0.05,afade=t=out:st=${Math.max(0, length - 0.08)}:d=0.08`, '-c:a', 'libmp3lame', '-q:a', '3', file]);
});
fs.rmSync(work, { recursive: true });
console.log(`✓ ${id}: ${lines.length} lines saved for VOICE=${VOICE}`);
