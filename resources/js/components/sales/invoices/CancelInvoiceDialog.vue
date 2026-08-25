<script setup lang="ts">
import ConfirmDialog from '@/components/ui/ConfirmDialog.vue'

/**
 * The invoice-cancel confirm dialog (requirements §4.11, design §2.2.6). A required 3–255
 * character reason, framed explicitly as "not an undo" — cancelling posts a reversing mirror
 * entry, it does not touch the original one. The dismiss button says "Go back," never
 * "Cancel," since the dialog's own subject is cancelling the invoice (§1.2's naming rule).
 */
defineProps<{ open: boolean }>()

const emit = defineEmits<{ confirm: [reason: string]; cancel: [] }>()

function onConfirm(reason: string | undefined): void {
  emit('confirm', reason ?? '')
}
</script>

<template>
  <ConfirmDialog
    :open="open"
    mode="reason"
    danger
    title="Cancel this invoice?"
    message="This does not delete or undo the original entry — it posts a mirror entry that reverses it. The reason you give is recorded against this invoice permanently."
    reason-label="Reason for cancelling"
    confirm-label="Cancel invoice"
    cancel-label="Go back"
    @confirm="onConfirm"
    @cancel="emit('cancel')"
  />
</template>
