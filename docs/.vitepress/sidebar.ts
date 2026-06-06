import { existsSync, readFileSync, readdirSync, statSync } from 'node:fs'
import { join } from 'node:path'

import type { DefaultTheme } from 'vitepress'

const SECTION_ORDER = ['getting-started', 'concepts', 'guides', 'tutorial']
const SECTION_IGNORE = new Set(['.vitepress'])
const PACKAGE_GROUP_LABELS: Record<string, string> = {
  en: 'Packages',
  'zh-CN': '包文档',
  'ja-JP': 'パッケージ',
  'pt-BR': 'Pacotes'
}
const DOC_META_CACHE = new Map<string, Record<string, string>>()

const SECTION_LABELS: Record<string, string> = {
  authz: 'Authorization',
  beacon: 'Beacon',
  binding: 'Binding',
  cache: 'Cache',
  captcha: 'Captcha',
  cli: 'CLI',
  command: 'Command',
  'composer-extra': 'Composer Extra',
  console: 'Console',
  concepts: 'Concepts',
  context: 'Context',
  core: 'Core',
  crypto: 'Crypto',
  db: 'DB',
  di: 'DI',
  dict: 'Dict',
  event: 'Event',
  executor: 'Executor',
  guides: 'Guides',
  'getting-started': 'Getting Started',
  'http-cache': 'HTTP Cache',
  'http-client': 'HTTP Client',
  http: 'HTTP',
  i18n: 'I18n',
  id: 'ID',
  inspector: 'Inspector',
  invocation: 'Invocation',
  invoker: 'Invoker',
  jwt: 'JWT',
  kernel: 'Kernel',
  log: 'Log',
  mail: 'Mail',
  openapi: 'OpenAPI',
  orm: 'ORM',
  'orm-codegen': 'ORM Codegen',
  pool: 'Pool',
  principal: 'Principal',
  query: 'Query',
  queue: 'Queue',
  redis: 'Redis',
  renderer: 'Renderer',
  router: 'Router',
  rpc: 'RPC',
  schedule: 'Schedule',
  session: 'Session',
  sharding: 'Sharding',
  storage: 'Storage',
  sync: 'Sync',
  testing: 'Testing',
  throttle: 'Throttle',
  tutorial: 'Tutorial',
  highlights: 'Highlights',
  validation: 'Validation',
  view: 'View',
  yaml: 'YAML'
}

const ALL_CAPS_WORDS = new Set(['api', 'cli', 'db', 'di', 'html', 'http', 'i18n', 'id', 'jwt', 'orm', 'rpc', 'yaml'])
const LOWERCASE_WORDS = new Set(['a', 'an', 'and', 'of', 'the', 'to', 'with'])

