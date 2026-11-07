#!/usr/bin/env node
// Every English text the app translates has its Nepali (lang/ne.json), with
// the same :placeholders (docs/superpowers/specs/2026-11-07-languages-design.md).
// Keys are the literal first argument of t()/tc() in resources/js and of
// __()/trans_choice() in app/. A key built at run time can't be checked, so
// it is refused. Exits 1 on any problem; unused Nepali entries are listed
// as a warning only.
import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join, relative } from 'node:path';

const root = new URL('..', import.meta.url).pathname;
const nepali = JSON.parse(readFileSync(join(root, 'lang/ne.json'), 'utf8'));

// --with=a.json,b.json: also count translations not merged into ne.json yet.
for (const extra of (process.argv.find((arg) => arg.startsWith('--with=')) ?? '--with=')
    .slice('--with='.length)
    .split(',')
    .filter(Boolean)) {
    Object.assign(nepali, JSON.parse(readFileSync(extra, 'utf8')));
}

function files(dir, extensions) {
    return readdirSync(dir).flatMap((name) => {
        const path = join(dir, name);

        if (statSync(path).isDirectory()) {
            return files(path, extensions);
        }

        return extensions.some((extension) => name.endsWith(extension)) ? [path] : [];
    });
}

/** The string literal starting at `at` (its quote), unescaped, and where it ends. */
function literal(source, at) {
    const quote = source[at];
    let out = '';

    for (let i = at + 1; i < source.length; i++) {
        const char = source[i];

        if (char === '\\') {
            out += source[i + 1];
            i++;
        } else if (char === quote) {
            return { text: out, end: i };
        } else if (quote === '`' && char === '$' && source[i + 1] === '{') {
            return null;
        } else {
            out += char;
        }
    }

    return null;
}

const used = new Map();
const problems = [];

function collect(path, pattern) {
    const source = readFileSync(path, 'utf8');

    for (const match of source.matchAll(pattern)) {
        let at = match.index + match[0].length;

        while (/\s/.test(source[at])) {
            at++;
        }

        const line = source.slice(0, match.index).split('\n').length;
        const where = `${relative(root, path)}:${line}`;

        if (!`'"\``.includes(source[at])) {
            problems.push(`${where}: the key is not a string literal`);
            continue;
        }

        const found = literal(source, at);

        if (!found) {
            problems.push(`${where}: the key is built at run time`);
            continue;
        }

        used.set(found.text, [...(used.get(found.text) ?? []), where]);
    }
}

for (const path of files(join(root, 'resources/js'), ['.svelte', '.ts'])) {
    if (!path.endsWith('/lib/i18n.ts')) {
        collect(path, /(?<![\w.$])tc?\(/g);
    }
}

for (const path of files(join(root, 'app'), ['.php'])) {
    collect(path, /(?<![\w$>:])(?:__|trans_choice)\(/g);
}

const placeholders = (text) => [...text.matchAll(/:([a-z_]+)/gi)].map((m) => m[1]).sort().join(',');

// "auth.failed": a key of Laravel's group files (lang/ne/auth.php), not a text.
const groupKey = (key) => /^[a-z_-]+\.[\w.-]+$/.test(key);

for (const [key, wheres] of used) {
    if (groupKey(key)) {
        continue;
    }

    if (!(key in nepali)) {
        problems.push(`${wheres[0]}: no Nepali for "${key}"`);
    } else if (placeholders(key) !== placeholders(nepali[key])) {
        problems.push(`${wheres[0]}: "${key}" and its Nepali have different placeholders`);
    }
}

const unused = Object.keys(nepali).filter((key) => !used.has(key));

if (unused.length > 0) {
    console.warn(`${unused.length} Nepali entries no code uses (fine for Laravel's own keys):`);
    unused.slice(0, 20).forEach((key) => console.warn(`  ${key}`));
}

if (problems.length > 0) {
    problems.forEach((problem) => console.error(problem));
    console.error(`\n${problems.length} translation problems.`);
    process.exit(1);
}

console.log(`${used.size} texts translated, all with Nepali.`);
