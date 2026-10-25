const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const crypto = require('node:crypto');
const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const { availablePort, createEnvironment, runtimePaths, runManaged, startManaged } = require('../desktop/runtime.cjs');

async function main() {
    const payloadIndex = process.argv.indexOf('--payload');
    if (payloadIndex === -1 || !process.argv[payloadIndex + 1]) throw new Error('Supply --payload with the staged or installed resources\\payload directory.');
    const payload = path.resolve(process.argv[payloadIndex + 1]);
    const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'Office Converters smoke caf\u00e9-'));
    const paths = runtimePaths(payload);
    const port = await availablePort();
    const origin = `http://127.0.0.1:${port}`;
    const token = crypto.randomBytes(32).toString('hex');
    const environment = createEnvironment(payload, directory, token, port);
    const logPath = path.join(directory, 'smoke.log');
    const log = fs.createWriteStream(logPath);
    const children = [];
    const headers = { 'X-OfficeConverters-Desktop': token };
    const start = args => {
        const child = startManaged(paths, args, environment, log);
        children.push(child);
        child.on('error', error => log.write(`${error.stack}\n`));
        return child;
    };
    try {
        await runManaged(paths, ['artisan', 'package:discover', '--no-interaction'], environment, log);
        await runManaged(paths, ['artisan', 'converters:desktop-prepare', '--no-interaction'], environment, log);
        start(['-S', `127.0.0.1:${port}`, '-t', path.join(paths.app, 'public'), paths.router]);
        start(['artisan', 'queue:work', 'database', '--sleep=1', '--tries=1', '--timeout=150', '--no-interaction']);
        let ready = false;
        for (let attempt = 0; attempt < 100; attempt++) {
            try {
                const response = await fetch(`${origin}/desktop/ready`, { headers, signal: AbortSignal.timeout(30000) });
                if (response.ok) {
                    const result = await response.json();
                    assert.equal(result.ready, true, 'Bundled engine health check');
                    assert.equal(result.launch, crypto.createHash('sha256').update(token).digest('hex'));
                    ready = true;
                    break;
                }
            } catch (error) {
                if (error.cause?.code !== 'ECONNREFUSED') throw error;
            }
            await new Promise(resolve => setTimeout(resolve, 250));
        }
        assert.equal(ready, true, 'Backend startup deadline');
        assert.equal((await fetch(`${origin}/dashboard`)).status, 403);
        assert.equal((await fetch(`${origin}/build/manifest.json`)).status, 403);
        assert.equal((await fetch(`${origin}/register`, { headers })).status, 404);
        assert.equal((await fetch(`${origin}/admin`, { headers })).status, 404);
        const dashboardResponse = await fetch(`${origin}/dashboard`, { headers });
        headers.Cookie = dashboardResponse.headers.getSetCookie().map(value => value.split(';')[0]).join('; ');
        const dashboard = await dashboardResponse.text();
        assert.equal(dashboard.includes('Sign out'), false);
        const csrf = dashboard.match(/name="_token" value="([^"]+)"/)?.[1];
        assert.ok(csrf, 'Workspace renders an upload form');
        const assets = [...dashboard.matchAll(/(?:src|href)="(http:\/\/127\.0\.0\.1:[0-9]+\/build\/[^"]+)"/g)].map(match => match[1]);
        assert.ok(assets.length >= 2, 'Compiled stylesheet and JavaScript are available');
        for (const asset of assets) assert.equal((await fetch(asset, { headers })).status, 200);

        async function convert(name, bytes, target, mime) {
            const form = new FormData();
            form.append('files[]', new Blob([bytes], { type: mime }), name);
            form.append('target_format', target);
            form.append('request_key', crypto.randomUUID());
            form.append('accept_limitations', '1');
            form.append('_token', csrf);
            const response = await fetch(`${origin}/conversions`, {
                method: 'POST', headers: { ...headers, Origin: origin, Accept: 'application/json' },
                body: form, redirect: 'manual',
            });
            assert.equal(response.status, 302, `${name} submission: ${await response.text()}`);
            const location = response.headers.get('location');
            assert.match(location, /\/conversions\/[a-f0-9-]+$/);
            const deadline = Date.now() + 160000;
            while (Date.now() < deadline) {
                const status = await (await fetch(`${location}/status`, { headers })).json();
                if (status.status === 'failed') throw new Error(`${name} failed: ${status.error}`);
                if (status.status === 'completed') {
                    const result = await fetch(`${location}/download`, { headers });
                    assert.equal(result.status, 200);
                    const bytes = Buffer.from(await result.arrayBuffer());
                    assert.ok(bytes.length > 10);
                    console.log(`PASS ${name} -> ${target} (${bytes.length} bytes)`);
                    return bytes;
                }
                await new Promise(resolve => setTimeout(resolve, 500));
            }
            throw new Error(`${name} conversion timed out`);
        }

        const workbook = await convert('unicode.csv', Buffer.from('Name,Amount,Code\ncafé,12.50,000123\n'), 'xlsx', 'text/csv');
        const xlsx = path.join(directory, 'result.xlsx');
        fs.writeFileSync(xlsx, workbook);
        execFileSync(paths.python, ['-I', '-c',
            'import sys; from openpyxl import load_workbook; w=load_workbook(sys.argv[1]); assert w.active["A2"].value=="café"; assert w.active["C2"].value=="000123"',
            xlsx], { env: environment });
        const csv = await convert('workbook.xlsx', workbook, 'csv', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        assert.match(csv.toString(), /000123/);
        const pdf = await convert('document.rtf', Buffer.from('{\\rtf1\\ansi Offline conversion sample.}'), 'pdf', 'application/rtf');
        const pdfPath = path.join(directory, 'result.pdf');
        fs.writeFileSync(pdfPath, pdf);
        execFileSync(paths.python, ['-I', '-c',
            'import sys; from pypdf import PdfReader; r=PdfReader(sys.argv[1]); assert len(r.pages)==1; assert "Offline conversion sample" in r.pages[0].extract_text()', pdfPath], { env: environment });
        const docx = await convert('native.pdf', pdf, 'docx', 'application/pdf');
        const docxPath = path.join(directory, 'result.docx');
        fs.writeFileSync(docxPath, docx);
        execFileSync(paths.python, ['-I', '-c',
            'import sys; from docx import Document; assert "Offline conversion sample" in "\\n".join(p.text for p in Document(sys.argv[1]).paragraphs)', docxPath], { env: environment });

        const image = path.join(directory, 'scan.png');
        const presentation = path.join(directory, 'slides.pptx');
        execFileSync(paths.python, ['-I', '-c',
            'import sys; from pathlib import Path; from PIL import Image, ImageDraw, ImageFont; from pptx import Presentation; ' +
            'im=Image.new("RGB",(1100,220),"white"); d=ImageDraw.Draw(im); ' +
            'font=ImageFont.truetype(str(Path(sys.argv[3])/"Fonts"/"arial.ttf"),60); ' +
            'd.text((40,70),"HELLO OFFLINE",fill="black",font=font); im.save(sys.argv[1]); ' +
            'p=Presentation(); s=p.slides.add_slide(p.slide_layouts[0]); s.shapes.title.text="Offline slides"; p.save(sys.argv[2])',
            image, presentation, environment.SystemRoot], { env: environment });
        const ocr = await convert('scan.png', fs.readFileSync(image), 'txt', 'image/png');
        assert.match(ocr.toString(), /HELLO OFFLINE/);
        const slidePdf = await convert('slides.pptx', fs.readFileSync(presentation), 'pdf',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation');
        const slidePath = path.join(directory, 'slides.pdf');
        fs.writeFileSync(slidePath, slidePdf);
        execFileSync(paths.python, ['-I', '-c',
            'import sys; from pypdf import PdfReader; assert "Offline slides" in PdfReader(sys.argv[1]).pages[0].extract_text()', slidePath], { env: environment });
        const scannedPdf = await convert('scan.png', fs.readFileSync(image), 'pdf', 'image/png');
        const scannedDocx = await convert('scanned.pdf', scannedPdf, 'docx', 'application/pdf');
        fs.writeFileSync(docxPath, scannedDocx);
        execFileSync(paths.python, ['-I', '-c',
            'import sys; from docx import Document; assert "HELLO OFFLINE" in "\\n".join(p.text for p in Document(sys.argv[1]).paragraphs)', docxPath], { env: environment });

        await Promise.all(children.map(child => new Promise(resolve => {
            if (child.exitCode !== null) return resolve();
            child.once('exit', resolve);
            child.stdin.end();
        })));
        const originalKey = fs.readFileSync(path.join(directory, 'application-key'), 'utf8');
        fs.writeFileSync(path.join(directory, 'storage', 'app', 'desktop-version'), '0.0.0');
        await runManaged(paths, ['artisan', 'converters:desktop-prepare', '--no-interaction'], environment, log);
        const backups = fs.readdirSync(path.join(directory, 'backups')).filter(name => name.endsWith('.sqlite'));
        assert.equal(backups.length, 1, 'Upgrade creates a consistent metadata backup');
        assert.equal(fs.readFileSync(path.join(directory, 'application-key'), 'utf8'), originalKey);
        execFileSync(paths.python, ['-I', '-c',
            'import sqlite3,sys; c=sqlite3.connect(sys.argv[1]); assert c.execute("select count(*) from conversions where status=?",("completed",)).fetchone()[0]==8',
            path.join(directory, 'backups', backups[0])], { env: environment });
        console.log('PASS upgrade preserves the application key and all eight completed conversion records in a SQLite backup');
        console.log('PASS packaged runtimes, loopback token gate, compiled UI assets, SQLite queue and parsed Office/PDF/OCR outputs');
    } catch (error) {
        console.error(fs.readFileSync(logPath, 'utf8').slice(-12000));
        throw error;
    } finally {
        await Promise.all(children.map(child => new Promise(resolve => {
            if (child.exitCode !== null) return resolve();
            child.once('exit', resolve);
            child.stdin.end();
        })));
        await new Promise(resolve => log.end(resolve));
        fs.rmSync(directory, { recursive: true, force: true });
    }
}

main().catch(error => { console.error(error); process.exitCode = 1; });
