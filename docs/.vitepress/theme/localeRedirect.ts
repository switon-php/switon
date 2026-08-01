import { onMounted } from 'vue'
import { inBrowser, useData, useRoute, withBase } from 'vitepress'

const BYPASS_QUERY_KEYS = ['stay', 'home', 'no-redirect'] as const

type LocaleConfig = { link?: string }

function shouldBypassRedirect(): boolean {
  const params = new URLSearchParams(window.location.search)
  return BYPASS_QUERY_KEYS.some((key) => params.has(key))
}

function normalizePathname(path: string): string {
  return path.replace(/\/index\.html$/, '').replace(/\/$/, '') || '/'
}

function isRootLocaleIndex(relativePath: string): boolean {
  return relativePath === 'index.md' || relativePath === 'index.html'
}

/** Root locale gateway only (docs/index.md), not /en/ home pages. */
function isRootGateway(
  routePath: string,
  base: string,
  layout: unknown,
  relativePath: string
): boolean {
  if (layout !== 'home' || !isRootLocaleIndex(relativePath)) return false

  const path = normalizePathname(routePath)
  const basePath = normalizePathname(base || '/')
  return path === basePath || path === '/'
}

export function detectPreferredLocale(
  locales: Record<string, LocaleConfig>
): string {
  const available = Object.keys(locales).filter((key) => key !== 'root')
  return available.includes('en') ? 'en' : (available[0] ?? 'en')
}

function resolveLocaleLink(
  locales: Record<string, LocaleConfig>,
  locale: string
): string {
  const configured = locales[locale]?.link
  if (configured) {
    return configured.startsWith('/') ? configured : `/${configured}`
  }
  return `/${locale}/`
}

export function useLocaleRedirect(): void {
  const route = useRoute()
  const { site, page } = useData()

  onMounted(() => {
    if (!inBrowser) return
    if (shouldBypassRedirect()) return
    if (
      !isRootGateway(
        route.path,
        site.value.base,
        page.value.frontmatter.layout,
        page.value.relativePath
      )
    ) {
      return
    }

    const locales = site.value.locales ?? {}
    if (!('root' in locales)) return

    const preferred = detectPreferredLocale(locales)
    const target = resolveLocaleLink(locales, preferred)
    const destination = withBase(target.endsWith('/') ? target : `${target}/`)

    if (window.location.pathname === destination) return

    window.location.replace(destination)
  })
}
