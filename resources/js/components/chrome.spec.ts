import { beforeEach, describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { nextTick } from 'vue'
import { useUiStore } from '@/stores/ui'
import BaseButton from '@/components/ui/BaseButton.vue'
import SurfaceCard from '@/components/ui/SurfaceCard.vue'
import NoticeStack from '@/components/app/NoticeStack.vue'
import ThemeToggle from '@/components/app/ThemeToggle.vue'
import StepUpDialog from '@/components/app/StepUpDialog.vue'

/**
 * The interface chrome.
 *
 * `StepUpDialog` is the one with real machinery: it registers `window.asidsRequestStepUp` so the API
 * client can await a code without importing Vue, and the client then replays the original request.
 * That indirection is what makes step-up a control rather than an obstacle — the user's action
 * completes after confirmation instead of failing and needing to be started again.
 */

beforeEach(() => {
  setActivePinia(createPinia())
  delete (window as { asidsRequestStepUp?: unknown }).asidsRequestStepUp
})

describe('BaseButton', () => {
  it('renders its slot', () => {
    expect(mount(BaseButton, { slots: { default: 'Save' } }).text()).toBe('Save')
  })

  it('defaults to a non-submitting button', () => {
    // A button inside a form defaults to `submit` in HTML, which submits the form on any click.
    expect(mount(BaseButton).attributes('type')).toBe('button')
  })

  it('can submit when asked', () => {
    expect(mount(BaseButton, { props: { type: 'submit' } }).attributes('type')).toBe('submit')
  })

  it('disables itself and announces busy while loading', () => {
    const wrapper = mount(BaseButton, { props: { loading: true }, slots: { default: 'Save' } })

    // Both matter: `disabled` stops the second click, `aria-busy` tells a screen-reader user why
    // nothing appears to be happening.
    expect(wrapper.attributes('disabled')).toBeDefined()
    expect(wrapper.attributes('aria-busy')).toBe('true')
  })

  it('keeps its label while loading, so the layout does not reflow', () => {
    const wrapper = mount(BaseButton, { props: { loading: true }, slots: { default: 'Pay now' } })

    // A button that swaps its text for a spinner changes width, and a shifting layout under the
    // cursor is how a payment gets submitted twice.
    expect(wrapper.text()).toContain('Pay now')
    expect(wrapper.find('svg').exists()).toBe(true)
  })

  it('is disabled when told, without claiming to be busy', () => {
    const wrapper = mount(BaseButton, { props: { disabled: true } })

    expect(wrapper.attributes('disabled')).toBeDefined()
    expect(wrapper.attributes('aria-busy')).toBe('false')
  })

  it('emits a click when enabled and not while loading', async () => {
    const enabled = mount(BaseButton)
    await enabled.trigger('click')
    expect(enabled.emitted('click')).toBeTruthy()

    const loading = mount(BaseButton, { props: { loading: true } })
    await loading.trigger('click')
    expect(loading.emitted('click')).toBeFalsy()
  })

  it('styles each variant distinctly', () => {
    const classes = (['primary', 'secondary', 'danger', 'ghost'] as const).map((variant) =>
      mount(BaseButton, { props: { variant } }).classes().join(' '),
    )

    expect(new Set(classes).size).toBe(4)
  })

  it('can fill its container', () => {
    expect(mount(BaseButton, { props: { block: true } }).classes()).toContain('w-full')
  })
})

describe('SurfaceCard', () => {
  it('renders its content', () => {
    expect(mount(SurfaceCard, { slots: { default: 'Body' } }).text()).toContain('Body')
  })

  it('renders a heading when given a title', () => {
    const wrapper = mount(SurfaceCard, { props: { title: 'Two factor authentication' } })

    // A real `<h2>`, not styled text: a screen-reader user navigates a settings page by heading.
    expect(wrapper.find('h2').text()).toBe('Two factor authentication')
  })

  it('omits the header entirely when there is no title', () => {
    expect(mount(SurfaceCard, { slots: { default: 'Body' } }).find('header').exists()).toBe(false)
  })

  it('renders a description under the title', () => {
    const wrapper = mount(SurfaceCard, {
      props: { title: 'Invite a user', description: 'They choose their own password.' },
    })

    expect(wrapper.text()).toContain('They choose their own password.')
  })

  it('omits the footer unless a footer slot is provided', () => {
    expect(mount(SurfaceCard, { slots: { default: 'Body' } }).find('footer').exists()).toBe(false)

    expect(
      mount(SurfaceCard, { slots: { default: 'Body', footer: 'Actions' } })
        .find('footer')
        .exists(),
    ).toBe(true)
  })
})

describe('NoticeStack', () => {
  it('renders nothing when there is nothing to say', () => {
    expect(mount(NoticeStack).findAll('p')).toHaveLength(0)
  })

  it('renders a notice from the store', async () => {
    const wrapper = mount(NoticeStack)

    useUiStore().notify('success', 'Settings saved.')
    await nextTick()

    expect(wrapper.text()).toContain('Settings saved.')
  })

  it('announces politely rather than interrupting', () => {
    const wrapper = mount(NoticeStack)

    // Queried rather than read off the wrapper: the template opens with an explanatory comment, so
    // the component's root node is that comment and `wrapper.attributes()` describes nothing.
    const region = wrapper.find('[role="status"]')

    // A save confirmation must not cut across a screen reader mid-sentence. Errors persist until
    // dismissed, so nothing is missed by being polite about them either.
    expect(region.exists()).toBe(true)
    expect(region.attributes('aria-live')).toBe('polite')
  })

  it('labels each dismiss button with what it dismisses', async () => {
    const wrapper = mount(NoticeStack)

    useUiStore().notify('error', 'Could not save.')
    await nextTick()

    // Three identical "Dismiss" buttons are indistinguishable in a screen reader's element list.
    expect(wrapper.find('button').attributes('aria-label')).toBe('Dismiss: Could not save.')
  })

  it('dismisses the notice it belongs to', async () => {
    const wrapper = mount(NoticeStack)
    const ui = useUiStore()

    ui.notify('info', 'First')
    await nextTick()

    await wrapper.find('button').trigger('click')

    expect(ui.notices).toHaveLength(0)
  })
})

describe('ThemeToggle', () => {
  it('offers three choices, not a binary switch', () => {
    const wrapper = mount(ThemeToggle)

    const group = wrapper.find('[role="radiogroup"]')

    // "Match my device" is a distinct choice from "always light". A two-state toggle silently
    // overrides the OS preference and there is no way back to following it.
    expect(wrapper.findAll('[role="radio"]')).toHaveLength(3)
    expect(group.exists()).toBe(true)
    expect(group.attributes('aria-label')).toBe('Appearance')
  })

  it('marks the current choice as checked', () => {
    const wrapper = mount(ThemeToggle)

    const checked = wrapper.findAll('[aria-checked="true"]')

    expect(checked).toHaveLength(1)
  })

  it('changes the theme when a choice is picked', async () => {
    const wrapper = mount(ThemeToggle)
    const ui = useUiStore()

    const dark = wrapper.findAll('[role="radio"]').at(2)
    await dark?.trigger('click')

    expect(ui.theme).toBe('dark')
  })
})

describe('StepUpDialog', () => {
  /** The bridge the API client awaits. */
  function requestStepUp(): Promise<string | null> {
    const request = (window as { asidsRequestStepUp?: () => Promise<string | null> })
      .asidsRequestStepUp

    if (request === undefined) {
      throw new Error('The dialog did not register window.asidsRequestStepUp.')
    }

    return request()
  }

  it('registers the bridge the API client uses', () => {
    mount(StepUpDialog)

    // Registered on `window` rather than imported, so `api/client.ts` can await a code without
    // depending on Vue — the client is used by plain functions too.
    expect(
      (window as { asidsRequestStepUp?: unknown }).asidsRequestStepUp,
    ).toBeTypeOf('function')
  })

  it('stays closed until a code is requested', () => {
    const wrapper = mount(StepUpDialog)

    expect(wrapper.text()).toBe('')
  })

  it('opens when a code is requested', async () => {
    const wrapper = mount(StepUpDialog)

    void requestStepUp()
    await nextTick()

    expect(wrapper.find('input').exists()).toBe(true)
  })

  it('refuses a code that is too short without troubling the server', async () => {
    const wrapper = mount(StepUpDialog)

    void requestStepUp()
    await nextTick()

    await wrapper.find('input').setValue('123')
    await wrapper.find('form').trigger('submit')

    // Rejected locally: a five-digit code cannot be right, and sending it would consume one of the
    // user's rate-limited attempts to tell them something the client already knew.
    expect(wrapper.text()).toContain('six-digit code')
  })

  it('resolves with the code once a full one is entered', async () => {
    const wrapper = mount(StepUpDialog)

    const pending = requestStepUp()
    await nextTick()

    await wrapper.find('input').setValue('123 456')
    await wrapper.find('form').trigger('submit')

    // Spaces stripped, because authenticator apps display codes in groups and users paste what they
    // see.
    await expect(pending).resolves.toBe('123456')
  })

  it('resolves with null when cancelled', async () => {
    const wrapper = mount(StepUpDialog)

    const pending = requestStepUp()
    await nextTick()

    const cancel = wrapper.findAll('button').find((button) => button.text().match(/cancel/i))
    await cancel?.trigger('click')

    // Null rather than a rejection: cancelling is a decision the client handles by surfacing the
    // original error, not an exception to report.
    await expect(pending).resolves.toBeNull()
  })
})
