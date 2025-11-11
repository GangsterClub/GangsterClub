# JWT refresh manual verification

These steps confirm that an authenticated browser session mints a replacement access token after the original JWT expires, while the logout flow still clears every credential.

1. Sign in through the normal email/TOTP flow. Use the developer tools network tab to capture the initial `Authorization` header after login and confirm the token's `exp` claim by decoding it with [jwt.io](https://jwt.io/).
2. Let the session sit idle until the captured token is older than `JWT::DEFAULT_TTL` (6 minutes). Do **not** reload the page during this wait.
3. Trigger an authenticated request (for example, navigate to any page that makes an AJAX call). Observe that:
   - The response includes a fresh `Authorization: Bearer …` header.
   - Decoding the new token shows a later `iat`/`exp` pair, confirming the refresh.
4. Without closing the tab, click the **Logout** button. Verify that:
   - A subsequent authenticated request fails because the session no longer includes `UID` or `jwt_token`.
   - Reloading the page requires you to authenticate again.

Capturing the headers before and after step 3 demonstrates the automatic refresh for an idle-but-valid session, while step 4 proves that the logout workflow still removes all authentication state.
