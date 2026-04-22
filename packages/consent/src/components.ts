/**
 * Side-effect-free barrel export for the consent package.
 *
 * Use this entry when importing consent components into another
 * workspace package (e.g. `@wcpos/settings`). It intentionally does
 * not include the DOM mount logic that lives in `index.tsx`.
 */
export { ConsentModal, type ConsentModalProps } from './consent-modal';
export { ConsentCallout, type ConsentCalloutProps } from './consent-callout';
export { PrivacyInfoModal, type PrivacyInfoModalProps } from './privacy-info-modal';
export { saveConsent, type ConsentChoice, type ConsentConfig } from './api';
