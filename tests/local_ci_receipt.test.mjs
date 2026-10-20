// Synthetic maintainer-controller fixtures; no GitHub API or runtime services are used.
import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import crypto from 'node:crypto';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { attempt, bindings, canonical, digest, publish, publishAttempt, relativeName, seal, stableZip, verify } from '../scripts/local-ci/lib/receipt.mjs';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const NAMES = ['MySQL and Redis PHP 8.3', 'MySQL and Redis PHP 8.4', 'MySQL and Redis PHP 8.5',
  'PHP 8.3', 'PHP 8.3 on ubuntu-latest', 'PHP 8.3 on windows-latest', 'PHP 8.4', 'PHP 8.4 on ubuntu-latest',
  'PHP 8.4 on windows-latest', 'PHP 8.5', 'PHP 8.5 on ubuntu-latest', 'PHP 8.5 on windows-latest',
  'Package on ubuntu-latest', 'Package on windows-latest', 'upload-http'];
const PYTHON = process.env.FNLLA_TEST_PYTHON || (process.platform === 'win32' ? 'python' : 'python3');

function git(root, ...args) {
  const result = spawnSync('git', args, { cwd: root, encoding: 'utf8', windowsHide: true });
  assert.equal(result.status, 0, result.stderr);
  return result.stdout.trim();
}

function committedPayload(root) {
  // The deterministic builder packages Git blobs; checkout line endings are not
  // package bytes. Keep this fixture equally strict on autocrlf Windows clones.
  const names = git(root, 'ls-files', '-z').split('\0').filter(Boolean);
  const result = spawnSync('git', ['cat-file', '--batch'], { cwd: root,
    input: names.map(name => 'HEAD:' + name).join('\n') + '\n', maxBuffer: 16 * 1024 * 1024, windowsHide: true });
  assert.equal(result.status, 0, result.stderr?.toString());
  const payload = new Map(); let cursor = 0;
  for (const name of names) {
    const newline = result.stdout.indexOf(10, cursor);
    const match = /^[a-f0-9]{40} blob ([0-9]+)$/.exec(result.stdout.subarray(cursor, newline).toString('ascii'));
    assert.ok(newline >= cursor && match, 'Expected the exact committed blob.');
    const size = Number(match[1]); cursor = newline + 1;
    assert.ok(Number.isSafeInteger(size) && cursor + size < result.stdout.length);
    assert.equal(result.stdout[cursor + size], 10);
    payload.set(name, result.stdout.subarray(cursor, cursor + size)); cursor += size + 1;
  }
  assert.equal(cursor, result.stdout.length);
  return payload;
}

function crc32(bytes) {
  let crc = 0xffffffff;
  for (const value of bytes) {
    crc ^= value;
    for (let bit = 0; bit < 8; bit++) crc = (crc >>> 1) ^ ((crc & 1) ? 0xedb88320 : 0);
  }
  return (crc ^ 0xffffffff) >>> 0;
}

function zip(files) {
  const local = [], central = [];
  let offset = 0;
  for (const [name, bytes] of files) {
    const filename = Buffer.from('fnlla-core-3.0.0/' + name), crc = crc32(bytes);
    const header = Buffer.alloc(30);
    header.writeUInt32LE(0x04034b50); header.writeUInt16LE(20, 4); header.writeUInt32LE(crc, 14);
    header.writeUInt32LE(bytes.length, 18); header.writeUInt32LE(bytes.length, 22); header.writeUInt16LE(filename.length, 26);
    const directory = Buffer.alloc(46);
    directory.writeUInt32LE(0x02014b50); directory.writeUInt16LE(20, 4); directory.writeUInt16LE(20, 6);
    directory.writeUInt32LE(crc, 16); directory.writeUInt32LE(bytes.length, 20); directory.writeUInt32LE(bytes.length, 24);
    directory.writeUInt16LE(filename.length, 28); directory.writeUInt32LE(offset, 42);
    local.push(header, filename, bytes); central.push(directory, filename);
    offset += header.length + filename.length + bytes.length;
  }
  const directory = Buffer.concat(central), end = Buffer.alloc(22);
  end.writeUInt32LE(0x06054b50); end.writeUInt16LE(files.size, 8); end.writeUInt16LE(files.size, 10);
  end.writeUInt32LE(directory.length, 12); end.writeUInt32LE(offset, 16);
  return Buffer.concat([...local, directory, end]);
}

