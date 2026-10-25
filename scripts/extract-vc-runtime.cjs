const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const { execFileSync } = require('node:child_process');
const { getPath7za } = require('app-builder-lib/out/toolsets/7zip');

const expectedHash = '843068991daaa1f73ad9f6239bce4d0f6a07a51f18c37ea2a867e9beca71295c';

async function main() {
    const directory = path.resolve(process.argv[2]);
    const output = path.resolve(process.argv[3]);
    fs.mkdirSync(directory, { recursive: true });
    fs.mkdirSync(output, { recursive: true });
    const archive = path.join(directory, 'VC_redist.x64.exe');
    if (!fs.existsSync(archive)) {
        const response = await fetch('https://aka.ms/vc14/vc_redist.x64.exe');
        if (!response.ok) throw new Error(`Microsoft runtime download failed: HTTP ${response.status}`);
        fs.writeFileSync(archive, Buffer.from(await response.arrayBuffer()));
    }
    const contents = fs.readFileSync(archive);
    if (crypto.createHash('sha256').update(contents).digest('hex') !== expectedHash) {
        throw new Error('Microsoft runtime hash changed. Verify its signature/version and explicitly update the pinned hash before building.');
    }
    const zip = await getPath7za();
    let offset = -1;
    const cabinets = [];
    while ((offset = contents.indexOf('MSCF', offset + 1)) !== -1) {
        const length = contents.readUInt32LE(offset + 8);
        if (length > 36 && offset + length <= contents.length) {
            const file = path.join(directory, `vc-container-${cabinets.length}.cab`);
            fs.writeFileSync(file, contents.subarray(offset, offset + length));
            cabinets.push(file);
        }
    }
    if (cabinets.length !== 2) throw new Error('Unexpected Microsoft runtime bundle structure.');
    const attached = path.join(directory, 'vc-attached');
    const extracted = path.join(directory, 'vc-crt');
    execFileSync(zip, ['x', cabinets[1], `-o${attached}`, '-y'], { stdio: 'inherit' });
    execFileSync(zip, ['x', path.join(attached, 'a4'), `-o${extracted}`, '-y'], { stdio: 'inherit' });
    const files = fs.readdirSync(extracted).filter(name => name.endsWith('.dll_amd64'));
    if (files.length !== 12) throw new Error('Unexpected Microsoft runtime DLL inventory.');
    for (const filename of files) {
        fs.copyFileSync(path.join(extracted, filename), path.join(output, filename.replace('_amd64', '')));
    }
    fs.writeFileSync(path.join(output, 'SOURCE.txt'),
        'Microsoft Visual C++ Runtime 14.51.36247.0, x64.\n' +
        'Source: https://aka.ms/vc14/vc_redist.x64.exe\n' +
        `SHA-256: ${expectedHash}\n` +
        'Licence: https://aka.ms/VCRedistLicense\n' +
        'Application-local, unmodified redistributable CRT DLLs. Not a separate SDK or developer tool.\n');
}

main().catch(error => { console.error(error.message); process.exitCode = 1; });
