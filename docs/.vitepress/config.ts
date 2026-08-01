import { fileURLToPath } from 'node:url'
import { join } from 'node:path'
import { readdirSync, statSync } from 'node:fs'

import { defineConfig } from 'vitepress'

import { createSidebar } from './sidebar'
import { createVersionNav } from './versions'

const docsRoot = fileURLToPath(new URL('..', import.meta.url))
const englishRoot = join(docsRoot, 'en')
const docsBase = process.env.DOCS_BASE ?? '/latest/'
const excludedRoots = readdirSync(docsRoot)
  .filter((entry) => !['.vitepress', 'en'].includes(entry))
  .filter((entry) => statSync(join(docsRoot, entry)).isDirectory())
  .map((entry) => `${entry}/**`)
const excludedFiles = ['en/README.md']

function createNav() {
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
    ...excludedFiles
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
        nav: createNav(),
        sidebar: createSidebar(englishRoot, 'en')
      }
    }
  },
  themeConfig: {
    logo: '/brand/switon-mark.png',
    siteTitle: "<span class='switon-nav-brand-left'>Swi</span><span class='switon-nav-brand-right'>ton</span>",
    nav: createNav(),
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