function packageFiles(source, changes = {}, payload = new Map()) {
  const provenance = { version: '3.0.0', base_commit: source, channel: 'stable', release_approved: true, workspace_dirty: false, ...changes };
  const metadata = { package: 'techayodev/fnlla-core', version: '3.0.0', channel: 'stable', release_approved: true, source: { commit: source } };
  const files = new Map(payload);
  files.set('VERSION', Buffer.from('3.0.0\n')); files.set('FNLLA-PROVENANCE.json', canonical(provenance));
  files.set('FNLLA-PACKAGE.json', canonical(metadata));
  files.set('FNLLA-MANIFEST.sha256', Buffer.from([...files].map(([name, bytes]) => digest(bytes) + '  ' + name + '\n').join('')));
  return files;
}
const stablePackage = (source, changes = {}) => zip(packageFiles(source, changes));

async function fixture(t) {
  const root = await fs.mkdtemp(path.join(os.tmpdir(), 'fnlla-local-ci-test-'));
  t.after(() => fs.rm(root, { recursive: true, force: true }));
  const sourceRoot = path.join(root, 'source'), evidenceRoot = path.join(root, 'evidence'), trust = path.join(root, 'controller');
  await Promise.all([fs.mkdir(sourceRoot), fs.mkdir(evidenceRoot), fs.mkdir(trust)]);
  await Promise.all(['.github/workflows', 'scripts/local-ci/lib'].map(name => fs.mkdir(path.join(sourceRoot, name), { recursive: true })));
  await fs.copyFile(path.join(HERE, '../scripts/local-ci/receipt.mjs'), path.join(sourceRoot, 'scripts/local-ci/receipt.mjs'));
  await fs.copyFile(path.join(HERE, '../scripts/local-ci/lib/receipt.mjs'), path.join(sourceRoot, 'scripts/local-ci/lib/receipt.mjs'));
  await fs.writeFile(path.join(sourceRoot, 'scripts/check-release-ci.py'), 'import json\nprint(json.dumps(' + JSON.stringify(NAMES) + '))\n');
  await fs.writeFile(path.join(sourceRoot, '.github/workflows/quality.yml'), 'name: Synthetic maintainer fixture\n');
  await fs.writeFile(path.join(sourceRoot, 'composer.lock'), '{}\n');
  await fs.writeFile(path.join(sourceRoot, 'VERSION'), '3.0.0\n');
  const policy = { schema: 'fnlla.local-ci.policy.v1', repository: 'techayoDEV/fnlla-core', required_checks: NAMES,
    check_prefix: 'Local CI / ', workflow_path: '.github/workflows/quality.yml', lock_path: 'composer.lock',
    adapter_paths: ['scripts/local-ci/receipt.mjs', 'scripts/local-ci/lib/receipt.mjs', 'scripts/check-release-ci.py'],
    max_age_seconds: 86400, max_future_seconds: 120, allowed_refs: { release: 'refs/heads/main', 'pull-request': 'refs/heads/release/core-3.0.0' },
    allowed_omissions: { 'PHP 8.3 on windows-latest': ['SKIP: synthetic unavailable symlink.'] },
    omission_counterparts: { 'PHP 8.3 on windows-latest': 'PHP 8.3 on ubuntu-latest' } };
  await fs.writeFile(path.join(sourceRoot, '.github/local-ci-policy.json'), canonical(policy));
  git(sourceRoot, 'init', '-q'); git(sourceRoot, 'config', 'core.autocrlf', 'true'); git(sourceRoot, 'add', '.');
  git(sourceRoot, '-c', 'user.name=Synthetic test', '-c', 'user.email=test@example.invalid', '-c', 'commit.gpgsign=false', 'commit', '-qm', 'Synthetic controller fixture');
  const source = git(sourceRoot, 'rev-parse', 'HEAD');
  const signing = crypto.generateKeyPairSync('ed25519');
  await fs.writeFile(path.join(trust, 'public.pem'), signing.publicKey.export({ type: 'spki', format: 'pem' }));
  const privateKey = path.join(trust, 'private.pem');
  await fs.writeFile(privateKey, signing.privateKey.export({ type: 'pkcs8', format: 'pem' }), { mode: 0o600 });
  const toolBytes = Buffer.from('# Synthetic approved controller adapter\n');
  await fs.writeFile(path.join(evidenceRoot, 'adapter.py'), toolBytes);
  const external = { schema: 'fnlla.local-ci.controller-policy.v1', repository: 'techayoDEV/fnlla-core',
    source_policy_path: '.github/local-ci-policy.json', controller_public_key: 'public.pem', latest_state: 'latest.json',
    github_app: { app_id: 123456, installation_id: 654321, slug: 'synthetic-fnlla-local-ci' },
    execution_tools: { native: { sha256: digest(toolBytes), size: toolBytes.length } } };
  const policyPath = path.join(trust, 'policy.json');
  await fs.writeFile(policyPath, canonical(external));
  const options = { policy: policyPath, source, sourceRoot, evidenceRoot, purpose: 'release', python: PYTHON,
    privateKey, draft: path.join(evidenceRoot, 'draft.json'), receipt: path.join(evidenceRoot, 'receipt.json'), signature: path.join(evidenceRoot, 'receipt.sig') };
  const bound = await bindings(options);
  const begun = await attempt({ ...options, runId: 'synthetic-1' }, 'running');
  await publishAttempt(await appOptions({ options: { ...options, runId: 'synthetic-1' }, trust }), 'running', apiMock(source).fetcher);
  const latest = JSON.parse(await fs.readFile(path.join(trust, 'latest.json'), 'utf8'));
  const pendingAt = latest.latest['release:refs/heads/main'].pending_published_at;
  const started_at = begun.started_at, completed_at = new Date().toISOString();
  const descriptor = async (name, bytes) => {
    await fs.mkdir(path.dirname(path.join(evidenceRoot, name)), { recursive: true });
    await fs.writeFile(path.join(evidenceRoot, name), bytes);
    return { path: name, sha256: digest(bytes), size: bytes.length };
  };
  const checks = [];
  for (const [index, name] of NAMES.entries()) {
    const directory = `check-${index}`, logName = directory + '/actual.log', resultName = directory + '/result.json', log = Buffer.from('Synthetic completed native check\n');
    const php = /8\.[345]/.exec(name)?.[0];
    const platform = name.includes('windows-latest') ? 'windows' : /^PHP 8\.[345]$/.test(name) ? 'aggregate' : 'linux';
    const matrix = name.includes(' on ') ? { os: platform === 'windows' ? 'windows-latest' : 'ubuntu-latest', ...(php ? { php } : {}) } : php ? { php } : {};
    const report = canonical({ check: name, source_commit: source, status: 'success', platform, matrix, log: 'actual.log', log_sha256: digest(log) });
    checks.push({ name, source_commit: source, status: 'success', started_at: pendingAt, completed_at, omissions: [],
      result: { path: resultName }, evidence: [await descriptor(logName, log), await descriptor(resultName, report)] });
  }
  const payload = committedPayload(sourceRoot);
  const packaged = packageFiles(source, {}, payload), artifact = zip(packaged);
  const artifactDescriptors = {};
  for (const [platform, checkName] of [['linux', 'Package on ubuntu-latest'], ['windows', 'Package on windows-latest']]) {
    const check = checks.find(item => item.name === checkName), directory = path.posix.dirname(check.result.path);
    const originals = new Map([['fnlla-core-3.0.0.zip', artifact],
      ['fnlla-core-3.0.0.zip.sha256', Buffer.from(digest(artifact) + '  fnlla-core-3.0.0.zip\n')],
      ...['FNLLA-PROVENANCE.json', 'FNLLA-PACKAGE.json', 'FNLLA-MANIFEST.sha256'].map(name => [name, packaged.get(name)])]);
    const report = JSON.parse(await fs.readFile(path.join(evidenceRoot, check.result.path), 'utf8'));
    report.artifacts = {};
    for (const [name, bytes] of originals) {
      const desc = await descriptor(directory + '/artifacts/' + name, bytes);
      check.evidence.push(desc); report.artifacts[name] = { sha256: desc.sha256, size: desc.size };
      if (name.endsWith('.zip')) artifactDescriptors[platform] = desc;
    }
    check.evidence = check.evidence.filter(item => item.path !== check.result.path);
    check.evidence.push(await descriptor(check.result.path, canonical(report)));
  }
  const draft = { schema: 'fnlla.local-ci.receipt.v1', repository: 'techayoDEV/fnlla-core', source_commit: source,
    source_tree: bound.source_tree, source_ref: bound.source_ref, purpose: 'release', run_id: 'synthetic-1', sequence: 1,
    started_at, completed_at, controller_policy_sha256: bound.controller_policy_sha256, bindings: bound.bindings,
    execution_tools: [{ name: 'native', path: 'adapter.py', sha256: digest(toolBytes), size: toolBytes.length }], checks,
    artifacts: artifactDescriptors };
  const save = async () => fs.writeFile(options.draft, canonical(draft));
  await save();
  return { root, sourceRoot, evidenceRoot, trust, options, draft, save, descriptor,
    begin: async runId => {
      const state = await attempt({ ...options, runId }, 'running');
      await publishAttempt(await appOptions({ options: { ...options, runId }, trust }), 'running', apiMock(source).fetcher);
      const latest = JSON.parse(await fs.readFile(path.join(trust, 'latest.json'), 'utf8'));
      const pendingAt = latest.latest['release:refs/heads/main'].pending_published_at;
      draft.run_id = runId; draft.sequence = state.sequence; draft.started_at = state.started_at;
      draft.completed_at = new Date().toISOString();
      for (const check of draft.checks) { check.started_at = pendingAt; check.completed_at = draft.completed_at; }
      await save();
    } };
}

