#!/usr/bin/env node
// Maintainer-only controller; never installed as a runtime service or worker credential.
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { attempt, bindings, publish, publishAttempt, requireThat, seal, verify } from './lib/receipt.mjs';

try {
  requireThat(Number(process.versions.node.split('.')[0]) >= 22, 'Maintainer local CI tooling requires Node.js 22 or later.');
  const [command, ...args] = process.argv.slice(2);
  requireThat(['bindings', 'begin', 'fail', 'publish-pending', 'publish-failure', 'seal', 'verify', 'publish'].includes(command), 'Usage: receipt.mjs bindings|begin|fail|publish-pending|publish-failure|seal|verify|publish --policy FILE --source SHA --purpose release|pull-request --evidence-root DIR [options]');
  const allowed = new Set(['policy', 'source', 'purpose', 'evidence-root', 'source-root', 'python',
    ...(['begin', 'fail', 'publish-pending', 'publish-failure'].includes(command) ? ['run-id'] : []),
    ...(command === 'seal' ? ['draft', 'receipt', 'signature', 'private-key'] : []),
    ...(['verify', 'publish'].includes(command) ? ['receipt', 'signature'] : []),
    ...(['publish', 'publish-pending', 'publish-failure'].includes(command) ? ['app-private-key'] : [])]);
  const options = {};
  for (let index = 0; index < args.length; index += 2) {
    requireThat(args[index].startsWith('--') && index + 1 < args.length, 'Every option requires one explicit value.');
    const key = args[index].slice(2);
    requireThat(allowed.has(key) && !Object.hasOwn(options, key) && !args[index + 1].startsWith('--'), 'Unknown, duplicate or missing option.');
    options[key] = args[index + 1];
  }
  const needed = ['policy', 'source', 'purpose', 'evidence-root',
    ...(['begin', 'fail', 'publish-pending', 'publish-failure'].includes(command) ? ['run-id'] : []),
    ...(command === 'seal' ? ['draft', 'receipt', 'signature', 'private-key'] : []),
    ...(['verify', 'publish'].includes(command) ? ['receipt', 'signature'] : []),
    ...(['publish', 'publish-pending', 'publish-failure'].includes(command) ? ['app-private-key'] : [])];
  requireThat(needed.every(key => options[key]), 'A required explicit controller option is missing.');
  const values = Object.fromEntries(Object.entries(options).map(([key, value]) => [key.replace(/-([a-z])/g, (_, letter) => letter.toUpperCase()), value]));
  values.sourceRoot ??= path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
  const output = command === 'begin' ? await attempt(values, 'running') : command === 'fail' ? await attempt(values, 'failure')
    : command === 'publish-pending' ? await publishAttempt(values, 'running') : command === 'publish-failure' ? await publishAttempt(values, 'failure')
    : command === 'bindings' ? await bindings(values) : command === 'seal' ? await seal(values)
    : command === 'verify' ? (await verify(values)).output : await publish(values);
  process.stdout.write(JSON.stringify(output, null, 2) + '\n');
} catch (error) {
  // Do not expose keys, installation tokens, environment variables or provider response bodies.
  process.stderr.write('Local CI controller rejected the operation: ' + error.message + '\n');
  process.exitCode = 1;
}
