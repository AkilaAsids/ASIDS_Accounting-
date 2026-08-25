<script setup lang="ts">
import { computed, ref } from 'vue'
import { RouterLink } from 'vue-router'
import BaseButton from '@/components/ui/BaseButton.vue'
import ConfirmDialog from '@/components/ui/ConfirmDialog.vue'
import OverflowMenu from '@/components/ui/OverflowMenu.vue'
import CancelInvoiceDialog from '@/components/sales/invoices/CancelInvoiceDialog.vue'
import { useMoney } from '@/composables/useMoney'
import type { SalesInvoice } from '@/types/domain'

/**
 * Edit / Issue / Cancel / Delete for the invoice detail header (ADR 0013 §7 table,
 * requirements §4.9–§4.12). Every control is gated on the invoice's own `capabilities`
 * object — never on the raw permission alone (ADR 0012 D4's owner gap) — and every
 * destructive/consequential action is confirmed before it fires:
 *
 *   - Edit: `capabilities.can_update` (draft only).
 *   - Issue: Tier-2 confirm, `capabilities.can_issue`.
 *   - Cancel: Tier-2 confirm with a required reason, `capabilities.can_cancel`.
 *   - Delete: behind the overflow menu, Tier-3 confirm (checkbox — a draft has no `number`
 *     to type), `capabilities.can_delete`.
 *
 * This component never calls the API itself — it only emits a confirmed intent. The page
 * performs the request and maps each documented refusal to its own toast wording (§4.10.3/
 * §4.11.3), which is page-level concern, not this component's.
 */
const props = defineProps<{ invoice: SalesInvoice; busy?: boolean }>()

const emit = defineEmits<{ issue: []; cancel: [reason: string]; delete: [] }>()

const { formatPlain } = useMoney()

const issueOpen = ref(false)
const cancelOpen = ref(false)
const deleteOpen = ref(false)

const issueMessage = computed(
  () =>
    `Issuing posts this to the ledger and assigns an invoice number for ${props.invoice.customer?.name ?? 'this customer'} (total ${formatPlain(props.invoice.total)}). It can only be undone by cancelling it — issuing itself cannot be reversed.`,
)

function confirmIssue(): void {
  issueOpen.value = false
  emit('issue')
}

function confirmCancel(reason: string): void {
  cancelOpen.value = false
  emit('cancel', reason)
}

function confirmDelete(): void {
  deleteOpen.value = false
  emit('delete')
}
</script>

<template>
  <div class="flex flex-wrap items-center gap-2">
    <RouterLink v-if="invoice.capabilities.can_update" :to="{ name: 'invoice-edit', params: { invoiceId: invoice.id } }">
      <BaseButton variant="secondary" size="sm">Edit</BaseButton>
    </RouterLink>

    <BaseButton
      v-if="invoice.capabilities.can_issue"
      variant="primary"
      size="sm"
      :disabled="busy"
      @click="issueOpen = true"
    >
      Issue invoice
    </BaseButton>

    <BaseButton
      v-if="invoice.capabilities.can_cancel"
      variant="danger"
      size="sm"
      :disabled="busy"
      @click="cancelOpen = true"
    >
      Cancel invoice
    </BaseButton>

    <OverflowMenu
      v-if="invoice.capabilities.can_delete"
      :label="`More actions for invoice ${invoice.number ?? 'draft'}`"
    >
      <template #default="{ close }">
        <button
          type="button"
          role="menuitem"
          class="block w-full px-3 py-1.5 text-left text-sm text-danger hover:bg-surface-sunken"
          @click="deleteOpen = true; close()"
        >
          Delete
        </button>
      </template>
    </OverflowMenu>

    <ConfirmDialog
      :open="issueOpen"
      title="Issue this invoice?"
      :message="issueMessage"
      confirm-label="Issue invoice"
      cancel-label="Go back"
      @confirm="confirmIssue"
      @cancel="issueOpen = false"
    />

    <CancelInvoiceDialog :open="cancelOpen" @confirm="confirmCancel" @cancel="cancelOpen = false" />

    <ConfirmDialog
      :open="deleteOpen"
      mode="typed"
      danger
      title="Delete this draft invoice?"
      message="This is permanent — there is no restore."
      :confirm-token="null"
      confirm-label="Delete draft"
      @confirm="confirmDelete"
      @cancel="deleteOpen = false"
    />
  </div>
</template>