test('signed latest exact-source receipt verifies all 15 checks and stable original ZIPs', async t => {
  const f = await fixture(t);
  const sealed = await seal(f.options), verified = (await verify(f.options)).output;
  assert.equal(verified.receipt_sha256, sealed.receipt_sha256);
  assert.equal(verified.checks.length, 15);
  assert.equal(verified.publication_checks.app_id, 123456);
  assert.equal(verified.publication_checks.names.every(name => name.startsWith('Local CI / ')), true);
});

test('synthetic packages use committed LF blobs while the checkout retains CRLF bytes', async t => {
  const root = await fs.mkdtemp(path.join(os.tmpdir(), 'fnlla-local-ci-crlf-test-'));
  t.after(() => fs.rm(root, { recursive: true, force: true }));
  git(root, 'init', '-q'); git(root, 'config', 'core.autocrlf', 'true');
  const filename = path.join(root, 'line-endings.txt');
  await fs.writeFile(filename, Buffer.from('first\r\nsecond\r\n'));
  git(root, 'add', '.');
  git(root, '-c', 'user.name=Synthetic test', '-c', 'user.email=test@example.invalid', '-c', 'commit.gpgsign=false', 'commit', '-qm', 'Synthetic CRLF fixture');
  assert.deepEqual(await fs.readFile(filename), Buffer.from('first\r\nsecond\r\n'));
  assert.deepEqual(committedPayload(root).get('line-endings.txt'), Buffer.from('first\nsecond\n'));
});

