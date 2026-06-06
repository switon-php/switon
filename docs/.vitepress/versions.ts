import type { DefaultTheme } from 'vitepress'

const currentVersion = process.env.DOCS_VERSION ?? 'latest'
const docsOrigin = (process.env.DOCS_ORIGIN ?? 'https://docs.switon.dev').replace(/\/$/, '')

const configuredVersions = (process.env.DOCS_VERSIONS ?? currentVersion)
  .split(',')
  .map((value) => value.trim())
  .filter(Boolean)

function versionLink(version: string): string {
  if (version === currentVersion || version === 'latest') {
    return '/en/'
  }

  return `${docsOrigin}/${version}/en/`
}

export function createVersionNav(): DefaultTheme.NavItem[] {
  if (configuredVersions.length <= 1) {
    return []
  }

  const label = currentVersion === 'latest' ? 'latest' : currentVersion

  return [
    {
      text: label,
      items: configuredVersions.map((version) => ({
        text: version,
        link: versionLink(version)
      }))
    }
  ]
}
