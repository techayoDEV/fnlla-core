import fs from 'node:fs/promises';
import path from 'node:path';
import crypto from 'node:crypto';
import { spawnSync } from 'node:child_process';

const MAX_JSON = 2 * 1024 * 1024;
const SHA = /^[0-9a-f]{40}$/;
const HASH = /^[0-9a-f]{64}$/;
const REQUIRED_ADAPTERS = ['scripts/local-ci/receipt.mjs', 'scripts/local-ci/lib/receipt.mjs', 'scripts/check-release-ci.py'];
const REPOSITORY = 'techayoDEV/fnlla-core';
const API = 'https://api.github.com';

export function requireThat(value, message) {
  if (!value) throw new Error(message);
}

export function canonical(value) {
  function visit(item, depth = 0) {
    requireThat(depth <= 40, 'JSON nesting exceeds the maintained limit.');
    if (item === null || typeof item === 'string' || typeof item === 'boolean') return item;
    if (typeof item === 'number') {
      requireThat(Number.isSafeInteger(item), 'Only finite safe integers are accepted in signed JSON.');
      return item;
    }
    if (Array.isArray(item)) return item.map(child => visit(child, depth + 1));
    requireThat(item && typeof item === 'object' && Object.getPrototypeOf(item) === Object.prototype, 'Invalid signed JSON value.');
    return Object.fromEntries(Object.keys(item).sort().map(key => [key, visit(item[key], depth + 1)]));
  }
  return Buffer.from(JSON.stringify(visit(value)) + '\n');
}

export const digest = bytes => crypto.createHash('sha256').update(bytes).digest('hex');
const same = (a, b) => canonical(a).equals(canonical(b));
const inside = (child, root) => child === root || (!path.relative(root, child).startsWith('..' + path.sep) && !path.isAbsolute(path.relative(root, child)) && path.relative(root, child) !== '..');

function crc32(bytes) {
  let crc = 0xffffffff;
  for (const value of bytes) {
    crc ^= value;
    for (let bit = 0; bit < 8; bit++) crc = (crc >>> 1) ^ ((crc & 1) ? 0xedb88320 : 0);
  }
  return (crc ^ 0xffffffff) >>> 0;
}

export function stableZip(bytes, source, expectedSource = null) {
  // The maintained deterministic Core builder emits STORE ZIPs, without ZIP64,
  // encryption, links, descriptors or comments. No artifact code is executed.
  requireThat(bytes.length >= 22 && bytes.length <= 64 * 1024 * 1024, 'Invalid stable ZIP size.');
  const end = bytes.length - 22;
  requireThat(bytes.readUInt32LE(end) === 0x06054b50 && bytes.readUInt16LE(end + 4) === 0 && bytes.readUInt16LE(end + 6) === 0 && bytes.readUInt16LE(end + 20) === 0, 'Unsupported or malformed deterministic ZIP ending.');
  const count = bytes.readUInt16LE(end + 10), directorySize = bytes.readUInt32LE(end + 12), directory = bytes.readUInt32LE(end + 16);
  requireThat(count > 0 && count <= 5000 && bytes.readUInt16LE(end + 8) === count && directory + directorySize === end, 'Invalid deterministic ZIP directory.');
  let cursor = directory;
  const files = new Map(), ranges = [];
  for (let index = 0; index < count; index++) {
    requireThat(cursor + 46 <= end && bytes.readUInt32LE(cursor) === 0x02014b50, 'Invalid ZIP central entry.');
    const flags = bytes.readUInt16LE(cursor + 8), method = bytes.readUInt16LE(cursor + 10), crc = bytes.readUInt32LE(cursor + 16);
    const compressed = bytes.readUInt32LE(cursor + 20), size = bytes.readUInt32LE(cursor + 24), nameLength = bytes.readUInt16LE(cursor + 28);
    const extraLength = bytes.readUInt16LE(cursor + 30), commentLength = bytes.readUInt16LE(cursor + 32), offset = bytes.readUInt32LE(cursor + 42);
    const mode = bytes.readUInt32LE(cursor + 38) >>> 16;
    requireThat((flags & ~0x800) === 0 && method === 0 && compressed === size && (mode & 0xf000) !== 0xa000 && cursor + 46 + nameLength + extraLength + commentLength <= end, 'Unsupported compression, link or ZIP entry flags.');
    const name = bytes.subarray(cursor + 46, cursor + 46 + nameLength).toString('utf8');
    requireThat(/^fnlla-core-3\.0\.0\/[A-Za-z0-9_.+-]+(?:\/[A-Za-z0-9_.+-]+)*$/.test(name), 'Unexpected stable package root or unsafe ZIP name.');
    const relative = name.slice('fnlla-core-3.0.0/'.length);
    relativeName(relative);
    requireThat(!files.has(relative) && offset + 30 <= directory && bytes.readUInt32LE(offset) === 0x04034b50, 'Duplicate ZIP member or invalid local entry.');
    const localNameLength = bytes.readUInt16LE(offset + 26), localExtraLength = bytes.readUInt16LE(offset + 28);
    const dataStart = offset + 30 + localNameLength + localExtraLength, dataEnd = dataStart + size;
    requireThat(bytes.readUInt16LE(offset + 6) === flags && bytes.readUInt16LE(offset + 8) === method && bytes.readUInt32LE(offset + 14) === crc && bytes.readUInt32LE(offset + 18) === compressed && bytes.readUInt32LE(offset + 22) === size && dataEnd <= directory && bytes.subarray(offset + 30, offset + 30 + localNameLength).toString('utf8') === name, 'ZIP local and central entries differ.');
    const data = bytes.subarray(dataStart, dataEnd);
    requireThat(crc32(data) === crc, 'ZIP entry checksum differs.');
    files.set(relative, data);
    ranges.push([offset, dataEnd]);
    cursor += 46 + nameLength + extraLength + commentLength;
  }
  requireThat(cursor === end, 'Unexpected ZIP directory bytes.');
  ranges.sort((a, b) => a[0] - b[0]);
  requireThat(ranges[0][0] === 0 && ranges.at(-1)[1] === directory && ranges.every((range, index) => index === 0 || range[0] === ranges[index - 1][1]), 'ZIP local entries overlap or contain unaccounted bytes.');
  const getJson = name => { requireThat(files.has(name), 'Required stable package metadata is missing.'); return JSON.parse(files.get(name).toString('utf8')); };
  const provenance = getJson('FNLLA-PROVENANCE.json'), metadata = getJson('FNLLA-PACKAGE.json');
  requireThat(files.get('VERSION')?.toString('utf8').trim() === '3.0.0' && provenance.version === '3.0.0' && metadata.version === '3.0.0' && provenance.channel === 'stable' && metadata.channel === 'stable' && provenance.release_approved === true && metadata.release_approved === true && provenance.workspace_dirty === false && provenance.base_commit === source && metadata.source?.commit === source && metadata.package === 'techayodev/fnlla-core', 'Stable package source, approval or version is invalid.');
  requireThat(files.has('FNLLA-MANIFEST.sha256'), 'Stable package manifest is absent.');
  const manifest = new Map();
  for (const line of files.get('FNLLA-MANIFEST.sha256').toString('utf8').trimEnd().split('\n')) {
    const match = /^([0-9a-f]{64})  (.+)$/.exec(line);
    requireThat(match && !manifest.has(match[2]) && match[2] !== 'FNLLA-MANIFEST.sha256' && files.has(match[2]) && digest(files.get(match[2])) === match[1], 'Stable package manifest does not match its original bytes.');
    relativeName(match[2]);
    manifest.set(match[2], match[1]);
  }
  requireThat(manifest.size + 1 === files.size, 'Stable package contains unmanifested files.');
  if (expectedSource) {
    const generated = new Set(['FNLLA-PROVENANCE.json', 'FNLLA-PACKAGE.json', 'FNLLA-MANIFEST.sha256']);
    requireThat(files.size === expectedSource.size + generated.size && [...expectedSource].every(([name, data]) => files.has(name) && files.get(name).equals(data)), 'Stable package payload differs from the exact committed source blobs.');
  }
  return { version: '3.0.0', source_commit: source, archive_sha256: digest(bytes), manifest_sha256: digest(files.get('FNLLA-MANIFEST.sha256')), file_count: files.size };
}