test('receipt tampering fails before any publication', async t => {
  const f = await fixture(t); await seal(f.options);
  const receipt = JSON.parse(await fs.readFile(f.options.receipt, 'utf8')); receipt.run_id = 'tampered';
  await fs.writeFile(f.options.receipt, canonical(receipt));
  await assert.rejects(verify(f.options), /signature is invalid/);
});

test('older receipt is rejected after a newer run is sealed for the same source ref', async t => {
  const f = await fixture(t); await seal(f.options);
  const first = { ...f.options };
  f.options.receipt = path.join(f.evidenceRoot, 'second.json'); f.options.signature = path.join(f.evidenceRoot, 'second.sig');
  await f.begin('synthetic-2'); await seal(f.options);
  await assert.rejects(verify(first), /not the latest successful/);
  assert.equal((await verify(f.options)).output.latest_sequence, 2);
});

test('sealing rejects missing, duplicate, failed, stale and mismatched evidence', async t => {
  const f = await fixture(t), original = JSON.parse(JSON.stringify(f.draft));
  const originalResults = new Map(await Promise.all(original.checks.map(async check => [check.result.path, await fs.readFile(path.join(f.evidenceRoot, check.result.path))])));
  for (const [name, mutate, pattern] of [
  ['missing check', f => f.draft.checks.pop(), /15 checks/],
  ['duplicate check', f => { f.draft.checks[1].name = f.draft.checks[0].name; }, /15 checks/],
  ['failed check', f => { f.draft.checks[0].status = 'failure'; }, /Failed, skipped/],
  ['wrong source', f => { f.draft.checks[0].source_commit = 'b'.repeat(40); }, /mismatched-source/],
  ['workflow drift', f => { f.draft.bindings.workflow.sha256 = 'b'.repeat(64); }, /Workflow, lock/],
  ['unapproved adapter', f => { f.draft.execution_tools[0].sha256 = 'b'.repeat(64); }, /not approved/],
  ['unrecorded omission', f => { f.draft.checks[0].omissions = ['SKIP: invented']; }, /omitted checks/],
  ['stale run', f => { f.draft.completed_at = new Date(Date.now() - 2 * 86400000).toISOString(); }, /future-dated, stale/],
  ['future run', f => { f.draft.completed_at = new Date(Date.now() + 3600000).toISOString(); }, /future-dated, stale/],
  ['traversing evidence', f => { f.draft.checks[0].evidence[0].path = '../escape.log'; }, /canonical relative/],
  ['wrong actual platform', async f => {
    const check = f.draft.checks[0], result = JSON.parse(await fs.readFile(path.join(f.evidenceRoot, check.result.path), 'utf8'));
    result.platform = 'windows';
    check.evidence = check.evidence.filter(item => item.path !== check.result.path);
    check.evidence.push(await f.descriptor(check.result.path, canonical(result)));
  }, /platform or PHP matrix/],
  ['changed package-job artifact identity', async f => {
    const check = f.draft.checks.find(item => item.name === 'Package on ubuntu-latest');
    const result = JSON.parse(await fs.readFile(path.join(f.evidenceRoot, check.result.path), 'utf8'));
    result.artifacts['fnlla-core-3.0.0.zip'].sha256 = 'b'.repeat(64);
    check.evidence = check.evidence.filter(item => item.path !== check.result.path);
    check.evidence.push(await f.descriptor(check.result.path, canonical(result)));
  }, /artifact or sidecar/],
  ]) await t.test(name, async () => {
    for (const [name, bytes] of originalResults) await fs.writeFile(path.join(f.evidenceRoot, name), bytes);
    Object.keys(f.draft).forEach(key => delete f.draft[key]); Object.assign(f.draft, JSON.parse(JSON.stringify(original)));
    await mutate(f); await f.save(); await assert.rejects(seal(f.options), pattern);
  });
});

