# Authenticator app enrollment refactor

## Overview

This release strengthens authenticator-app enrollment by requiring users to confirm that they have saved their recovery codes before Authenticator-app authentication is activated.

The change is supported by a broader authentication refactor that separates enrollment, authentication challenges, recovery-code handling, rate limiting, and security auditing into clearer application services. This improves the reliability and maintainability of the authentication flow while preparing the codebase for future security features.

## Highlights

- Require explicit recovery-code confirmation during TOTP enrollment
- Prevent incomplete authenticator enrollment
- Improve recovery-code generation, storage, and consumption
- Clarify authentication service responsibilities
- Strengthen authentication challenge and rate-limit handling
- Expand security-focused test coverage