function sourcePayload(context) {
  // Match the maintained deterministic builder's committed distribution boundary.
  const excluded = name => /^(?:\.git|vendor|dist|storage)(?:\/|$)/i.test(name) || /(?:^|\/)node_modules(?:\/|$)/i.test(name)
    || /^\.fnlla\/(?:application-update|update-transaction)(?:\/|$)/i.test(name)
    || (path.posix.basename(name).toLowerCase().startsWith('.env') && !['.env.example', '.env.platform.example'].includes(path.posix.basename(name).toLowerCase()))
    || ['auth.json', '.npmrc', '.pypirc', 'id_rsa', 'id_ed25519'].includes(path.posix.basename(name).toLowerCase())
    || /\.(?:key|pem|p12|pfx|sql|sqlite|sqlite3|bak|log|tmp|zip|pyc|pyo)$/i.test(name);
  const result = spawnSync('git', ['ls-tree', '-r', '-z', context.options.source], { cwd: context.sourceRoot, maxBuffer: 8 * 1024 * 1024, windowsHide: true });
  requireThat(!result.error && result.status === 0, 'Cannot identify the committed artifact source tree.');
  const entries = result.stdout.toString('utf8').split('\0').filter(Boolean).map(entry => {
    const match = /^(100644|100755) blob ([a-f0-9]{40})\t(.+)$/.exec(entry);
    requireThat(match, 'Stable source cannot contain links or submodules.');
    return { name: match[3], object: match[2] };
  }).filter(entry => !excluded(entry.name));
  const objects = spawnSync('git', ['cat-file', '--batch'], { cwd: context.sourceRoot, input: entries.map(entry => entry.object).join('\n') + '\n', maxBuffer: 64 * 1024 * 1024, windowsHide: true });
  requireThat(!objects.error && objects.status === 0, 'Cannot read the committed artifact source blobs.');
  let cursor = 0;
  const payload = new Map();
  for (const entry of entries) {
    relativeName(entry.name);
    const newline = objects.stdout.indexOf(10, cursor);
    const match = /^([a-f0-9]{40}) blob ([0-9]+)$/.exec(objects.stdout.subarray(cursor, newline).toString('ascii'));
    requireThat(newline >= cursor && match && match[1] === entry.object, 'Invalid committed source object stream.');
    const size = Number(match[2]); cursor = newline + 1;
    requireThat(Number.isSafeInteger(size) && cursor + size < objects.stdout.length && objects.stdout[cursor + size] === 10, 'Truncated committed source blob.');
    let bytes = objects.stdout.subarray(cursor, cursor + size); cursor += size + 1;
    if (entry.name === 'VERSION') bytes = Buffer.from(bytes.toString('utf8').trim() + '\n');
    payload.set(entry.name, bytes);
  }
  requireThat(cursor === objects.stdout.length, 'Unexpected committed source blob stream bytes.');
  return payload;
}