test('original retained evidence mutation and hard links are rejected', async t => {
  const f = await fixture(t);
  await fs.writeFile(path.join(f.evidenceRoot, 'check-0/actual.log'), 'changed bytes');
  await assert.rejects(seal(f.options), /signed size and hash/);
  await fs.writeFile(path.join(f.evidenceRoot, 'check-0/actual.log'), 'Synthetic completed native check\n');
  await fs.link(path.join(f.evidenceRoot, 'check-0/actual.log'), path.join(f.evidenceRoot, 'linked.log'));
  await assert.rejects(seal(f.options), /regular, unlinked file/);
});

test('Windows and Linux artifacts must remain byte-identical originals', async t => {
  const f = await fixture(t), changed = stablePackage(f.options.source, { workspace_dirty: true });
  f.draft.artifacts.windows = await f.descriptor('windows.zip', changed); await f.save();
  await assert.rejects(seal(f.options), /byte-identical/);
});

test('stable ZIP parser rejects RC approval, wrong source, corruption and unmanifested entries', () => {
  const source = 'a'.repeat(40), bytes = stablePackage(source);
  assert.equal(stableZip(bytes, source).version, '3.0.0');
  assert.throws(() => stableZip(stablePackage(source, { release_approved: false }), source), /approval or version/);
  assert.throws(() => stableZip(bytes, 'b'.repeat(40)), /approval or version/);
  assert.throws(() => stableZip(bytes, source, new Map([['VERSION', Buffer.from('different committed bytes\n')]])), /exact committed source blobs/);
  const corrupt = Buffer.from(bytes); corrupt[40] ^= 1;
  assert.throws(() => stableZip(corrupt, source));
  assert.throws(() => relativeName('a/../secret'));
  assert.throws(() => relativeName('C:\\secret'));
});

