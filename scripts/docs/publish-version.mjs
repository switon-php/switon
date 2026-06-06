import { cpSync, existsSync, mkdirSync, readFileSync, rmSync, writeFileSync } from 'node:fs'
import { join, resolve } from 'node:path'

function parseArgs(argv) {
  const args = {}

  for (let index = 0; index < argv.length; index += 1) {
    const token = argv[index]

    if (!token.startsWith('--')) {
      continue
    }

    const key = token.slice(2)
    const next = argv[index + 1]

    if (!next || next.startsWith('--')) {
      args[key] = 'true'
      continue
    }

    args[key] = next
    index += 1
  }

  return args
}

function readVersions(siteRoot, fallbackVersion, defaultVersion) {
  const versionsFile = join(siteRoot, 'versions.json')

  if (!existsSync(versionsFile)) {
    return [defaultVersion, fallbackVersion].filter((value, index, array) => array.indexOf(value) === index)
  }

  try {
    const parsed = JSON.parse(readFileSync(versionsFile, 'utf8'))

    if (!Array.isArray(parsed)) {
      return [defaultVersion, fallbackVersion].filter((value, index, array) => array.indexOf(value) === index)
    }

    return parsed
      .filter((value) => typeof value === 'string' && value.trim() !== '')
      .map((value) => value.trim())
  } catch {
    return [defaultVersion, fallbackVersion].filter((value, index, array) => array.indexOf(value) === index)
  }
}

function toSemverParts(value) {
  const match = value.match(/^v?(\d+)(?:\.(\d+))?(?:\.(\d+))?$/)

  if (!match) {
    return null
  }

  return [
    Number(match[1] ?? 0),
    Number(match[2] ?? 0),
    Number(match[3] ?? 0)
  ]
}

function compareVersions(left, right) {
  const leftSemver = toSemverParts(left)
  const rightSemver = toSemverParts(right)

  if (leftSemver && rightSemver) {
    for (let index = 0; index < 3; index += 1) {
      if (leftSemver[index] !== rightSemver[index]) {
        return rightSemver[index] - leftSemver[index]
      }
    }

    return 0
  }

  if (leftSemver) {
    return -1
  }

  if (rightSemver) {
    return 1
  }

  return right.localeCompare(left)
}

function mergeVersions(existing, currentVersion, defaultVersion) {
  const merged = new Set(existing)

  merged.add(defaultVersion)
  merged.add(currentVersion)

  const values = [...merged].filter(Boolean)
  const ordered = values
    .filter((value) => value !== defaultVersion)
    .sort(compareVersions)

  return [defaultVersion, ...ordered]
}

function writeRedirect(filePath, targetPath) {
  writeFileSync(
    filePath,
    `<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8" />
    <meta http-equiv="refresh" content="0; url=${targetPath}" />
    <link rel="canonical" href="${targetPath}" />
    <title>Redirecting…</title>
  </head>
  <body>
    <p>Redirecting to <a href="${targetPath}">${targetPath}</a>.</p>
  </body>
</html>
`,
    'utf8'
  )
}

const args = parseArgs(process.argv.slice(2))
const distDir = args.dist ? resolve(args.dist) : null
const siteRoot = args.site ? resolve(args.site) : null
const currentVersion = args.version ?? ''
const defaultVersion = args.default ?? 'latest'
const cname = args.cname && args.cname !== 'true' ? args.cname : ''

if (!distDir || !siteRoot || currentVersion === '') {
  throw new Error('Usage: node scripts/docs/publish-version.mjs --dist <dir> --site <dir> --version <name> [--default latest] [--cname domain]')
}

mkdirSync(siteRoot, { recursive: true })

const existingVersions = readVersions(siteRoot, currentVersion, defaultVersion)
const versions = mergeVersions(existingVersions, currentVersion, defaultVersion)
const versionRoot = join(siteRoot, currentVersion)

rmSync(versionRoot, { recursive: true, force: true })
mkdirSync(versionRoot, { recursive: true })
cpSync(distDir, versionRoot, { recursive: true })

writeFileSync(join(siteRoot, 'versions.json'), `${JSON.stringify(versions, null, 2)}\n`, 'utf8')
writeFileSync(join(siteRoot, '.nojekyll'), '\n', 'utf8')
writeRedirect(join(siteRoot, 'index.html'), `/${defaultVersion}/en/`)
writeRedirect(join(siteRoot, '404.html'), `/${defaultVersion}/en/`)

if (cname !== '') {
  writeFileSync(join(siteRoot, 'CNAME'), `${cname}\n`, 'utf8')
}