/** Short nav labels (keywords). Full titles stay in page H1. */
const DOC_SIDEBAR_LABELS: Record<string, Record<string, string>> = {
  en: {
    'add-api-docs': 'API docs',
    'add-authentication': 'Authentication',
    'add-authorization': 'Authorization',
    'add-caching': 'Caching',
    'add-cli': 'CLI',
    'add-cross-cutting-http-behavior': 'HTTP cross-cutting',
    'add-durable-background-work': 'Background work',
    'add-internationalization': 'I18n',
    'add-queue-backed-request-reply': 'Queue RPC',
    'add-recurring-work': 'Schedule',
    'add-testing-to-a-working-app': 'Testing',
    'build-an-api': 'API',
    'build-html-pages': 'HTML pages',
    'call-an-upstream-api': 'HTTP client',
    'deploy-an-app-to-production': 'Deploy',
    'handle-file-uploads-and-downloads': 'File storage',
    'persist-data': 'Persistence',
    'protect-endpoints-with-rate-limits': 'Rate limits',
    'send-transactional-email': 'Email',
    'validation-constant-constraints': 'Validation constants',
    'application-model': 'App model',
    'config-and-conventions': 'Config',
    'framework-methodology': 'Methodology',
    'request-lifecycle': 'Request lifecycle',
    'runtime-and-state': 'Runtime state',
    'service-boundaries': 'Boundaries',
    environment: 'Environment',
    'first-hello-world': 'Hello World',
    install: 'Install',
    '01-http': 'HTTP',
    '02-input': 'Input',
    '03-service': 'Services',
    '04-data': 'Data',
    '05-cli': 'CLI',
    '06-log': 'Log',
    'timed-cleanup': 'Timed cleanup',
    'faster-list': 'Faster list'
  },
  'zh-CN': {
    'add-api-docs': 'API 文档',
    'add-authentication': '认证',
    'add-authorization': '授权',
    'add-caching': '缓存',
    'add-cli': 'CLI',
    'add-cross-cutting-http-behavior': 'HTTP 横切',
    'add-durable-background-work': '后台任务',
    'add-internationalization': '国际化',
    'add-queue-backed-request-reply': '队列 RPC',
    'add-recurring-work': '定时任务',
    'add-testing-to-a-working-app': '测试',
    'build-an-api': 'API',
    'build-html-pages': 'HTML 页面',
    'call-an-upstream-api': 'HTTP 客户端',
    'deploy-an-app-to-production': '部署',
    'handle-file-uploads-and-downloads': '文件存储',
    'persist-data': '持久化',
    'protect-endpoints-with-rate-limits': '限流',
    'send-transactional-email': '邮件',
    'validation-constant-constraints': '校验常量',
    'application-model': '应用模型',
    'config-and-conventions': '配置',
    'framework-methodology': '方法论',
    'request-lifecycle': '请求生命周期',
    'runtime-and-state': '运行时状态',
    'service-boundaries': '边界',
    glossary: '术语表',
    modules: '模块',
    environment: '环境',
    'first-hello-world': 'Hello World',
    install: '安装',
    '01-http': 'HTTP',
    '02-input': '输入',
    '03-service': '服务',
    '04-data': '数据',
    '05-cli': 'CLI',
    '06-log': '日志',
    'timed-cleanup': '定时清理',
    'faster-list': '更快列表'
  }
}

function sidebarLabelFor(locale: string, slug: string, meta: Record<string, string>): string {
  if (meta.sidebar_label) {
    return meta.sidebar_label
  }

  return DOC_SIDEBAR_LABELS[locale]?.[slug] ?? DOC_SIDEBAR_LABELS.en[slug] ?? titleFor(slug)
}

function titleFor(name: string): string {
  return SECTION_LABELS[name] ?? name.split('-').map((part, index) => {
    const lower = part.toLowerCase()

    if (ALL_CAPS_WORDS.has(lower)) {
      return lower.toUpperCase()
    }

    if (index > 0 && LOWERCASE_WORDS.has(lower)) {
      return lower
    }

    return lower.charAt(0).toUpperCase() + lower.slice(1)
  }).join(' ')
}

