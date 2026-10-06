import { readFile, mkdir, writeFile } from "node:fs/promises";
import { resolve } from "node:path";

const output = resolve(process.argv.find((arg) => arg.startsWith("--output="))?.slice(9) || "public/assets/fnlla");
const metadata = JSON.parse(await readFile("node_modules/@hotwired/turbo/package.json", "utf8"));
if (metadata.version !== "8.0.23") throw new Error("Unexpected Turbo version; review the navigation dependency before building.");
await mkdir(output, { recursive: true });
await writeFile(resolve(output, "turbo.js"), await readFile("node_modules/@hotwired/turbo/dist/turbo.es2017-esm.js"));
await writeFile(resolve(output, "TURBO-LICENSE"), await readFile("scripts/TURBO-MIT-LICENSE"));
console.log(`FNLLA Navigation: local Turbo ${metadata.version} assets built.`);