function apiMock(source, mode = 'success') {
  const calls = [], token = 'synthetic-installation-token';
  let refs = 0;
  const fetcher = async (url, options) => {
    const route = new URL(url).pathname; calls.push({ route, options });
    let data;
    if (route === '/app') data = { id: 123456, slug: 'synthetic-fnlla-local-ci' };
    else if (route === '/app/installations/654321') data = { app_id: 123456, repository_selection: mode === 'all' ? 'all' : 'selected', permissions: { checks: 'write', contents: 'read', ...(mode === 'broad' ? { actions: 'write' } : {}) } };
    else if (route.endsWith('/access_tokens')) data = { token, permissions: { checks: 'write', contents: 'read' } };
    else if (route === '/installation/repositories') data = { total_count: 1, repositories: [{ full_name: 'techayoDEV/fnlla-core' }] };
    else if (route.includes('/git/ref/')) { refs++; data = { object: { type: 'commit', sha: mode === 'moved' ? 'b'.repeat(40) : source } }; }
    else if (/\/check-runs(?:\/\d+)?$/.test(route)) { const body = JSON.parse(options.body); data = { head_sha: source, ...body, id: Number(/\/(\d+)$/.exec(route)?.[1] ?? calls.length), html_url: 'https://github.com/synthetic/check', app: { id: 123456 } }; }
    else if (route === '/installation/token' && options.method === 'DELETE') return { ok: true, status: 204, text: async () => '' };
    else throw new Error('Unexpected mocked API route.');
    return { ok: true, status: 200, text: async () => JSON.stringify(data) };
  };
  return { fetcher, calls, token };
}

