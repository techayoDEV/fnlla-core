# Stale browser tabs and CSRF

An invalid POST continues to fail CSRF validation. Invalid JSON requests return
HTTP 419 with private/no-store caching, without changing the session's valid
CSRF token or writing a flash message into another tab. Rejected requests never
execute their downstream handler. Non-JSON requests keep the existing flash and
redirect behaviour, also without rotating a valid token on rejection.

This prevents a stale polling tab from repeatedly invalidating forms in an
active tab. Applications should stop background polling after 419 and ask the
user to refresh. Do not automatically replay mutations or weaken validation.
Login, logout and identity changes must still rotate the token as appropriate.

No configuration or method signature changes are required. This source change
is intended for a reviewed future release; existing release artifacts are not
modified. SecurityPrimitivesTest covers repeated stale requests and a subsequent
valid form submission.
