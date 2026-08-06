<script setup lang="ts">
import { computed } from 'vue'
import { useAuthStore } from '@/stores/auth'

/**
 * Renders its slot only when the user holds one of the given permissions.
 *
 * Presentation only. The server authorises every request independently, so this hides
 * controls the user cannot use rather than protecting anything — a user who edits the DOM
 * gains a button that returns 403.
 */
const props = defineProps<{ permission: string | string[] }>()

const auth = useAuthStore()

const allowed = computed(() =>
  Array.isArray(props.permission) ? auth.canAny(...props.permission) : auth.can(props.permission),
)
</script>

<template>
  <slot v-if="allowed" />
  <slot v-else name="fallback" />
</template>