async function appOptions(f) {
  const key = crypto.generateKeyPairSync('rsa', { modulusLength: 2048 }).privateKey;
  const appPrivateKey = path.join(f.trust, 'app.pem');
  await fs.writeFile(appPrivateKey, key.export({ type: 'pkcs8', format: 'pem' }), { mode: 0o600 });
  return { ...f.options, appPrivateKey };
}

test('publisher uses narrow single-repository App token and 15 distinct genuine check-runs', async t => {
  const f = await fixture(t); await seal(f.options); const mocked = apiMock(f.options.source);
  const result = await publish(await appOptions(f), mocked.fetcher);
  assert.equal(result.checks.length, 15);
  const tokenCall = mocked.calls.find(call => call.route.endsWith('/access_tokens'));
  assert.deepEqual(JSON.parse(tokenCall.options.body), { repositories: ['fnlla-core'], permissions: { checks: 'write', contents: 'read' } });
  const checks = mocked.calls.filter(call => /\/check-runs(?:\/\d+)?$/.test(call.route));
  assert.equal(new Set(checks.map(call => JSON.parse(call.options.body).name)).size, 15);
  assert.equal(checks.every(call => call.options.redirect === 'error' && JSON.parse(call.options.body).external_id === result.receipt_sha256), true);
  assert.equal(JSON.stringify(result).includes(mocked.token), false);
  assert.equal(mocked.calls.at(-1).route, '/installation/token');
});

test('publisher rejects moved sources and excessive App scope before creating checks', async t => {
  const f = await fixture(t); await seal(f.options); const options = await appOptions(f);
  for (const [mode, pattern] of [['moved', /actual remote ref/], ['broad', /only checks-write/], ['all', /select repositories explicitly/]]) await t.test(mode, async () => {
    const mocked = apiMock(f.options.source, mode);
    await assert.rejects(publish(options, mocked.fetcher), pattern);
    assert.equal(mocked.calls.some(call => /\/check-runs(?:\/\d+)?$/.test(call.route)), false);
  });
});

test('controller public key and latest-attempt files cannot be selected from worker evidence', async t => {
  const f = await fixture(t), policy = JSON.parse(await fs.readFile(f.options.policy, 'utf8'));
  await fs.copyFile(path.join(f.trust, 'public.pem'), path.join(f.evidenceRoot, 'public.pem'));
  for (const which of ['controller_public_key', 'latest_state']) await t.test(which, async () => {
    const external = { ...policy, controller_public_key: 'controller/public.pem', latest_state: 'controller/latest.json' };
    external[which] = which === 'controller_public_key' ? 'evidence/public.pem' : 'evidence/latest.json';
    const externalPath = path.join(f.root, which + '-policy.json'); await fs.writeFile(externalPath, canonical(external));
    await assert.rejects(bindings({ ...f.options, policy: externalPath }), /must remain outside source and worker evidence/);
  });
});

test('new running and failed attempts immediately invalidate an older successful receipt', async t => {
  const f = await fixture(t); await seal(f.options);
  await attempt({ ...f.options, runId: 'newer-unfinished' }, 'running');
  await assert.rejects(verify(f.options), /not the latest successful/);
  const options = await appOptions({ options: { ...f.options, runId: 'newer-unfinished' }, trust: f.trust });
  const pending = apiMock(f.options.source);
  await publishAttempt(options, 'running', pending.fetcher);
  assert.equal(pending.calls.filter(call => call.route.endsWith('/check-runs')).every(call => JSON.parse(call.options.body).status === 'in_progress'), true);
  await attempt({ ...f.options, runId: 'newer-unfinished' }, 'failure');
  const failed = apiMock(f.options.source);
  await publishAttempt(options, 'failure', failed.fetcher);
  const checks = failed.calls.filter(call => /\/check-runs\/\d+$/.test(call.route));
  assert.equal(checks.length, 15);
  assert.equal(checks.every(call => JSON.parse(call.options.body).conclusion === 'failure'), true);
  await assert.rejects(verify(f.options), /not the latest successful/);
});
