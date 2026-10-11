import { readFile, mkdir, writeFile } from "node:fs/promises";
import { resolve } from "node:path";
import { transform } from "esbuild";

const output = resolve(process.argv.find((arg) => arg.startsWith("--output="))?.slice(9) || "public/assets/fnlla");
const metadata = JSON.parse(await readFile("node_modules/@hotwired/turbo/package.json", "utf8"));
if (metadata.version !== "8.0.23") throw new Error("Unexpected Turbo version; review the navigation dependency before building.");
await mkdir(output, { recursive: true });
// Compact the pinned ESM without renaming symbols or rewriting its syntax.
// The original package remains the build input; the adjacent MIT notice ships
// with every export. Node/esbuild are build tools, never runtime requirements.
const source = await readFile("node_modules/@hotwired/turbo/dist/turbo.es2017-esm.js", "utf8");
const compiled = await transform(source, {
  minifyWhitespace: true, format: "esm", target: "esnext", legalComments: "inline",
});
await writeFile(resolve(output, "turbo.js"), compiled.code);
await writeFile(resolve(output, "TURBO-LICENSE"), await readFile("scripts/TURBO-MIT-LICENSE"));
console.log(`FNLLA Navigation: local Turbo ${metadata.version} assets built.`);
