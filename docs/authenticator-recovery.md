# Authenticator recovery

## Overview

Authenticator enrollment requires the user to verify account access, configure and verify an authenticator, and generate ten recovery codes. The authenticator and recovery-code set are activated only after the user confirms that the recovery codes have been stored safely.

Users who already have an authenticator enabled can generate their first recovery-code set from Account settings after completing fresh authenticator verification. Recovery codes are shown only once and can never be retrieved later. Generating a replacement creates a pending set while the current codes remain valid; the old set is invalidated only after the new codes have been displayed and acknowledged.

During sign-in, one unused recovery code can be used instead of an authenticator code. If an authenticator is permanently lost, the user can verify access to the account email address, consume an active recovery code, and complete the protected authenticator-replacement flow. Successful replacement activates a new authenticator and recovery-code set and revokes other browser sessions for the account.

## Self-service security model

This feature follows a self-service account-security model. Users remain in control of authenticator enrollment, recovery-code replacement, authenticator removal, and lost-authenticator recovery through the protected account flows.

Customer support will not:

- disable or remove authenticators;
- retrieve or reveal recovery codes;
- bypass required verification steps;
- manually restore access when the required recovery factors are unavailable.

## Security constraints

Authenticator secrets and recovery codes must never be logged. Recovery-code plaintext exists only during its intentional one-time display.