function expectedCell(name) {
  let match = /^Package on (ubuntu-latest|windows-latest)$/.exec(name);
  if (match) return { platform: match[1] === 'ubuntu-latest' ? 'linux' : 'windows', matrix: { os: match[1] } };
  match = /^PHP (8\.[345]) on (ubuntu-latest|windows-latest)$/.exec(name);
  if (match) return { platform: match[2] === 'ubuntu-latest' ? 'linux' : 'windows', matrix: { os: match[2], php: match[1] } };
  match = /^MySQL and Redis PHP (8\.[345])$/.exec(name);
  if (match) return { platform: 'linux', matrix: { php: match[1] } };
  match = /^PHP (8\.[345])$/.exec(name);
  if (match) return { platform: 'aggregate', matrix: { php: match[1] } };
  requireThat(name === 'upload-http', 'Unknown maintained Core check contract.');
  return { platform: 'linux', matrix: {} };
}

export function relativeName(value) {
  requireThat(typeof value === 'string' && value.length > 0 && value.length <= 2048 && !value.includes('\\') && !value.includes(':') && !value.startsWith('/') && value.split('/').every(part => part && part !== '.' && part !== '..'), 'Evidence paths must be canonical relative paths without traversal.');
  return value;
}

async function noLinks(file) {
  const resolved = path.resolve(file);
  const parsed = path.parse(resolved);
  let current = parsed.root;
  for (const part of resolved.slice(parsed.root.length).split(path.sep).filter(Boolean)) {
    current = path.join(current, part);
    const info = await fs.lstat(current);
    requireThat(!info.isSymbolicLink(), 'Symbolic links are not accepted in trusted or evidence paths.');
  }
  return resolved;
}

async function regularBytes(file, limit = Number.MAX_SAFE_INTEGER) {
  const resolved = await noLinks(file);
  const before = await fs.lstat(resolved);
  requireThat(before.isFile() && before.nlink === 1 && before.size <= limit, 'Expected one regular, unlinked file within its size limit.');
  const bytes = await fs.readFile(resolved);
  const after = await fs.lstat(resolved);
  requireThat(before.ino === after.ino && before.size === after.size && before.mtimeMs === after.mtimeMs && bytes.length === after.size && !after.isSymbolicLink(), 'Input file changed while being read.');
  return bytes;
}

async function jsonFile(file, signed = false) {
  const bytes = await regularBytes(file, MAX_JSON);
  const data = JSON.parse(bytes.toString('utf8'));
  if (signed) requireThat(canonical(data).equals(bytes), 'Signed JSON must use canonical serialization.');
  return { data, bytes };
}

function command(command, args, cwd, binary = command) {
  const result = spawnSync(binary, args, { cwd, encoding: 'utf8', timeout: 60000, maxBuffer: 8 * 1024 * 1024, windowsHide: true });
  requireThat(!result.error && result.status === 0, 'A required maintainer source command failed.');
  return result.stdout.trim();
}

const git = (root, args) => command('git', args, root);
function gitBlob(root, source, name) {
  relativeName(name);
  const result = spawnSync('git', ['show', `${source}:${name}`], { cwd: root, timeout: 60000, maxBuffer: 8 * 1024 * 1024, windowsHide: true });
  requireThat(!result.error && result.status === 0, 'A required source-bound contract is absent.');
  return result.stdout;
}

function gitBlobs(root, source, names) {
  names.forEach(relativeName);
  const result = spawnSync('git', ['cat-file', '--batch'], { cwd: root, input: names.map(name => source + ':' + name).join('\n') + '\n', timeout: 60000, maxBuffer: 16 * 1024 * 1024, windowsHide: true });
  requireThat(!result.error && result.status === 0, 'A required source-bound contract is absent.');
  const values = new Map(); let cursor = 0;
  for (const name of names) {
    const newline = result.stdout.indexOf(10, cursor), header = result.stdout.subarray(cursor, newline).toString('ascii');
    const match = /^[a-f0-9]{40} blob ([0-9]+)$/.exec(header);
    requireThat(newline >= cursor && match, 'A required source-bound contract is absent.');
    const size = Number(match[1]); cursor = newline + 1;
    requireThat(Number.isSafeInteger(size) && cursor + size < result.stdout.length && result.stdout[cursor + size] === 10, 'Invalid source-bound contract bytes.');
    values.set(name, result.stdout.subarray(cursor, cursor + size)); cursor += size + 1;
  }
  requireThat(cursor === result.stdout.length, 'Unexpected source-bound contract bytes.');
  return values;
}

function timestamp(value) {
  requireThat(typeof value === 'string' && /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/.test(value), 'UTC timestamps must include milliseconds and Z.');
  const parsed = Date.parse(value);
  requireThat(Number.isFinite(parsed) && new Date(parsed).toISOString() === value, 'Invalid UTC timestamp.');
  return parsed;
}

function bound(pathName, bytes) { return { path: pathName, sha256: digest(bytes), size: bytes.length }; }

