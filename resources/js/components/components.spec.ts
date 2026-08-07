import { beforeEach, describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { useAuthStore } from '@/stores/auth'
import AlertBanner from '@/components/ui/AlertBanner.vue'
import TextField from '@/components/ui/TextField.vue'
import PermissionGate from '@/components/app/PermissionGate.vue'

/**
 * The shared components.
 *
 * Two of these carry accessibility contracts that are invisible when broken. `TextField` wires an
 * error into `aria-describedby` and `aria-invalid` so a screen-reader user learns *which* field failed
 * and why — colour alone would fail WCAG and be invisible to a colour-blind user, and "your VAT
 * return failed to file" is not a message to convey with hue. `AlertBanner` pairs every kind with a
 * distinct icon for the same reason.
 */

beforeEach(() => {
  setActivePinia(createPinia())
})

describe('TextField', () => {
  it('associates the label with the input', () => {
    const wrapper = mount(TextField, {
      props: { label: 'E-mail address', modelValue: '' },
    })

    const id = wrapper.find('input').attributes('id')

    expect(id).toBeTruthy()
    expect(wrapper.find('label').attributes('for')).toBe(id)
  })

  it('gives each field on a form a distinct id', () => {
    // Both fields in one app, which is what a real form is. `useId()` counts per application
    // instance, so two separate `mount()` calls each start from zero and would collide for a reason
    // that never occurs in the SPA — there is one app.
    const form = mount(
      {
        components: { TextField },
        template: `
          <form>
            <TextField label="First name" model-value="" />
            <TextField label="Last name" model-value="" />
          </form>
        `,
      },
      { global: { stubs: {} } },
    )

    const [first, second] = form.findAll('input')

    // Two fields sharing an id makes both labels point at the first input, so clicking the second
    // label focuses the wrong box.
    expect(first?.attributes('id')).toBeTruthy()
    expect(first?.attributes('id')).not.toBe(second?.attributes('id'))
  })

  it('announces a required field to a screen reader as well as visually', () => {
    const wrapper = mount(TextField, {
      props: { label: 'E-mail address', modelValue: '', required: true },
    })

    // The asterisk is `aria-hidden`, because "asterisk" is not a word a screen reader should read
    // out. The text alternative is what carries the meaning.
    expect(wrapper.find('[aria-hidden="true"]').text()).toBe('*')
    expect(wrapper.find('.sr-only').text()).toBe('(required)')
  })

  it('wires an error into aria-invalid and aria-describedby', () => {
    const wrapper = mount(TextField, {
      props: { label: 'E-mail address', modelValue: '', error: 'That address is already in use.' },
    })

    const input = wrapper.find('input')
    const id = input.attributes('id')

    expect(input.attributes('aria-invalid')).toBe('true')
    expect(input.attributes('aria-describedby')).toBe(`${id}-error`)
    expect(wrapper.find(`#${id}-error`).text()).toBe('That address is already in use.')
  })

  it('announces the error immediately', () => {
    const wrapper = mount(TextField, {
      props: { label: 'E-mail', modelValue: '', error: 'Required.' },
    })

    // `role="alert"` so the message is read when it appears, rather than only when the user next
    // happens to focus the field.
    expect(wrapper.find('[role="alert"]').exists()).toBe(true)
  })

  it('describes the input by its hint when there is no error', () => {
    const wrapper = mount(TextField, {
      props: { label: 'Workspace address', modelValue: '', hint: 'Lowercase letters and hyphens.' },
    })

    const input = wrapper.find('input')

    expect(input.attributes('aria-describedby')).toBe(`${input.attributes('id')}-hint`)
    expect(input.attributes('aria-invalid')).toBeUndefined()
  })

  it('hides the hint once there is an error, so the two do not compete', () => {
    const wrapper = mount(TextField, {
      props: { label: 'A', modelValue: '', hint: 'Some guidance.', error: 'Required.' },
    })

    expect(wrapper.text()).toContain('Required.')
    expect(wrapper.text()).not.toContain('Some guidance.')
  })

  it('leaves aria-describedby unset when there is nothing to describe', () => {
    const wrapper = mount(TextField, { props: { label: 'A', modelValue: '' } })

    // An empty `aria-describedby` is worse than none: some screen readers announce the dangling id.
    expect(wrapper.find('input').attributes('aria-describedby')).toBeUndefined()
  })

  it('emits the typed value', async () => {
    const wrapper = mount(TextField, { props: { label: 'A', modelValue: '' } })

    await wrapper.find('input').setValue('typed')

    expect(wrapper.emitted('update:modelValue')?.[0]).toEqual(['typed'])
  })

  it('renders the value it is given', () => {
    const wrapper = mount(TextField, { props: { label: 'A', modelValue: 'existing' } })

    expect(wrapper.find('input').element.value).toBe('existing')
  })

  it('defaults to a text input and honours an override', () => {
    expect(mount(TextField, { props: { label: 'A', modelValue: '' } }).find('input').attributes('type')).toBe(
      'text',
    )

    expect(
      mount(TextField, { props: { label: 'A', modelValue: '', type: 'password' } })
        .find('input')
        .attributes('type'),
    ).toBe('password')
  })
})

describe('AlertBanner', () => {
  it('announces itself as an alert', () => {
    const wrapper = mount(AlertBanner, { slots: { default: 'Something happened.' } })

    expect(wrapper.attributes('role')).toBe('alert')
    expect(wrapper.text()).toContain('Something happened.')
  })

  it('pairs each kind with a distinct icon, not only a colour', () => {
    const paths = (['success', 'error', 'warning', 'info'] as const).map(
      (kind) => mount(AlertBanner, { props: { kind } }).find('path').attributes('d'),
    )

    // Colour alone is not a signal a colour-blind user can read. Four kinds must be four shapes.
    expect(new Set(paths).size).toBe(4)
  })

  it('hides the decorative icon from assistive technology', () => {
    const wrapper = mount(AlertBanner, { props: { kind: 'error' } })

    // The icon repeats what the text says. Announcing it would read the message twice.
    expect(wrapper.find('svg').attributes('aria-hidden')).toBe('true')
  })

  it('renders a title when given one', () => {
    const wrapper = mount(AlertBanner, {
      props: { kind: 'warning', title: 'These are shown only once' },
      slots: { default: 'Keep them safe.' },
    })

    expect(wrapper.text()).toContain('These are shown only once')
  })

  it('shows a request id in monospace when given one', () => {
    const wrapper = mount(AlertBanner, { props: { kind: 'error', requestId: 'req_01HZ' } })

    // Support asks for it by name, and a proportional font makes a 26-character identifier
    // error-prone to read aloud.
    expect(wrapper.text()).toContain('req_01HZ')
    expect(wrapper.find('.font-mono').exists()).toBe(true)
  })

  it('defaults to the info kind', () => {
    const wrapper = mount(AlertBanner, { slots: { default: 'Note.' } })

    expect(wrapper.classes().join(' ')).toContain('info')
  })
})

describe('PermissionGate', () => {
  /** Seeds a session with an explicit permission set. */
  function signInWith(permissions: string[], isOwner = false): void {
    useAuthStore().$patch({
      user: { id: 'u', full_name: 'A', email: 'a@b.test', is_owner: isOwner },
      permissions: new Set(permissions),
    } as never)
  }

  it('renders its slot when the permission is held', () => {
    signInWith(['identity.users.invite'])

    const wrapper = mount(PermissionGate, {
      props: { permission: 'identity.users.invite' },
      slots: { default: '<button>Invite a user</button>' },
    })

    expect(wrapper.text()).toContain('Invite a user')
  })

  it('renders nothing when the permission is missing', () => {
    signInWith(['identity.users.view'])

    const wrapper = mount(PermissionGate, {
      props: { permission: 'identity.users.invite' },
      slots: { default: '<button>Invite a user</button>' },
    })

    // Hiding the control rather than offering one that 403s. This is presentation only — a user who
    // edits the DOM gains a button that the server refuses.
    expect(wrapper.text()).not.toContain('Invite a user')
  })

  it('renders the fallback slot instead when one is given', () => {
    signInWith([])

    const wrapper = mount(PermissionGate, {
      props: { permission: 'identity.users.invite' },
      slots: { default: '<button>Invite</button>', fallback: '<p>Ask an administrator.</p>' },
    })

    expect(wrapper.text()).toBe('Ask an administrator.')
  })

  it('accepts several permissions and passes on any of them', () => {
    signInWith(['organization.companies.view'])

    const wrapper = mount(PermissionGate, {
      props: { permission: ['identity.users.invite', 'organization.companies.view'] },
      slots: { default: '<span>Visible</span>' },
    })

    expect(wrapper.text()).toContain('Visible')
  })

  it('requires at least one of several permissions', () => {
    signInWith(['settings.company.view'])

    const wrapper = mount(PermissionGate, {
      props: { permission: ['identity.users.invite', 'organization.companies.view'] },
      slots: { default: '<span>Visible</span>' },
    })

    expect(wrapper.text()).not.toContain('Visible')
  })

  it('shows everything to an owner', () => {
    signInWith([], true)

    const wrapper = mount(PermissionGate, {
      props: { permission: 'anything.at.all' },
      slots: { default: '<span>Visible</span>' },
    })

    // Mirrors the server's owner short circuit, so the interface does not hide a control the owner
    // can in fact use.
    expect(wrapper.text()).toContain('Visible')
  })

  it('shows nothing before a session has loaded', () => {
    const wrapper = mount(PermissionGate, {
      props: { permission: 'identity.users.view' },
      slots: { default: '<span>Visible</span>' },
    })

    // Fail closed. A menu that renders before the session arrives must show nothing, not everything.
    expect(wrapper.text()).not.toContain('Visible')
  })
})
