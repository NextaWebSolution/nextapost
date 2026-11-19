// Builds public/assets/css/app.css from resources/css/app.css with Tailwind CSS v4.
// Usage:  node tools/build-css.mjs  [path-to-tailwindcss-package]
// Only needed when you change classes; the compiled CSS ships with the release (cPanel needs no Node).
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const twPkg = process.argv[2] || process.env.TAILWIND_PKG || path.join(root, 'node_modules/tailwindcss');
const { compile } = await import(pathToFileURL(path.join(twPkg, 'dist/lib.mjs')).href);

const loadStylesheet = async (id, base) => {
  let file;
  if (id === 'tailwindcss') file = path.join(twPkg, 'index.css');
  else if (id.startsWith('tailwindcss/')) file = path.join(twPkg, id.slice('tailwindcss/'.length));
  else file = path.resolve(base, id);
  if (!fs.existsSync(file) && fs.existsSync(file + '.css')) file += '.css';
  return { path: file, base: path.dirname(file), content: fs.readFileSync(file, 'utf8') };
};

const input = fs.readFileSync(path.join(root, 'resources/css/app.css'), 'utf8');
const compiler = await compile(input, { base: path.join(root, 'resources/css'), loadStylesheet });

const scanDirs = ['views', 'public/assets/js', 'src', 'public/install'];
const candidates = new Set();
const walk = (dir) => {
  for (const e of fs.readdirSync(dir, { withFileTypes: true })) {
    const p = path.join(dir, e.name);
    if (e.isDirectory()) walk(p);
    else if (/\.(php|js|html)$/.test(e.name) && !p.includes('/vendor/')) {
      const src = fs.readFileSync(p, 'utf8');
      for (const m of src.matchAll(/[^<>"'`\s=]+/g)) {
        const raw = m[0];
        candidates.add(raw);
        // Also try the token without surrounding JS/PHP punctuation, e.g. "{'is-past':" → "is-past"
        const trimmed = raw.replace(/^[\[{(,;:!?]+/, '').replace(/[,;:)}?]+$/, '');
        if (trimmed && trimmed !== raw) candidates.add(trimmed);
      }
    }
  }
};
for (const d of scanDirs) if (fs.existsSync(path.join(root, d))) walk(path.join(root, d));

let css = compiler.build([...candidates]);
// Light minification: strip comments and collapse whitespace.
css = css.replace(/\/\*[\s\S]*?\*\//g, '').replace(/\n\s*\n/g, '\n').replace(/^\s+/gm, '');
const out = path.join(root, 'public/assets/css/app.css');
fs.writeFileSync(out, `/*! NextaPost ${fs.readFileSync(path.join(root, 'VERSION'), 'utf8').trim()} | Tailwind CSS v4 (MIT) */\n` + css);
console.log(`Built ${path.relative(root, out)} (${(fs.statSync(out).size / 1024).toFixed(1)} KB) from ${candidates.size} candidates`);