export async function loadContext(options) {
  requireThat(SHA.test(options.source), 'A full exact source commit is required.');
  requireThat(['release', 'pull-request'].includes(options.purpose), 'Unsupported local CI purpose.');
  const sourceRoot = await noLinks(options.sourceRoot);
  const evidenceRoot = await noLinks(options.evidenceRoot);
  const policyPath = await noLinks(options.policy);
  const policyRoot = path.dirname(policyPath);
  requireThat(!inside(policyPath, sourceRoot) && !inside(policyPath, evidenceRoot) && !inside(policyRoot, sourceRoot) && !inside(policyRoot, evidenceRoot), 'The controller trust root must be outside product source and worker evidence.');
  requireThat((await fs.lstat(sourceRoot)).isDirectory() && (await fs.lstat(evidenceRoot)).isDirectory(), 'Source and evidence roots must be directories.');
  requireThat(git(sourceRoot, ['rev-parse', 'HEAD']) === options.source && git(sourceRoot, ['status', '--porcelain=v1']) === '', 'The verifier requires the exact clean source checkout.');
  const external = await jsonFile(policyPath);
  const controller = external.data;
  requireThat(controller.schema === 'fnlla.local-ci.controller-policy.v1' && controller.repository === REPOSITORY, 'Invalid controller trust policy.');
  requireThat(controller.source_policy_path === '.github/local-ci-policy.json', 'Unexpected source policy path.');
  const publicKeyPath = path.join(policyRoot, relativeName(controller.controller_public_key));
  const latestPath = path.join(policyRoot, relativeName(controller.latest_state));
  requireThat(inside(publicKeyPath, policyRoot) && inside(latestPath, policyRoot), 'Controller paths must stay within the selected trust root.');
  requireThat([publicKeyPath, latestPath].every(file => !inside(file, sourceRoot) && !inside(file, evidenceRoot)), 'Controller public keys and latest-attempt state must remain outside source and worker evidence.');
  await noLinks(path.dirname(latestPath));
  const publicKey = crypto.createPublicKey(await regularBytes(publicKeyPath, 32768));
  requireThat(publicKey.asymmetricKeyType === 'ed25519', 'Controller receipt keys must use Ed25519.');
  const policyBytes = gitBlob(sourceRoot, options.source, controller.source_policy_path);
  const policy = JSON.parse(policyBytes.toString('utf8'));
  requireThat(policy.schema === 'fnlla.local-ci.policy.v1' && policy.repository === REPOSITORY && policy.check_prefix === 'Local CI / ', 'Invalid source-bound local CI policy.');
  requireThat(policy.workflow_path === '.github/workflows/quality.yml' && policy.lock_path === 'composer.lock', 'Unexpected workflow or lock contract.');
  requireThat(Number.isSafeInteger(policy.max_age_seconds) && policy.max_age_seconds > 0 && policy.max_age_seconds <= 604800 && Number.isSafeInteger(policy.max_future_seconds) && policy.max_future_seconds >= 0 && policy.max_future_seconds <= 300, 'Invalid receipt freshness limits.');
  requireThat(Array.isArray(policy.adapter_paths) && new Set(policy.adapter_paths).size === policy.adapter_paths.length && REQUIRED_ADAPTERS.every(name => policy.adapter_paths.includes(name)), 'All maintained CI adapters must be source-bound.');
  const declared = command('python', ['-I', '-B', path.join(sourceRoot, 'scripts/check-release-ci.py'), '--required-checks'], sourceRoot, options.python || (process.platform === 'win32' ? 'python' : 'python3'));
  const required = JSON.parse(declared);
  requireThat(Array.isArray(required) && required.length === 15 && new Set(required).size === 15 && Array.isArray(policy.required_checks) && same([...required].sort(), [...policy.required_checks].sort()), 'The source policy must preserve all 15 maintained Core checks exactly once.');
  requireThat(policy.allowed_refs && typeof policy.allowed_refs[options.purpose] === 'string', 'The requested purpose has no reviewed source ref.');
  const sourceRef = policy.allowed_refs[options.purpose];
  requireThat(/^refs\/heads\/[A-Za-z0-9][A-Za-z0-9._/-]*$/.test(sourceRef) && !sourceRef.includes('..') && !sourceRef.includes('//') && !sourceRef.endsWith('/') && (options.purpose !== 'release' || sourceRef === 'refs/heads/main'), 'Invalid reviewed source ref.');
  const app = controller.github_app;
  requireThat(app && Number.isSafeInteger(app.app_id) && app.app_id > 0 && Number.isSafeInteger(app.installation_id) && app.installation_id > 0 && /^[a-z0-9][a-z0-9-]{0,99}$/.test(app.slug), 'A concrete trusted GitHub App installation is required.');
  const contractBytes = gitBlobs(sourceRoot, options.source, [policy.workflow_path, policy.lock_path, ...policy.adapter_paths]);
  const bindings = { source_policy: bound(controller.source_policy_path, policyBytes),
    workflow: bound(policy.workflow_path, contractBytes.get(policy.workflow_path)),
    lock: bound(policy.lock_path, contractBytes.get(policy.lock_path)),
    adapters: policy.adapter_paths.map(name => bound(name, contractBytes.get(name))).sort((a, b) => a.path.localeCompare(b.path)) };
  return { options, sourceRoot, evidenceRoot, policyRoot, policyPath, latestPath, controller, controllerPolicyHash: digest(external.bytes), publicKey, policy, policyHash: digest(policyBytes), required, sourceRef, bindings, sourceTree: git(sourceRoot, ['rev-parse', 'HEAD^{tree}']) };
}

async function evidenceFile(context, descriptor) {
  requireThat(descriptor && HASH.test(descriptor.sha256) && Number.isSafeInteger(descriptor.size) && descriptor.size >= 0, 'Invalid evidence file descriptor.');
  const name = relativeName(descriptor.path);
  const target = path.join(context.evidenceRoot, ...name.split('/'));
  requireThat(inside(target, context.evidenceRoot), 'Evidence escaped its root.');
  requireThat(descriptor.size <= 128 * 1024 * 1024, 'An evidence file exceeds the maintained size limit.');
  const bytes = await regularBytes(target, 128 * 1024 * 1024);
  requireThat(bytes.length === descriptor.size && digest(bytes) === descriptor.sha256, 'Original evidence bytes do not match their signed size and hash.');
  return bytes;
}

async function latestState(context, optional = false) {
  try {
    const { data } = await jsonFile(context.latestPath, true);
    requireThat(data.schema === 'fnlla.local-ci.latest.v1' && data.controller_policy_sha256 === context.controllerPolicyHash && Number.isSafeInteger(data.sequence) && data.sequence > 0 && data.latest && typeof data.latest === 'object', 'Invalid trusted latest-receipt state.');
    return data;
  } catch (error) {
    if (optional && error.code === 'ENOENT') return { schema: 'fnlla.local-ci.latest.v1', controller_policy_sha256: context.controllerPolicyHash, sequence: 0, latest: {} };
    throw error;
  }
}

