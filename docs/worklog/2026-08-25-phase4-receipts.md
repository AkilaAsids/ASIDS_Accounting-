# Worklog — Phase 4 receipts + allocation (Minions Team 2)

**Date:** 2026-08-25 · **Branch:** `feature/phase4-payments` → PR #3

## What shipped
Phase 4 Milestone A — **customer receipts + allocation**, backend/service layer only (no HTTP/front-end).
`ReceiptService::record()` records a receipt, allocates it across a customer's issued invoices
(full or partial per invoice, but the receipt must be fully allocated), updates each invoice's
`amount_paid`/`amount_due`/status, and posts a balanced journal entry (Dr Bank/Cash, Cr Trade
Receivables) with gapless `RCT-` numbering, through the existing `PostingService`.

- **Schema:** `customer_receipts` + `receipt_allocations` (FORCED RLS on both, immutability triggers);
  dropped the phase-scoped `sales_invoices_no_payments_until_payments_phase` CHECK and added
  `amount_paid <= total` (the oversell backstop); `DocumentType::CustomerReceipt`.
- **Domain/app:** models, DTOs, `PaymentMethod`, `ReceiptPostingMap`, three refusal exceptions,
  `ReceiptService`, `CustomerReceiptPolicy`, `sales.receipts.manage` (accountant-only).

## Process
Intake (carried) → Gate 1 (requirements + 7 decisions, approved) → architecture (ADR 0014, Opus) →
Gate 2 (approved) → QA red Pest tests (test-first) → Backend Engineer (Opus, 4 stages) → QA
verification ∥ Security review → fixes → PR #3. Three gates honoured; no self-review; Architect,
Backend and Security on Opus (Fable unavailable — approved fallback).

## Verification
`composer lint` (Pint) clean · `composer analyse` (PHPStan, 406 files) clean · full Sales +
Accounting + Authorization Pest **1177 passing / 0 failing** · receipt code ~89.6% covered.
Security review: 0 blockers.

## Review findings
- **7 QA acceptance-test assertions** were test-setup defects (setup invoices post their own `JV`,
  so `JV-0001` / `JournalEntry::count()===0` assumptions were wrong; one missing `openYearContaining`).
  QA adjudicated and fixed its own tests with baseline-delta assertions — no implementation bugs.
- **Security:** clean, 3 cosmetic nits (see STATUS) — deferred as fast-follows.
- The Backend Engineer adapted 4 pre-existing tests to the sanctioned schema change (DocumentType
  4→5, dropped CHECK, a helper rename); QA confirmed all preserve intent, two strengthen.

## Design note
A posted receipt is immutable from its first write (no draft state), so `journal_entry_id` can't be
back-filled. The service builds the receipt + allocations in memory (id assigned up front), posts the
ledger entry citing the receipt as source, then inserts the posted row carrying its ledger link — all
in one transaction, so nothing posts without persisting or persists without posting.

## Interruptions (environmental, no work lost)
Two usage-limit windows and repeated machine-sleep events interrupted subagents mid-run; all resumed
from transcript with no lost work. `caffeinate` used to hold the machine awake.

## Deferred (out of scope this wave)
Receipt cancellation/reversal, unallocated credit on account, withholding tax on receipt, and any
HTTP/front-end surface.
