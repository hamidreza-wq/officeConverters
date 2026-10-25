const { test } = require('node:test');
const assert = require('node:assert/strict');
const path = require('node:path');
const { spawn, execFileSync } = require('node:child_process');
const { once } = require('node:events');

test('closing the desktop pipe terminates the backend and its descendants', { skip: process.platform !== 'win32', timeout: 20000 }, async () => {
    const python = execFileSync('python', ['-c', 'import sys; print(sys.executable)'], { encoding: 'utf8' }).trim();
    const program = 'const {spawn}=require("node:child_process");' +
        'const child=spawn(process.execPath,["-e","setInterval(()=>{},1000)"],{windowsHide:true,stdio:"ignore"});' +
        'console.log(JSON.stringify({parent:process.pid,child:child.pid}));setInterval(()=>{},1000);';
    const supervisor = spawn(python, ['-I', path.resolve(__dirname, '..', 'supervisor.py'), process.execPath, '-e', program],
        { windowsHide: true, stdio: ['pipe', 'pipe', 'pipe'] });
    let stderr = '';
    supervisor.stderr.on('data', chunk => { stderr += chunk; });
    const stopped = once(supervisor, 'exit');
    try {
        const [output] = await once(supervisor.stdout, 'data');
        const pids = JSON.parse(output.toString());
        supervisor.stdin.end();
        await stopped;
        for (const pid of [pids.parent, pids.child]) {
            let exited = false;
            for (let attempt = 0; attempt < 50; attempt++) {
                try {
                    process.kill(pid, 0);
                } catch (error) {
                    if (error.code !== 'ESRCH') throw error;
                    exited = true;
                    break;
                }
                await new Promise(resolve => setTimeout(resolve, 50));
            }
            assert.equal(exited, true, `Managed process ${pid} must not survive desktop shutdown. ${stderr}`);
        }
    } finally {
        supervisor.stdin.end();
        await stopped;
    }
});
