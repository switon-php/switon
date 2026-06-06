import { fileURLToPath } from 'node:url'
import { join } from 'node:path'
import { existsSync, readdirSync, statSync } from 'node:fs'

import { defineConfig } from 'vitepress'

import { createSidebar } from './sidebar'
import { createVersionNav } from './versions'

const docsRoot = fileURLToPath(new URL('..', import.meta.url))
const englishRoot = join(docsRoot, 'en')
const zhCnRoot = join(docsRoot, 'zh-CN')
const ptBrRoot = join(docsRoot, 'pt-BR')
const jaJpRoot = join(docsRoot, 'ja-JP')
const enableJaJp = false
const docsBase = process.env.DOCS_BASE ?? '/latest/'
const excludedRoots = readdirSync(docsRoot)
  .filter((entry) => !['.vitepress', 'en', 'zh-CN', 'pt-BR', 'ja-JP'].includes(entry))
  .filter((entry) => statSync(join(docsRoot, entry)).isDirectory())
  .map((entry) => `${entry}/**`)
const excludedFiles = [
  'en/README.md',
  'zh-CN/README.md',
  'pt-BR/README.md',
  'ja-JP/README.md'
]

function createNav(locale: 'en' | 'ja-JP' | 'pt-BR' | 'zh-CN') {
  if (locale === 'zh-CN') {
    return [
      {
        text: '首页',
        link: '/zh-CN/'
      },
      {
        text: '入门',
        link: '/zh-CN/getting-started/'
      },
      {
        text: '概念',
        link: '/zh-CN/concepts/'
      },
      {
        text: '指南',
        link: '/zh-CN/guides/'
      },
      {
        text: '教程',
        link: '/zh-CN/tutorial/'
      },
      {
        text: '包索引',
        link: '/zh-CN/packages'
      },
      ...createVersionNav()
    ]
  }

  if (locale === 'ja-JP') {
    return [
      {
        text: 'ホーム',
        link: '/ja-JP/'
      },
      {
        text: '認可パッケージ',
        link: '/ja-JP/authz/'
      },
      {
        text: 'Beacon パッケージ',
        link: '/ja-JP/beacon/'
      },
      {
        text: 'Binding パッケージ',
        link: '/ja-JP/binding/'
      },
      {
        text: 'Cache パッケージ',
        link: '/ja-JP/cache/'
      },
      {
        text: 'Captcha パッケージ',
        link: '/ja-JP/captcha/'
      },
      {
        text: 'CLI パッケージ',
        link: '/ja-JP/cli/'
      },
      {
        text: 'Command パッケージ',
        link: '/ja-JP/command/'
      },
      {
        text: 'Composer Extra パッケージ',
        link: '/ja-JP/composer-extra/'
      },
      {
        text: 'Console パッケージ',
        link: '/ja-JP/console/'
      },
      {
        text: 'Context パッケージ',
        link: '/ja-JP/context/'
      },
      {
        text: 'Core パッケージ',
        link: '/ja-JP/core/'
      },
      {
        text: 'Crypto パッケージ',
        link: '/ja-JP/crypto/'
      },
      {
        text: 'DB パッケージ',
        link: '/ja-JP/db/'
      },
      {
        text: 'DI パッケージ',
        link: '/ja-JP/di/'
      },
      {
        text: 'Dict パッケージ',
        link: '/ja-JP/dict/'
      },
      {
        text: 'Event パッケージ',
        link: '/ja-JP/event/'
      },
      {
        text: 'Executor パッケージ',
        link: '/ja-JP/executor/'
      },
      {
        text: 'HTTP パッケージ',
        link: '/ja-JP/http/'
      },
      {
        text: 'Invocation パッケージ',
        link: '/ja-JP/invocation/'
      },
      {
        text: 'Invoker パッケージ',
        link: '/ja-JP/invoker/'
      },
      {
        text: 'JWT パッケージ',
        link: '/ja-JP/jwt/'
      },
      {
        text: 'Log パッケージ',
        link: '/ja-JP/log/'
      },
      ...createVersionNav()
    ]
  }

  if (locale === 'pt-BR') {
    return [
      {
        text: 'Início',
        link: '/pt-BR/'
      },
      {
        text: 'Primeiros passos',
        link: '/pt-BR/getting-started/'
      },
      {
        text: 'Conceitos',
        link: '/pt-BR/concepts/'
      },
      {
        text: 'Guias',
        link: '/pt-BR/guides/'
      },
      {
        text: 'Tutorial',
        link: '/pt-BR/tutorial/'
      },
      {
        text: 'Pacotes',
        link: '/pt-BR/packages'
      },
      ...createVersionNav()
    ]
  }

  return [
    {
      text: 'Home',
      link: '/en/'
    },
    {
      text: 'Getting Started',
      link: '/en/getting-started/'
    },
    {
      text: 'Concepts',
      link: '/en/concepts/'
    },
    {
      text: 'Guides',
      link: '/en/guides/'
    },
    {
      text: 'Tutorial',
      link: '/en/tutorial/'
    },
    {
      text: 'Packages',
      link: '/en/packages'
    },
    ...createVersionNav()
  ]
}

