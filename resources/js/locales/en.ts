/**
 * English messages.
 *
 * Sinhala (`si`) and Tamil (`ta`) are loaded on demand — see app/main.ts — so a visitor is
 * not sent three catalogues to read one.
 *
 * Two conventions, both of which matter for a product sold to accountants:
 *   * Sentence case, not Title Case. It reads as a person wrote it.
 *   * Accounting terms use the words a Sri Lankan bookkeeper uses, not their US equivalents:
 *     "workspace", not "organization"; "e-mail", not "email".
 */
export default {
  common: {
    save: 'Save',
    cancel: 'Cancel',
    delete: 'Delete',
    confirm: 'Confirm',
    search: 'Search',
    loading: 'Loading…',
    noResults: 'Nothing to show',
    required: 'Required',
    optional: 'Optional',
    never: 'Never',
    yes: 'Yes',
    no: 'No',
  },

  auth: {
    signIn: 'Sign in',
    signOut: 'Sign out',
    signOutEverywhere: 'Sign out everywhere',
    email: 'E-mail address',
    password: 'Password',
    rememberMe: 'Keep me signed in',
    forgotPassword: 'Forgot password?',
    twoFactorCode: 'Authenticator code',
    recoveryCode: 'Recovery code',
    trustDevice: 'Trust this device for 30 days',
    sessionEnded: 'Your session has ended. Please sign in again.',
  },

  security: {
    title: 'Security',
    twoFactor: 'Two factor authentication',
    twoFactorOn: 'Two factor authentication is on',
    twoFactorOff: 'Two factor authentication is off',
    recoveryCodes: 'Recovery codes',
    recoveryCodesRemaining: '{count} recovery code remaining | {count} recovery codes remaining',
    devices: 'Signed-in devices',
    thisDevice: 'This device',
    loginHistory: 'Recent sign-ins',
    apiTokens: 'API tokens',
    revoke: 'Revoke',
    changePassword: 'Change password',
  },

  users: {
    title: 'Users',
    invite: 'Invite a user',
    invited: 'Invitation sent',
    seatsUsed: '{used} of {limit} users',
    status: {
      pending_invitation: 'Invitation pending',
      active: 'Active',
      suspended: 'Suspended',
      deactivated: 'Deactivated',
    },
  },

  roles: {
    title: 'Roles',
    systemRole: 'Built in',
    ownerRole: 'Owner',
    permissions: 'Permissions',
    sensitiveWarning: 'This permission can move money or change security settings.',
  },

  settings: {
    title: 'Settings',
    scope: { user: 'Personal', company: 'Company', tenant: 'Workspace', system: 'Platform' },
    inherited: 'Inherited',
    resetToInherited: 'Reset to inherited',
    saved: 'Settings saved',
  },

  errors: {
    generic: 'Something went wrong. If it keeps happening, please contact support.',
    network: 'Could not reach the server. Check your connection and try again.',
    forbidden: 'You do not have permission to do that.',
    notFound: 'We could not find that page.',
    reference: 'Reference: {id}',
  },
} as const
