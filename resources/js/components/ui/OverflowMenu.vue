<script setup lang="ts">
import { nextTick, onBeforeUnmount, onMounted, ref } from 'vue'
import BaseButton from '@/components/ui/BaseButton.vue'

/**
 * The one overflow ("⋯") menu primitive in the application (Gate-2 decision B).
 *
 * Both lanes' row/detail actions — the customer lifecycle menu and the invoice actions menu
 * — reuse this rather than each hand-rolling a popover, and "Delete" always lives inside one
 * of these rather than as a peer of "Edit"/"Archive" (PHASE-3-FRONTEND-DESIGN.md §1.2).
 *
 * The default slot is scoped with `close`, so a menu item that opens a further confirm
 * dialog (the common case — every delete does) can close this menu itself once its own click
 * handler has done whatever it needs, rather than the menu closing itself on every click and
 * risking a race with a dialog that has not opened yet.
 */
const props = defineProps<{ label: string }>()

const open = ref(false)
const trigger = ref<InstanceType<typeof BaseButton> | null>(null)
const panel = ref<HTMLElement | null>(null)

function toggle(): void {
  if (open.value) {
    close()
  } else {
    show()
  }
}

function show(): void {
  open.value = true
  void nextTick(() => {
    panel.value?.querySelector<HTMLElement>('[role="menuitem"]')?.focus()
  })
}

function close(): void {
  if (!open.value) {
    return
  }

  open.value = false
  ;(trigger.value?.$el as HTMLElement | undefined)?.focus()
}

function items(): HTMLElement[] {
  return Array.from(panel.value?.querySelectorAll<HTMLElement>('[role="menuitem"]') ?? [])
}

function onKeydown(event: KeyboardEvent): void {
  if (event.key === 'Escape') {
    event.preventDefault()
    close()
    return
  }

  if (event.key !== 'Tab') {
    return
  }

  // Focus trap: Tab/Shift+Tab cycles within the menu rather than escaping to the page behind
  // it, matching the confirm dialog's own mechanics (§1.2's "reuse the same … mechanics").
  const list = items()
  if (list.length === 0) {
    return
  }

  const first = list[0]
  const last = list[list.length - 1]

  if (event.shiftKey && document.activeElement === first) {
    event.preventDefault()
    last?.focus()
  } else if (!event.shiftKey && document.activeElement === last) {
    event.preventDefault()
    first?.focus()
  }
}

function onClickOutside(event: MouseEvent): void {
  if (!open.value) {
    return
  }

  const target = event.target as Node
  const triggerEl = trigger.value?.$el as HTMLElement | undefined

  if (panel.value?.contains(target) || triggerEl?.contains(target)) {
    return
  }

  close()
}

onMounted(() => document.addEventListener('click', onClickOutside))
onBeforeUnmount(() => document.removeEventListener('click', onClickOutside))

defineExpose({ close })
</script>

<template>
  <div class="relative inline-block" @keydown="onKeydown">
    <BaseButton
      ref="trigger"
      type="button"
      variant="ghost"
      size="sm"
      :aria-label="label"
      aria-haspopup="menu"
      :aria-expanded="open"
      @click="toggle"
    >
      <svg class="h-4 w-4" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
        <circle cx="12" cy="5" r="1.5" />
        <circle cx="12" cy="12" r="1.5" />
        <circle cx="12" cy="19" r="1.5" />
      </svg>
    </BaseButton>

    <div
      v-if="open"
      ref="panel"
      role="menu"
      :aria-label="props.label"
      class="absolute right-0 z-20 mt-1 w-48 rounded-md border border-surface-border bg-surface-raised py-1 shadow-overlay animate-fade-in"
    >
      <slot :close="close" />
    </div>
  </div>
</template>