async function validateReceipt(context, receipt, options = {}) {
  requireThat(receipt.schema === 'fnlla.local-ci.receipt.v1' && receipt.repository === REPOSITORY && receipt.source_commit === context.options.source && receipt.source_tree === context.sourceTree && receipt.source_ref === context.sourceRef && receipt.purpose === context.options.purpose, 'Receipt source, tree, ref or purpose differs from the reviewed source.');
  requireThat(typeof receipt.run_id === 'string' && /^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/.test(receipt.run_id) && Number.isSafeInteger(receipt.sequence) && receipt.sequence > 0, 'Invalid controller run identity or sequence.');
  requireThat(receipt.controller_policy_sha256 === context.controllerPolicyHash && same(receipt.bindings, context.bindings), 'Workflow, lock, source policy or adapter identities differ from the reviewed source.');
  const start = timestamp(receipt.started_at), completed = timestamp(receipt.completed_at), now = options.now ?? Date.now();
  requireThat(start <= completed && completed <= now + context.policy.max_future_seconds * 1000 && now - completed <= context.policy.max_age_seconds * 1000, 'The signed run is future-dated, stale or has invalid timing.');
  const expectedTools = context.controller.execution_tools;
  requireThat(expectedTools && typeof expectedTools === 'object' && Object.keys(expectedTools).length > 0 && Array.isArray(receipt.execution_tools) && receipt.execution_tools.length === Object.keys(expectedTools).length, 'Every approved controller execution adapter must be recorded.');
  const seenTools = new Set();
  for (const tool of receipt.execution_tools) {
    requireThat(typeof tool.name === 'string' && !seenTools.has(tool.name) && expectedTools[tool.name], 'Unknown or duplicate execution adapter.');
    seenTools.add(tool.name);
    requireThat(expectedTools[tool.name].sha256 === tool.sha256 && expectedTools[tool.name].size === tool.size, 'Execution adapter was not approved by the controller trust root.');
    await evidenceFile(context, tool);
  }
  requireThat(Array.isArray(receipt.checks) && receipt.checks.length === 15 && new Set(receipt.checks.map(check => check.name)).size === 15 && same(receipt.checks.map(check => check.name).sort(), [...context.required].sort()), 'All 15 checks must be present exactly once; substitutions and duplicates fail.');
  const actualResults = new Map();
  for (const check of receipt.checks) {
    requireThat(check.status === 'success' && check.source_commit === receipt.source_commit, 'Failed, skipped or mismatched-source checks cannot be signed.');
    const checkStart = timestamp(check.started_at), checkEnd = timestamp(check.completed_at);
    requireThat(start <= checkStart && checkStart <= checkEnd && checkEnd <= completed, 'Check timing falls outside the actual controller run.');
    requireThat(Array.isArray(check.evidence) && check.evidence.length > 0 && check.evidence.length <= 128 && new Set(check.evidence.map(item => item.path)).size === check.evidence.length, 'A check requires its unique original evidence files.');
    const files = new Map();
    for (const item of check.evidence) files.set(item.path, await evidenceFile(context, item));
    requireThat(check.result && files.has(check.result.path), 'A check requires its retained actual result document.');
    const results = JSON.parse(files.get(check.result.path).toString('utf8'));
    let result;
    if (Array.isArray(results)) {
      requireThat(Number.isSafeInteger(check.result.index) && check.result.index >= 0 && check.result.index < results.length, 'Invalid actual-result array selection.');
      result = results[check.result.index];
    } else {
      requireThat(check.result.index === undefined, 'Unexpected result selection for an individual check.');
      result = results;
    }
    requireThat(result && (result.check ?? result.name) === check.name && result.source_commit === receipt.source_commit && result.status === 'success' && HASH.test(result.log_sha256), 'Retained actual check result is failed, missing or bound to different source.');
    const cell = expectedCell(check.name);
    requireThat(result.platform === cell.platform && same(result.matrix, cell.matrix), 'Actual check platform or PHP matrix differs from the maintained job.');
    actualResults.set(check.name, result);
    const logPath = path.posix.join(path.posix.dirname(check.result.path), relativeName(result.log));
    requireThat(files.has(logPath) && digest(files.get(logPath)) === result.log_sha256, 'The original check log is missing or changed.');
    if (check.name.startsWith('Package on ')) {
      requireThat(result.artifacts && Object.keys(result.artifacts).length >= 5, 'Actual package jobs require their original ZIP and release sidecars.');
      for (const [name, descriptor] of Object.entries(result.artifacts)) {
        relativeName(name);
        const artifactPath = path.posix.join(path.posix.dirname(check.result.path), 'artifacts', name);
        requireThat(files.has(artifactPath) && HASH.test(descriptor.sha256) && Number.isSafeInteger(descriptor.size) && files.get(artifactPath).length === descriptor.size && digest(files.get(artifactPath)) === descriptor.sha256, 'Original package-job artifact or sidecar is absent or changed.');
      }
    }
    const skips = files.get(logPath).toString('utf8').split(/\r?\n/).filter(line => /^(?:SKIP:|\[SKIP\])/.test(line));
    requireThat(Array.isArray(check.omissions) && same([...check.omissions].sort(), [...skips].sort()), 'Actual omitted checks must be recorded exactly.');
    const allowed = context.policy.allowed_omissions?.[check.name] ?? [];
    requireThat(check.omissions.every(message => allowed.includes(message)), 'An unapproved check omission was recorded.');
    if (check.omissions.length) {
      const counterpart = context.policy.omission_counterparts?.[check.name];
      const other = receipt.checks.find(item => item.name === counterpart);
      requireThat(other?.status === 'success' && Array.isArray(other.omissions) && other.omissions.length === 0, 'An omitted platform case requires its complete matching platform check.');
    }
  }
  requireThat(receipt.artifacts && receipt.artifacts.linux && receipt.artifacts.windows, 'Original Linux and Windows package artifacts are required.');
  const linux = await evidenceFile(context, receipt.artifacts.linux), windows = await evidenceFile(context, receipt.artifacts.windows);
  requireThat(linux.length > 0 && linux.equals(windows), 'Original Windows and Linux package ZIPs must be byte-identical.');
  requireThat(receipt.artifacts.linux.path.endsWith('.zip') && receipt.artifacts.windows.path.endsWith('.zip'), 'Expected original package ZIP artifacts.');
  for (const [platform, name] of [['linux', 'Package on ubuntu-latest'], ['windows', 'Package on windows-latest']]) {
    const original = actualResults.get(name)?.artifacts?.['fnlla-core-3.0.0.zip'];
    requireThat(original && original.sha256 === receipt.artifacts[platform].sha256 && original.size === receipt.artifacts[platform].size, 'Signed ZIP identity differs from the actual matching platform package job.');
  }
  stableZip(linux, receipt.source_commit, sourcePayload(context));
  return receipt;
}

