#!/usr/bin/env node
/**
 * Builds logliy-{version}.zip (and logliy-wordpress.zip alias) into dist/.
 * Entry paths use forward slashes — required by WordPress on Linux hosts.
 * (PowerShell Compress-Archive writes backslashes and breaks activation.)
 *
 * Pattern adapted from CookiePeak apps/wordpress-plugin/build.mjs.
 */
import {
  copyFileSync,
  existsSync,
  mkdirSync,
  readdirSync,
  readFileSync,
  rmSync,
  statSync,
  writeFileSync,
} from "node:fs";
import { dirname, join, relative } from "node:path";
import { fileURLToPath } from "node:url";
import { deflateRawSync } from "node:zlib";

const root = dirname(fileURLToPath(import.meta.url));
const pluginDir = join(root, "logliy");
const phpMain = join(pluginDir, "logliy.php");
const outDir = join(root, "dist");

const SKIP_NAMES = new Set([
  ".DS_Store",
  ".git",
  ".gitignore",
  "node_modules",
  "composer.lock",
]);

const SKIP_DIR_NAMES = new Set([".git", "node_modules"]);

function readPluginVersion() {
  const php = readFileSync(phpMain, "utf8");
  const m =
    php.match(/^\s*\*\s*Version:\s*(\S+)/m) ||
    php.match(/define\(\s*'LOGLIY_VERSION'\s*,\s*'([^']+)'/);
  if (!m) throw new Error("Could not read Version from logliy.php");
  return m[1];
}

function shouldSkip(name, isDir) {
  if (SKIP_NAMES.has(name)) return true;
  if (isDir && SKIP_DIR_NAMES.has(name)) return true;
  return false;
}

function walkFiles(dir) {
  const files = [];
  for (const name of readdirSync(dir)) {
    const full = join(dir, name);
    const st = statSync(full);
    if (shouldSkip(name, st.isDirectory())) continue;
    if (st.isDirectory()) files.push(...walkFiles(full));
    else files.push(full);
  }
  return files;
}

const CRC_TABLE = (() => {
  const table = new Uint32Array(256);
  for (let i = 0; i < 256; i++) {
    let c = i;
    for (let k = 0; k < 8; k++) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1;
    table[i] = c >>> 0;
  }
  return table;
})();

function crc32(buf) {
  let c = 0xffffffff;
  for (let i = 0; i < buf.length; i++) c = CRC_TABLE[(c ^ buf[i]) & 0xff] ^ (c >>> 8);
  return (c ^ 0xffffffff) >>> 0;
}

function buildZip(entries) {
  const parts = [];
  const central = [];
  let offset = 0;

  for (const { name, data } of entries) {
    const raw = Buffer.isBuffer(data) ? data : Buffer.from(data);
    const compressed = deflateRawSync(raw);
    const useComp = compressed.length < raw.length;
    const payload = useComp ? compressed : raw;
    const method = useComp ? 8 : 0;
    const checksum = crc32(raw);
    const nameBuf = Buffer.from(name, "utf8");

    const local = Buffer.alloc(30 + nameBuf.length);
    local.writeUInt32LE(0x04034b50, 0);
    local.writeUInt16LE(20, 4);
    local.writeUInt16LE(0, 6);
    local.writeUInt16LE(method, 8);
    local.writeUInt16LE(0, 10);
    local.writeUInt16LE(0, 12);
    local.writeUInt32LE(checksum, 14);
    local.writeUInt32LE(payload.length, 18);
    local.writeUInt32LE(raw.length, 22);
    local.writeUInt16LE(nameBuf.length, 26);
    local.writeUInt16LE(0, 28);
    nameBuf.copy(local, 30);

    parts.push(local, payload);
    central.push({
      offset,
      crc: checksum,
      compSize: payload.length,
      rawSize: raw.length,
      method,
      nameBuf,
    });
    offset += local.length + payload.length;
  }

  const centralStart = offset;
  const centralParts = [];
  for (const c of central) {
    const hdr = Buffer.alloc(46 + c.nameBuf.length);
    hdr.writeUInt32LE(0x02014b50, 0);
    hdr.writeUInt16LE(20, 4);
    hdr.writeUInt16LE(20, 6);
    hdr.writeUInt16LE(0, 8);
    hdr.writeUInt16LE(c.method, 10);
    hdr.writeUInt16LE(0, 12);
    hdr.writeUInt16LE(0, 14);
    hdr.writeUInt32LE(c.crc, 16);
    hdr.writeUInt32LE(c.compSize, 20);
    hdr.writeUInt32LE(c.rawSize, 24);
    hdr.writeUInt16LE(c.nameBuf.length, 28);
    hdr.writeUInt16LE(0, 30);
    hdr.writeUInt16LE(0, 32);
    hdr.writeUInt16LE(0, 34);
    hdr.writeUInt16LE(0, 36);
    hdr.writeUInt32LE(0, 38);
    hdr.writeUInt32LE(c.offset, 42);
    c.nameBuf.copy(hdr, 46);
    centralParts.push(hdr);
    offset += hdr.length;
  }

  const end = Buffer.alloc(22);
  end.writeUInt32LE(0x06054b50, 0);
  end.writeUInt16LE(0, 4);
  end.writeUInt16LE(0, 6);
  end.writeUInt16LE(central.length, 8);
  end.writeUInt16LE(central.length, 10);
  end.writeUInt32LE(offset - centralStart, 12);
  end.writeUInt32LE(centralStart, 16);
  end.writeUInt16LE(0, 20);

  return Buffer.concat([...parts, ...centralParts, end]);
}

if (!existsSync(phpMain)) {
  throw new Error(`Plugin main file missing: ${phpMain}`);
}

const version = readPluginVersion();
const versionedName = `logliy-${version}.zip`;
const aliasName = "logliy-wordpress.zip";
const outFile = join(outDir, versionedName);
const aliasFile = join(outDir, aliasName);
const versionFile = join(outDir, "logliy-wordpress.version");
const localVersioned = join(root, versionedName);
const localAlias = join(root, aliasName);

mkdirSync(outDir, { recursive: true });

for (const p of [outFile, aliasFile, versionFile, localVersioned, localAlias]) {
  if (existsSync(p)) rmSync(p);
}

// Clean older versioned zips in dist/ (keep folder tidy)
for (const name of readdirSync(outDir)) {
  if (/^logliy-\d+\.\d+\.\d+.*\.zip$/i.test(name) || name === aliasName || name === "logliy-wordpress.version") {
    rmSync(join(outDir, name));
  }
}

const entries = walkFiles(pluginDir).map((full) => {
  const rel = relative(pluginDir, full).split("\\").join("/");
  return { name: `logliy/${rel}`, data: readFileSync(full) };
});

const zipBuf = buildZip(entries);
writeFileSync(outFile, zipBuf);
copyFileSync(outFile, aliasFile);
copyFileSync(outFile, localVersioned);
copyFileSync(outFile, localAlias);
writeFileSync(versionFile, `${version}\n`);

const kb = (statSync(outFile).size / 1024).toFixed(1);
console.log(`Built: ${outFile} (${kb} KB) version=${version}`);
console.log(`Alias: ${aliasFile}`);
console.log(`Local: ${localVersioned}`);
console.log(`Files: ${entries.length}`);
