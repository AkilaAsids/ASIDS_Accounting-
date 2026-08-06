<script setup lang="ts">
import { RouterView } from 'vue-router'
import StepUpDialog from '@/components/app/StepUpDialog.vue'
import NoticeStack from '@/components/app/NoticeStack.vue'
</script>

<template>
  <!-- The skip link is the first tab stop on every page: keyboard users must be able to
       reach the main content without traversing the whole sidebar. -->
  <a href="#main" class="skip-link">Skip to main content</a>

  <RouterView v-slot="{ Component, route }">
    <!-- Keyed on the route so a navigation between two instances of the same component
         (a different user's profile, say) re-mounts rather than silently reusing state. -->
    <component :is="Component" :key="route.path" />
  </RouterView>

  <NoticeStack />
  <StepUpDialog />
</template>