export default defineConfig({
  title: 'Switon',
  description: 'Switon documentation for the AI-era PHP framework.',
  lang: 'en-US',
  cleanUrls: true,
  lastUpdated: true,
  head: [
    ['link', { rel: 'icon', type: 'image/png', sizes: '32x32', href: '/favicon-32.png' }],
    ['link', { rel: 'icon', type: 'image/png', href: '/favicon.png' }],
    ['link', { rel: 'apple-touch-icon', href: '/brand/switon-mark-192.png' }],
    ['meta', { name: 'theme-color', media: '(prefers-color-scheme: light)', content: '#f7fbff' }],
    ['meta', { name: 'theme-color', media: '(prefers-color-scheme: dark)', content: '#0f1726' }],
    ['meta', { property: 'og:type', content: 'website' }],
    ['meta', { property: 'og:site_name', content: 'Switon' }],
    ['meta', { property: 'og:title', content: 'Switon' }],
    ['meta', { property: 'og:description', content: 'Switon documentation for the AI-era PHP framework.' }],
    ['meta', { property: 'og:url', content: 'https://docs.switon.dev/en/' }],
    ['meta', { property: 'og:image', content: 'https://docs.switon.dev/brand/switon-mark.png' }],
    ['meta', { name: 'twitter:card', content: 'summary_large_image' }],
    ['meta', { name: 'twitter:title', content: 'Switon' }],
    ['meta', { name: 'twitter:description', content: 'Switon documentation for the AI-era PHP framework.' }],
    ['meta', { name: 'twitter:image', content: 'https://docs.switon.dev/brand/switon-mark.png' }]
  ],
  srcExclude: [
    ...excludedRoots,
    ...excludedFiles,
    ...(enableJaJp ? [] : ['ja-JP/**'])
  ],
  rewrites(id) {
    return id.replace(/(^|\/)README\.md$/, '$1index.md')
  },
  markdown: {
    config(md) {
      const fallbackRender = md.renderer.rules.code_inline ?? ((tokens, index, options, _env, self) => {
        return self.renderToken(tokens, index, options)
      })
      const fallbackLinkOpen = md.renderer.rules.link_open ?? ((tokens, index, options, _env, self) => {
        return self.renderToken(tokens, index, options)
      })

      md.renderer.rules.code_inline = (tokens, index, options, env, self) => {
        const token = tokens[index]

        if (
          token.content.includes('{{') ||
          token.content.includes('{!!') ||
          token.content.includes('${')
        ) {
          token.attrSet('v-pre', '')
        }

        return fallbackRender(tokens, index, options, env, self)
      }

      md.renderer.rules.link_open = (tokens, index, options, env, self) => {
        const token = tokens[index]
        const href = token.attrGet('href')

        if (href) {
          token.attrSet(
            'href',
            href
              .replace(/\/README\.md(?=[#?]|$)/g, '/')
              .replace(/\/README(?=[#?]|$)/g, '/')
              .replace(/\/index\.md(?=[#?]|$)/g, '/')
              .replace(/\/index(?=[#?]|$)/g, '/')
          )
        }

        return fallbackLinkOpen(tokens, index, options, env, self)
      }
    }
  },
  ignoreDeadLinks: [
    /^https?:\/\//
  ],
  sitemap: {
    hostname: 'https://docs.switon.dev'
  },
  base: docsBase,
  locales: {
    root: {
      label: 'Home',
      lang: 'en-US',
      title: 'Switon',
      description: 'Switon documentation for the AI-era PHP framework.',
      themeConfig: {
        nav: [
          { text: 'English', link: '/en/' },
          ...(existsSync(zhCnRoot) ? [{ text: '简体中文', link: '/zh-CN/' }] : []),
          ...(enableJaJp && existsSync(jaJpRoot) ? [{ text: '日本語', link: '/ja-JP/' }] : []),
          ...(existsSync(ptBrRoot) ? [{ text: 'Português (Brasil)', link: '/pt-BR/' }] : []),
          ...createVersionNav()
        ]
      }
    },
    en: {
      label: 'English',
      lang: 'en-US',
      link: '/en/',
      title: 'Switon',
      description: 'Switon documentation for the AI-era PHP framework.',
      themeConfig: {
        nav: createNav('en'),
        sidebar: createSidebar(englishRoot, 'en')
      }
    },
    ...(existsSync(zhCnRoot)
      ? {
          'zh-CN': {
            label: '简体中文',
            lang: 'zh-CN',
            link: '/zh-CN/',
            title: 'Switon',
            description: 'Switon 框架的简体中文文档。',
            themeConfig: {
              nav: createNav('zh-CN'),
              sidebar: createSidebar(zhCnRoot, 'zh-CN')
            }
          }
        }
      : {}),
    ...(enableJaJp && existsSync(jaJpRoot)
      ? {
          'ja-JP': {
            label: '日本語',
            lang: 'ja',
            link: '/ja-JP/',
            title: 'Switon',
            description: 'Switon フレームワークの日本語ドキュメント（認可・Beacon〜JWT・Log など）。',
            themeConfig: {
              nav: createNav('ja-JP'),
              sidebar: createSidebar(jaJpRoot, 'ja-JP')
            }
          }
        }
      : {}),
    ...(existsSync(ptBrRoot)
      ? {
          'pt-BR': {
            label: 'Português (Brasil)',
            lang: 'pt-BR',
            link: '/pt-BR/',
            title: 'Switon',
            description: 'Documentação Switon em português do Brasil.',
            themeConfig: {
              nav: createNav('pt-BR'),
              sidebar: createSidebar(ptBrRoot, 'pt-BR')
            }
          }
        }
      : {})
  },
  themeConfig: {
    logo: '/brand/switon-mark.png',
    siteTitle: "<span class='switon-nav-brand-left'>Swi</span><span class='switon-nav-brand-right'>ton</span>",
    nav: createNav('en'),
    sidebar: createSidebar(englishRoot, 'en'),
    socialLinks: [
      {
        icon: 'github',
        link: 'https://github.com/switon-php'
      }
    ],
    footer: {
      message: 'Released under the MIT License.',
      copyright: 'Copyright © 2026 Switon'
    },
    outline: {
      level: [2, 2]
    },
    search: {
      provider: 'local'
    }
  }
})
