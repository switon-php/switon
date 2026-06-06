import { h } from 'vue'
import DefaultTheme from 'vitepress/theme'

import HttpLifecycleDiagram from './components/HttpLifecycleDiagram.vue'
import SiteFooter from './components/SiteFooter.vue'
import { useLocaleRedirect } from './localeRedirect'
import './custom.css'

export default {
  ...DefaultTheme,
  Layout() {
    return h(DefaultTheme.Layout, null, {
      'doc-after': () => h(SiteFooter)
    })
  },
  setup() {
    DefaultTheme.setup?.()
    useLocaleRedirect()
  },
  enhanceApp({ app }) {
    app.component('HttpLifecycleDiagram', HttpLifecycleDiagram)
  }
}