function verifiedOutput(context, receipt, receiptBytes, signatureBytes) {
  const hash = digest(receiptBytes);
  return { schema: 'fnlla.local_ci.verified.v1', repository: REPOSITORY, source_commit: receipt.source_commit, source_ref: receipt.source_ref,
    purpose: receipt.purpose, source_tree: receipt.source_tree, completed_at: receipt.completed_at,
    receipt_sha256: hash, signature_sha256: digest(signatureBytes), policy_sha256: context.policyHash,
    controller_policy_sha256: context.controllerPolicyHash, latest_sequence: receipt.sequence, latest_status: 'success',
    checks: receipt.checks.map(check => ({ name: check.name, status: 'success', omissions: check.omissions })),
    publication_checks: { app_id: context.controller.github_app.app_id, installation_id: context.controller.github_app.installation_id,
      slug: context.controller.github_app.slug, names: receipt.checks.map(check => context.policy.check_prefix + check.name), external_id: hash } };
}

export async function verify(options) {
  const context = await loadContext(options);
  const { data: receipt, bytes } = await jsonFile(options.receipt, true);
  const signatureBytes = await regularBytes(options.signature, 1024);
  const encoded = signatureBytes.toString('ascii');
  requireThat(/^[A-Za-z0-9+/]{86}==\n$/.test(encoded), 'Invalid canonical detached Ed25519 signature.');
  requireThat(Buffer.from(encoded.trim(), 'base64').toString('base64') === encoded.trim(), 'Noncanonical detached signature encoding.');
  requireThat(crypto.verify(null, bytes, context.publicKey, Buffer.from(encoded.trim(), 'base64')), 'Controller receipt signature is invalid.');
  await validateReceipt(context, receipt, options);
  const state = await latestState(context);
  const key = `${receipt.purpose}:${receipt.source_ref}`;
  const latest = state.latest[key];
  requireThat(latest && latest.status === 'success' && latest.receipt_sha256 === digest(bytes) && latest.sequence === receipt.sequence && latest.source_commit === receipt.source_commit && latest.run_id === receipt.run_id, 'This is not the latest successful controller attempt for the reviewed ref.');
  return { context, receipt, output: verifiedOutput(context, receipt, bytes, signatureBytes) };
}

async function exclusiveWrite(file, bytes) {
  const handle = await fs.open(file, 'wx', 0o600);
  try { await handle.writeFile(bytes); await handle.sync(); }
  finally { await handle.close(); }
}

async function storeState(context, state) {
  const pending = context.latestPath + '.new-' + crypto.randomBytes(8).toString('hex');
  await exclusiveWrite(pending, canonical(state));
  await fs.rename(pending, context.latestPath);
}

export async function attempt(options, status) {
  const context = await loadContext(options);
  requireThat(['running', 'failure'].includes(status) && typeof options.runId === 'string' && /^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/.test(options.runId), 'An explicit controller run identity is required.');
  const lock = context.latestPath + '.lock', handle = await fs.open(lock, 'wx', 0o600);
  try {
    const state = await latestState(context, status === 'running');
    const stateKey = `${options.purpose}:${context.sourceRef}`;
    if (status === 'running') {
      requireThat(Number.isSafeInteger(state.sequence + 1) && state.latest[stateKey]?.run_id !== options.runId, 'Use a new controller attempt identity.');
      state.sequence++;
      state.latest[stateKey] = { source_commit: options.source, run_id: options.runId, sequence: state.sequence,
        status: 'running', started_at: new Date().toISOString() };
    } else {
      requireThat(state.latest[stateKey]?.source_commit === options.source && state.latest[stateKey]?.run_id === options.runId, 'Only the matching latest controller attempt can be failed.');
      state.latest[stateKey].status = 'failure';
      state.latest[stateKey].failed_at = new Date().toISOString();
      delete state.latest[stateKey].receipt_sha256;
    }
    await storeState(context, state);
    return { schema: 'fnlla.local-ci.attempt.v1', repository: REPOSITORY, source_ref: context.sourceRef,
      purpose: options.purpose, ...state.latest[stateKey] };
  } finally { await handle.close(); await fs.unlink(lock); }
}

