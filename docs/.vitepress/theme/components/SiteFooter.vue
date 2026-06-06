<script setup lang="ts">
import { computed } from 'vue'
import { useData } from 'vitepress'

const { theme, frontmatter } = useData()

const show = computed(
  () => theme.value.footer && frontmatter.value.footer !== false
)
</script>

<template>
  <footer v-if="show" class="sw-site-footer">
    <p
      v-if="theme.footer?.message"
      class="message"
      v-html="theme.footer.message"
    />
    <p
      v-if="theme.footer?.copyright"
      class="copyright"
      v-html="theme.footer.copyright"
    />
  </footer>
</template>

<style scoped>
.sw-site-footer {
  margin-top: 64px;
  padding-top: 32px;
  border-top: 1px solid var(--vp-c-gutter);
  text-align: center;
}

.sw-site-footer :deep(a) {
  text-decoration-line: underline;
  text-underline-offset: 2px;
  transition: color 0.25s;
}

.sw-site-footer :deep(a:hover) {
  color: var(--vp-c-text-1);
}

.message,
.copyright {
  line-height: 24px;
  font-size: 14px;
  font-weight: 500;
  color: var(--vp-c-text-2);
}
</style>