function readDocMeta(file: string): Record<string, string> {
  const cached = DOC_META_CACHE.get(file)

  if (cached) {
    return cached
  }

  const source = readFileSync(file, 'utf8')
  const match = source.match(/^---\n([\s\S]*?)\n---\n/)

  if (!match) {
    const empty: Record<string, string> = {}
    DOC_META_CACHE.set(file, empty)
    return empty
  }

  const meta: Record<string, string> = {}

  for (const line of match[1].split('\n')) {
    const entry = line.match(/^([A-Za-z0-9_]+):\s*(.+)$/)

    if (!entry) {
      continue
    }

    meta[entry[1]] = entry[2].replace(/^['"]|['"]$/g, '')
  }

  DOC_META_CACHE.set(file, meta)
  return meta
}

function pathToRoute(path: string): string {
  return `/${path
    .replace(/\\/g, '/')
    .replace(/\/README\.md$/, '/')
    .replace(/\/index\.md$/, '/')
    .replace(/\.md$/, '')}`
}

function directoryLink(root: string, locale: string, dir: string): string {
  const readme = join(root, dir, 'README.md')
  const index = join(root, dir, 'index.md')

  if (existsSync(readme)) {
    return pathToRoute(`${locale}/${dir}/README.md`)
  }

  if (existsSync(index)) {
    return pathToRoute(`${locale}/${dir}/index.md`)
  }

  return pathToRoute(`${locale}/index.md`)
}

function collectMarkdownItems(root: string, locale: string, dir: string): DefaultTheme.SidebarItem[] {
  const target = join(root, dir)
  const entries = readdirSync(target).sort((left, right) => left.localeCompare(right))
  const items: DefaultTheme.SidebarItem[] = []

  for (const entry of entries) {
    const fullPath = join(target, entry)
    const stats = statSync(fullPath)

    if (stats.isDirectory()) {
      const readmePath = join(fullPath, 'README.md')
      const indexPath = join(fullPath, 'index.md')
      const targetPath = existsSync(readmePath)
        ? readmePath
        : existsSync(indexPath)
          ? indexPath
          : ''
      const nestedLink = targetPath === ''
        ? ''
        : targetPath === readmePath
          ? pathToRoute(`${locale}/${dir}/${entry}/README.md`)
          : pathToRoute(`${locale}/${dir}/${entry}/index.md`)
      const meta = targetPath === '' ? {} : readDocMeta(targetPath)

      if (nestedLink !== '') {
        items.push({
          text: sidebarLabelFor(locale, entry, meta),
          link: nestedLink
        })
      }

      continue
    }

    if (
      !entry.endsWith('.md') ||
      entry === 'README.md' ||
      entry === 'index.md' ||
      entry === 'design.md' ||
      entry === 'faq.md'
    ) {
      continue
    }

    const meta = readDocMeta(fullPath)

    const slug = entry.replace(/\.md$/, '')

    items.push({
      text: sidebarLabelFor(locale, slug, meta),
      link: pathToRoute(`${locale}/${dir}/${entry}`)
    })
  }

  return items
}

function sectionSidebarItem(localeRoot: string, locale: string, entry: string): DefaultTheme.SidebarItem {
  const readmePath = join(localeRoot, entry, 'README.md')
  const readmeMeta = existsSync(readmePath) ? readDocMeta(readmePath) : {}

  const base: DefaultTheme.SidebarItem = {
    text: readmeMeta.sidebar_label ?? titleFor(entry),
    link: directoryLink(localeRoot, locale, entry)
  }

  const items = collectMarkdownItems(localeRoot, locale, entry)

  if (items.length === 0) {
    return base
  }

  return {
    ...base,
    items
  }
}

function packageSidebarItem(localeRoot: string, locale: string, entry: string): DefaultTheme.SidebarItem {
  const readmePath = join(localeRoot, entry, 'README.md')
  const readmeMeta = existsSync(readmePath) ? readDocMeta(readmePath) : {}

  return {
    text: readmeMeta.sidebar_label ?? titleFor(entry),
    link: directoryLink(localeRoot, locale, entry)
  }
}

export function createSidebar(localeRoot: string, locale: string): DefaultTheme.SidebarItem[] {
  const directories = readdirSync(localeRoot)
    .filter((entry) => !SECTION_IGNORE.has(entry))
    .filter((entry) => statSync(join(localeRoot, entry)).isDirectory())

  const sections = SECTION_ORDER.filter((entry) => directories.includes(entry))
  const packages = directories
    .filter((entry) => !SECTION_ORDER.includes(entry))
    .sort((left, right) => left.localeCompare(right))

  const sidebar: DefaultTheme.SidebarItem[] = sections.map((entry) =>
    sectionSidebarItem(localeRoot, locale, entry)
  )

  if (packages.length === 0) {
    return sidebar
  }

  const packagesIndex = join(localeRoot, 'packages.md')

  sidebar.push({
    text: PACKAGE_GROUP_LABELS[locale] ?? 'Packages',
    link: existsSync(packagesIndex) ? pathToRoute(`${locale}/packages.md`) : undefined,
    collapsed: true,
    items: packages.map((entry) => packageSidebarItem(localeRoot, locale, entry))
  })

  return sidebar
}
