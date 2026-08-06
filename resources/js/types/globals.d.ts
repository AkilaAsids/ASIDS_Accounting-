/**
 * The step-up bridge.
 *
 * The API client must be able to prompt for a TOTP code, but it cannot import a Vue
 * component without becoming untestable. App.vue registers this function on mount; the
 * client awaits it. A global is the smallest seam that keeps the client framework-free.
 */
declare global {
  interface Window {
    asidsRequestStepUp: () => Promise<string | null>
  }
}

export {}
