const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const { execFileSync } = require('node:child_process');

const payload = path.resolve(process.argv[2]);
const installed = JSON.parse(fs.readFileSync(path.join(payload, 'app', 'vendor', 'composer', 'installed.json'), 'utf8'));
const packages = installed.packages.map(item => ({
    name: item.name, version: item.version, license: item.license, source: item.source?.url || item.dist?.url,
}));
const python = [];
const site = path.join(payload, 'python', 'Lib', 'site-packages');
for (const name of fs.readdirSync(site).filter(name => name.endsWith('.dist-info'))) {
    const metadata = fs.readFileSync(path.join(site, name, 'METADATA'), 'utf8');
    const field = name => metadata.match(new RegExp(`^${name}: (.*)$`, 'm'))?.[1];
    python.push({ name: field('Name'), version: field('Version'),
        license: field('License-Expression') || field('License') || 'See packaged METADATA and licence files',
        source: field('Home-page') || field('Project-URL') || 'https://pypi.org/' });
}
const files = {};
for (const relative of ['php/php.exe', 'python/python.exe', 'libreoffice/program/soffice.com',
    'tesseract/tesseract.exe', 'tesseract/tessdata/eng.traineddata']) {
    files[relative] = crypto.createHash('sha256').update(fs.readFileSync(path.join(payload, relative))).digest('hex');
}
const inventory = {
    applicationVersion: JSON.parse(fs.readFileSync(path.join(payload, 'app', 'desktop-version.json'), 'utf8').replace(/^\uFEFF/, '')).version,
    php: execFileSync(path.join(payload, 'php', 'php.exe'), ['-n', '-v'], { encoding: 'utf8' }).split('\n')[0].trim(),
    python: execFileSync(path.join(payload, 'python', 'python.exe'), ['-I', '--version'], { encoding: 'utf8' }).trim(),
    libreoffice: execFileSync(path.join(payload, 'libreoffice', 'program', 'soffice.com'), ['--version'], { encoding: 'utf8' }).trim(),
    tesseract: execFileSync(path.join(payload, 'tesseract', 'tesseract.exe'), ['--version'], { encoding: 'utf8' }).split('\n')[0].trim(),
    composer: packages, pythonPackages: python, executableHashes: files,
};
fs.writeFileSync(path.join(payload, 'runtime-inventory.json'), JSON.stringify(inventory, null, 2));