export async function seal(options) {
  const context = await loadContext(options);
  const privatePath = await noLinks(options.privateKey);
  requireThat(!inside(privatePath, context.sourceRoot) && !inside(privatePath, context.evidenceRoot), 'The controller signing key must never enter source or worker evidence.');
  const key = crypto.createPrivateKey(await regularBytes(privatePath, 32768));
  requireThat(key.asymmetricKeyType === 'ed25519' && crypto.createPublicKey(key).export({ type: 'spki', format: 'pem' }) === context.publicKey.export({ type: 'spki', format: 'pem' }), 'Controller signing key differs from the external trusted public key.');
  const lock = context.latestPath + '.lock';
  const handle = await fs.open(lock, 'wx', 0o600);
  try {
    const state = await latestState(context, true);
    const { data: receipt } = await jsonFile(options.draft);
    const stateKey = `${receipt.purpose}:${receipt.source_ref}`, current = state.latest[stateKey];
    requireThat(current && current.status === 'running' && current.source_commit === options.source && current.run_id === receipt.run_id && current.sequence === receipt.sequence && current.started_at === receipt.started_at, 'Only the matching latest running controller attempt can be sealed.');
    requireThat(typeof current.pending_published_at === 'string' && context.required.every(name => Number.isSafeInteger(current.check_run_ids?.[context.policy.check_prefix + name])) && receipt.checks.every(check => timestamp(check.started_at) >= timestamp(current.pending_published_at)), 'All required pending App checks must be published before executing the native jobs.');
    receipt.controller_policy_sha256 = context.controllerPolicyHash;
    await validateReceipt(context, receipt, options);
    const bytes = canonical(receipt), signature = Buffer.from(crypto.sign(null, bytes, key).toString('base64') + '\n');
    await exclusiveWrite(options.receipt, bytes);
    await exclusiveWrite(options.signature, signature);
    state.latest[stateKey] = { ...current, status: 'success', receipt_sha256: digest(bytes), completed_at: receipt.completed_at };
    await storeState(context, state);
    return verifiedOutput(context, receipt, bytes, signature);
  } finally {
    await handle.close();
    await fs.unlink(lock);
  }
}

export async function bindings(options) {
  const context = await loadContext(options);
  return { schema: 'fnlla.local-ci.bindings.v1', repository: REPOSITORY, source_commit: options.source,
    source_tree: context.sourceTree, source_ref: context.sourceRef, purpose: options.purpose,
    controller_policy_sha256: context.controllerPolicyHash, bindings: context.bindings };
}

async function request(fetcher, route, token, method = 'GET', body) {
  requireThat(route.startsWith('/') && !route.startsWith('//'), 'Invalid GitHub API route.');
  const response = await fetcher(API + route, { method, redirect: 'error', signal: AbortSignal.timeout(60000),
    headers: { Accept: 'application/vnd.github+json', Authorization: 'Bearer ' + token, 'X-GitHub-Api-Version': '2022-11-28',
      'User-Agent': 'FNLLA-Core-Local-CI', ...(body ? { 'Content-Type': 'application/json' } : {}) },
    ...(body ? { body: JSON.stringify(body) } : {}) });
  requireThat(response.ok, `GitHub API request failed (${response.status}); no credentials or response body are exposed.`);
  const text = await response.text();
  requireThat(text.length <= 4 * 1024 * 1024, 'GitHub API response exceeds the maintained limit.');
  if (response.status === 204 && text === '') return {};
  return JSON.parse(text);
}

async function appClient(context, options, fetcher) {
  const appKeyPath = await noLinks(options.appPrivateKey);
  requireThat(!inside(appKeyPath, context.sourceRoot) && !inside(appKeyPath, context.evidenceRoot), 'The GitHub App private key must remain outside product source and workers.');
  const appKey = crypto.createPrivateKey(await regularBytes(appKeyPath, 32768));
  requireThat(appKey.asymmetricKeyType === 'rsa' && appKey.asymmetricKeyDetails.modulusLength >= 2048, 'Expected the external GitHub App RSA private key.');
  const now = Math.floor(Date.now() / 1000), app = context.controller.github_app;
  const header = Buffer.from(JSON.stringify({ alg: 'RS256', typ: 'JWT' })).toString('base64url');
  const payload = Buffer.from(JSON.stringify({ iat: now - 60, exp: now + 540, iss: String(app.app_id) })).toString('base64url');
  const content = header + '.' + payload;
  const jwt = content + '.' + crypto.sign('RSA-SHA256', Buffer.from(content), appKey).toString('base64url');
  const identity = await request(fetcher, '/app', jwt);
  requireThat(identity.id === app.app_id && identity.slug === app.slug, 'GitHub App key does not match the trusted installation identity.');
  const installation = await request(fetcher, `/app/installations/${app.installation_id}`, jwt);
  requireThat(installation.app_id === app.app_id && installation.repository_selection === 'selected' && installation.permissions?.checks === 'write' && installation.permissions?.contents === 'read' && Object.keys(installation.permissions).every(name => ['checks', 'contents', 'metadata'].includes(name)), 'The GitHub App must select repositories explicitly and have only checks-write, contents-read and implicit metadata-read permissions.');
  const tokenResult = await request(fetcher, `/app/installations/${app.installation_id}/access_tokens`, jwt, 'POST',
    { repositories: ['fnlla-core'], permissions: { checks: 'write', contents: 'read' } });
  requireThat(typeof tokenResult.token === 'string', 'GitHub did not return an installation token.');
  const token = tokenResult.token;
  try {
    requireThat(tokenResult.permissions?.checks === 'write' && tokenResult.permissions?.contents === 'read' && Object.keys(tokenResult.permissions).every(name => ['checks', 'contents', 'metadata'].includes(name)), 'Installation token permissions differ from the required narrow scope.');
    const repositories = await request(fetcher, '/installation/repositories?per_page=100', token);
    requireThat(repositories.total_count === 1 && repositories.repositories?.length === 1 && repositories.repositories[0].full_name === REPOSITORY, 'Installation token is not restricted to the single Core repository.');
    return { request: (route, method, body) => request(fetcher, route, token, method, body),
      close: () => request(fetcher, '/installation/token', token, 'DELETE') };
  } catch (error) { await request(fetcher, '/installation/token', token, 'DELETE'); throw error; }
}

async function remoteRef(client, context) {
  const ref = context.sourceRef.slice('refs/'.length).split('/').map(encodeURIComponent).join('/');
  const actual = await client.request(`/repos/${REPOSITORY}/git/ref/${ref}`);
  requireThat(actual.object?.type === 'commit' && actual.object.sha === context.options.source, 'Reviewed source no longer equals the actual remote ref.');
}

export async function publish(options, fetcher = globalThis.fetch) {
  const { context, receipt, output } = await verify(options);
  const client = await appClient(context, options, fetcher);
  try {
    await remoteRef(client, context);
    const current = (await latestState(context)).latest[`${receipt.purpose}:${receipt.source_ref}`];
    const published = [];
    for (const check of receipt.checks) {
    const name = context.policy.check_prefix + check.name;
    const id = current.check_run_ids?.[name];
    const result = await client.request(`/repos/${REPOSITORY}/check-runs${id ? '/' + id : ''}`, id ? 'PATCH' : 'POST', {
      name, ...(id ? {} : { head_sha: receipt.source_commit }), external_id: output.receipt_sha256,
      status: 'completed', conclusion: 'success', started_at: check.started_at, completed_at: check.completed_at,
      details_url: `https://github.com/${REPOSITORY}/commit/${receipt.source_commit}`,
      output: { title: name, summary: ['Locally executed native CI published by the dedicated FNLLA GitHub App. This is not GitHub Actions.',
        `Source: ${receipt.source_commit}`, `Signed receipt SHA-256: ${output.receipt_sha256}`,
        `Source policy SHA-256: ${output.policy_sha256}`, `Controller sequence: ${receipt.sequence}`,
        `Recorded platform omissions: ${check.omissions.length}`, ...check.omissions].join('\n') }
    });
    requireThat(result.app?.id === context.controller.github_app.app_id && result.head_sha === receipt.source_commit && result.name === name && result.external_id === output.receipt_sha256 && result.status === 'completed' && result.conclusion === 'success', 'GitHub returned a check with mismatched source, identity or conclusion.');
    published.push({ name, id: result.id, url: result.html_url });
  }
  // Revalidate the remote binding after publication; a moved ref never produces a successful publication receipt.
    await remoteRef(client, context);
    await verify(options);
    return { schema: 'fnlla.local-ci.publication.v1', repository: REPOSITORY, source_commit: receipt.source_commit,
    source_ref: receipt.source_ref, receipt_sha256: output.receipt_sha256, policy_sha256: output.policy_sha256,
      app_id: context.controller.github_app.app_id, installation_id: context.controller.github_app.installation_id, checks: published };
  } finally { await client.close(); }
}

export async function publishAttempt(options, status, fetcher = globalThis.fetch) {
  const context = await loadContext(options);
  requireThat(['running', 'failure'].includes(status) && typeof options.runId === 'string', 'An explicit pending/failure attempt is required.');
  const stateKey = `${options.purpose}:${context.sourceRef}`;
  const current = (await latestState(context)).latest[stateKey];
  requireThat(current && current.source_commit === options.source && current.run_id === options.runId && current.status === status, 'Only the matching current controller state can be published.');
  const client = await appClient(context, options, fetcher);
  try {
    await remoteRef(client, context);
    const ids = { ...(current.check_run_ids ?? {}) }, published = [];
    for (const check of context.required) {
      const name = context.policy.check_prefix + check, id = ids[name];
      const body = { name, ...(id ? {} : { head_sha: options.source }), external_id: `fnlla-local-ci-attempt:${current.run_id}:${current.sequence}`,
        status: status === 'running' ? 'in_progress' : 'completed', started_at: current.started_at,
        ...(status === 'failure' ? { conclusion: 'failure', completed_at: current.failed_at } : {}),
        details_url: `https://github.com/${REPOSITORY}/commit/${options.source}`,
        output: { title: name, summary: `Local native CI controller attempt ${current.run_id}, sequence ${current.sequence}: ${status}. This is not GitHub Actions and is not a successful signed release receipt.` } };
      const result = await client.request(`/repos/${REPOSITORY}/check-runs${id ? '/' + id : ''}`, id ? 'PATCH' : 'POST', body);
      requireThat(Number.isSafeInteger(result.id) && result.id > 0 && result.app?.id === context.controller.github_app.app_id && result.head_sha === options.source && result.name === name && result.status === body.status && result.external_id === body.external_id && (status !== 'failure' || result.conclusion === 'failure'), 'GitHub returned a mismatched pending/failure check.');
      ids[name] = result.id; published.push({ name, id: result.id, url: result.html_url });
    }
    await remoteRef(client, context);
    const lock = context.latestPath + '.lock', handle = await fs.open(lock, 'wx', 0o600);
    try {
      const latest = await latestState(context), selected = latest.latest[stateKey];
      requireThat(selected?.run_id === current.run_id && selected.sequence === current.sequence && selected.status === status, 'Controller attempt changed during publication.');
      selected.check_run_ids = ids;
      if (status === 'running') selected.pending_published_at = new Date().toISOString();
      await storeState(context, latest);
    } finally { await handle.close(); await fs.unlink(lock); }
    return { schema: 'fnlla.local-ci.attempt-publication.v1', repository: REPOSITORY, source_commit: options.source,
      source_ref: context.sourceRef, run_id: current.run_id, sequence: current.sequence, status, checks: published };
  } finally { await client.close(); }
}
